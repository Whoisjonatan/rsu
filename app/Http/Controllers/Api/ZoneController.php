<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Zonas de recoleccion (W13). El perimetro y las rutas se manejan aparte.
 */
class ZoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $zones = Zone::query()
            ->with(['sector', 'district'])
            ->withCount('coords')
            ->when($request->filled('district_id'), fn ($q) => $q
                ->where('district_id', $request->integer('district_id')))
            ->orderBy('name')
            ->get();

        return response()->json($zones);
    }

    public function show(Zone $zone): JsonResponse
    {
        return response()->json($zone->load(['sector', 'district', 'coords', 'routes']));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Zone::create($this->validated($request)), 201);
    }

    public function update(Request $request, Zone $zone): JsonResponse
    {
        $zone->update($this->validated($request, $zone->id));

        return response()->json($zone->load(['sector', 'district']));
    }

    public function destroy(Zone $zone): JsonResponse
    {
        if ($zone->schedulings()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: la zona tiene programaciones registradas.',
            ], 409);
        }

        $zone->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('zones', 'name')->ignore($id)],
            'area' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'sector_id' => ['nullable', 'exists:sectors,id'],
            'district_id' => ['required', 'exists:districts,id'],
        ]);
    }
}
