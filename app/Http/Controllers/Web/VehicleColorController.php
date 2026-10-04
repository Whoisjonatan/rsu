<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleColorRequest;
use App\Models\VehicleColor;
use Illuminate\Http\RedirectResponse;

/** Colores (pestaña de W03). */
class VehicleColorController extends Controller
{
    public function store(VehicleColorRequest $request): RedirectResponse
    {
        $color = VehicleColor::create($request->colorData());

        return back()->with('success', "Color \"{$color->name}\" registrado.");
    }

    public function update(VehicleColorRequest $request, VehicleColor $vehiclecolor): RedirectResponse
    {
        $vehiclecolor->update($request->colorData());

        return back()->with('success', "Color \"{$vehiclecolor->name}\" actualizado.");
    }

    public function destroy(VehicleColor $vehiclecolor): RedirectResponse
    {
        if ($blocker = $vehiclecolor->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $vehiclecolor->delete();

        return back()->with('success', "Color \"{$vehiclecolor->name}\" eliminado.");
    }
}
