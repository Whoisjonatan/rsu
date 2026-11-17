<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\RoutePath;
use App\Models\Scheduling;
use App\Models\User;
use App\Models\UserType;
use App\Models\VehicleRoute;
use App\Support\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de la app móvil del conductor. Van fuera del middleware
 * "permission" del panel: el conductor no tiene permisos de panel, solo ve lo
 * suyo.
 */
class MobileController extends Controller
{
    /** Cuántas programaciones devuelve como máximo (hoy y las siguientes). */
    private const LIMIT = 30;

    /**
     * Programaciones del conductor autenticado desde hoy en adelante, de la
     * más próxima a la más lejana, con el estado del recorrido de su día.
     * No incluye las inactivas: esas no salen a la calle.
     */
    public function schedulings(Request $request): JsonResponse
    {
        $user = $this->driver($request);

        Scheduling::closeFinished();

        $today = now()->toDateString();

        $schedulings = Scheduling::query()
            ->with($this->relations($today))
            ->where('driver_id', $user->id)
            ->where('status', '!=', 'inactiva')
            ->whereDate('end_date', '>=', $today)
            ->orderBy('start_date')
            ->get()
            // Mismo día: por hora de inicio del turno.
            ->sortBy(fn (Scheduling $s) => $s->start_date->toDateString().' '.($s->schedule?->time_start ?? ''))
            ->take(self::LIMIT)
            ->values();

        return response()->json([
            'today' => $today,
            'data' => $schedulings->map(fn (Scheduling $s) => $this->present($s, $today))->all(),
        ]);
    }

    /**
     * Detalle de una programación del conductor con la asistencia de toda la
     * tripulación en el turno de ese día. El recorrido solo se puede iniciar
     * hoy, si sigue "programado" y si todos marcaron entrada (presente o
     * tarde), igual que en W21.
     */
    public function scheduling(Request $request, Scheduling $scheduling): JsonResponse
    {
        $user = $this->driver($request);

        // Solo las suyas; a las demás responde como si no existieran.
        abort_unless((int) $scheduling->driver_id === (int) $user->id && $scheduling->status !== 'inactiva', 404);

        $today = now()->toDateString();
        $scheduling->load($this->relations($today));

        $data = $this->present($scheduling, $today);
        $route = $scheduling->vehicleRoutes->first();
        $crew = $this->crew($scheduling, $route, $data['date'], $user);

        $allPresent = $crew !== [] && collect($crew)->every(fn (array $member) => $member['attended']);
        $blocker = match (true) {
            ! $data['is_today'] => 'Solo puedes iniciar el recorrido el día programado.',
            ! $route => 'Esta programación no tiene recorrido para hoy.',
            $route->status !== 'programado' => match ($route->status) {
                'iniciado' => 'El recorrido ya está iniciado.',
                'finalizado' => 'El recorrido ya finalizó.',
                'bloqueado' => 'El recorrido está bloqueado.',
                default => 'El recorrido no está en estado programado.',
            },
            ! $allPresent => 'Todo el personal debe marcar asistencia para iniciar el recorrido.',
            default => null,
        };

        return response()->json([
            'today' => $today,
            'data' => $data + [
                'crew' => $crew,
                'all_present' => $allPresent,
                'can_start' => $blocker === null,
                'start_blocker' => $blocker,
            ],
        ]);
    }

    // ------------------------------------------------------------ Recorrido en curso

    /**
     * Inicia el recorrido del día desde la app. Exige lo mismo que W21
     * (personal completo, vehículo operativo, recorrido programado) y además
     * que sea la fecha del recorrido.
     */
    public function startRoute(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $this->ownRoute($request, $vehicleroute);

        if ($vehicleroute->date_route->toDateString() !== now()->toDateString()) {
            return response()->json(['message' => 'Solo puedes iniciar el recorrido el día programado.'], 422);
        }

        $readiness = $vehicleroute->readiness();

        if (! $readiness['ready']) {
            return response()->json([
                'message' => $readiness['blockers'][0] ?? 'El recorrido todavía no puede iniciar.',
                'blockers' => $readiness['blockers'],
            ], 422);
        }

        $vehicleroute->markStarted();

        return response()->json(['data' => $this->tracking($vehicleroute)]);
    }

