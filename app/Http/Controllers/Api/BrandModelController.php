<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrandModelRequest;
use App\Models\BrandModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function store(BrandModelRequest $request): JsonResponse
    {
        $model = BrandModel::create($request->validated());

        return response()->json($model->load('brand'), 201);
    }

    public function update(BrandModelRequest $request, BrandModel $brandmodel): JsonResponse
    {
        $brandmodel->update($request->validated());

        return response()->json($brandmodel->load('brand'));
    }

    public function destroy(BrandModel $brandmodel): JsonResponse
    {
        if ($blocker = $brandmodel->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $brandmodel->delete();

        return response()->json(null, 204);
    }
}
