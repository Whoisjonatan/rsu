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
            ->with(['brand', 'model', 'type', 'color', 'profileImage'])
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
        // La ficha y sus fotos se registran juntas (ver Vehicle::createWithImages).
        $vehicle = Vehicle::createWithImages(
            $request->safe()->except(['images', 'profile_image']),
            $request->file('images', []),
            $request->integer('profile_image'),
        );

        // Envuelto en "data", igual que update(): una sola forma por recurso.
        return (new VehicleResource($vehicle->load(['brand', 'model', 'type', 'color', 'images'])))
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
        if ($blocker = $vehicle->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $vehicle->deleteWithImages();

        return response()->json(null, 204);
    }

    /** Contadores de la barra superior de W04. */
    public function summary(): JsonResponse
    {
        return response()->json(Vehicle::summary());
    }
}
