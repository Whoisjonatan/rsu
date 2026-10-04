<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrandRequest;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Brand::withCount('models', 'vehicles')->orderBy('name')->get()
        );
    }

    public function store(BrandRequest $request): JsonResponse
    {
        return response()->json(Brand::create($request->brandData()), 201);
    }

    public function update(BrandRequest $request, Brand $brand): JsonResponse
    {
        $brand->update($request->brandData());

        return response()->json($brand);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        if ($blocker = $brand->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $brand->deleteWithLogo();

        return response()->json(null, 204);
    }

    /** Logo de la marca (W03): subir, reemplazando el anterior, y eliminar. */
    public function uploadLogo(Request $request, Brand $brand): JsonResponse
    {
        $request->validate(['logo' => ['required', ...BrandRequest::logoRules()]]);

        $brand->replaceLogo($request->file('logo'));

        return response()->json($brand->fresh());
    }

    public function deleteLogo(Brand $brand): JsonResponse
    {
        $brand->replaceLogo(null);

        return response()->json($brand->fresh());
    }
}
