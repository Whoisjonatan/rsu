<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            VehicleType::withCount('vehicles')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(VehicleType::create($this->validated($request)), 201);
    }

    public function update(Request $request, VehicleType $vehicletype): JsonResponse
    {
        $vehicletype->update($this->validated($request, $vehicletype->id));

        return response()->json($vehicletype);
    }

    public function destroy(VehicleType $vehicletype): JsonResponse
    {
        if ($vehicletype->vehicles()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay vehiculos de este tipo.',
            ], 409);
        }

        $vehicletype->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('vehicletypes', 'name')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
