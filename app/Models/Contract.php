<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    /** Tipos que dan derecho a vacaciones, segun el informe. */
    public const PERMANENT_TYPES = ['nombrado', 'permanente'];

    /** Duracion maxima de un contrato eventual, en meses. */
    public const TEMPORARY_MONTHS = 2;

    protected $fillable = [
        'user_id', 'code', 'type', 'regime', 'start_date', 'end_date', 'description',
    ];

    protected $appends = ['is_active', 'duration_label'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Contratos vigentes en la fecha dada. */
    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            });
    }

    protected function isActive(): Attribute
    {
        return Attribute::get(function () {
            $today = now()->startOfDay();

            return $this->start_date <= $today
                && ($this->end_date === null || $this->end_date >= $today);
        });
    }

    /** Columna DURACIÓN de W09: "2 meses" para eventuales, "—" para indefinidos. */
    protected function durationLabel(): Attribute
    {
        return Attribute::get(function () {
            if ($this->end_date === null) {
                return '—';
            }

            $months = $this->start_date->diffInMonths($this->end_date->copy()->addDay());

            return $months >= 1
                ? $months.' '.($months === 1 ? 'mes' : 'meses')
                : $this->start_date->diffInDays($this->end_date) + 1 .' días';
        });
    }

    /** Solo nombrados y permanentes tienen derecho a vacaciones. */
    public function grantsVacations(): bool
    {
        return in_array($this->type, self::PERMANENT_TYPES, true);
    }

    /** Genera el correlativo CON-AAAA-NNN que muestra W09. */
    public static function nextCode(int $year): string
    {
        $count = static::whereYear('start_date', $year)->count() + 1;

        return sprintf('CON-%d-%03d', $year, $count);
    }
}
