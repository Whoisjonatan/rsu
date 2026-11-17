<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\VehicleRoute;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Recorridos en vivo: mapa con los camiones en la calle y el trayecto GPS de
 * cada recorrido. Los datos los trae la propia página desde el API
 * (Api\TrackingController) cada 5 segundos.
 */
class TrackingController extends Controller
{
    public function index(Request $request): View
    {
        $selected = $request->filled('recorrido')
            ? VehicleRoute::find((int) $request->query('recorrido'))
            : null;

        // Abierto desde una programación: el día es el de su recorrido.
        $date = $selected?->date_route?->toDateString()
            ?? ($request->filled('date') ? $request->date('date')->format('Y-m-d') : now()->toDateString());

        return view('tracking.index', [
            'date' => $date,
            'selected' => $selected?->id,
        ]);
    }
}
