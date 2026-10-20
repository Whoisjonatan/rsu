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
 * Las reglas del informe están en VacationRequest; toda solicitud entra
 * pendiente y se aprueba o rechaza con approve/reject.
 */
class VacationController extends Controller
{
    public function index(Request $request, User $person): JsonResponse
    {
        Vacation::closeFinished($person->id);

        return response()->json([
            'data' => $person->vacations()->orderByDesc('start_date')->get(),
            'summary' => Vacation::summaryFor($person, $request->integer('year', (int) now()->year)),
        ]);
    }

    public function store(VacationRequest $request, User $person): JsonResponse
    {
        return response()->json(
            $person->vacations()->create([...$request->vacationData(), 'status' => 'pendientes']),
            201,
        );
    }

    public function update(VacationRequest $request, User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->editBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $vacation->update($request->vacationData());

        return response()->json($vacation->fresh());
    }

    public function approve(User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->decisionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        if (! $person->isVacationEligible()) {
            return response()->json(['message' => 'Solo el personal nombrado o con contrato permanente puede tener vacaciones.'], 409);
        }

        $vacation->update(['status' => 'programadas']);

        return response()->json($vacation);
    }

    public function reject(User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->decisionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $vacation->update(['status' => 'rechazadas']);

        return response()->json($vacation);
    }

    public function destroy(User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $vacation->delete();

        return response()->json(null, 204);
    }
}
