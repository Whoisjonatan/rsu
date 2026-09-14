<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vacation extends Model
{
    /** Tope anual que fija el informe. */
    public const MAX_DAYS_PER_YEAR = 30;

    protected $fillable = ['user_id', 'year', 'days', 'start_date', 'end_date', 'status'];

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

    /** Vacaciones que cubren la fecha dada y siguen en pie. */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->where('status', '!=', 'canceladas')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
    }

    /** Espejo en memoria de scopeCovering(); ver la nota en Contract::coversDate(). */
    public function coversDate(string $date): bool
    {
        return $this->status !== 'canceladas'
            && $this->start_date->toDateString() <= $date
            && $this->end_date->toDateString() >= $date;
    }

    public function scopeOfYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    /** Días ya comprometidos en el año, sin contar los cancelados. */
    public static function usedDays(int $userId, int $year, ?int $ignoreId = null): int
    {
        return (int) static::query()
            ->where('user_id', $userId)
            ->ofYear($year)
            ->where('status', '!=', 'canceladas')
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->sum('days');
    }

    /** Saldo del año: 30 menos lo ya comprometido. */
    public static function pendingDays(int $userId, int $year, ?int $ignoreId = null): int
    {
        return max(0, self::MAX_DAYS_PER_YEAR - static::usedDays($userId, $year, $ignoreId));
    }
}
