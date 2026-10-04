<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleTypeRequest;
use App\Models\VehicleType;
use Illuminate\Http\JsonResponse;

class VehicleTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            VehicleType::withCount('vehicles')->orderBy('name')->get()
        );
    }

    public function store(VehicleTypeRequest $request): JsonResponse
    {
        return response()->json(VehicleType::create($request->validated()), 201);
    }

    public function update(VehicleTypeRequest $request, VehicleType $vehicletype): JsonResponse
    {
        $vehicletype->update($request->validated());

        return response()->json($vehicletype);
    }

    public function destroy(VehicleType $vehicletype): JsonResponse
    {
        if ($blocker = $vehicletype->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $vehicletype->delete();

        return response()->json(null, 204);
    }
}
