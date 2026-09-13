<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandModelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $models = BrandModel::with('brand')
            ->withCount('vehicles')
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->orderBy('name')
            ->get();

        return response()->json($models);
    }

    public function store(Request $request): JsonResponse
    {
        $model = BrandModel::create($this->validated($request));

        return response()->json($model->load('brand'), 201);
    }

    public function update(Request $request, BrandModel $brandmodel): JsonResponse
    {
        $brandmodel->update($this->validated($request, $brandmodel->id));

        return response()->json($brandmodel->load('brand'));
    }

    public function destroy(BrandModel $brandmodel): JsonResponse
    {
        if ($brandmodel->vehicles()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay vehiculos con este modelo.',
            ], 409);
        }

        $brandmodel->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('brandmodels', 'name')
                    ->where('brand_id', $request->input('brand_id'))
                    ->ignore($id),
            ],
            'code' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['required', 'exists:brands,id'],
        ], [
            'name.unique' => 'Esa marca ya tiene un modelo con ese nombre.',
        ]);
    }
}
