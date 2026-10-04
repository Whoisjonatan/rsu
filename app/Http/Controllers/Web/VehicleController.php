<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\Vehicle;
use App\Models\VehicleColor;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VehicleController extends Controller
{
    /** W04 Listado de vehículos. */
    public function index(Request $request): View
    {
        $vehicles = Vehicle::query()
            // Las imágenes vienen todas: el visor de la fila las recorre sin
            // tener que pedirlas al abrirlo.
            ->with(['brand', 'model', 'type', 'color', 'images'])
            ->search($request->string('q')->toString())
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('type_id'), fn ($q) => $q->where('type_id', $request->integer('type_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('vehicles.index', [
            'vehicles' => $vehicles,
            'summary' => Vehicle::summary(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'types' => VehicleType::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['q', 'brand_id', 'type_id', 'status']),
        ]);
    }

    /** W05 Ficha de vehículo: alta. */
    public function create(): View
    {
        return view('vehicles.form', ['vehicle' => null, ...$this->catalogs()]);
    }

    public function store(VehicleRequest $request): RedirectResponse
    {
        // La ficha y sus fotos se registran juntas (ver Vehicle::createWithImages).
        $vehicle = Vehicle::createWithImages(
            $request->safe()->except(['images', 'profile_image']),
            $request->file('images', []),
            $request->integer('profile_image'),
        );

        return redirect()->route('vehicles.index')->with('success', "Vehículo {$vehicle->code} registrado.");
    }

    /** W05 Ficha de vehículo: edición. */
    public function edit(Vehicle $vehicle): View
    {
        return view('vehicles.form', [
            'vehicle' => $vehicle->load(['images' => fn ($q) => $q->orderBy('id')]),
            ...$this->catalogs($vehicle),
        ]);
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $vehicle->update($request->safe()->except(['images', 'profile_image']));

        return redirect()->route('vehicles.index')->with('success', "Vehículo {$vehicle->code} actualizado.");
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        if ($blocker = $vehicle->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $vehicle->deleteWithImages();

        return back()->with('success', "Vehículo {$vehicle->code} eliminado.");
    }

    /**
     * Los combos de la ficha. Los modelos van todos con su marca: el
     * formulario filtra los de la marca elegida sin volver al servidor.
     *
     * Solo se ofrecen marcas con algún modelo: sin modelo no se puede
     * registrar el vehículo, así que elegirlas no lleva a ninguna parte. Al
     * editar se conserva la marca del vehículo pase lo que pase, para que el
     * combo no pierda su valor.
     *
     * @return array<string, mixed>
     */
    private function catalogs(?Vehicle $current = null): array
    {
        $types = VehicleType::orderBy('name')->get(['id', 'name']);

        return [
            'brands' => Brand::query()
                ->where(fn ($q) => $q->has('models')->when($current, fn ($q) => $q->orWhere('id', $current->brand_id)))
                ->orderBy('name')
                ->get(['id', 'name']),
            'models' => BrandModel::orderBy('name')->get(['id', 'name', 'brand_id']),
            'types' => $types,
            // Los tipos que habilitan la capacidad de compactación.
            'compactorTypeIds' => $types->filter->isCompactor()->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'colors' => VehicleColor::orderBy('name')->get(['id', 'name', 'red', 'green', 'blue']),
        ];
    }
}
