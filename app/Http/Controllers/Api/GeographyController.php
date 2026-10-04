<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SectorRequest;
use App\Models\District;
use App\Models\Sector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function storeSector(SectorRequest $request): JsonResponse
    {
        return response()->json(Sector::create($request->validated()), 201);
    }

    public function updateSector(SectorRequest $request, Sector $sector): JsonResponse
    {
        $sector->update($request->validated());

        return response()->json($sector);
    }

    public function destroySector(Sector $sector): JsonResponse
    {
        if ($blocker = $sector->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $sector->delete();

        return response()->json(null, 204);
    }
}
