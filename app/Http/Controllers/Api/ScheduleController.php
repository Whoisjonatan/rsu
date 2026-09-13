<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        return response()->json(Schedule::create($this->validated($request)), 201);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $schedule->update($this->validated($request, $schedule->id));

        return response()->json($schedule);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        if ($schedule->schedulings()->exists() || $schedule->vehicleRoutes()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: el turno tiene programaciones o recorridos.',
            ], 409);
        }

        $schedule->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('schedules', 'name')->ignore($id)],
            'time_start' => ['required', 'date_format:H:i'],
            'time_end' => ['required', 'date_format:H:i', 'after:time_start'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'time_end.after' => 'La hora de fin debe ser posterior a la de inicio.',
            'name.unique' => 'Ya existe un turno con ese nombre.',
        ]);
    }
}
