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

    /** Período de prueba más largo que admite el formulario, en meses. */
    public const MAX_PROBATION_MONTHS = 12;

    protected $fillable = [
        'user_id', 'code', 'type', 'regime', 'start_date', 'end_date', 'salary', 'probation_months', 'description',
    ];

    protected $appends = ['is_active', 'duration_label'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'salary' => 'decimal:2',
            'probation_months' => 'integer',
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

    /**
     * Espejo en memoria de scopeActiveOn(). Existe porque la programacion
     * comprueba decenas de fechas de golpe y no puede lanzar una consulta por
     * cada una. Si cambia una regla aqui, cambia tambien en el scope.
     */
    public function coversDate(string $date): bool
    {
        if ($this->start_date->toDateString() > $date) {
            return false;
        }

        return $this->end_date === null || $this->end_date->toDateString() >= $date;
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

    /** Columna SALARIO de W09: "S/ 1,500.00", o "—" en los contratos anteriores al campo. */
    protected function salaryLabel(): Attribute
    {
        return Attribute::get(fn () => $this->salary === null ? '—' : 'S/ '.number_format((float) $this->salary, 2));
    }

    /** Columna PERÍODO DE PRUEBA de W09: "3 meses", o "—" si no tiene. */
    protected function probationLabel(): Attribute
    {
        return Attribute::get(fn () => match ($this->probation_months) {
            null => '—',
            1 => '1 mes',
            default => $this->probation_months.' meses',
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
