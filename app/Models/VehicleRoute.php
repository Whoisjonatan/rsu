<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleRoute extends Model
{
    protected $table = 'vehicleroutes';

    protected $fillable = [
        'scheduling_id', 'date_route', 'time_route', 'vehicle_id',
        'route_id', 'schedule_id', 'description', 'status', 'block_reason',
    ];

    protected function casts(): array
    {
        return ['date_route' => 'date:Y-m-d'];
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

            $attended = $occupant->user?->attendedOn($date) ?? false;

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
                    '%s (%s) no marcó asistencia. Reemplázalo por personal de reserva.',
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
     * @return \Illuminate\Support\Collection<int, User>
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
                && $person->attendedOn($date))
            ->values();
    }

    /**
     * Deja como ocupantes al conductor y al ayudante de la programacion.
     *
     * Solo toca a los que siguen en "asignado": un reemplazo por personal de
     * reserva ya registrado en W21 (status "reemplazado" + replaced_user_id)
     * no se deshace porque despues se reedite la programacion maestra.
     */
    public function syncOccupants(int $driverId, ?int $helperId): void
    {
        $wanted = array_values(array_filter([$driverId, $helperId]));

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

        // "Personal ausente" del panel: gente con recorrido hoy que no marcó.
        // Se cuentan personas y no asignaciones, porque alguien programado en
        // dos turnos del mismo día sigue siendo un solo ausente.
        $ausentes = VehicleOccupant::query()
            ->where('status', 'asignado')
            ->whereHas('vehicleRoute', fn ($q) => $q->whereDate('date_route', $date))
            ->whereDoesntHave('user.attendances', fn ($q) => $q
                ->whereDate('date', $date)
                ->whereIn('status', ['presente', 'tarde']))
            ->distinct()
            ->count('user_id');

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
