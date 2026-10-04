<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleTypeRequest;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;

/** Tipos de vehículo (pestaña de W03). */
class VehicleTypeController extends Controller
{
    public function store(VehicleTypeRequest $request): RedirectResponse
    {
        $type = VehicleType::create($request->validated());

        return back()->with('success', "Tipo \"{$type->name}\" registrado.");
    }

    public function update(VehicleTypeRequest $request, VehicleType $vehicletype): RedirectResponse
    {
        $vehicletype->update($request->validated());

        return back()->with('success', "Tipo \"{$vehicletype->name}\" actualizado.");
    }

    public function destroy(VehicleType $vehicletype): RedirectResponse
    {
        if ($blocker = $vehicletype->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $vehicletype->delete();

        return back()->with('success', "Tipo \"{$vehicletype->name}\" eliminado.");
    }
}
