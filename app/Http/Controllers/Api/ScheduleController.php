<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleRequest;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;

/**
 * Turnos (W16): nombre y rango horario, como pide el informe.
 */
class ScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Schedule::withCount('schedulings')->orderBy('time_start')->get()
        );
    }

    public function store(ScheduleRequest $request): JsonResponse
    {
        return response()->json(Schedule::create($request->validated()), 201);
    }

    public function update(ScheduleRequest $request, Schedule $schedule): JsonResponse
    {
        $schedule->update($request->validated());

        return response()->json($schedule);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        if ($blocker = $schedule->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $schedule->delete();

        return response()->json(null, 204);
    }
}
