<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoutePath;
use App\Models\VehicleRoute;
use App\Support\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recorridos en vivo: lo que consulta cada 5 segundos la pantalla del panel
 * que sigue a los camiones en el mapa. Los puntos los manda la app del
 * conductor (MobileController::storePaths).
 */
class TrackingController extends Controller
{
    /** Sin un punto nuevo en este tiempo, el panel avisa "sin señal". */
    private const SIGNAL_TIMEOUT = 30;

    /**
     * Recorridos iniciados o finalizados de un día, cada uno con su última
     * posición. Los en curso primero.
     */
    public function index(Request $request): JsonResponse
    {
        $date = $request->filled('date') ? $request->date('date')->format('Y-m-d') : now()->toDateString();

        $routes = VehicleRoute::query()
            ->with(['scheduling.zone', 'scheduling.driver', 'scheduling.helpers', 'vehicle', 'schedule'])
            ->withCount('paths')
            ->whereDate('date_route', $date)
            ->whereIn('status', ['iniciado', 'finalizado'])
            ->get();

        // Último punto de cada recorrido, en una sola consulta.
        $lastIds = RoutePath::query()
            ->whereIn('vehicleroute_id', $routes->pluck('id'))
            ->selectRaw('MAX(id) as id')
            ->groupBy('vehicleroute_id')
            ->pluck('id');
        $last = RoutePath::query()->whereIn('id', $lastIds)->get()->keyBy('vehicleroute_id');

        $data = $routes
            ->sortBy(fn (VehicleRoute $r) => [$r->status === 'iniciado' ? 0 : 1, -($r->started_at?->timestamp ?? 0)])
            ->map(fn (VehicleRoute $r) => $this->present($r, $last->get($r->id)))
            ->values();

        return response()->json([
            'date' => $date,
            'is_today' => $date === now()->toDateString(),
            'server_time' => now()->format('Y-m-d H:i:s'),
            'counts' => [
                'iniciado' => $routes->where('status', 'iniciado')->count(),
                'finalizado' => $routes->where('status', 'finalizado')->count(),
            ],
            'data' => $data,
        ]);
    }

    /**
     * Un recorrido con sus puntos. Con ?after_id solo devuelve los nuevos,
     * para que el mapa agregue el tramo sin volver a bajar todo el trayecto.
     */
    public function show(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $afterId = max(0, (int) $request->query('after_id', 0));

        $vehicleroute->load(['scheduling.zone.coords', 'scheduling.driver', 'scheduling.helpers', 'vehicle', 'schedule'])
            ->loadCount('paths');

        $points = $vehicleroute->paths()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get(['id', 'latitude', 'longitude', 'created_at']);

        $last = $points->last() ?? $vehicleroute->paths()->latest('id')->first();
        $zone = $vehicleroute->scheduling?->zone;

        return response()->json([
            'data' => $this->present($vehicleroute, $last) + [
                // Calificaciones de los ciudadanos de la zona (app móvil).
                'rating_average' => ($avg = $vehicleroute->ratings()->avg('stars')) !== null ? round((float) $avg, 1) : null,
                'ratings' => $afterId === 0
                    ? $vehicleroute->ratings()->with('user:id,name,lastname')->latest()->limit(20)->get()
                        ->map(fn ($r) => [
                            'stars' => $r->stars,
                            'comment' => $r->comment,
                            'citizen' => trim($r->user?->name.' '.mb_substr((string) $r->user?->lastname, 0, 1).'.'),
                            'at' => $r->updated_at?->format('d/m/Y H:i'),
                        ])
                    : null,
                // El perímetro solo en la primera carga: no cambia.
                'zone_perimeter' => $afterId === 0 && $zone
                    ? $zone->coords->sortBy('position')->map(fn ($c) => [$c->latitude, $c->longitude])->values()
                    : null,
            ],
            'points' => $points->map(fn (RoutePath $p) => [
                'id' => $p->id,
                'lat' => $p->latitude,
                'lng' => $p->longitude,
                'at' => $p->created_at?->format('H:i:s'),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(VehicleRoute $r, ?RoutePath $last): array
    {
        $s = $r->scheduling;
        $end = $r->finished_at ?? now();
        $sinceLast = $last?->created_at ? (int) $last->created_at->diffInSeconds(now(), true) : null;

        return [
            'id' => $r->id,
            'scheduling_id' => $r->scheduling_id,
            'code' => $s?->code,
            'date' => $r->date_route->toDateString(),
            'status' => $r->status,
            'status_label' => $r->status === 'iniciado' ? 'En curso' : Status::label('route', $r->status),
            'zone' => $s?->zone?->name,
            'vehicle' => $r->vehicle ? trim(($r->vehicle->code ? $r->vehicle->code.' · ' : '').$r->vehicle->plate) : null,
            'driver' => $s?->driver?->full_name,
            'helpers' => $s ? $s->helpers->pluck('full_name')->values() : [],
            'schedule' => $r->schedule ? $r->schedule->name.' ('.substr((string) $r->schedule->time_start, 0, 5).' - '.substr((string) $r->schedule->time_end, 0, 5).')' : null,
            'started_at' => $r->started_at?->format('H:i'),
            'finished_at' => $r->finished_at?->format('H:i'),
            'elapsed_seconds' => $r->started_at ? (int) $r->started_at->diffInSeconds($end, true) : 0,
            'observation' => $r->observation,
            'points_count' => (int) ($r->paths_count ?? 0),
            'last' => $last ? ['lat' => $last->latitude, 'lng' => $last->longitude, 'at' => $last->created_at?->format('H:i:s')] : null,
            'seconds_since_last' => $sinceLast,
            'signal_lost' => $r->status === 'iniciado' && ($sinceLast === null || $sinceLast > self::SIGNAL_TIMEOUT),
        ];
    }
}
