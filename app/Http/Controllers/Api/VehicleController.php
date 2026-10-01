<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VehicleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $vehicles = Vehicle::query()
            ->with(['brand', 'model', 'type', 'color', 'profileImage'])
            ->search($request->string('search')->toString())
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('type_id'), fn ($q) => $q->where('type_id', $request->integer('type_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('code')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return VehicleResource::collection($vehicles);
    }

    public function store(VehicleRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['images', 'profile_image']);
        $images = $request->file('images', []);
        $profileIndex = $request->integer('profile_image');
        $created = null;

        // La ficha y sus fotos se registran juntas: si una foto falla no
        // queda un vehiculo a medias, ni archivos huerfanos en disco.
        try {
            $vehicle = DB::transaction(function () use ($data, $images, $profileIndex, &$created) {
                $created = Vehicle::create($data);

                foreach ($images as $index => $file) {
                    $created->addImage($file, $index === $profileIndex);
                }

                return $created;
            });
        } catch (Throwable $e) {
            if ($created) {
                Storage::disk('public')->deleteDirectory('vehicles/'.$created->id);
            }

            throw $e;
        }

        // Envuelto en "data", igual que update(): una sola forma por recurso.
        return (new VehicleResource($vehicle->load(['brand', 'model', 'type', 'color', 'images'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource(
            $vehicle->load(['brand', 'model', 'type', 'color', 'images']),
        );
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle->update($request->validated());

        return new VehicleResource($vehicle->load(['brand', 'model', 'type', 'color', 'images']));
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->schedulings()->exists() || $vehicle->vehicleRoutes()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: el vehiculo tiene programaciones o recorridos registrados.',
            ], 409);
        }

        $vehicle->delete();
        // Las filas de vehicleimages caen en cascada; los archivos hay que borrarlos.
        Storage::disk('public')->deleteDirectory('vehicles/'.$vehicle->id);

        return response()->json(null, 204);
    }

    /** Contadores de la barra superior de W04. */
    public function summary(): JsonResponse
    {
        return response()->json([
            'total' => Vehicle::count(),
            'operativo' => Vehicle::where('status', 'operativo')->count(),
            'mantenimiento' => Vehicle::where('status', 'mantenimiento')->count(),
            'baja' => Vehicle::where('status', 'baja')->count(),
        ]);
    }
}
