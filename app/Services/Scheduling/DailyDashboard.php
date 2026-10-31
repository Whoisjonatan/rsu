<?php

namespace App\Services\Scheduling;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Scheduling;
use App\Models\StaffGroup;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use App\Models\VehicleOccupant;
use App\Models\VehicleRoute;
use App\Support\Status;
use Illuminate\Support\Collection;

/**
 * Dashboard: el monitoreo diario de las programaciones de un día.
 *
 * Cada programación de ese día (una por grupo y turno, ver GeneralScheduling)
 * sale como una card con su tripulación y la asistencia de cada uno:
 *
 * - **Completa**: todo su personal marcó asistencia (presente o tardanza).
 * - **Incompleta**: falta al menos uno, porque marcó falta o no ha marcado.
 * - **Pendiente**: es un día futuro; nadie puede haber marcado todavía y no
 *   cuenta ni como completa ni como incompleta.
 *
 * La tripulación sale del recorrido del día cuando existe, porque ahí quedan
 * los reemplazos por personal de reserva hechos al iniciarlo (W21); si no,
 * de la programación.
 *
 * Desde las cards se modifica la programación del día (turno, vehículo y
 * personal) y se reemplaza a quien falta, las dos cosas con Rescheduling.
 * Para ofrecer solo lo que está libre, options() trae el personal y los
 * vehículos disponibles ese día y qué programación ocupa a cada uno en cada
 * turno; el servidor lo vuelve a validar todo al guardar.
 */
class DailyDashboard
{
    private const ROUTE_STATUS = [
        'programado' => 'Programado',
        'iniciado' => 'Iniciado',
        'bloqueado' => 'Bloqueado',
        'finalizado' => 'Finalizado',
    ];

