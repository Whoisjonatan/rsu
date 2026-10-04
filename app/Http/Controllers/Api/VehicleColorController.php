<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleColorRequest;
use App\Models\VehicleColor;
use Illuminate\Http\JsonResponse;

class VehicleColorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            VehicleColor::withCount('vehicles')->orderBy('name')->get()
        );
    }

    public function store(VehicleColorRequest $request): JsonResponse
    {
        return response()->json(VehicleColor::create($request->colorData()), 201);
    }

    public function update(VehicleColorRequest $request, VehicleColor $vehiclecolor): JsonResponse
    {
        $vehiclecolor->update($request->colorData());

        return response()->json($vehiclecolor);
    }

    public function destroy(VehicleColor $vehiclecolor): JsonResponse
    {
        if ($blocker = $vehiclecolor->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $vehiclecolor->delete();

        return response()->json(null, 204);
    }
}
