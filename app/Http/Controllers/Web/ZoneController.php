<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ZoneRequest;
use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use App\Models\Zone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ZoneController extends Controller
{
    /** W13 Zonas. */
    public function index(): View
    {
        $zones = Zone::with(['district.province', 'district.department'])->withCount('coords')->orderBy('name')->get();

        return view('zones.index', [
            'zones' => $zones,
            // Los tres niveles completos: el modal filtra en cascada sin ir al servidor.
            'departments' => Department::whereHas('provinces')->orderBy('name')->get(['id', 'name']),
            'provinces' => Province::orderBy('name')->get(['id', 'name', 'department_id']),
            'districts' => District::orderBy('name')->get(['id', 'name', 'province_id', 'department_id']),
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

    /** Mapa general: todas las zonas con su perímetro, y las que aún no lo tienen. */
    public function map(): View
    {
        $zones = Zone::with(['coords', 'district'])->orderBy('name')->get();
        [$drawn, $pending] = $zones->partition(fn (Zone $zone) => $zone->coords->count() >= 3);

        return view('zones.map', [
            'drawn' => $drawn->values(),
            'pending' => $pending->values(),
            'shapes' => $drawn->map(fn (Zone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'status' => $zone->status,
                'points' => $zone->coords->map(fn ($coord) => [
                    'latitude' => (float) $coord->latitude,
                    'longitude' => (float) $coord->longitude,
                ]),
                'popup' => [
                    'title' => $zone->name,
                    'lines' => array_values(array_filter([
                        $zone->district?->name,
                        'Área: '.number_format((float) $zone->area, 3).' km²',
                        $zone->average_waste_kg !== null ? 'Residuos promedio: '.number_format((float) $zone->average_waste_kg, 2).' kg' : null,
                        $zone->isActive() ? null : 'Zona inactiva',
                    ])),
                    'link' => ['url' => route('zones.perimeter', $zone), 'label' => 'Editar perímetro →'],
                ],
            ])->values(),
        ]);
    }

    /** W14 Perímetro de la zona. */
    public function perimeter(Zone $zone): View
    {
        return view('zones.perimeter', [
            'zone' => $zone->load('coords'),
            // Los perímetros ya trazados de las demás zonas, de referencia: así se
            // ve qué está cubierto y no se dibuja dos veces el mismo terreno.
            'references' => Zone::whereKeyNot($zone->id)->has('coords')->with('coords')->orderBy('name')->get()
                ->map(fn (Zone $other) => [
                    'name' => $other->name,
                    'points' => $other->coords->map(fn ($coord) => [
                        'latitude' => (float) $coord->latitude,
                        'longitude' => (float) $coord->longitude,
                    ]),
                ]),
        ]);
    }

    public function savePerimeter(Request $request, Zone $zone): RedirectResponse
    {
        // El mapa manda los vértices como JSON en un solo campo: un perímetro
        // vacío también es una respuesta válida ("borrarlo").
        $request->merge(['coords' => json_decode((string) $request->input('coords_json'), true) ?? []]);
        $data = $request->validate(Zone::coordsRules());

        $zone->replaceCoords($data['coords']);

        $count = count($data['coords']);

        if ($count === 0) {
            return back()->with('success', 'Perímetro borrado: la zona queda sin área.');
        }

        // Cruzarse con otra zona no impide guardar, pero se avisa.
        $overlaps = collect(Zone::overlapsFor($data['coords'], $zone->id))->pluck('name');

        return back()->with('success', "Perímetro guardado con {$count} puntos. Área: ".number_format((float) $zone->area, 3).' km².'
            .($overlaps->isEmpty() ? '' : ' Ojo: se cruza con '.$overlaps->implode(', ').'.'));
    }
}
