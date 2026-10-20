<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalogos geograficos que alimentan el alta de zona (W13): departamento,
 * provincia y distrito, cada uno filtrable por el nivel de arriba.
 */
class GeographyController extends Controller
{
    /** Solo los departamentos con provincias cargadas: los demás no llevan a ningún distrito. */
    public function departments(): JsonResponse
    {
        return response()->json(
            Department::whereHas('provinces')->orderBy('name')->get(['id', 'name', 'code'])
        );
    }

    public function provinces(Request $request): JsonResponse
    {
        $provinces = Province::query()
            ->when($request->filled('department_id'), fn ($q) => $q
                ->where('department_id', $request->integer('department_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'department_id']);

        return response()->json($provinces);
    }

    public function districts(Request $request): JsonResponse
    {
        $districts = District::query()
            ->when($request->filled('province_id'), fn ($q) => $q
                ->where('province_id', $request->integer('province_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'province_id', 'department_id']);

        return response()->json($districts);
    }
}
