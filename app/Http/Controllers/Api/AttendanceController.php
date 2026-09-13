<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Marcacion de asistencia (W11) y su reporte (W12).
 */
class AttendanceController extends Controller
{
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

        $person = User::with('zone')->where('dni', $data['dni'])->first();

        if (! $person || ! Hash::check($data['password'], $person->password)) {
            throw ValidationException::withMessages(['dni' => 'DNI o clave incorrectos.']);
        }

        if ($person->status !== 'activo') {
            throw ValidationException::withMessages(['dni' => 'El trabajador está inactivo.']);
        }

        $now = CarbonImmutable::now();
        $today = $now->toDateString();

        if (! $person->hasActiveContractOn($today)) {
            throw ValidationException::withMessages([
                'dni' => 'No tienes un contrato vigente para marcar asistencia.',
            ]);
        }

        if ($person->isOnVacationOn($today)) {
            throw ValidationException::withMessages([
                'dni' => 'Estás de vacaciones: no corresponde marcar asistencia.',
            ]);
        }

        $existing = $person->attendances()->whereDate('date', $today)->first();

        if ($existing) {
            return response()->json([
                'already_marked' => true,
                'message' => 'Ya registraste tu asistencia hoy a las '.substr((string) $existing->check_in, 0, 5).'.',
                'attendance' => $this->present($existing->load('schedule'), $person),
            ]);
        }

        $schedule = Schedule::forMarking($now);

        if (! $schedule) {
            return response()->json([
                'message' => 'Marcación fuera de horario.',
                'windows' => Schedule::markingWindows($now),
            ], 422);
        }

        $window = $schedule->markingWindow($now);
        $status = $now->lessThanOrEqualTo($window['grace']) ? 'presente' : 'tarde';

        $attendance = $person->attendances()->create([
            'date' => $today,
            'schedule_id' => $schedule->id,
            'check_in' => $now->format('H:i:s'),
            'status' => $status,
        ]);

        return response()->json([
            'already_marked' => false,
            'message' => $status === 'presente'
                ? 'Asistencia registrada: '.$now->format('H:i').'.'
                : 'Asistencia registrada con tardanza: '.$now->format('H:i').'.',
            'attendance' => $this->present($attendance->load('schedule'), $person),
        ], 201);
    }

    /** Ventanas de marcacion de hoy, para que el terminal las muestre. */
    public function windows(): JsonResponse
    {
        return response()->json([
            'now' => CarbonImmutable::now()->format('H:i'),
            'windows' => Schedule::markingWindows(CarbonImmutable::now()),
        ]);
    }

    /**
     * Reporte por persona en un rango de fechas (pestaña "Por persona" de W12).
     *
     * Los dias habiles del rango sin marcacion se devuelven como ausentes, que
     * es lo que W12 muestra con un guion en la hora.
     */
    public function byPerson(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:presente,tarde,falta'],
        ]);

        $person = User::with('zone')->findOrFail($data['user_id']);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();

        $marked = $person->attendances()
            ->with('schedule')
            ->between($from->toDateString(), $to->toDateString())
            ->get()
            ->keyBy(fn (Attendance $row) => $row->date->toDateString());

        $rows = [];
        $today = CarbonImmutable::now()->startOfDay();

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = CarbonImmutable::parse($day);
            $key = $date->toDateString();

            if ($marked->has($key)) {
                $rows[] = $this->present($marked[$key], $person);

                continue;
            }

            // Solo cuentan como ausencia los dias habiles ya transcurridos.
            if (! $this->isWorkingDay($date) || $date->greaterThan($today)) {
                continue;
            }

            $rows[] = [
                'id' => null,
                'date' => $key,
                'schedule' => null,
                'check_in' => null,
                'zone' => $person->zone?->name,
                'status' => 'falta',
            ];
        }

        // Los indicadores describen el rango completo; el filtro solo recorta
        // la tabla, así que se resume antes de aplicarlo.
        $summary = $this->summarize($rows);

        if (! empty($data['status'])) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['status'] === $data['status']));
        }

        return response()->json([
            'person' => [
                'id' => $person->id,
                'full_name' => $person->full_name,
                'dni' => $person->dni,
            ],
            'data' => $rows,
            'summary' => $summary,
        ]);
    }

    /**
     * Reporte de un dia concreto para todo el personal (pestaña "Por fecha").
     */
    public function byDate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'status' => ['nullable', 'in:presente,tarde,falta'],
        ]);

        $date = CarbonImmutable::parse($data['date'])->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();

        $people = User::with('zone')
            ->active()
            ->whereHas('contracts', fn ($q) => $q->activeOn($date->toDateString()))
            ->orderBy('lastname')
            ->orderBy('name')
            ->get();

        $marked = Attendance::with('schedule')
            ->whereDate('date', $date->toDateString())
            ->get()
            ->keyBy('user_id');

        $rows = [];

        foreach ($people as $person) {
            if ($marked->has($person->id)) {
                $rows[] = $this->present($marked[$person->id], $person) + [
                    'person' => ['id' => $person->id, 'full_name' => $person->full_name, 'dni' => $person->dni],
                ];

                continue;
            }

            // De vacaciones no se espera marcacion, así que no es ausencia.
            if ($person->isOnVacationOn($date->toDateString())) {
                continue;
            }

            if (! $this->isWorkingDay($date) || $date->greaterThan($today)) {
                continue;
            }

            $rows[] = [
                'id' => null,
                'date' => $date->toDateString(),
                'schedule' => null,
                'check_in' => null,
                'zone' => $person->zone?->name,
                'status' => 'falta',
                'person' => ['id' => $person->id, 'full_name' => $person->full_name, 'dni' => $person->dni],
            ];
        }

        $summary = $this->summarize($rows);

        if (! empty($data['status'])) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['status'] === $data['status']));
        }

        return response()->json([
            'data' => $rows,
            'summary' => $summary,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attendanceOrRow
     * @return array<string, mixed>
     */
    private function present(Attendance $attendance, User $person): array
    {
        return [
            'id' => $attendance->id,
            'date' => $attendance->date->toDateString(),
            'schedule' => $attendance->schedule?->name,
            'check_in' => $attendance->check_in ? substr((string) $attendance->check_in, 0, 5) : null,
            'zone' => $person->zone?->name,
            'status' => $attendance->status,
        ];
    }

    /**
     * Los indicadores de W12.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarize(array $rows): array
    {
        $count = fn (string $status) => count(array_filter($rows, fn ($row) => $row['status'] === $status));

        return [
            'dias_habiles' => count($rows),
            'presente' => $count('presente'),
            'tarde' => $count('tarde'),
            'falta' => $count('falta'),
        ];
    }

    /**
     * Dia habil: de lunes a sabado. Cuando exista el modulo de programacion
     * esto debera salir de los dias realmente programados a la persona.
     */
    private function isWorkingDay(CarbonImmutable $date): bool
    {
        return $date->dayOfWeekIso <= 6;
    }
}
