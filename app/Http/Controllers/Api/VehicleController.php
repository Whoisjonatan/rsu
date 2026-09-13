<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VehicleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $vehicles = Vehicle::query()
            ->with(['brand', 'model', 'type', 'color'])
            ->search($request->string('search')->toString())
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('type_id'), fn ($q) => $q->where('type_id', $request->integer('type_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('code')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return VehicleResource::collection($vehicles);
    }

    public function store(VehicleRequest $request): JsonResponse
    {
        $vehicle = Vehicle::create($request->validated());

        // Envuelto en "data", igual que update(): una sola forma por recurso.
        return (new VehicleResource($vehicle->load(['brand', 'model', 'type', 'color'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource(
            $vehicle->load(['brand', 'model', 'type', 'color', 'images']),
        );
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle->update($request->validated());

        return new VehicleResource($vehicle->load(['brand', 'model', 'type', 'color', 'images']));
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->schedulings()->exists() || $vehicle->vehicleRoutes()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: el vehiculo tiene programaciones o recorridos registrados.',
            ], 409);
        }

        $vehicle->delete();

        return response()->json(null, 204);
    }

    /** Contadores de la barra superior de W04. */
    public function summary(): JsonResponse
    {
        return response()->json([
            'total' => Vehicle::count(),
            'operativo' => Vehicle::where('status', 'operativo')->count(),
            'mantenimiento' => Vehicle::where('status', 'mantenimiento')->count(),
            'baja' => Vehicle::where('status', 'baja')->count(),
        ]);
    }
}
