<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    /**
     * Ventana de marcacion, en minutos respecto a la hora de inicio del turno.
     *
     * W11 describe una ventana por turno ("el turno Mañana admite marcación
     * entre las 05:30 y las 06:30") y distingue Presente de Tardanza. Estos son
     * los tres parametros que la definen; cambiarlos aqui cambia la regla en el
     * terminal y el mensaje que ve el trabajador.
     */
    public const OPENS_BEFORE = 30;

    /** Tolerancia tras la hora de inicio antes de contar tardanza. */
    public const GRACE_AFTER = 10;

    /** Pasado este margen ya no se admite marcacion: el dia queda como falta. */
    public const CLOSES_AFTER = 120;

    /**
     * La salida se marca desde la entrada hasta este margen tras el fin del
     * turno. Antes del fin queda como salida anticipada.
     */
    public const EXIT_CLOSES_AFTER = 120;

    protected $fillable = ['user_id', 'date', 'schedule_id', 'check_in', 'check_out', 'status'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    /** Presente y tarde cuentan como asistencia para iniciar el recorrido. */
    public function isPresent(): bool
    {
        return in_array($this->status, ['presente', 'tarde'], true);
    }

    /** Marcó la entrada y todavía no la salida. */
    public function isOpen(): bool
    {
        return $this->isPresent() && $this->check_in && ! $this->check_out;
    }

    /** Fin del turno de esta marcación, o null si quedó sin turno. */
    public function shiftEndsAt(): ?CarbonImmutable
    {
        return $this->schedule?->endsAt(CarbonImmutable::parse($this->date));
    }

    /** Hasta cuándo se acepta la salida de esta marcación. */
    public function exitClosesAt(): ?CarbonImmutable
    {
        return $this->schedule?->exitClosesAt(CarbonImmutable::parse($this->date));
    }

    /**
     * Cómo va la salida: "anticipada" si se marcó antes del fin del turno,
     * "sin_salida" si el turno ya terminó y no se marcó, o null si está en
     * orden (o el turno sigue en curso).
     */
    public function exitState(?CarbonImmutable $now = null): ?string
    {
        $end = $this->shiftEndsAt();

        if (! $this->isPresent() || ! $end) {
            return null;
        }

        if ($this->check_out) {
            $out = CarbonImmutable::parse($this->date->toDateString().' '.$this->check_out);

            return $out->lessThan($end) ? 'anticipada' : null;
        }

        return ($now ?? CarbonImmutable::now())->greaterThan($end) ? 'sin_salida' : null;
    }
}
