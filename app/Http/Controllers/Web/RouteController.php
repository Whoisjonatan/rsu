<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\RouteRequest;
use App\Models\Route;
use App\Models\Zone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * W15 Rutas de la zona. Una ruta guarda solo su punto de partida y de fin,
 * que es lo que pide el informe.
 */
class RouteController extends Controller
{
    public function index(Request $request): View
    {
        $routes = Route::query()
            ->with('zones:id,name')
            ->withCount('schedulings')
            ->when($request->filled('zone_id'), fn ($q) => $q->whereHas(
                'zones',
                fn ($z) => $z->where('zones.id', $request->integer('zone_id')),
            ))
            ->orderBy('name')
            ->get();

        return view('zones.routes', [
            'routes' => $routes,
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
            'zoneFilter' => $request->query('zone_id'),
        ]);
    }

    public function store(RouteRequest $request): RedirectResponse
    {
        $route = (new Route)->saveWithZones($request->validated());

        return back()->with('success', "Ruta \"{$route->name}\" registrada.");
    }

    public function update(RouteRequest $request, Route $route): RedirectResponse
    {
        $route->saveWithZones($request->validated());

        return back()->with('success', "Ruta \"{$route->name}\" actualizada.");
    }

    public function destroy(Route $route): RedirectResponse
    {
        if ($blocker = $route->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $route->delete();

        return back()->with('success', "Ruta \"{$route->name}\" eliminada.");
    }
}
