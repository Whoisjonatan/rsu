<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleImageResource;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carga de imagenes del vehiculo: subir, marcar como perfil y eliminar.
 */
class VehicleImageController extends Controller
{
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'profile' => ['sometimes', 'boolean'],
        ]);

        $image = $vehicle->addImage($request->file('image'), $request->boolean('profile'));

        return response()->json(new VehicleImageResource($image), 201);
    }

    /** Establece esta imagen como la de perfil del vehiculo. */
    public function setProfile(Vehicle $vehicle, VehicleImage $image): JsonResponse
    {
        abort_if($image->vehicle_id !== $vehicle->id, 404);

        $image->makeProfile();

        return response()->json(new VehicleImageResource($image->fresh()));
    }

    public function destroy(Vehicle $vehicle, VehicleImage $image): JsonResponse
    {
        abort_if($image->vehicle_id !== $vehicle->id, 404);

        $image->deleteAndPromote();

        return response()->json(null, 204);
    }
}
