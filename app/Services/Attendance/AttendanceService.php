<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Marcación de asistencia (W11) y su reporte (W12).
 *
 * Vive aquí y no en un controlador porque la usan dos: el API (la app móvil)
 * y las vistas Blade del panel. Devuelve arreglos simples; cada controlador
 * decide si los manda como JSON o los pinta.
 */
class AttendanceService
{
    /**
     * Registra la asistencia del trabajador que se identifica con DNI y clave.
     *
     * @return array{status: 'created'|'already_marked'|'out_of_hours', message: string, attendance?: array<string, mixed>, windows?: mixed}
     *
     * @throws ValidationException si las credenciales o la situación del trabajador no lo permiten
     */
    public function mark(string $dni, string $password): array
    {
        $person = User::with('zone')->where('dni', $dni)->first();

        if (! $person || ! Hash::check($password, $person->password)) {
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
            return [
                'status' => 'already_marked',
                'message' => 'Ya registraste tu asistencia hoy a las '.substr((string) $existing->check_in, 0, 5).'.',
                'attendance' => $this->present($existing->load('schedule'), $person),
            ];
        }

        $schedule = Schedule::forMarking($now);

        if (! $schedule) {
            return [
                'status' => 'out_of_hours',
                'message' => 'Marcación fuera de horario.',
                'windows' => Schedule::markingWindows($now),
            ];
        }

        $window = $schedule->markingWindow($now);
        $status = $now->lessThanOrEqualTo($window['grace']) ? 'presente' : 'tarde';

        $attendance = $person->attendances()->create([
            'date' => $today,
            'schedule_id' => $schedule->id,
            'check_in' => $now->format('H:i:s'),
            'status' => $status,
        ]);

        return [
            'status' => 'created',
            'message' => $status === 'presente'
                ? 'Asistencia registrada: '.$now->format('H:i').'.'
                : 'Asistencia registrada con tardanza: '.$now->format('H:i').'.',
            'attendance' => $this->present($attendance->load('schedule'), $person),
        ];
    }

    /**
     * Reporte por persona en un rango de fechas (pestaña "Por persona" de W12).
     *
     * Los días hábiles del rango sin marcación se devuelven como ausentes, que
     * es lo que W12 muestra con un guion en la hora.
     *
     * @return array{person: array<string, mixed>, data: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function byPerson(int $userId, string $from, string $to, ?string $status = null): array
    {
        $person = User::with('zone')->findOrFail($userId);
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();

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

            // Solo cuentan como ausencia los días hábiles ya transcurridos.
            if (! $this->isWorkingDay($date) || $date->greaterThan($today)) {
                continue;
            }

            $rows[] = $this->absence($key, $person);
        }

        return [
            'person' => [
                'id' => $person->id,
                'full_name' => $person->full_name,
                'dni' => $person->dni,
            ],
            ...$this->summarizeAndFilter($rows, $status),
        ];
    }

    /**
     * Reporte de un día concreto para todo el personal (pestaña "Por fecha").
     *
     * @return array{data: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function byDate(string $date, ?string $status = null): array
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
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
            $who = ['person' => ['id' => $person->id, 'full_name' => $person->full_name, 'dni' => $person->dni]];

            if ($marked->has($person->id)) {
                $rows[] = $this->present($marked[$person->id], $person) + $who;

                continue;
            }

            // De vacaciones no se espera marcación, así que no es ausencia.
            if ($person->isOnVacationOn($date->toDateString())) {
                continue;
            }

            if (! $this->isWorkingDay($date) || $date->greaterThan($today)) {
                continue;
            }

            $rows[] = $this->absence($date->toDateString(), $person) + $who;
        }

        return $this->summarizeAndFilter($rows, $status);
    }

    /**
     * Los indicadores describen el rango completo; el filtro de estado solo
     * recorta la tabla, así que se resume antes de aplicarlo.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{data: list<array<string, mixed>>, summary: array<string, int>}
     */
    private function summarizeAndFilter(array $rows, ?string $status): array
    {
        $count = fn (string $value) => count(array_filter($rows, fn ($row) => $row['status'] === $value));

        $summary = [
            'dias_habiles' => count($rows),
            'presente' => $count('presente'),
            'tarde' => $count('tarde'),
            'falta' => $count('falta'),
        ];

        if (filled($status)) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['status'] === $status));
        }

        return ['data' => $rows, 'summary' => $summary];
    }

    /**
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
     * @return array<string, mixed>
     */
    private function absence(string $date, User $person): array
    {
        return [
            'id' => null,
            'date' => $date,
            'schedule' => null,
            'check_in' => null,
            'zone' => $person->zone?->name,
            'status' => 'falta',
        ];
    }

    /**
     * Día hábil: de lunes a sábado. Cuando exista el módulo de programación
     * esto deberá salir de los días realmente programados a la persona.
     */
    private function isWorkingDay(CarbonImmutable $date): bool
    {
        return $date->dayOfWeekIso <= 6;
    }
}
