<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Sector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catalogos geograficos que alimentan el alta de zona (W13).
 */
class GeographyController extends Controller
{
    public function districts(Request $request): JsonResponse
    {
        $districts = District::query()
            ->when($request->filled('province_id'), fn ($q) => $q
                ->where('province_id', $request->integer('province_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'province_id', 'department_id']);

        return response()->json($districts);
    }

    public function sectors(Request $request): JsonResponse
    {
        $sectors = Sector::query()
            ->when($request->filled('district_id'), fn ($q) => $q
                ->where('district_id', $request->integer('district_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'area', 'district_id']);

        return response()->json($sectors);
    }

    public function storeSector(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'district_id' => ['required', 'exists:districts,id'],
        ]);

        $exists = Sector::where('district_id', $data['district_id'])
            ->where('name', $data['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Ese distrito ya tiene un sector con ese nombre.',
                'errors' => ['name' => ['Ese distrito ya tiene un sector con ese nombre.']],
            ], 422);
        }

        return response()->json(Sector::create($data), 201);
    }

    public function updateSector(Request $request, Sector $sector): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'district_id' => ['required', 'exists:districts,id'],
        ]);

        $sector->update($data);

        return response()->json($sector);
    }

    public function destroySector(Sector $sector): JsonResponse
    {
        if ($sector->zones()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: el sector tiene zonas asociadas.',
            ], 409);
        }

        $sector->delete();

        return response()->json(null, 204);
    }
}
