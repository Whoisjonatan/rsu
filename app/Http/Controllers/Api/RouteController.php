<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RouteRequest;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rutas de recoleccion (W15).
 *
 * Una ruta guarda solo punto de partida y fin, que es lo que pide el informe.
 * El camino real por donde pasó el vehículo NO vive aqui: son los `routepaths`
 * de cada `vehicleroute`, que llegaran del GPS cuando exista la app movil.
 */
class RouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $routes = Route::query()
            ->with('zones:id,name')
            ->withCount('schedulings')
            ->when($request->filled('zone_id'), fn ($q) => $q->whereHas(
                'zones',
                fn ($z) => $z->where('zones.id', $request->integer('zone_id')),
            ))
            ->when($request->filled('status'), fn ($q) => $q
                ->where('status', $request->string('status')->toString()))
            ->orderBy('name')
            ->get();

        return response()->json($routes);
    }

    public function show(Route $route): JsonResponse
    {
        return response()->json($route->load('zones:id,name'));
    }

    public function store(RouteRequest $request): JsonResponse
    {
        $route = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $route = Route::create($data);
            $route->zones()->sync($data['zone_ids']);

            return $route;
        });

        return response()->json($route->load('zones:id,name'), 201);
    }

    public function update(RouteRequest $request, Route $route): JsonResponse
    {
        DB::transaction(function () use ($request, $route) {
            $data = $request->validated();
            $route->update($data);
            $route->zones()->sync($data['zone_ids']);
        });

        return response()->json($route->load('zones:id,name'));
    }

    public function destroy(Route $route): JsonResponse
    {
        // Una ruta en uso no se borra: dejaria programaciones apuntando al aire.
        if ($route->schedulings()->exists()) {
            $count = $route->schedulings()->count();

            return response()->json([
                'message' => sprintf(
                    'No se puede eliminar: la ruta está usada por %d %s. Cámbiala a inactiva.',
                    $count,
                    $count === 1 ? 'programación' : 'programaciones',
                ),
            ], 409);
        }

        $route->delete();

        return response()->json(null, 204);
    }
}