    /** Datos del recorrido y los puntos ya registrados (para dibujar el mapa al volver a la pantalla). */
    public function route(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $this->ownRoute($request, $vehicleroute);

        return response()->json(['data' => $this->tracking($vehicleroute)]);
    }

    /**
     * Puntos GPS que la app envía cada 5 segundos (o varios juntos si estuvo
     * sin señal). Los que repiten la última ubicación no se guardan.
     */
    public function storePaths(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $this->ownRoute($request, $vehicleroute);
        $points = $this->validatePoints($request, true);

        if ($vehicleroute->status !== 'iniciado') {
            return response()->json([
                'message' => $vehicleroute->status === 'finalizado'
                    ? 'El recorrido ya fue finalizado.'
                    : 'El recorrido no está en curso.',
                'route_status' => $vehicleroute->status,
            ], 409);
        }

        $result = $vehicleroute->recordPath($points);
        $last = $vehicleroute->paths()->latest('id')->first();

        return response()->json($result + [
            'total' => $vehicleroute->paths()->count(),
            'last' => $last ? ['latitude' => $last->latitude, 'longitude' => $last->longitude] : null,
        ]);
    }

    /**
     * Cierra el recorrido con la observación (opcional) del conductor. Recibe también los
     * puntos que el teléfono no alcanzó a enviar, para no perder el final.
     */
    public function finishRoute(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $this->ownRoute($request, $vehicleroute);

        $data = $request->validate([
            // Opcional: el conductor anota algo solo si hubo novedades.
            'observation' => ['nullable', 'string', 'max:500'],
        ], [
            'observation.max' => 'La observación no puede pasar de 500 caracteres.',
        ]);
        $points = $this->validatePoints($request, false);

        if ($vehicleroute->status !== 'iniciado') {
            return response()->json([
                'message' => 'Solo se puede finalizar un recorrido en curso.',
                'route_status' => $vehicleroute->status,
            ], 409);
        }

        if ($points !== []) {
            $vehicleroute->recordPath($points);
        }

        $observation = trim((string) ($data['observation'] ?? ''));
        $vehicleroute->markFinished($observation !== '' ? $observation : null);

        return response()->json(['data' => $this->tracking($vehicleroute)]);
    }

    /** El recorrido debe ser de una programación del conductor autenticado. */
    private function ownRoute(Request $request, VehicleRoute $route): void
    {
        $user = $this->driver($request);
        $route->loadMissing('scheduling');

        abort_unless(
            $route->scheduling
                && (int) $route->scheduling->driver_id === (int) $user->id
                && $route->scheduling->status !== 'inactiva',
            404,
        );
    }

    /** @return list<array{latitude: float, longitude: float, recorded_at: string|null}> */
    private function validatePoints(Request $request, bool $required): array
    {
        $data = $request->validate([
            'points' => [$required ? 'required' : 'nullable', 'array', 'max:200'],
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'points.*.recorded_at' => ['nullable', 'date'],
        ]);

        return array_values($data['points'] ?? []);
    }

    /** @return array<string, mixed> */
    private function tracking(VehicleRoute $route): array
    {
        $route->loadMissing(['scheduling.zone', 'scheduling.helpers', 'vehicle', 'schedule', 'route']);
        $s = $route->scheduling;
        $started = $route->started_at;
        $end = $route->finished_at ?? now();
        $paths = $route->paths()->orderBy('id')->get(['latitude', 'longitude']);

        return [
            'id' => $route->id,
            'scheduling_id' => $route->scheduling_id,
            'code' => $s?->code,
            'date' => $route->date_route->toDateString(),
            'status' => $route->status,
            'status_label' => Status::label('route', $route->status),
            'zone' => $s?->zone?->name,
            'route' => $route->route?->name,
            'vehicle' => $route->vehicle ? [
                'code' => $route->vehicle->code,
                'plate' => $route->vehicle->plate,
                'name' => $route->vehicle->name,
            ] : null,
            'helpers' => $s ? $s->helpers->pluck('full_name')->values()->all() : [],
            'schedule' => $route->schedule ? [
                'name' => $route->schedule->name,
                'time_start' => substr((string) $route->schedule->time_start, 0, 5),
                'time_end' => substr((string) $route->schedule->time_end, 0, 5),
            ] : null,
            'started_at' => $started?->format('Y-m-d H:i:s'),
            'finished_at' => $route->finished_at?->format('Y-m-d H:i:s'),
            // El cronómetro de la app parte de aquí: no depende del reloj del teléfono.
            'elapsed_seconds' => $started ? max(0, (int) $started->diffInSeconds($end, true)) : 0,
            'observation' => $route->observation,
            'interval_seconds' => 5,
            'min_distance' => VehicleRoute::MIN_PATH_DISTANCE,
            'distance_meters' => (int) round(self::pathLength($paths)),
            'paths' => $paths
                ->map(fn ($p) => ['latitude' => $p->latitude, 'longitude' => $p->longitude])
                ->all(),
        ];
    }

