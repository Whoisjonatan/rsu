<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scheduling extends Model
{
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
     * Fechas que cubre la programacion, solo en los dias de semana marcados.
     *
     * @return list<string>
     */
    public function occurrenceDates(): array
    {
        $days = array_map('intval', $this->weekdays ?? []);

        return collect(CarbonPeriod::create($this->start_date, $this->end_date))
            ->filter(fn ($date) => in_array($date->dayOfWeekIso, $days, true))
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
