<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\RoutePath;
use App\Models\RouteRating;
use App\Models\Scheduling;
use App\Models\User;
use App\Models\UserType;
use App\Models\VehicleRoute;
use App\Models\Zone;
use App\Support\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * App móvil del ciudadano: programaciones de su zona, el recorrido en vivo
 * (los puntos que manda la app del conductor), la calificación del servicio,
 * su historial y su perfil. Solo ve lo de su zona.
 */
class CitizenController extends Controller
{
    private const LIMIT = 30;

    // ------------------------------------------------------------ Zonas (público)

    /**
     * Zonas activas con su perímetro, para el selector del registro. Con
     * ?lat&lng vienen ordenadas de la más cercana a la más lejana y marcan
     * la que contiene ese punto.
     */
    public function zones(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $hasPoint = $request->filled('lat') && $request->filled('lng');
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');

        $zones = Zone::query()
            ->with(['coords', 'district'])
            ->where('status', 'activa')
            ->orderBy('name')
            ->get()
            ->map(function (Zone $zone) use ($hasPoint, $lat, $lng) {
                $perimeter = $zone->coords->sortBy('position')->map(fn ($c) => [(float) $c->latitude, (float) $c->longitude])->values()->all();
                [$distance, $contains] = $hasPoint && count($perimeter) >= 3
                    ? self::distanceToPolygon($lat, $lng, $perimeter)
                    : [null, false];

                return [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'district' => $zone->district?->name,
                    'perimeter' => $perimeter,
                    'center' => self::center($perimeter),
                    'distance_meters' => $distance !== null ? (int) round($distance) : null,
                    'contains' => $contains,
                ];
            });

        if ($hasPoint) {
            // Las que no tienen perímetro no se pueden medir: al final.
            $zones = $zones->sortBy(fn ($z) => $z['distance_meters'] ?? PHP_INT_MAX)->values();
        }

        return response()->json([
            'data' => $zones->all(),
            'nearest_id' => $hasPoint ? ($zones->first(fn ($z) => $z['distance_meters'] !== null)['id'] ?? null) : null,
        ]);
    }

    // ------------------------------------------------------------ Programaciones

    /** Programaciones de la zona del ciudadano, de hoy en adelante. */
    public function schedulings(Request $request): JsonResponse
    {
        $user = $this->citizen($request);
        $zone = $this->zoneOf($user);
        $today = now()->toDateString();

        Scheduling::closeFinished();

        $schedulings = Scheduling::query()
            ->with(['schedule', 'vehicle', 'vehicleRoutes' => fn ($q) => $q->whereDate('date_route', '>=', $today)->orderBy('date_route')])
            ->where('zone_id', $zone->id)
            ->where('status', '!=', 'inactiva')
            ->whereDate('end_date', '>=', $today)
            ->orderBy('start_date')
            ->get()
            ->sortBy(fn (Scheduling $s) => $s->start_date->toDateString().' '.($s->schedule?->time_start ?? ''))
            ->take(self::LIMIT)
            ->values();

        return response()->json([
            'today' => $today,
            'zone' => ['id' => $zone->id, 'name' => $zone->name],
            'data' => $schedulings->map(fn (Scheduling $s) => $this->presentScheduling($s, $today))->all(),
        ]);
    }

