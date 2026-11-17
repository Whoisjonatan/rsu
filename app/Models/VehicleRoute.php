<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleRoute extends Model
{
    protected $table = 'vehicleroutes';

    protected $fillable = [
        'scheduling_id', 'date_route', 'time_route', 'vehicle_id',
        'route_id', 'schedule_id', 'description', 'status', 'block_reason',
        'started_at', 'finished_at', 'observation',
    ];

    /**
     * Distancia mínima (metros) entre dos puntos guardados del recorrido. Con
     * el camión detenido (un semáforo, una descarga) el GPS sigue reportando
     * casi el mismo lugar con unos metros de ruido: esos puntos no se guardan.
     */
    public const MIN_PATH_DISTANCE = 10;

    protected function casts(): array
    {
        return [
            'date_route' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** Arranca el recorrido y deja su programación "iniciada". */
    public function markStarted(): void
    {
        DB::transaction(function () {
            $this->update([
                'status' => 'iniciado',
                'time_route' => now()->format('H:i:s'),
                'started_at' => now(),
            ]);
            $this->scheduling?->markStarted();
        });
    }

    /** Cierra el recorrido y su programación sale de "iniciada". */
    public function markFinished(?string $observation = null): void
    {
        DB::transaction(function () use ($observation) {
            $this->update(array_filter([
                'status' => 'finalizado',
                'finished_at' => now(),
                'observation' => $observation,
            ], fn ($value) => $value !== null));
            $this->scheduling?->markRouteFinished();
        });
    }

    /**
     * Guarda los puntos GPS del recorrido en orden, descartando los que caen a
     * menos de MIN_PATH_DISTANCE del último guardado (ubicación repetida).
     * Se bloquea la fila del recorrido para que dos envíos simultáneos no
     * comparen contra el mismo "último punto" y dupliquen ubicaciones.
     *
     * @param  list<array{latitude: float|string, longitude: float|string, recorded_at?: string|null}>  $points
     * @return array{saved: int, skipped: int}
     */
    public function recordPath(array $points): array
    {
        return DB::transaction(function () use ($points) {
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            $last = $this->paths()->latest('id')->first();
            $saved = 0;
            $skipped = 0;
            $floor = $this->started_at ?? now()->startOfDay();

            foreach ($points as $point) {
                $lat = (float) $point['latitude'];
                $lng = (float) $point['longitude'];

                if ($last && self::distance($last->latitude, $last->longitude, $lat, $lng) < self::MIN_PATH_DISTANCE) {
                    $skipped++;

                    continue;
                }

                // La hora la pone el teléfono (puntos encolados sin señal), pero
                // nunca antes del inicio ni en el futuro.
                $at = now();
                if (! empty($point['recorded_at'])) {
                    $at = Carbon::parse($point['recorded_at']);
                    $at = $at->lt($floor) ? $floor->copy() : ($at->gt(now()) ? now() : $at);
                }

                $path = new RoutePath(['latitude' => $lat, 'longitude' => $lng]);
                $path->vehicleroute_id = $this->id;
                $path->created_at = $at;
                $path->updated_at = $at;
                $path->save();

                $last = $path;
                $saved++;
            }

            return ['saved' => $saved, 'skipped' => $skipped];
        });
    }

    /** Distancia en metros entre dos coordenadas (fórmula de Haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function scheduling(): BelongsTo
    {
        return $this->belongsTo(Scheduling::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function occupants(): HasMany
    {
        return $this->hasMany(VehicleOccupant::class, 'vehicleroute_id');
    }

    public function paths(): HasMany
    {
        return $this->hasMany(RoutePath::class, 'vehicleroute_id');
    }

    /** Calificaciones de los ciudadanos de la zona. */
    public function ratings(): HasMany
    {
        return $this->hasMany(RouteRating::class, 'vehicleroute_id');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date_route', $date);
    }

    public function canStart(): bool
    {
        return $this->status === 'programado';
    }

    /**
     * Las tres comprobaciones que el informe pide antes de iniciar (W21):
     * asistencia del personal, disponibilidad del vehiculo y que el recorrido
     * siga pendiente.
     *
     * Devuelve el detalle, no un booleano, porque W21 no solo decide si el
     * boton se habilita: tambien tiene que explicar QUE falta y ofrecer el
     * reemplazo por personal de reserva de quien no marco.
     *
     * @return array{ready: bool, blockers: list<string>, occupants: list<array<string, mixed>>}
     */
    public function readiness(): array
    {
        $date = $this->date_route->toDateString();
        $blockers = [];

        if (! $this->canStart()) {
            $blockers[] = match ($this->status) {
                'iniciado' => 'El recorrido ya está iniciado.',
                'finalizado' => 'El recorrido ya finalizó.',
                'bloqueado' => 'El recorrido está bloqueado'.($this->block_reason ? ': '.$this->block_reason : '.'),
                default => 'El recorrido no está en estado programado.',
            };
        }

        $vehicle = $this->vehicle;

        if ($vehicle && $vehicle->status !== 'operativo') {
            $blockers[] = "El vehículo {$vehicle->code} está en {$vehicle->status}.";
        }

        // Un vehiculo no puede salir dos veces en el mismo turno del mismo dia.
        $busy = static::query()
            ->whereKeyNot($this->getKey())
            ->where('vehicle_id', $this->vehicle_id)
            ->whereDate('date_route', $date)
            ->where('schedule_id', $this->schedule_id)
            ->whereIn('status', ['iniciado', 'finalizado'])
            ->exists();

        if ($busy) {
            $blockers[] = "El vehículo {$vehicle?->code} ya salió en otro recorrido de este turno.";
        }

        $occupants = [];

        foreach ($this->occupants()->with(['user', 'usertype'])->get() as $occupant) {
            if ($occupant->status === 'reemplazado') {
                continue; // Es el registro del saliente; el entrante va aparte.
            }

            $attended = $occupant->user?->attendedOn($date, $this->schedule_id) ?? false;

            $occupants[] = [
                'occupant_id' => $occupant->id,
                'user_id' => $occupant->user_id,
                'full_name' => $occupant->user?->full_name,
                'usertype' => $occupant->usertype?->name,
                'status' => $occupant->status,
                'attended' => $attended,
                'replaced_user_id' => $occupant->replaced_user_id,
            ];

            if (! $attended) {
                $blockers[] = sprintf(
                    '%s (%s) no marcó entrada en este turno. Reemplázalo por personal de reserva.',
                    $occupant->user?->full_name ?? 'El personal asignado',
                    $occupant->usertype?->name ?? 'sin función',
                );
            }
        }

        if ($occupants === []) {
            $blockers[] = 'El recorrido no tiene personal asignado.';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'occupants' => $occupants,
        ];
    }

    /**
     * Ocupantes que estan de vacaciones ese dia.
     *
     * Es el badge "Vacaciones" de las tarjetas del calendario (W20). Trabaja
     * sobre las relaciones ya cargadas para que pintar una semana entera no
     * dispare una consulta por recorrido.
     *
     * @return list<array{user_id: int, full_name: string}>
     */
    public function staffOnVacation(): array
    {
        if (! $this->relationLoaded('occupants')) {
            return [];
        }

        $date = $this->date_route->toDateString();

        return $this->occupants
            ->where('status', '!=', 'reemplazado')
            ->filter(fn (VehicleOccupant $occupant) => $occupant->user?->vacations
                ->contains(fn (Vacation $vacation) => $vacation->coversDate($date)) ?? false)
            ->map(fn (VehicleOccupant $occupant) => [
                'user_id' => $occupant->user_id,
                'full_name' => $occupant->user?->full_name,
            ])
            ->values()
            ->all();
    }

    /**
     * Personal que puede sustituir a quien no marco: de la misma funcion,
     * activo, con contrato vigente, sin vacaciones, **que si marco asistencia**
     * y que no este ya ocupado en otro recorrido del mismo dia y turno.
     *
     * @return Collection<int, User>
     */
    public function availableReserves(int $usertypeId)
    {
        $date = $this->date_route->toDateString();

        $busyIds = VehicleOccupant::query()
            ->whereIn('status', ['asignado'])
            ->whereHas('vehicleRoute', fn (Builder $q) => $q
                ->whereDate('date_route', $date)
                ->where('schedule_id', $this->schedule_id))
            ->pluck('user_id');

        return User::query()
            ->where('usertype_id', $usertypeId)
            ->where('status', 'activo')
            ->whereKeyNot($busyIds->all())
            ->with(['contracts', 'vacations'])
            ->get()
            ->filter(fn (User $person) => $person->datesWithoutContract([$date]) === []
                && $person->datesOnVacation([$date]) === []
                && $person->attendedOn($date, $this->schedule_id))
            ->values();
    }

    /**
     * Deja como ocupantes al conductor y a los ayudantes de la programacion.
     *
     * Solo toca a los que siguen en "asignado": un reemplazo por personal de
     * reserva ya registrado en W21 (status "reemplazado" + replaced_user_id)
     * no se deshace porque despues se reedite la programacion maestra.
     *
     * @param  list<int>  $helperIds
     */
    public function syncOccupants(int $driverId, array $helperIds): void
    {
        $wanted = array_values(array_unique(array_filter([$driverId, ...$helperIds])));

        $this->occupants()
            ->where('status', 'asignado')
            ->whereNotIn('user_id', $wanted)
            ->delete();

        foreach (User::findMany($wanted) as $person) {
            $this->occupants()->firstOrCreate(
                ['user_id' => $person->id],
                ['usertype_id' => $person->usertype_id, 'status' => 'asignado'],
            );
        }
    }

    /**
     * Contadores del día: la cabecera de W20 y W21, y el panel (W02).
     *
     * @return array{date: string, programado: int, iniciado: int, bloqueado: int, finalizado: int, total: int, personal_asignado: int, personal_ausente: int}
     */
    public static function daySummary(string $date): array
    {
        $counts = static::query()
            ->whereDate('date_route', $date)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // "Personal ausente" del panel: gente con recorrido hoy que no marcó
        // entrada en el turno de ese recorrido (la marcación es por turno). Se
        // cuentan personas y no asignaciones, porque alguien que falta en dos
        // turnos del mismo día sigue siendo un solo ausente.
        $ausentes = VehicleOccupant::query()
            ->join('vehicleroutes', 'vehicleroutes.id', '=', 'vehicleoccupants.vehicleroute_id')
            ->where('vehicleoccupants.status', 'asignado')
            ->whereDate('vehicleroutes.date_route', $date)
            ->whereNotExists(fn ($q) => $q
                ->from('attendances')
                ->whereColumn('attendances.user_id', 'vehicleoccupants.user_id')
                ->whereColumn('attendances.schedule_id', 'vehicleroutes.schedule_id')
                ->whereDate('attendances.date', $date)
                ->whereIn('attendances.status', ['presente', 'tarde']))
            ->distinct()
            ->count('vehicleoccupants.user_id');

        $asignados = VehicleOccupant::query()
            ->where('status', 'asignado')
            ->whereHas('vehicleRoute', fn ($q) => $q->whereDate('date_route', $date))
            ->distinct()
            ->count('user_id');

        return [
            'date' => $date,
            'programado' => (int) ($counts['programado'] ?? 0),
            'iniciado' => (int) ($counts['iniciado'] ?? 0),
            'bloqueado' => (int) ($counts['bloqueado'] ?? 0),
            'finalizado' => (int) ($counts['finalizado'] ?? 0),
            'total' => (int) $counts->sum(),
            'personal_asignado' => $asignados,
            'personal_ausente' => $ausentes,
        ];
    }
}