    /**
     * Historial de recorridos del conductor (iniciados y finalizados), del
     * más reciente al más antiguo, de 15 en 15.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $this->driver($request);

        $routes = VehicleRoute::query()
            ->with(['scheduling.zone', 'vehicle', 'schedule'])
            ->withCount('paths')
            ->whereHas('scheduling', fn ($q) => $q->where('driver_id', $user->id))
            ->whereIn('status', ['iniciado', 'finalizado'])
            ->orderByDesc('date_route')
            ->orderByDesc('started_at')
            ->paginate(15);

        // Distancia de toda la página con una sola consulta de puntos.
        $distances = RoutePath::query()
            ->whereIn('vehicleroute_id', $routes->pluck('id'))
            ->orderBy('id')
            ->get(['vehicleroute_id', 'latitude', 'longitude'])
            ->groupBy('vehicleroute_id')
            ->map(fn ($points) => (int) round(self::pathLength($points)));

        return response()->json([
            'data' => collect($routes->items())->map(function (VehicleRoute $r) use ($distances) {
                $s = $r->scheduling;
                $end = $r->finished_at ?? now();

                return [
                    'id' => $r->id,
                    'code' => $s?->code,
                    'date' => $r->date_route->toDateString(),
                    'status' => $r->status,
                    'status_label' => $r->status === 'iniciado' ? 'En curso' : Status::label('route', $r->status),
                    'zone' => $s?->zone?->name,
                    'vehicle' => $r->vehicle ? [
                        'code' => $r->vehicle->code,
                        'plate' => $r->vehicle->plate,
                        'name' => $r->vehicle->name,
                    ] : null,
                    'schedule' => $r->schedule ? [
                        'name' => $r->schedule->name,
                        'time_start' => substr((string) $r->schedule->time_start, 0, 5),
                        'time_end' => substr((string) $r->schedule->time_end, 0, 5),
                    ] : null,
                    'started_at' => $r->started_at?->format('Y-m-d H:i:s'),
                    'finished_at' => $r->finished_at?->format('Y-m-d H:i:s'),
                    'elapsed_seconds' => $r->started_at ? (int) $r->started_at->diffInSeconds($end, true) : 0,
                    'points_count' => (int) $r->paths_count,
                    'distance_meters' => $distances[$r->id] ?? 0,
                    'observation' => $r->observation,
                ];
            })->all(),
            'current_page' => $routes->currentPage(),
            'last_page' => $routes->lastPage(),
            'total' => $routes->total(),
        ]);
    }

    /** Metros recorridos sumando los tramos entre puntos consecutivos. */
    private static function pathLength($points): float
    {
        $meters = 0.0;
        $prev = null;

        foreach ($points as $p) {
            if ($prev) {
                $meters += VehicleRoute::distance($prev->latitude, $prev->longitude, $p->latitude, $p->longitude);
            }
            $prev = $p;
        }

        return $meters;
    }

    /** El usuario autenticado, si es conductor. */
    private function driver(Request $request): User
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('usertype');

        abort_unless(
            $user->usertype?->slug === UserType::DRIVER,
            403,
            'Solo el personal conductor puede usar la aplicación',
        );

