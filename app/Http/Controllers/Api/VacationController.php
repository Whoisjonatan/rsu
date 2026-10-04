<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VacationRequest;
use App\Models\User;
use App\Models\Vacation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vacaciones del personal (W10). Anidado bajo /people/{person}/vacations.
 * Las reglas del informe están en VacationRequest.
 */
class VacationController extends Controller
{
    public function index(Request $request, User $person): JsonResponse
    {
        return response()->json([
            'data' => $person->vacations()->orderByDesc('start_date')->get(),
            'summary' => Vacation::summaryFor($person, $request->integer('year', (int) now()->year)),
        ]);
    }

    public function store(VacationRequest $request, User $person): JsonResponse
    {
        return response()->json($person->vacations()->create($request->vacationData()), 201);
    }

    public function update(VacationRequest $request, User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        $vacation->update($request->vacationData());

        return response()->json($vacation->fresh());
    }

    public function destroy(User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        $vacation->delete();

        return response()->json(null, 204);
    }
}
