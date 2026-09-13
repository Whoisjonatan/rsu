<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Brand::withCount('models', 'vehicles')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Brand::create($this->validated($request)), 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $brand->update($this->validated($request, $brand->id));

        return response()->json($brand);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        if ($brand->vehicles()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay vehiculos con esta marca.',
            ], 409);
        }

        $brand->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('brands', 'name')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
