<?php

namespace App\Models;

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
}
