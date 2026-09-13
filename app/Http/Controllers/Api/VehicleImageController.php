<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleImageResource;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

        $path = $request->file('image')->store('vehicles/'.$vehicle->id, 'public');

        // La primera imagen del vehiculo queda como perfil si no hay otra.
        $isProfile = $request->boolean('profile') || ! $vehicle->images()->where('profile', true)->exists();

        $image = DB::transaction(function () use ($vehicle, $path, $isProfile) {
            if ($isProfile) {
                $vehicle->images()->update(['profile' => false]);
            }

            return $vehicle->images()->create([
                'image' => $path,
                'profile' => $isProfile,
            ]);
        });

        return response()->json(new VehicleImageResource($image), 201);
    }

    /** Establece esta imagen como la de perfil del vehiculo. */
    public function setProfile(Vehicle $vehicle, VehicleImage $image): JsonResponse
    {
        abort_if($image->vehicle_id !== $vehicle->id, 404);

        DB::transaction(function () use ($vehicle, $image) {
            $vehicle->images()->update(['profile' => false]);
            $image->update(['profile' => true]);
        });

        return response()->json(new VehicleImageResource($image->fresh()));
    }

    public function destroy(Vehicle $vehicle, VehicleImage $image): JsonResponse
    {
        abort_if($image->vehicle_id !== $vehicle->id, 404);

        Storage::disk('public')->delete($image->image);
        $wasProfile = $image->profile;
        $image->delete();

        // Si se borro la de perfil, promover la siguiente imagen disponible.
        if ($wasProfile) {
            $vehicle->images()->oldest('id')->first()?->update(['profile' => true]);
        }

        return response()->json(null, 204);
    }
}
