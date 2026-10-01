<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
        }

        $brand->delete();

        return response()->json(null, 204);
    }

    /** Logo de la marca (W03): subir, reemplazando el anterior, y eliminar. */
    public function uploadLogo(Request $request, Brand $brand): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
        }

        $brand->update(['logo' => $request->file('logo')->store('brands', 'public')]);

        return response()->json($brand->fresh());
    }

    public function deleteLogo(Brand $brand): JsonResponse
    {
        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
            $brand->update(['logo' => null]);
        }

        return response()->json($brand->fresh());
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
