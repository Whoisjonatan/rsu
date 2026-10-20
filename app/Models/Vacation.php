<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vacation extends Model
{
    /** Tope anual que fija el informe. */
    public const MAX_DAYS_PER_YEAR = 30;

    /**
     * Estados que ocupan fechas y días del saldo. Lo solicitado también cuenta,
     * para que no se pueda pedir dos veces lo mismo mientras se evalúa.
     */
    public const COMMITTED_STATUSES = ['pendientes', 'programadas', 'gozadas'];

    /** Estados aprobados: solo estos sacan a la persona de la programación. */
    public const APPROVED_STATUSES = ['programadas', 'gozadas'];

    protected $fillable = ['user_id', 'year', 'days', 'start_date', 'end_date', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Recién solicitada: es la única que se puede editar, aprobar o rechazar. */
    public function isPending(): bool
    {
        return $this->status === 'pendientes';
    }

    /** Rechazada queda cerrada: ni se edita, ni se elimina, ni se aprueba. */
    public function isRejected(): bool
    {
        return $this->status === 'rechazadas';
    }

    /** Por qué no se puede editar, o null si se puede. */
    public function editBlocker(): ?string
    {
        return match (true) {
            $this->isRejected() => 'Una solicitud rechazada ya no se puede modificar.',
            ! $this->isPending() => 'Solo se pueden editar las solicitudes pendientes.',
            default => null,
        };
    }

    /** Gozada es historial: no se borra. */
    public function isEnjoyed(): bool
    {
        return $this->status === 'gozadas';
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        return match (true) {
            $this->isRejected() => 'Una solicitud rechazada ya no se puede eliminar.',
            $this->isEnjoyed() => 'Unas vacaciones ya gozadas no se pueden eliminar.',
            default => null,
        };
    }

    /** Por qué no se puede aprobar ni rechazar, o null si se puede. */
    public function decisionBlocker(): ?string
    {
        return $this->isPending() ? null : 'Esta solicitud ya fue evaluada.';
    }

    /** Vacaciones aprobadas que cubren la fecha dada. */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->whereIn('status', self::APPROVED_STATUSES)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
    }

    /** Espejo en memoria de scopeCovering(); ver la nota en Contract::coversDate(). */
    public function coversDate(string $date): bool
    {
        return in_array($this->status, self::APPROVED_STATUSES, true)
            && $this->start_date->toDateString() <= $date
            && $this->end_date->toDateString() >= $date;
    }

    /**
     * Pasa a "gozadas" las vacaciones aprobadas cuyo último día ya pasó. Se
     * llama al mostrar las vacaciones y una vez al día desde el scheduler, así
     * el estado queda bien aunque el scheduler no esté corriendo.
     */
    public static function closeFinished(?int $userId = null): int
    {
        return static::query()
            ->where('status', 'programadas')
            ->whereDate('end_date', '<', now()->toDateString())
            ->when($userId, fn (Builder $q) => $q->where('user_id', $userId))
            ->update(['status' => 'gozadas']);
    }

    public function scopeOfYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    /**
     * Días del año en los estados dados; por defecto, los ya comprometidos
     * (solicitados o aprobados).
     *
     * @param  list<string>  $statuses
     */
    public static function usedDays(int $userId, int $year, ?int $ignoreId = null, array $statuses = self::COMMITTED_STATUSES): int
    {
        return (int) static::query()
            ->where('user_id', $userId)
            ->ofYear($year)
            ->whereIn('status', $statuses)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->sum('days');
    }

    /** Saldo del año: 30 menos lo solicitado o aprobado. */
    public static function pendingDays(int $userId, int $year, ?int $ignoreId = null): int
    {
        return max(0, self::MAX_DAYS_PER_YEAR - static::usedDays($userId, $year, $ignoreId));
    }

    /**
     * Los indicadores de W10 para un período. `pending_days` es el saldo que
     * queda por pedir; lo que espera aprobación va en `requested_days`.
     *
     * @return array{year: int, max_days: int, scheduled_days: int, requested_days: int, pending_days: int, eligible: bool}
     */
    public static function summaryFor(User $person, int $year): array
    {
        return [
            'year' => $year,
            'max_days' => self::MAX_DAYS_PER_YEAR,
            'scheduled_days' => static::usedDays($person->id, $year, statuses: self::APPROVED_STATUSES),
            'requested_days' => static::usedDays($person->id, $year, statuses: ['pendientes']),
            'pending_days' => static::pendingDays($person->id, $year),
            // Si no tiene contrato que dé derecho, W10 no deja registrar.
            'eligible' => $person->isVacationEligible(),
        ];
    }
}