    /** Detalle de una programación de su zona, con el perímetro para el mapa. */
    public function scheduling(Request $request, Scheduling $scheduling): JsonResponse
    {
        $user = $this->citizen($request);
        $zone = $this->zoneOf($user);
        abort_unless((int) $scheduling->zone_id === (int) $zone->id && $scheduling->status !== 'inactiva', 404);

        $today = now()->toDateString();
        $scheduling->load(['schedule', 'vehicle', 'route', 'zone.coords', 'zone.district',
            'vehicleRoutes' => fn ($q) => $q->whereDate('date_route', '>=', $today)->orderBy('date_route')]);
        /** @var VehicleRoute|null $route */
        $route = $scheduling->vehicleRoutes->first()
            // Si ya pasó (finalizada ayer), el último recorrido que tuvo.
            ?? $scheduling->vehicleRoutes()->orderByDesc('date_route')->first();

        return response()->json([
            'data' => $this->presentScheduling($scheduling, $today, $route) + [
                'route_name' => $scheduling->route?->name,
                'district' => $scheduling->zone?->district?->name,
                'zone_perimeter' => $scheduling->zone?->coords->sortBy('position')
                    ->map(fn ($c) => [(float) $c->latitude, (float) $c->longitude])->values()->all() ?? [],
                'my_rating' => $route ? $this->ratingOf($route, $user) : null,
            ],
        ]);
    }

    // ------------------------------------------------------------ Recorrido

    /**
     * Recorrido de su zona con sus puntos GPS. Con ?after_id solo los nuevos:
     * la app lo consulta cada 5 segundos mientras el recorrido está en curso.
     */
    public function route(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $user = $this->citizen($request);
        $this->ownZone($user, $vehicleroute);
        $afterId = max(0, (int) $request->query('after_id', 0));

        $points = $vehicleroute->paths()->where('id', '>', $afterId)->orderBy('id')->get(['id', 'latitude', 'longitude', 'created_at']);
        $last = $points->last() ?? $vehicleroute->paths()->latest('id')->first();
        $vehicleroute->loadMissing(['scheduling.zone.coords', 'vehicle', 'schedule']);

        return response()->json([
            'data' => $this->presentRoute($vehicleroute, $user) + [
                'last' => $last ? ['lat' => $last->latitude, 'lng' => $last->longitude, 'at' => $last->created_at?->format('H:i:s')] : null,
                'seconds_since_last' => $last?->created_at ? (int) $last->created_at->diffInSeconds(now(), true) : null,
                'zone_perimeter' => $afterId === 0
                    ? $vehicleroute->scheduling?->zone?->coords->sortBy('position')
                        ->map(fn ($c) => [(float) $c->latitude, (float) $c->longitude])->values()->all()
                    : null,
            ],
            'points' => $points->map(fn (RoutePath $p) => ['id' => $p->id, 'latitude' => $p->latitude, 'longitude' => $p->longitude])->all(),
        ]);
    }

