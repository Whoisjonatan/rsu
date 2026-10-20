<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ZoneRequest;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zonas de recoleccion (W13). El perimetro y las rutas se manejan aparte.
 */
class ZoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $zones = Zone::query()
            ->with(['district.province', 'district.department'])
            ->withCount('coords')
            ->when($request->filled('district_id'), fn ($q) => $q
                ->where('district_id', $request->integer('district_id')))
            // ?status=activa: lo que se puede elegir al programar o crear rutas.
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name')
            ->get();

        return response()->json($zones);
    }

    public function show(Zone $zone): JsonResponse
    {
        return response()->json($zone->load(['district.province', 'district.department', 'coords', 'routes']));
    }

    public function store(ZoneRequest $request): JsonResponse
    {
        return response()->json(Zone::create($request->validated()), 201);
    }

    public function update(ZoneRequest $request, Zone $zone): JsonResponse
    {
        $zone->update($request->validated());

        return response()->json($zone->load('district'));
    }

    public function destroy(Zone $zone): JsonResponse
    {
        if ($blocker = $zone->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $zone->delete();

        return response()->json(null, 204);
    }

    /** Perimetro de la zona (W14): la lista completa de vertices (ver Zone::replaceCoords). */
    public function saveCoords(Request $request, Zone $zone): JsonResponse
    {
        $data = $request->validate(Zone::coordsRules());

        $zone->replaceCoords($data['coords']);

        return response()->json([
            ...$zone->load('coords')->toArray(),
            // Guardar no se impide por cruzarse con otra zona, pero se avisa.
            'overlaps' => Zone::overlapsFor($data['coords'], $zone->id),
        ]);
    }

    /**
     * Con qué zonas se cruza un perímetro sin guardarlo: el aviso en vivo de
     * W14 mientras se dibuja. Ver Zone::overlapsFor().
     */
    public function overlaps(Request $request, Zone $zone): JsonResponse
    {
        $data = $request->validate(Zone::coordsRules());

        return response()->json(Zone::overlapsFor($data['coords'], $zone->id));
    }
}
