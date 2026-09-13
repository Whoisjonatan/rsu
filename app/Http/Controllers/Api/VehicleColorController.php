<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleColor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleColorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            VehicleColor::withCount('vehicles')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(VehicleColor::create($this->validated($request)), 201);
    }

    public function update(Request $request, VehicleColor $vehiclecolor): JsonResponse
    {
        $vehiclecolor->update($this->validated($request, $vehiclecolor->id));

        return response()->json($vehiclecolor);
    }

    public function destroy(VehicleColor $vehiclecolor): JsonResponse
    {
        if ($vehiclecolor->vehicles()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay vehiculos con este color.',
            ], 409);
        }

        $vehiclecolor->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('vehiclecolors', 'name')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
            'red' => ['required', 'integer', 'min:0', 'max:255'],
            'green' => ['required', 'integer', 'min:0', 'max:255'],
            'blue' => ['required', 'integer', 'min:0', 'max:255'],
        ]);
    }
}
