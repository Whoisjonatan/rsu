<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ZoneRequest;
use App\Models\District;
use App\Models\Sector;
use App\Models\Zone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ZoneController extends Controller
{
    /** W13 Zonas. */
    public function index(): View
    {
        $zones = Zone::with(['sector', 'district'])->withCount('coords')->orderBy('name')->get();

        return view('zones.index', [
            'zones' => $zones,
            'districts' => District::orderBy('name')->get(['id', 'name']),
            // Todos los sectores: el modal filtra los del distrito elegido.
            'sectors' => Sector::orderBy('name')->get(['id', 'name', 'district_id']),
        ]);
    }

    public function store(ZoneRequest $request): RedirectResponse
    {
        $zone = Zone::create($request->validated());

        return back()->with('success', "Zona \"{$zone->name}\" registrada.");
    }

    public function update(ZoneRequest $request, Zone $zone): RedirectResponse
    {
        $zone->update($request->validated());

        return back()->with('success', "Zona \"{$zone->name}\" actualizada.");
    }

    public function destroy(Zone $zone): RedirectResponse
    {
        if ($blocker = $zone->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $zone->delete();

        return back()->with('success', "Zona \"{$zone->name}\" eliminada.");
    }

    /** W14 Perímetro de la zona. */
    public function perimeter(Zone $zone): View
    {
        return view('zones.perimeter', ['zone' => $zone->load('coords')]);
    }

    public function savePerimeter(Request $request, Zone $zone): RedirectResponse
    {
        // El mapa manda los vértices como JSON en un solo campo: un perímetro
        // vacío también es una respuesta válida ("borrarlo").
        $request->merge(['coords' => json_decode((string) $request->input('coords_json'), true) ?? []]);
        $data = $request->validate(Zone::coordsRules());

        $zone->replaceCoords($data['coords']);

        $count = count($data['coords']);

        return back()->with('success', $count === 0 ? 'Perímetro borrado.' : "Perímetro guardado con {$count} puntos.");
    }
}
