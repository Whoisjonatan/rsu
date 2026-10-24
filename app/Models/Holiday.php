<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Feriado. Uno activo saca ese día de la programación: no se generan
 * recorridos ni se cuenta como día hábil en asistencias. Uno inactivo queda
 * registrado pero no se considera en ninguna validación.
 */
class Holiday extends Model
{
    protected $fillable = ['date', 'description', 'status'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'activo');
    }

    /**
     * Filtros del listado: rango de fechas y estado.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', (string) $filters['status']));
    }

    /**
     * Fechas de los feriados activos dentro del rango, como 'Y-m-d'.
     *
     * @return list<string>
     */
    public static function activeDatesBetween(mixed $from, mixed $to): array
    {
        if (! $from || ! $to) {
            return [];
        }

        return static::active()
            ->whereDate('date', '>=', CarbonImmutable::parse($from)->toDateString())
            ->whereDate('date', '<=', CarbonImmutable::parse($to)->toDateString())
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->all();
    }

    public static function isHoliday(string $date): bool
    {
        return static::active()->whereDate('date', $date)->exists();
    }

    /**
     * Contadores de las tarjetas: año y mes en curso, y los que faltan.
     *
     * @return array{year: int, year_total: int, month_total: int, upcoming: int, next: ?self}
     */
    public static function summary(): array
    {
        $today = CarbonImmutable::now();
        $upcoming = static::active()->whereDate('date', '>=', $today->toDateString());

        return [
            'year' => $today->year,
            'year_total' => static::active()->whereYear('date', $today->year)->count(),
            'month_total' => static::active()
                ->whereYear('date', $today->year)
                ->whereMonth('date', $today->month)
                ->count(),
            'upcoming' => (clone $upcoming)->count(),
            'next' => $upcoming->orderBy('date')->first(),
        ];
    }

    /**
     * Rehace los recorridos de las programaciones que cubren esas fechas. Se
     * llama después de crear, editar, eliminar o cargar feriados: el día que
     * pasa a feriado pierde sus recorridos pendientes y el que deja de serlo
     * los recupera. Lo ya iniciado y lo pasado no se toca (ver
     * Scheduling::syncVehicleRoutes).
     *
     * @param  list<string>  $dates
     */
    public static function resyncSchedulings(array $dates): int
    {
        $today = now()->toDateString();
        $dates = array_values(array_filter(array_unique($dates), fn (string $date) => $date >= $today));

        if ($dates === []) {
            return 0;
        }

        $schedulings = Scheduling::query()
            ->where(function ($q) use ($dates) {
                foreach ($dates as $date) {
                    $q->orWhere(fn ($q) => $q->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date));
                }
            })
            ->get();

        foreach ($schedulings as $scheduling) {
            $scheduling->syncVehicleRoutes();
        }

        return $schedulings->count();
    }
}
