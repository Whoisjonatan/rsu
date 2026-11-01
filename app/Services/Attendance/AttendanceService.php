<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
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
     * Registra la entrada o la salida del trabajador que se identifica con
     * DNI y clave. La marcación es por turno: quien trabaja mañana y tarde
     * marca entrada y salida en cada uno, hasta tres turnos al día.
     *
     * @param  'entrada'|'salida'  $type
     * @return array{status: 'created'|'exit'|'already_marked'|'out_of_hours'|'rejected', kind: string, message: string, attendance?: array<string, mixed>, windows?: mixed, own?: bool}
     *
     * @throws ValidationException si las credenciales o la situación del trabajador no lo permiten
     */
    public function mark(string $dni, string $password, string $type = 'entrada'): array
    {
        $person = User::with('zone')->where('dni', $dni)->first();

        if (! $person || ! Hash::check($password, $person->password)) {
            throw ValidationException::withMessages(['dni' => 'DNI o clave incorrectos.']);
        }

        if ($person->status !== 'activo') {
            throw ValidationException::withMessages(['dni' => 'El trabajador está inactivo.']);
        }

        $now = CarbonImmutable::now();

        return $type === 'salida'
            ? $this->markExit($person, $now)
            : $this->markEntry($person, $now);
    }

    /**
     * Entrada a un turno. Si la persona está programada ese día solo vale el
     * horario de sus turnos: no marca en la tarde por una programación de la
     * mañana. Quien no está programado (personal de reserva) marca en el
     * turno cuya ventana esté abierta.
     *
     * @return array<string, mixed>
     */
    private function markEntry(User $person, CarbonImmutable $now): array
    {
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

        $own = $person->scheduledShiftsOn($today);
        $scope = $own->isNotEmpty() ? $own : null;
        $schedule = Schedule::forMarking($now, $scope);

        if (! $schedule) {
            return [
                'status' => 'out_of_hours',
                'kind' => 'entrada',
                'message' => $scope
                    ? 'Fuera del horario de entrada de tu turno.'
                    : 'Marcación fuera de horario.',
                'windows' => Schedule::markingWindows($now, $scope),
                'own' => (bool) $scope,
            ];
        }

        $existing = $person->attendances()
            ->with('schedule')
            ->whereDate('date', $today)
            ->where('schedule_id', $schedule->id)
            ->first();

        if ($existing) {
            return [
                'status' => 'already_marked',
                'kind' => 'entrada',
                'message' => "Ya registraste tu entrada al turno {$schedule->name} a las ".substr((string) $existing->check_in, 0, 5).'.',
                'attendance' => $this->present($existing, $person),
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
            'kind' => 'entrada',
            'message' => $status === 'presente'
                ? 'Entrada registrada: '.$now->format('H:i').'.'
                : 'Entrada registrada con tardanza: '.$now->format('H:i').'.',
            'attendance' => $this->present($attendance->load('schedule'), $person),
        ];
    }

    /**
     * Salida del turno con la entrada abierta. Se acepta desde la entrada
     * hasta Attendance::EXIT_CLOSES_AFTER minutos tras el fin del turno; antes
     * del fin queda como salida anticipada. Se mira también ayer porque la
     * salida del turno de noche puede caer pasada la medianoche.
     *
     * @return array<string, mixed>
     */
    private function markExit(User $person, CarbonImmutable $now): array
    {
        $recent = $person->attendances()
            ->with('schedule')
            ->whereDate('date', '>=', $now->subDay()->toDateString())
            ->whereDate('date', '<=', $now->toDateString())
            ->whereIn('status', ['presente', 'tarde'])
            ->whereNotNull('check_in')
            ->get()
            ->sortByDesc(fn (Attendance $row) => $row->date->toDateString().' '.$row->check_in)
            ->values();

        $open = $recent->first(fn (Attendance $row) => $row->isOpen()
            && $row->exitClosesAt()
            && $now->lessThanOrEqualTo($row->exitClosesAt()));

        if (! $open) {
            $last = $recent->first();
            $shift = $last?->schedule?->name;

            return match (true) {
                $last && $last->check_out && $last->date->isSameDay($now) => [
                    'status' => 'already_marked',
                    'kind' => 'salida',
                    'message' => "Ya registraste tu salida del turno {$shift} a las ".substr((string) $last->check_out, 0, 5).'.',
                    'attendance' => $this->present($last, $person),
                ],
                $last && $last->isOpen() && $last->exitClosesAt() => [
                    'status' => 'rejected',
                    'kind' => 'salida',
                    'message' => "El plazo para marcar la salida del turno {$shift} venció a las {$last->exitClosesAt()->format('H:i')}. Comuníquese con el supervisor.",
                ],
                default => [
                    'status' => 'rejected',
                    'kind' => 'salida',
                    'message' => 'No tienes una entrada registrada para marcar la salida.',
                ],
            };
        }

        $open->update(['check_out' => $now->format('H:i:s')]);
        $early = $open->exitState($now) === 'anticipada';

        return [
            'status' => 'exit',
            'kind' => 'salida',
            'message' => $early
                ? 'Salida anticipada registrada: '.$now->format('H:i').'.'
                : 'Salida registrada: '.$now->format('H:i').'.',
            'attendance' => $this->present($open, $person),
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
            ->groupBy(fn (Attendance $row) => $row->date->toDateString());

        $rows = [];
        $today = CarbonImmutable::now()->startOfDay();
        $holidays = Holiday::activeDatesBetween($from, $to);

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = CarbonImmutable::parse($day);
            $key = $date->toDateString();

            // Una fila por turno marcado: quien trabajó mañana y tarde sale dos veces.
            if ($marked->has($key)) {
                foreach (self::byShift($marked[$key]) as $row) {
                    $rows[] = $this->present($row, $person);
                }

                continue;
            }

            // Solo cuentan como ausencia los días hábiles ya transcurridos.
            if (! $this->isWorkingDay($date, $holidays) || $date->greaterThan($today)) {
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
            ->groupBy('user_id');

        $rows = [];
        $holidays = Holiday::activeDatesBetween($date, $date);

        foreach ($people as $person) {
            $who = ['person' => ['id' => $person->id, 'full_name' => $person->full_name, 'dni' => $person->dni]];

            if ($marked->has($person->id)) {
                foreach (self::byShift($marked[$person->id]) as $row) {
                    $rows[] = $this->present($row, $person) + $who;
                }

                continue;
            }

            // De vacaciones no se espera marcación, así que no es ausencia.
            if ($person->isOnVacationOn($date->toDateString())) {
                continue;
            }

            if (! $this->isWorkingDay($date, $holidays) || $date->greaterThan($today)) {
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

        // Con varios turnos al día hay más filas que días: se cuenta cada día
        // (y cada persona, en el reporte por fecha) una sola vez.
        $days = collect($rows)->map(fn (array $row) => $row['date'].'|'.($row['person']['id'] ?? ''))->unique();

        $summary = [
            'dias_habiles' => $days->count(),
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
            'check_out' => $attendance->check_out ? substr((string) $attendance->check_out, 0, 5) : null,
            // "anticipada", "sin_salida" o null (ver Attendance::exitState).
            'exit_state' => $attendance->exitState(),
            'shift_end' => $attendance->shiftEndsAt()?->format('H:i'),
            'zone' => $person->zone?->name,
            'status' => $attendance->status,
        ];
    }

    /**
     * Las marcaciones de un día en el orden de sus turnos.
     *
     * @param  Collection<int, Attendance>  $marks
     * @return Collection<int, Attendance>
     */
    private static function byShift($marks)
    {
        return $marks->sortBy(fn (Attendance $row) => [$row->schedule?->time_start, $row->check_in])->values();
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
            'check_out' => null,
            'exit_state' => null,
            'shift_end' => null,
            'zone' => $person->zone?->name,
            'status' => 'falta',
        ];
    }

    /**
     * Día hábil: de lunes a sábado y que no sea feriado activo. Cuando exista
     * el módulo de programación esto deberá salir de los días realmente
     * programados a la persona.
     *
     * @param  list<string>  $holidays  Feriados activos del rango consultado.
     */
    private function isWorkingDay(CarbonImmutable $date, array $holidays): bool
    {
        return $date->dayOfWeekIso <= 6 && ! in_array($date->toDateString(), $holidays, true);
    }
}
