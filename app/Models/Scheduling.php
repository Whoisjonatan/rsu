<?php

namespace App\Models;

use App\Services\Scheduling\SchedulingRules;
use App\Support\Sequence;
use App\Support\Status;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Scheduling extends Model
{
    /** Relaciones que pintan las listas y la ficha (W17, W18). */
    public const RELATIONS = ['zone', 'route', 'vehicle', 'schedule', 'driver', 'helpers'];

    /** Estados de una programación que sigue en pie (ver scopeInForce). */
    public const IN_FORCE = ['activa', 'reprogramada'];

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
                ->whereDate('end_date', '>=', $filters['date']))
            // Rango de fechas: las que se cruzan con él en al menos un día.
            // Cualquiera de los dos extremos puede venir vacío y queda abierto.
            ->when(filled($filters['date_from'] ?? null), fn ($q) => $q->whereDate('end_date', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($q) => $q->whereDate('start_date', '<=', $filters['date_to']));
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
                ?? ($scheduling->isFinished() ? 'finalizada' : null)
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
     * "Activas" cuenta también las reprogramadas: siguen en pie.
     *
     * @return array{total: int, activas: int, vigentes: int, inactivas: int, finalizadas: int, reprogramadas: int}
     */
    public static function summary(): array
    {
        $today = now()->toDateString();

        return [
            'total' => static::count(),
            'activas' => static::inForce()->count(),
            'vigentes' => static::inForce()
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->count(),
            'inactivas' => static::where('status', 'inactiva')->count(),
            'finalizadas' => static::where('status', 'finalizada')->count(),
            'reprogramadas' => static::where('status', 'reprogramada')->count(),
        ];
    }

    /**
     * Las que siguen en pie: activas y reprogramadas. Son las que generan
     * recorridos y ocupan vehículo y personal.
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereIn('status', self::IN_FORCE);
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

            if (array_key_exists('helper_ids', $data)) {
                $this->syncHelpers(SchedulingRules::normalizeHelpers($data['helper_ids']));
            }

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
        'zone_id', 'staffgroup_id', 'route_id', 'vehicle_id', 'schedule_id',
        'driver_id', 'weekdays', 'worked_holidays', 'start_date', 'end_date', 'status', 'notes',
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

    /**
     * Genera el correlativo PRG-NNNN que muestra W17. Sale de un contador y no
     * del mayor código existente: si no, eliminar la última programación
     * dejaba su número libre y la siguiente lo repetía.
     */
    public static function nextCode(): string
    {
        // Se compara el número y no el texto: "PRG-10000" < "PRG-9999" como texto.
        $highest = fn () => static::query()
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 4))
            ->max() ?? 0;

        return sprintf('PRG-%04d', Sequence::next('schedulings', $highest));
    }

    /**
     * Pasa a "finalizada" las programaciones en pie cuya fecha de término ya
     * pasó. Se llama al mostrar el listado y una vez al día desde el
     * scheduler, así el estado queda bien aunque el scheduler no esté corriendo.
     */
    public static function closeFinished(): int
    {
        return static::query()
            ->inForce()
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => 'finalizada']);
    }

    public function isFinished(): bool
    {
        return $this->status === 'finalizada';
    }

    /** Por qué no se puede finalizar a mano, o null si se puede. */
    public function finishBlocker(): ?string
    {
        if ($this->isFinished()) {
            return "La programación {$this->code} ya está finalizada.";
        }

        if ($this->start_date->toDateString() > now()->toDateString()) {
            return "La programación {$this->code} todavía no empieza: elimínala o déjala inactiva.";
        }

        return null;
    }

    /**
     * Finaliza hoy. Si terminaba más adelante, la fecha de término pasa a hoy
     * y se retiran los recorridos de mañana en adelante que no arrancaron; el
     * de hoy se queda como esté. Al no estar activa, el vehículo y el personal
     * quedan libres para otras programaciones.
     */
    public function finish(): void
    {
        $today = now()->toDateString();

        DB::transaction(function () use ($today) {
            if ($this->end_date->toDateString() > $today) {
                $this->vehicleRoutes()
                    ->where('status', 'programado')
                    ->whereDate('date_route', '>', $today)
                    ->delete();

                $this->end_date = $today;
            }

            $this->status = 'finalizada';
            $this->save();
        });
    }

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'worked_holidays' => 'array',
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

    /** El grupo de personal del que salió, si salió de uno. */
    public function staffGroup(): BelongsTo
    {
        return $this->belongsTo(StaffGroup::class, 'staffgroup_id');
    }

    /** Ayudantes, en el orden en que se eligieron. Caben tantos como admita el vehículo. */
    public function helpers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'schedulinghelpers')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /** @return list<int> */
    public function helperIds(): array
    {
        return $this->helpers->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param  list<int>  $ids */
    public function syncHelpers(array $ids): void
    {
        $this->helpers()->sync(collect($ids)->values()->mapWithKeys(
            fn (int $id, int $index) => [$id => ['position' => $index + 1]],
        )->all());

        $this->unsetRelation('helpers');
    }

    /**
     * Lo que muestra el modal "Ver detalles" del listado: datos generales,
     * personal asignado e historial de cambios. Espera cargadas RELATIONS y
     * history.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [
            'code' => $this->code,
            'status' => [Status::label('scheduling', $this->status), Status::tone('scheduling', $this->status)],
            'zone' => $this->zone?->name,
            'schedule' => $this->schedule?->label,
            'vehicle' => $this->vehicle?->plate,
            'dates' => $this->start_date->format('d/m/Y').' al '.$this->end_date->format('d/m/Y'),
            'weekdays' => $this->weekdaysLabel(),
            'crew' => [
                ['role' => 'Conductor', 'name' => $this->driver?->full_name ?? '—'],
                ...$this->helpers->values()->map(fn (User $helper, int $index) => [
                    'role' => $this->helpers->count() > 1 ? 'Ayudante '.($index + 1) : 'Ayudante',
                    'name' => $helper->full_name,
                ]),
            ],
            // Del más reciente al más antiguo.
            'changes' => $this->history->sortByDesc('id')->values()->map(fn (SchedulingChange $change) => [
                'date' => $change->created_at->format('d/m/Y H:i'),
                'type' => $change->typeLabel(),
                'old' => $change->old_value ?? '—',
                'new' => $change->new_value,
                'reason' => $change->reason,
                'description' => $change->description,
            ])->all(),
        ];
    }

    /** "Pérez, Ana; Ruiz, Luis" para las listas. */
    public function helpersLabel(): string
    {
        return $this->helpers->pluck('full_name')->implode('; ');
    }

    public function vehicleRoutes(): HasMany
    {
        return $this->hasMany(VehicleRoute::class);
    }

    /** Cambios de turno, vehículo o personal, del más antiguo al más reciente. */
    public function history(): HasMany
    {
        return $this->hasMany(SchedulingChange::class)->orderBy('id');
    }

    /**
     * Fechas que cubre la programacion, solo en los dias de semana marcados y
     * sin los feriados activos: un feriado no lleva recorrido, y por eso
     * tampoco cuenta en las validaciones de contrato y vacaciones. La
     * excepción son los feriados que la programación trabaja (worked_holidays).
     *
     * @return list<string>
     */
    public function occurrenceDates(): array
    {
        $days = array_map('intval', $this->weekdays ?? []);
        $holidays = array_diff(
            Holiday::activeDatesBetween($this->start_date, $this->end_date),
            $this->worked_holidays ?? [],
        );

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
        $crew = collect([['Conductor', $this->driver]])
            ->concat($this->helpers->map(fn (User $helper) => ['Ayudante', $helper]));

        foreach ($crew as [$role, $person]) {
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
        $helperIds = $this->helperIds();

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

                    $route->syncOccupants($this->driver_id, $helperIds);
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

            $route->syncOccupants($this->driver_id, $helperIds);
            $creados++;
        }

        return [
            'creados' => $creados,
            'retirados' => (int) $retirados,
            'intactos' => count($dates) - $creados,
        ];
    }
}
