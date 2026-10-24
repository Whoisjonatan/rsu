<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Scheduling extends Model
{
    /** Relaciones que pintan las listas y la ficha (W17, W18). */
    public const RELATIONS = ['zone', 'route', 'vehicle', 'schedule', 'driver', 'helper'];

    /**
     * Filtros del listado de W17. Los comparten el API y la vista Blade.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $today = now()->toDateString();

        return $query
            ->when(filled($filters['zone_id'] ?? null), fn ($q) => $q->where('zone_id', (int) $filters['zone_id']))
            ->when(filled($filters['schedule_id'] ?? null), fn ($q) => $q->where('schedule_id', (int) $filters['schedule_id']))
            ->when(filled($filters['vehicle_id'] ?? null), fn ($q) => $q->where('vehicle_id', (int) $filters['vehicle_id']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', (string) $filters['status']))
            // "Vigentes hoy": las que cubren la fecha actual.
            ->when(filter_var($filters['current'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today))
            // W17 filtra por fecha ("24/08/2026"): solo las vigentes ese día.
            ->when(filled($filters['date'] ?? null), fn ($q) => $q
                ->whereDate('start_date', '<=', $filters['date'])
                ->whereDate('end_date', '>=', $filters['date']));
    }

    /**
     * Cuelga de cada programación el estado de su recorrido en esa fecha y el
     * aviso de vacaciones sobrevenidas, que es lo que W17 pinta bajo la tabla.
     *
     * La columna ESTADO de W17 no es el estado de la programación
     * (activa/inactiva) sino el del recorrido del día consultado, que vive en
     * otra tabla. Se resuelve en una consulta para toda la página.
     *
     * @param  Collection<int, self>  $schedulings
     */
    public static function attachDayStatus(Collection $schedulings, string $date): void
    {
        $routes = VehicleRoute::query()
            ->whereIn('scheduling_id', $schedulings->pluck('id'))
            ->whereDate('date_route', $date)
            ->get()
            ->keyBy('scheduling_id');

        $weekday = (int) date('N', strtotime($date));
        $isHoliday = Holiday::isHoliday($date);

        foreach ($schedulings as $scheduling) {
            $route = $routes->get($scheduling->id);

            $scheduling->day_route_id = $route?->id;
            // Sin recorrido ese día puede ser que ese día no toque (no está en
            // weekdays), que sea feriado o que sea pasado: no es lo mismo y la
            // fila lo dice.
            $scheduling->day_status = $route?->status
                ?? (in_array($weekday, array_map('intval', $scheduling->weekdays ?? []), true)
                    ? ($isHoliday ? 'feriado' : 'sin_recorrido')
                    : 'no_aplica');
            $scheduling->day_block_reason = $route?->block_reason;
            $scheduling->vacation_conflicts = $scheduling->vacationConflicts($date);
        }
    }

    /**
     * Contadores de la barra superior de W17.
     *
     * @return array{total: int, activas: int, vigentes: int, inactivas: int}
     */
    public static function summary(): array
    {
        $today = now()->toDateString();

        return [
            'total' => static::count(),
            'activas' => static::where('status', 'activa')->count(),
            'vigentes' => static::where('status', 'activa')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->count(),
            'inactivas' => static::where('status', 'inactiva')->count(),
        ];
    }

    /**
     * Guarda la regla maestra y regenera los recorridos de cada día en la
     * misma transacción: una programación sin sus recorridos no existe para
     * el calendario (W20) ni para el inicio de recorrido (W21).
     *
     * @param  array<string, mixed>  $data  Lo validado por SchedulingRules.
     */
    public function saveAndSync(array $data): self
    {
        DB::transaction(function () use ($data) {
            $this->fill($data)->save();
            $this->syncVehicleRoutes();
        });

        return $this;
    }

    /**
     * Por qué no se puede eliminar, o null si se puede. Si algún recorrido ya
     * arrancó, la programación es historia: se desactiva, no se borra, para
     * que W20 siga explicando lo que pasó.
     */
    public function deletionBlocker(): ?string
    {
        $started = $this->vehicleRoutes()->where('status', '!=', 'programado')->count();

        return $started > 0
            ? sprintf(
                'No se puede eliminar: la programación ya tiene %d %s. Cámbiala a inactiva.',
                $started,
                $started === 1 ? 'recorrido iniciado' : 'recorridos iniciados',
            )
            : null;
    }

    /** Etiquetas ISO-8601: 1 es lunes, 7 domingo. */
    public const WEEKDAY_LABELS = [
        1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom',
    ];

    protected $fillable = [
        'zone_id', 'route_id', 'vehicle_id', 'schedule_id',
        'driver_id', 'helper_id', 'weekdays', 'start_date', 'end_date', 'status',
    ];

    /**
     * El codigo no se recibe del formulario: lo pone el sistema, como en
     * Contract. Se asigna aqui para que valga igual desde la API, desde un
     * seeder o desde tinker.
     */
    protected static function booted(): void
    {
        static::creating(function (self $scheduling) {
            $scheduling->code ??= self::nextCode();
        });
    }

    /** Genera el correlativo PRG-NNNN que muestra W17. */
    public static function nextCode(): string
    {
        $last = static::query()
            ->whereNotNull('code')
            ->orderByDesc('code')
            ->value('code');

        $numero = $last ? (int) substr($last, 4) : 0;

        return sprintf('PRG-%04d', $numero + 1);
    }

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function helper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'helper_id');
    }

    public function vehicleRoutes(): HasMany
    {
        return $this->hasMany(VehicleRoute::class);
    }

    /**
     * Fechas que cubre la programacion, solo en los dias de semana marcados y
     * sin los feriados activos: un feriado no lleva recorrido, y por eso
     * tampoco cuenta en las validaciones de contrato y vacaciones.
     *
     * @return list<string>
     */
    public function occurrenceDates(): array
    {
        $days = array_map('intval', $this->weekdays ?? []);
        $holidays = Holiday::activeDatesBetween($this->start_date, $this->end_date);

        return collect(CarbonPeriod::create($this->start_date, $this->end_date))
            ->filter(fn ($date) => in_array($date->dayOfWeekIso, $days, true))
            ->reject(fn ($date) => in_array($date->toDateString(), $holidays, true))
            ->map(fn ($date) => $date->toDateString())
            ->values()
            ->all();
    }

    /**
     * Personal de esta programacion con vacaciones dentro de su vigencia.
     *
     * Las validaciones de W18 se aplican al guardar, pero unas vacaciones se
     * pueden registrar DESPUES de programar: sin esto, la programacion seguiria
     * en pie como si nada. Es el aviso que W17 muestra bajo la tabla
     * ("PRG-0144 esta bloqueada: ... tiene vacaciones desde el 26/08") y el
     * badge "Vacaciones" del calendario.
     *
     * @return list<array{user_id: int, full_name: string, role: string, start_date: string}>
     */
    public function vacationConflicts(?string $from = null): array
    {
        $from = max($from ?? $this->start_date->toDateString(), $this->start_date->toDateString());
        $to = $this->end_date->toDateString();

        if ($from > $to) {
            return [];
        }

        $conflicts = [];

        foreach (['driver' => 'Conductor', 'helper' => 'Ayudante'] as $relation => $role) {
            $person = $this->{$relation};

            if (! $person) {
                continue;
            }

            $vacation = $person->vacations()
                ->where('status', '!=', 'canceladas')
                ->where('start_date', '<=', $to)
                ->where('end_date', '>=', $from)
                ->orderBy('start_date')
                ->first();

            if (! $vacation) {
                continue;
            }

            $conflicts[] = [
                'user_id' => $person->id,
                'full_name' => $person->full_name,
                'role' => $role,
                'start_date' => $vacation->start_date->toDateString(),
            ];
        }

        return $conflicts;
    }

    /** "Lun, Mié, Vie" para las listas; W17 no tiene sitio para más. */
    public function weekdaysLabel(): string
    {
        $days = array_map('intval', $this->weekdays ?? []);
        sort($days);

        return implode(', ', array_map(
            fn (int $day) => self::WEEKDAY_LABELS[$day] ?? (string) $day,
            $days,
        ));
    }

    /**
     * Crea los recorridos de cada dia a partir de la programacion maestra, y
     * retira los que dejaron de corresponder.
     *
     * Tres reglas, y las tres importan:
     *
     * - **No se programa hacia atras.** Solo se generan recorridos de hoy en
     *   adelante; un rango que empieza el mes pasado no inventa recorridos que
     *   nunca ocurrieron.
     * - **Lo que ya arranco no se toca.** Un recorrido iniciado, bloqueado o
     *   finalizado es historia: editar la programacion no lo borra ni lo mueve.
     * - **El pasado se conserva.** Al reeditar solo se retiran los recorridos
     *   futuros que siguen en "programado" y que ya no caen en los dias nuevos.
     *
     * @return array{creados: int, retirados: int, intactos: int}
     */
    public function syncVehicleRoutes(): array
    {
        $today = now()->toDateString();
        $dates = array_values(array_filter(
            $this->occurrenceDates(),
            fn (string $date) => $date >= $today,
        ));

        $retirados = $this->vehicleRoutes()
            ->where('status', 'programado')
            ->where('date_route', '>=', $today)
            ->when($dates !== [], fn ($q) => $q->whereNotIn('date_route', $dates))
            ->delete();

        $existing = $this->vehicleRoutes()
            ->whereIn('date_route', $dates ?: ['1970-01-01'])
            ->get()
            ->keyBy(fn (VehicleRoute $route) => $route->date_route->toDateString());

        $creados = 0;

        foreach ($dates as $date) {
            if ($existing->has($date)) {
                // Un recorrido ya iniciado conserva su vehiculo y su turno:
                // cambiarlos despues de arrancar falsearia lo que paso.
                $route = $existing->get($date);

                if ($route->status === 'programado') {
                    $route->update([
                        'vehicle_id' => $this->vehicle_id,
                        'route_id' => $this->route_id,
                        'schedule_id' => $this->schedule_id,
                    ]);

                    $route->syncOccupants($this->driver_id, $this->helper_id);
                }

                continue;
            }

            $route = $this->vehicleRoutes()->create([
                'date_route' => $date,
                'vehicle_id' => $this->vehicle_id,
                'route_id' => $this->route_id,
                'schedule_id' => $this->schedule_id,
                'status' => 'programado',
            ]);

            $route->syncOccupants($this->driver_id, $this->helper_id);
            $creados++;
        }

        return [
            'creados' => $creados,
            'retirados' => (int) $retirados,
            'intactos' => count($dates) - $creados,
        ];
    }
}
