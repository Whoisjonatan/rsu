<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Services\Attendance\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marcacion de asistencia (W11) y su reporte (W12). La logica esta en
 * AttendanceService, que comparte con las vistas Blade.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendances) {}

    /**
     * Terminal de marcacion. Es publico a proposito: W11 no tiene navegacion
     * lateral, es un punto de fichaje compartido donde cada trabajador se
     * identifica con su DNI y su clave, como pide el informe.
     */
    public function mark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dni' => ['required', 'digits:8'],
            'password' => ['required', 'string'],
        ], [
            'dni.digits' => 'El DNI debe tener 8 dígitos.',
        ]);

        $result = $this->attendances->mark($data['dni'], $data['password']);

        return match ($result['status']) {
            'out_of_hours' => response()->json([
                'message' => $result['message'],
                'windows' => $result['windows'],
            ], 422),
            'already_marked' => response()->json([
                'already_marked' => true,
                'message' => $result['message'],
                'attendance' => $result['attendance'],
            ]),
            default => response()->json([
                'already_marked' => false,
                'message' => $result['message'],
                'attendance' => $result['attendance'],
            ], 201),
        };
    }

    /** Ventanas de marcacion de hoy, para que el terminal las muestre. */
    public function windows(): JsonResponse
    {
        return response()->json([
            'now' => CarbonImmutable::now()->format('H:i'),
            'windows' => Schedule::markingWindows(CarbonImmutable::now()),
        ]);
    }

    /** Reporte por persona en un rango de fechas (pestaña "Por persona" de W12). */
    public function byPerson(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:presente,tarde,falta'],
        ]);

        return response()->json($this->attendances->byPerson(
            (int) $data['user_id'], $data['from'], $data['to'], $data['status'] ?? null,
        ));
    }

    /** Reporte de un dia concreto para todo el personal (pestaña "Por fecha"). */
    public function byDate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'status' => ['nullable', 'in:presente,tarde,falta'],
        ]);

        return response()->json($this->attendances->byDate($data['date'], $data['status'] ?? null));
    }
}