    /** @var list<array<string, mixed>> */
    private array $cards = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /**
     * @param  array{zone_id?: mixed, schedule_id?: mixed}  $filters
     */
    public function __construct(private readonly string $date, array $filters = [])
    {
        $holidays = Holiday::activeDatesBetween($date, $date);

        // Todas las del día, sin filtros: las de otras zonas y turnos también
        // ocupan personal y vehículos.
        $day = Scheduling::query()
            ->with(['general', 'zone', 'schedule', 'vehicle', 'staffGroup', 'driver.usertype', 'helpers.usertype'])
            // Las desactivadas no salen a la calle; las finalizadas sí cuentan: ya se hicieron.
            ->where('status', '!=', 'inactiva')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get()
            // Las anteriores al desglose por día pueden cubrir un rango: solo si ese día les toca.
            ->filter(fn (Scheduling $scheduling) => in_array($date, $scheduling->occurrenceDates($holidays), true))
            ->values();

        $schedulings = $day
            ->when(filled($filters['zone_id'] ?? null), fn ($c) => $c->where('zone_id', (int) $filters['zone_id']))
            ->when(filled($filters['schedule_id'] ?? null), fn ($c) => $c->where('schedule_id', (int) $filters['schedule_id']))
            ->sortBy(fn (Scheduling $scheduling) => [$scheduling->schedule?->time_start, $scheduling->zone?->name])
            ->values();

        $routes = VehicleRoute::query()
            ->with(['occupants.user', 'occupants.usertype'])
            ->whereIn('scheduling_id', $day->pluck('id'))
            ->whereDate('date_route', $date)
            ->get()
            ->keyBy('scheduling_id');

        $crews = $schedulings->mapWithKeys(fn (Scheduling $scheduling) => [
            $scheduling->id => $this->crew($scheduling, $routes->get($scheduling->id)),
        ]);

        $candidates = $this->candidates();

        // Una sola consulta de asistencias para todo el día.
        $attendances = Attendance::query()
            ->whereDate('date', $date)
            ->whereIn('user_id', $crews->flatten(1)->pluck('user_id')->concat($candidates->pluck('id'))->unique()->values())
            ->get()
            ->groupBy('user_id');

        $today = now()->toDateString();
        $pending = $date > $today;

        foreach ($schedulings as $scheduling) {
            $route = $routes->get($scheduling->id);
            $scheduled = [$scheduling->driver_id, ...$scheduling->helperIds()];

            // Si marcó en más de un turno, vale la marca de este turno.
            $attendance = function (int $userId) use ($attendances, $scheduling): array {
                $marks = $attendances->get($userId, collect());
                $mark = $marks->firstWhere('schedule_id', $scheduling->schedule_id) ?? $marks->first();

                return [
                    'present' => $mark?->isPresent() ?? false,
                    'mark' => self::markLabel($mark),
                    'check_in' => $mark?->check_in ? substr($mark->check_in, 0, 5) : null,
                ];
            };

            $crew = collect($crews->get($scheduling->id))->map(fn (array $member) => [
                ...$member,
                ...$attendance($member['user_id']),
                // Un reemplazo de W21 no está en la programación: no se cambia desde aquí.
                'replaceable' => in_array($member['user_id'], $scheduled, true),
            ]);

            $present = $crew->where('present', true)->count();
            $missing = $crew->count() - $present;
            $status = match (true) {
                $pending => 'pendiente',
                $crew->isNotEmpty() && $missing === 0 => 'completa',
                default => 'incompleta',
            };

            // Cambiar un día que ya pasó, o un recorrido que ya salió, falsearía lo que pasó.
            $lock = match (true) {
                $scheduling->isFinished() => 'La programación ya finalizó.',
                $date < $today => 'El día ya pasó.',
                $route && ! $route->canStart() => 'El recorrido ya está '.mb_strtolower(self::ROUTE_STATUS[$route->status] ?? $route->status).'.',
                default => null,
            };

            $this->cards[] = [
                'id' => $scheduling->id,
                'code' => $scheduling->code,
                'general' => $scheduling->general?->code,
                'zone' => $scheduling->zone?->name ?? '—',
                'schedule' => $scheduling->schedule?->name ?? '—',
                'schedule_label' => $scheduling->schedule?->label,
                'group' => $scheduling->staffGroup?->name ?? 'Sin grupo',
                'vehicle' => $scheduling->vehicle?->code ?? '—',
                'vehicle_name' => $scheduling->vehicle?->name,
                'plate' => $scheduling->vehicle?->plate,
                'scheduling_status' => [Status::label('scheduling', $scheduling->status), Status::tone('scheduling', $scheduling->status)],
                'route' => $route ? (self::ROUTE_STATUS[$route->status] ?? ucfirst($route->status)) : 'Sin recorrido',
                'block_reason' => $route?->block_reason,
                'status' => $status,
                'present' => $present,
                'missing' => $missing,
                'crew' => $crew->values()->all(),
                // Lo que no cuenta como ocupación para esta card (ver occupation()).
                'owners' => array_values(array_filter(['s'.$scheduling->id, $scheduling->staffgroup_id ? 'g'.$scheduling->staffgroup_id : null])),
                'editable' => $lock === null,
                'lock_reason' => $lock,
                // Lo que tiene la programación, que es sobre lo que trabaja Rescheduling.
                'editor' => [
                    'schedule_id' => $scheduling->schedule_id,
                    'schedule' => $scheduling->schedule?->label ?? '—',
                    'vehicle_id' => $scheduling->vehicle_id,
                    'vehicle' => self::vehicleLabel($scheduling->vehicle),
                    'crew' => collect([$scheduling->driver])
                        ->concat($scheduling->helpers)
                        ->filter()
                        // Quien ya marcó asistencia no se cambia: solo se reemplaza al que falta.
                        ->map(fn (User $person) => [
                            'id' => $person->id,
                            'full_name' => $person->full_name,
                            'role' => $person->id === $scheduling->driver_id ? 'conductor' : 'ayudante',
                            ...$attendance($person->id),
                        ])
                        ->values()
                        ->all(),
                ],
            ];
        }

        $this->options = [
            'staff' => $candidates->map(function (User $person) use ($attendances) {
                $mark = $attendances->get($person->id)?->first(fn (Attendance $attendance) => $attendance->isPresent());

                return [
                    'id' => $person->id,
                    'full_name' => $person->full_name,
                    'role' => $person->usertype?->slug === UserType::DRIVER ? 'conductor' : 'ayudante',
                    'mark' => $mark ? trim(self::markLabel($mark)[0].' '.substr((string) $mark->check_in, 0, 5)) : null,
                ];
            })->values()->all(),
            'vehicles' => Vehicle::operational()->orderBy('code')->get()
                ->map(fn (Vehicle $vehicle) => ['id' => $vehicle->id, 'label' => self::vehicleLabel($vehicle)])
                ->all(),
            ...$this->occupation($day, $routes),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function cards(): array
    {
        return $this->cards;
    }

    /**
     * Lo que ofrecen los combos de los modales: el personal y los vehículos
     * disponibles ese día, y en `busy_staff` / `busy_vehicles` qué
     * programación ocupa a cada uno en cada turno (turno => id => programación).
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Los cuatro indicadores de arriba. El personal faltante se cuenta en los
     * días que ya llegaron: en uno futuro todavía nadie pudo marcar.
     *
     * @return array{total: int, completas: int, incompletas: int, faltantes: int}
     */
    public function totals(): array
    {
        $cards = collect($this->cards);

        return [
            'total' => $cards->count(),
            'completas' => $cards->where('status', 'completa')->count(),
            'incompletas' => $cards->where('status', 'incompleta')->count(),
            'faltantes' => $cards->where('status', '!=', 'pendiente')->sum('missing'),
        ];
    }

    /**
     * Quién va ese día: los ocupantes del recorrido sin el registro del
     * reemplazado (el entrante va aparte), o la tripulación programada.
     *
     * @return list<array{user_id: int, full_name: ?string, dni: ?string, role: string, replaced: bool}>
     */
    private function crew(Scheduling $scheduling, ?VehicleRoute $route): array
    {
        if ($route && $route->occupants->isNotEmpty()) {
            return $route->occupants
                ->where('status', '!=', 'reemplazado')
                ->sortBy(fn (VehicleOccupant $occupant) => $occupant->user_id === $scheduling->driver_id ? 0 : 1)
                ->map(fn (VehicleOccupant $occupant) => [
                    'user_id' => $occupant->user_id,
                    'full_name' => $occupant->user?->full_name,
                    'dni' => $occupant->user?->dni,
                    'role' => $occupant->usertype?->name ?? '—',
                    'replaced' => $occupant->replaced_user_id !== null,
                ])
                ->values()
                ->all();
        }

        return collect([$scheduling->driver])
            ->concat($scheduling->helpers)
            ->filter()
            ->map(fn (User $person) => [
                'user_id' => $person->id,
                'full_name' => $person->full_name,
                'dni' => $person->dni,
                'role' => $person->id === $scheduling->driver_id ? 'Conductor' : 'Ayudante',
                'replaced' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * Conductores y ayudantes que pueden salir ese día: activos, con un
     * contrato que lo cubre, sin vacaciones, y los conductores con licencia.
     *
     * @return Collection<int, User>
     */
    private function candidates(): Collection
    {
        return StaffAvailability::candidates()
            ->filter(fn (User $person) => $person->status === 'activo'
                && $person->datesWithoutContract([$this->date]) === []
                && $person->datesOnVacation([$this->date]) === []
                && ($person->usertype?->slug !== UserType::DRIVER || $person->hasValidLicense($this->date)))
            ->values()
            ->toBase();
    }

    /**
     * Quién y qué está tomado en cada turno ese día, y por quién: la
     * tripulación de cada programación ("s{id}"), quien entró como reemplazo
     * en su recorrido, y los miembros de los grupos activos que salen ese día
     * de la semana ("g{id}"), que SchedulingRules reserva para su grupo
     * aunque el grupo no esté programado.
     *
     * @param  Collection<int, Scheduling>  $day
     * @param  Collection<int, VehicleRoute>  $routes
     * @return array{busy_staff: array<int, array<int, list<string>>>, busy_vehicles: array<int, array<int, list<string>>>}
     */
    private function occupation(Collection $day, Collection $routes): array
    {
        $staff = [];
        $vehicles = [];

        foreach ($day as $scheduling) {
            $owner = 's'.$scheduling->id;
            $vehicles[$scheduling->schedule_id][$scheduling->vehicle_id][] = $owner;

            foreach ([$scheduling->driver_id, ...$scheduling->helperIds()] as $userId) {
                $staff[$scheduling->schedule_id][$userId][] = $owner;
            }

            foreach ($routes->get($scheduling->id)?->occupants ?? [] as $occupant) {
                if ($occupant->status === 'asignado') {
                    $staff[$scheduling->schedule_id][$occupant->user_id][] = $owner;
                }
            }
        }

        $weekday = (int) date('N', strtotime($this->date));
        $groups = StaffGroup::active()->with('helpers:id')->get()
            ->filter(fn (StaffGroup $group) => in_array($weekday, array_map('intval', $group->weekdays ?? []), true));

        foreach ($groups as $group) {
            foreach ([(int) $group->driver_id, ...$group->helperIds()] as $userId) {
                $staff[$group->schedule_id][$userId][] = 'g'.$group->id;
            }
        }

        return ['busy_staff' => $staff, 'busy_vehicles' => $vehicles];
    }

    /** @return array{0: string, 1: string} Etiqueta y tono de la marcación. */
    private static function markLabel(?Attendance $mark): array
    {
        return match ($mark?->status) {
            'presente' => ['Presente', 'green'],
            'tarde' => ['Tardanza', 'amber'],
            'falta' => ['Falta', 'red'],
            default => ['Sin marcar', 'slate'],
        };
    }

    /** "VEH-001 · Toyota Dyna 300 · B2K-841": código, nombre y placa. */
    private static function vehicleLabel(?Vehicle $vehicle): string
    {
        return $vehicle
            ? implode(' · ', array_filter([$vehicle->code, $vehicle->name, $vehicle->plate], 'filled'))
            : '—';
    }
}
