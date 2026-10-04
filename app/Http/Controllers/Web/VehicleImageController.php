<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Imágenes de un vehículo ya registrado (W05): subir, marcar la de perfil y
 * eliminar. Durante el alta las fotos viajan junto con la ficha.
 */
class VehicleImageController extends Controller
{
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'images.required' => 'Elige al menos una imagen.',
            'images.max' => 'Puedes subir como máximo 10 imágenes a la vez.',
            'images.*.image' => 'Uno de los archivos no es una imagen.',
            'images.*.mimes' => 'Las imágenes deben ser JPG, PNG o WEBP.',
            'images.*.max' => 'Cada imagen puede pesar como máximo 4 MB.',
        ]);

        // Una a una y en orden: si el vehículo no tenía perfil, la primera lo toma.
        foreach ($request->file('images') as $file) {
            $vehicle->addImage($file);
        }

        return back()->with('success', 'Imágenes agregadas.');
    }

    public function setProfile(Vehicle $vehicle, VehicleImage $image): RedirectResponse
    {
        abort_if($image->vehicle_id !== $vehicle->id, 404);

        $image->makeProfile();

        return back()->with('success', 'Imagen de perfil actualizada.');
    }

    public function destroy(Vehicle $vehicle, VehicleImage $image): RedirectResponse
    {
        abort_if($image->vehicle_id !== $vehicle->id, 404);

        $image->deleteAndPromote();

        return back()->with('success', 'Imagen eliminada.');
    }
}