    /** Califica (o corrige su calificación de) un recorrido finalizado. */
    public function rate(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $user = $this->citizen($request);
        $this->ownZone($user, $vehicleroute);

        $data = $request->validate([
            'stars' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [
            'stars.required' => 'Elige de 1 a 5 estrellas.',
            'stars.between' => 'Elige de 1 a 5 estrellas.',
            'comment.max' => 'El comentario no puede pasar de 500 caracteres.',
        ]);

        if ($vehicleroute->status !== 'finalizado') {
            return response()->json(['message' => 'Solo puedes calificar un recorrido que ya finalizó.'], 409);
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        RouteRating::updateOrCreate(
            ['vehicleroute_id' => $vehicleroute->id, 'user_id' => $user->id],
            ['stars' => $data['stars'], 'comment' => $comment !== '' ? $comment : null],
        );

        return response()->json(['data' => $this->presentRoute($vehicleroute->fresh(['scheduling', 'vehicle', 'schedule']), $user)]);
    }

    /**
     * Historial: los recorridos realizados en su zona (en curso y
     * finalizados), del más reciente al más antiguo, con su calificación.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $this->citizen($request);
        $zone = $this->zoneOf($user);

        $routes = VehicleRoute::query()
            ->with(['scheduling', 'vehicle', 'schedule'])
            ->withCount('paths')
            ->whereHas('scheduling', fn ($q) => $q->where('zone_id', $zone->id))
            ->whereIn('status', ['iniciado', 'finalizado'])
            ->orderByDesc('date_route')
            ->orderByDesc('started_at')
            ->paginate(15);

        $mine = RouteRating::query()
            ->where('user_id', $user->id)
            ->whereIn('vehicleroute_id', $routes->pluck('id'))
            ->get()
            ->keyBy('vehicleroute_id');

        return response()->json([
            'zone' => ['id' => $zone->id, 'name' => $zone->name],
            'data' => collect($routes->items())->map(fn (VehicleRoute $r) => $this->presentRoute($r, $user, $mine->get($r->id), false))->all(),
            'current_page' => $routes->currentPage(),
            'last_page' => $routes->lastPage(),
            'total' => $routes->total(),
        ]);
    }

    // ------------------------------------------------------------ Perfil

    public function profile(Request $request): JsonResponse
    {
        $user = $this->citizen($request);

        return response()->json([
            'data' => new UserResource($user->load('usertype', 'zone')),
            'stats' => [
                'ratings' => RouteRating::where('user_id', $user->id)->count(),
                'member_since' => $user->created_at?->toDateString(),
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->citizen($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'lastname' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'zone_id' => ['required', Rule::exists('zones', 'id')->where('status', 'activa')],
        ], [
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'zone_id.exists' => 'La zona elegida no está disponible.',
        ]);

        $user->update([
            'name' => trim($data['name']),
            'lastname' => trim($data['lastname']),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'zone_id' => $data['zone_id'],
        ]);

        return response()->json(['data' => new UserResource($user->load('usertype', 'zone'))]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->citizen($request);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'different:current_password'],
        ], [
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    // ------------------------------------------------------------ Apoyo

    private function citizen(Request $request): User
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('usertype', 'zone');
        abort_unless($user->usertype?->slug === UserType::CITIZEN, 403, 'Esta sección es solo para ciudadanos.');

        return $user;
    }

    private function zoneOf(User $user): Zone
    {
        abort_unless($user->zone !== null, 409, 'Elige tu zona en tu perfil para ver sus programaciones.');

        return $user->zone;
    }

    /** El recorrido debe ser de una programación de su zona. */
    private function ownZone(User $user, VehicleRoute $route): void
    {
        $route->loadMissing('scheduling');
        abort_unless($user->zone_id && $route->scheduling && (int) $route->scheduling->zone_id === (int) $user->zone_id, 404);
    }

    /** @return array<string, mixed>|null */
    private function ratingOf(VehicleRoute $route, User $user, ?RouteRating $rating = null): ?array
    {
        $rating ??= RouteRating::where('vehicleroute_id', $route->id)->where('user_id', $user->id)->first();

        return $rating ? [
            'stars' => $rating->stars,
            'comment' => $rating->comment,
            'updated_at' => $rating->updated_at?->format('Y-m-d H:i:s'),
        ] : null;
    }

    /** @return array<string, mixed> */
    private function presentScheduling(Scheduling $s, string $today, ?VehicleRoute $route = null): array
    {
        $route ??= $s->vehicleRoutes->first();
        $routeStatus = $route?->status ?? 'sin_recorrido';

        return [
            'id' => $s->id,
            'code' => $s->code,
            'date' => $route?->date_route?->toDateString() ?? max($s->start_date->toDateString(), $today),
            'is_today' => $s->start_date->toDateString() <= $today && $s->end_date->toDateString() >= $today,
            'schedule' => $s->schedule ? [
                'name' => $s->schedule->name,
                'time_start' => substr((string) $s->schedule->time_start, 0, 5),
                'time_end' => substr((string) $s->schedule->time_end, 0, 5),
            ] : null,
            'vehicle' => $s->vehicle ? ['code' => $s->vehicle->code, 'plate' => $s->vehicle->plate, 'name' => $s->vehicle->name] : null,
            'vehicleroute_id' => $route?->id,
            'route_status' => $routeStatus,
            'route_status_label' => match ($routeStatus) {
                'iniciado' => 'En curso',
                'sin_recorrido' => 'Sin recorrido',
                default => Status::label('route', $routeStatus),
            },
            'route_status_tone' => $routeStatus === 'iniciado' ? 'live' : ($route ? Status::tone('route', $routeStatus) : 'slate'),
            'started_at' => $route?->started_at?->format('H:i'),
            'finished_at' => $route?->finished_at?->format('H:i'),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRoute(VehicleRoute $r, User $user, ?RouteRating $rating = null, bool $lookupRating = true): array
    {
        $s = $r->scheduling;
        $end = $r->finished_at ?? now();
        $paths = $r->paths()->orderBy('id')->get(['latitude', 'longitude']);
        $summary = $r->ratings()->selectRaw('AVG(stars) as avg, COUNT(*) as total')->first();

        return [
            'id' => $r->id,
            'scheduling_id' => $r->scheduling_id,
            'code' => $s?->code,
            'date' => $r->date_route->toDateString(),
            'status' => $r->status,
            'status_label' => $r->status === 'iniciado' ? 'En curso' : Status::label('route', $r->status),
            'vehicle' => $r->vehicle ? ['code' => $r->vehicle->code, 'plate' => $r->vehicle->plate, 'name' => $r->vehicle->name] : null,
            'schedule' => $r->schedule ? [
                'name' => $r->schedule->name,
                'time_start' => substr((string) $r->schedule->time_start, 0, 5),
                'time_end' => substr((string) $r->schedule->time_end, 0, 5),
            ] : null,
            'started_at' => $r->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $r->finished_at?->format('Y-m-d H:i:s'),
            'elapsed_seconds' => $r->started_at ? (int) $r->started_at->diffInSeconds($end, true) : 0,
            'points_count' => $paths->count(),
            'distance_meters' => (int) round(self::pathLength($paths)),
            'can_rate' => $r->status === 'finalizado',
            'my_rating' => $lookupRating ? $this->ratingOf($r, $user, $rating) : ($rating ? $this->ratingOf($r, $user, $rating) : null),
            'rating_average' => $summary?->total ? round((float) $summary->avg, 1) : null,
            'rating_count' => (int) ($summary?->total ?? 0),
        ];
    }

    private static function pathLength(Collection $points): float
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

    /** @param list<array{0: float, 1: float}> $perimeter */
    private static function center(array $perimeter): ?array
    {
        if ($perimeter === []) {
            return null;
        }

        return [
            round(array_sum(array_column($perimeter, 0)) / count($perimeter), 7),
            round(array_sum(array_column($perimeter, 1)) / count($perimeter), 7),
        ];
    }

    /**
     * Distancia en metros del punto al perímetro (0 si está dentro) y si lo
     * contiene. Proyección plana local: sobra para distancias de una ciudad.
     *
     * @param  list<array{0: float, 1: float}>  $perimeter
     * @return array{0: float, 1: bool}
     */
    private static function distanceToPolygon(float $lat, float $lng, array $perimeter): array
    {
        $k = 6371000 * M_PI / 180;
        $cos = cos(deg2rad($lat));
        $xy = array_map(fn ($p) => [($p[1] - $lng) * $k * $cos, ($p[0] - $lat) * $k], $perimeter);

        $inside = false;
        $min = INF;
        $n = count($xy);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $xy[$i];
            [$xj, $yj] = $xy[$j];

            // Rayo desde el punto (origen) hacia +x.
            if ((($yi > 0) !== ($yj > 0)) && (0 < ($xj - $xi) * (0 - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }

            // Distancia del origen al segmento.
            $dx = $xj - $xi;
            $dy = $yj - $yi;
            $len = $dx * $dx + $dy * $dy;
            $t = $len > 0 ? max(0, min(1, -($xi * $dx + $yi * $dy) / $len)) : 0;
            $min = min($min, hypot($xi + $t * $dx, $yi + $t * $dy));
        }

        return [$inside ? 0.0 : $min, $inside];
    }
}