        return $user;
    }

    /** @return array<int|string, mixed> */
    private function relations(string $today): array
    {
        return [
            'zone', 'route', 'vehicle', 'schedule', 'helpers',
            // El recorrido de su día (o el más próximo si cubre un rango).
            'vehicleRoutes' => fn ($q) => $q->whereDate('date_route', '>=', $today)->orderBy('date_route'),
        ];
    }

    /**
     * Conductor y ayudantes con su asistencia en el turno de esa fecha. Si el
     * recorrido ya existe se usan sus ocupantes, que incluyen los reemplazos
     * hechos en W21; si no, el personal de la programación.
     *
     * @return list<array<string, mixed>>
     */
    private function crew(Scheduling $s, ?VehicleRoute $route, string $date, User $me): array
    {
        if ($route) {
            $occupants = $route->occupants()
                ->with(['user', 'usertype'])
                ->where('status', '!=', 'reemplazado')
                ->get();
            $helperOrder = $s->helpers->pluck('id')->flip();
            $people = $occupants
                ->sortBy(fn ($o) => $o->usertype?->slug === UserType::DRIVER
                    ? -1
                    : ($helperOrder[$o->user_id] ?? PHP_INT_MAX))
                ->map(fn ($o) => [$o->user, $o->usertype?->slug === UserType::DRIVER])
                ->values();
        } else {
            $people = collect([[$s->driver, true]])
                ->concat($s->helpers->map(fn (User $helper) => [$helper, false]));
        }

        $people = $people->filter(fn (array $p) => $p[0] !== null)->values();

        $attendances = Attendance::query()
            ->whereIn('user_id', $people->map(fn (array $p) => $p[0]->id))
            ->whereDate('date', $date)
            ->where('schedule_id', $s->schedule_id)
            ->get()
            ->keyBy('user_id');

        $helperNumber = 0;
        $helperCount = $people->reject(fn (array $p) => $p[1])->count();

        return $people->map(function (array $p) use ($attendances, $me, &$helperNumber, $helperCount) {
            [$person, $isDriver] = $p;
            $attendance = $attendances->get($person->id);
            $status = $attendance?->status ?? 'sin_marcar';

            return [
                'user_id' => $person->id,
                'full_name' => $person->full_name,
                'role' => $isDriver
                    ? 'Conductor'
                    : ($helperCount > 1 ? 'Ayudante '.(++$helperNumber) : 'Ayudante'),
                'is_me' => $person->id === $me->id,
                'attendance_status' => $status,
                'attendance_label' => $attendance ? Status::label('attendance', $status) : 'Sin marcar',
                'attendance_tone' => $attendance ? Status::tone('attendance', $status) : 'slate',
                'check_in' => $attendance?->check_in ? substr((string) $attendance->check_in, 0, 5) : null,
                'attended' => in_array($status, ['presente', 'tarde'], true),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function present(Scheduling $s, string $today): array
    {
        /** @var VehicleRoute|null $route */
        $route = $s->vehicleRoutes->first();
        $routeStatus = $route?->status ?? 'sin_recorrido';

        return [
            'id' => $s->id,
            'code' => $s->code,
            'status' => $s->status,
            'status_label' => Status::label('scheduling', $s->status),
            'start_date' => $s->start_date->toDateString(),
            'end_date' => $s->end_date->toDateString(),
            // Si cubre un rango (programaciones antiguas), la fecha que se muestra es la del próximo recorrido.
            'date' => $route?->date_route?->toDateString() ?? max($s->start_date->toDateString(), $today),
            'is_today' => $s->start_date->toDateString() <= $today && $s->end_date->toDateString() >= $today,
            'date_label' => $s->dateLabel(),
            'weekdays_label' => $s->weekdaysLabel(),
            'notes' => $s->notes,
            'schedule' => $s->schedule ? [
                'name' => $s->schedule->name,
                'time_start' => substr((string) $s->schedule->time_start, 0, 5),
                'time_end' => substr((string) $s->schedule->time_end, 0, 5),
            ] : null,
            'zone' => $s->zone?->name,
            'route' => $s->route?->name,
            'vehicle' => $s->vehicle ? [
                'code' => $s->vehicle->code,
                'plate' => $s->vehicle->plate,
                'name' => $s->vehicle->name,
            ] : null,
            'helpers' => $s->helpers->pluck('full_name')->values()->all(),
            'vehicleroute_id' => $route?->id,
            'route_status' => $routeStatus,
            'route_status_label' => $route
                ? Status::label('route', $routeStatus)
                : 'Sin recorrido',
            'route_status_tone' => $route ? Status::tone('route', $routeStatus) : 'slate',
            'block_reason' => $route?->block_reason,
        ];
    }
}
