<?php

namespace App\Services\Scheduling;

use App\Models\GeneralScheduling;
use App\Models\Holiday;
use App\Models\Scheduling;
use App\Models\StaffGroup;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Las reglas de una programacion, en un solo sitio.
 *
 * Viven fuera del FormRequest porque hay tres entradas distintas a la misma
 * validacion y ninguna puede quedarse con una copia propia:
 *
 * - el alta y la edicion de W18 (SchedulingRequest),
 * - la checklist en vivo del panel derecho de W18 (validar sin guardar),
 * - la programacion masiva de W19 (BulkScheduling), que valida N grupos en
 *   una sola peticion.
 */
class SchedulingRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'zone_id' => ['required', 'exists:zones,id'],
            // Solo informa de qué plantilla salió; los datos viajan completos.
            'staffgroup_id' => ['nullable', 'exists:staffgroups,id'],
            'route_id' => ['nullable', Rule::exists('routes', 'id')],
            'vehicle_id' => [
                'required',
                // Un vehiculo de baja no puede programarse; en mantenimiento
                // tampoco, porque W21 lo bloquearia el dia del recorrido.
                Rule::exists('vehicles', 'id')->where('status', 'operativo'),
            ],
            'schedule_id' => ['required', 'exists:schedules,id'],
            'driver_id' => ['required', 'exists:users,id'],
            // Cuántos caben lo decide la capacidad del vehículo (checkCapacity).
            'helper_ids' => ['nullable', 'array', 'max:10'],
            'helper_ids.*' => ['integer', 'exists:users,id'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            // Feriados que esta programación sí trabaja (ver Scheduling::occurrenceDates).
            'worked_holidays' => ['nullable', 'array'],
            'worked_holidays.*' => ['date_format:Y-m-d'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // "reprogramada" no se elige: la pone Scheduling::reschedule().
            'status' => ['required', Rule::in(['activa', 'inactiva', 'reprogramada'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'vehicle_id.exists' => 'El vehículo no existe o no está operativo.',
            'helper_ids.*.exists' => 'Uno de los ayudantes no existe.',
            'weekdays.required' => 'Marca al menos un día de la semana.',
            'weekdays.min' => 'Marca al menos un día de la semana.',
            'end_date.after_or_equal' => 'La fecha de término no puede ser anterior a la de inicio.',
        ];
    }

    /** Dias unicos y ordenados; el formulario puede mandarlos de cualquier forma. */
    public static function normalizeWeekdays(mixed $weekdays): array
    {
        if (! is_array($weekdays)) {
            return [];
        }

        $days = array_values(array_unique(array_map('intval', $weekdays)));
        sort($days);

        return $days;
    }

    /**
     * Ayudantes sin vacíos ni repetidos, en el orden en que se eligieron (el
     * formulario manda un select por asiento y los libres llegan vacíos).
     *
     * @return list<int>
     */
    public static function normalizeHelpers(mixed $helpers): array
    {
        if (! is_array($helpers)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($helpers, fn ($id) => filled($id)))));
    }

    /**
     * Programar otra vez el mismo grupo en el mismo turno, con fechas que se
     * cruzan con una programación general que sigue en pie, no crea otra
     * general: los días nuevos se suman a esa. Devuelve la que se ampliaría,
     * o null si toca crear una nueva.
     */
    public static function mergeTarget(array $data): ?GeneralScheduling
    {
        if (blank($data['staffgroup_id'] ?? null) || blank($data['schedule_id'] ?? null)) {
            return null;
        }

        [$start, $end] = self::dateRange($data);

        if ($start === null || $start > $end) {
            return null;
        }

        return GeneralScheduling::query()
            ->where('staffgroup_id', (int) $data['staffgroup_id'])
            ->where('schedule_id', (int) $data['schedule_id'])
            ->whereHas('schedulings', fn ($q) => $q->where('status', '!=', 'finalizada'))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Días del rango que el grupo ya tiene programados en ese turno, en
     * cualquier estado. No se vuelven a crear: cada día es una programación
     * propia y se cambia desde la pestaña Detallada, no registrando encima.
     *
     * @return list<string>
     */
    public static function programmedDates(array $data): array
    {
        if (blank($data['staffgroup_id'] ?? null) || blank($data['schedule_id'] ?? null)) {
            return [];
        }

        [$start, $end] = self::dateRange($data);

        if ($start === null || $start > $end) {
            return [];
        }

        $holidays = Holiday::activeDatesBetween($start, $end);

        return self::normalizeDates(Scheduling::query()
            ->where('staffgroup_id', (int) $data['staffgroup_id'])
            ->where('schedule_id', (int) $data['schedule_id'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get()
            ->flatMap(fn (Scheduling $scheduling) => $scheduling->occurrenceDates($holidays))
            ->filter(fn (string $date) => $date >= $start && $date <= $end)
            ->all());
    }

    /**
     * Lo que queda la general $target al sumarle lo nuevo: el rango cubre los
     * dos y los días de la semana se suman. Los feriados trabajados de lo
     * nuevo mandan en su rango; fuera de él se conservan los que ya tenía.
     *
     * @return array<string, mixed>
     */
    public static function mergeInto(GeneralScheduling $target, array $data): array
    {
        [$start, $end] = self::dateRange($data);

        $worked = $target->worked_holidays ?? [];

        if (array_key_exists('worked_holidays', $data)) {
            $worked = [
                ...array_filter($worked, fn (string $date) => $date < $start || $date > $end),
                ...($data['worked_holidays'] ?? []),
            ];
        }

        return [
            ...$data,
            'start_date' => min($target->start_date->toDateString(), $start),
            'end_date' => max($target->end_date->toDateString(), $end),
            'weekdays' => self::normalizeWeekdays([
                ...array_map('intval', $target->weekdays ?? []),
                ...self::normalizeWeekdays($data['weekdays'] ?? null),
            ]),
            'worked_holidays' => self::normalizeDates($worked),
        ];
    }

    /**
     * Fechas Y-m-d únicas y ordenadas.
     *
     * @return list<string>
     */
    public static function normalizeDates(mixed $dates): array
    {
        if (! is_array($dates)) {
            return [];
        }

        $dates = array_values(array_unique(array_filter($dates, 'is_string')));
        sort($dates);

        return $dates;
    }

    /**
     * Rango del formulario como Y-m-d, o [null, null] si no se entiende.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function dateRange(array $data): array
    {
        // Carbon::parse('') sería hoy, no "sin fecha".
        if (blank($data['start_date'] ?? null) || blank($data['end_date'] ?? null)) {
            return [null, null];
        }

        try {
            return [
                Carbon::parse((string) ($data['start_date'] ?? ''))->toDateString(),
                Carbon::parse((string) ($data['end_date'] ?? ''))->toDateString(),
            ];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /**
     * Validador completo, reglas base mas las cuatro del informe.
     *
     * @param  list<int>  $ignoreGroupIds  Grupos cuya tripulación no reserva a nadie (ver checkGroupMembership).
     */
    public static function validator(array $data, ?int $ignoreId = null, array $ignoreGroupIds = []): ValidatorContract
    {
        $data['weekdays'] = self::normalizeWeekdays($data['weekdays'] ?? null);
        $data['helper_ids'] = self::normalizeHelpers($data['helper_ids'] ?? [$data['helper_id'] ?? null]);

        $validator = Validator::make($data, self::rules(), self::messages());

        self::attachBusinessRules($validator, $ignoreId, $ignoreGroupIds);

        return $validator;
    }

    /**
     * Las cuatro validaciones del informe. Se añaden sobre un validador ya
     * hecho para que el FormRequest pueda usarlas desde withValidator().
     *
     * @param  list<int>  $ignoreGroupIds  Grupos cuya tripulación no reserva a nadie (ver checkGroupMembership).
     */
    public static function attachBusinessRules(ValidatorContract $validator, ?int $ignoreId = null, array $ignoreGroupIds = []): void
    {
        $validator->after(function (ValidatorContract $validator) use ($ignoreId, $ignoreGroupIds) {
            // Sin lo básico validado, lo de abajo no tiene datos fiables.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $data['weekdays'] = self::normalizeWeekdays($data['weekdays'] ?? null);
            $data['helper_ids'] = self::normalizeHelpers($data['helper_ids'] ?? null);

            self::checkZone($validator, $data, $ignoreId);
            self::checkCrew($validator, $data);

            $dates = self::occurrenceDates($data);

            if ($dates === [] && ($data['skip_dates'] ?? []) !== [] && self::occurrenceDates([...$data, 'skip_dates' => []]) !== []) {
                $validator->errors()->add(
                    'start_date',
                    'El grupo ya tiene programados en ese turno todos los días de ese rango: no hay días nuevos que programar.',
                );

                return;
            }

            if ($dates === []) {
                $validator->errors()->add(
                    'weekdays',
                    'Los días elegidos no caen dentro del rango de fechas o son todos feriados: la programación no tendría ningún recorrido.',
                );

                return;
            }

            $driver = User::find((int) $data['driver_id']);
            self::checkPerson($validator, (int) $data['driver_id'], 'driver_id', trim('El conductor '.$driver?->full_name), $dates);

            foreach (User::findMany($data['helper_ids']) as $helper) {
                self::checkPerson($validator, $helper->id, 'helper_ids', "El ayudante {$helper->full_name}", $dates);
            }

            self::checkOverlap($validator, $data, $dates, $ignoreId);
            self::checkGroupMembership($validator, $data, $dates, $ignoreGroupIds);
        });
    }

    /**
     * Fechas reales que cubriria la programacion. Se arma un Scheduling sin
     * guardar para no repetir la logica de expansion de dias.
     *
     * `skip_dates` son los días que el grupo ya tiene programados en ese
     * turno (ver programmedDates): no se validan ni se vuelven a crear.
     *
     * @return list<string>
     */
    public static function occurrenceDates(array $data): array
    {
        $dates = (new Scheduling([
            'weekdays' => self::normalizeWeekdays($data['weekdays'] ?? null),
            'worked_holidays' => self::normalizeDates($data['worked_holidays'] ?? null),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]))->occurrenceDates();

        return array_values(array_diff($dates, self::normalizeDates($data['skip_dates'] ?? null)));
    }

    /**
     * Una zona inactiva no recibe programaciones nuevas. La que ya la tenía
     * puede seguir editándose (incluida la edición masiva de W19) mientras no
     * cambie de zona.
     */
    private static function checkZone(ValidatorContract $validator, array $data, ?int $ignoreId): void
    {
        $zone = Zone::find($data['zone_id'] ?? null);

        if (! $zone || $zone->isActive()) {
            return;
        }

        $alreadyThere = $ignoreId && Scheduling::whereKey($ignoreId)->where('zone_id', $zone->id)->exists();

        if (! $alreadyThere) {
            $validator->errors()->add('zone_id', "La zona \"{$zone->name}\" está inactiva: no se puede programar.");
        }
    }

    /**
     * La tripulación cabe en el vehículo: un conductor más tantos ayudantes
     * como permita su capacidad de ocupantes. El conductor no puede ir
     * también de ayudante.
     */
    private static function checkCrew(ValidatorContract $validator, array $data): void
    {
        if (in_array((int) $data['driver_id'], $data['helper_ids'], true)) {
            $validator->errors()->add('helper_ids', 'El conductor no puede ir también como ayudante.');
        }

        $vehicle = Vehicle::find($data['vehicle_id'] ?? null);

        if ($vehicle && 1 + count($data['helper_ids']) > max(1, $vehicle->occupant_capacity)) {
            $validator->errors()->add('helper_ids', sprintf(
                'El vehículo %s admite %s: 1 conductor + %s.',
                $vehicle->code,
                $vehicle->occupant_capacity === 1 ? '1 persona' : "{$vehicle->occupant_capacity} personas",
                $vehicle->helperSeats() === 1 ? '1 ayudante' : "{$vehicle->helperSeats()} ayudantes",
            ));
        }
    }

    /**
     * Validaciones 1 y 2: solo personal contratado y sin vacaciones.
     *
     * @param  list<string>  $dates
     */
    private static function checkPerson(ValidatorContract $validator, int $personId, string $field, string $label, array $dates): void
    {
        $person = User::with(['contracts', 'vacations'])->find($personId);

        if (! $person) {
            return;
        }

        $withoutContract = $person->datesWithoutContract($dates);

        if ($withoutContract !== []) {
            $validator->errors()->add($field, sprintf(
                '%s no tiene contrato vigente en %s.',
                $label,
                self::describeDates($withoutContract, count($dates)),
            ));
        }

        $onVacation = $person->datesOnVacation($dates);

        if ($onVacation !== []) {
            $validator->errors()->add($field, sprintf(
                '%s está de vacaciones en %s.',
                $label,
                self::describeDates($onVacation, count($dates)),
            ));
        }
    }

    /**
     * Validacion 4. El informe dice "que coincidan en Turno, Vehiculo,
     * Conductor y Ayudantes"; se aplica por recurso y no a los cuatro a la vez,
     * porque lo que no puede pasar es que un mismo vehiculo o una misma persona
     * esten en dos sitios en el mismo turno. Exigir que coincidieran los cuatro
     * dejaria pasar al mismo conductor con dos vehiculos distintos a la vez.
     *
     * Coincidir es tener recorrido el mismo día: dos programaciones del mismo
     * mes no chocan si una es de lunes y la otra de martes. El aviso dice en
     * qué fechas chocan.
     *
     * Cada día es una programación, así que los choques con los días de una
     * misma general se juntan en un solo aviso que la nombra; si es un solo
     * día, se nombra ese día.
     *
     * @param  list<string>  $dates
     */
    private static function checkOverlap(ValidatorContract $validator, array $data, array $dates, ?int $ignoreId): void
    {
        $candidates = Scheduling::query()
            ->inForce()
            ->where('schedule_id', (int) $data['schedule_id'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->with(['general', 'vehicle', 'helpers', 'zone'])
            ->get();

        $holidays = Holiday::activeDatesBetween($data['start_date'], $data['end_date']);
        $crew = self::crew($data);
        // [campo, quién, clave de la general] => [campo, quién, códigos, zona, fechas]
        $found = [];

        foreach ($candidates as $other) {
            $shared = array_values(array_intersect($dates, $other->occurrenceDates($holidays)));

            if ($shared === []) {
                continue;
            }

            $clashes = [];

            if ($other->vehicle_id === (int) $data['vehicle_id']) {
                $clashes[] = ['vehicle_id', 'El vehículo '.($other->vehicle->plate ?? '')];
            }

            $otherCrew = [$other->driver_id, ...$other->helperIds()];

            foreach ($crew as $personId => [$field, $who]) {
                if (in_array($personId, $otherCrew, true)) {
                    $clashes[] = [$field, $who];
                }
            }

            foreach ($clashes as [$field, $what]) {
                $key = $field.'|'.$what.'|'.($other->general_scheduling_id ?? 's'.$other->id);
                $found[$key] ??= [$field, trim($what), $other->general?->code, [], $other->zone->name ?? '—', []];
                $found[$key][3][] = $other->code;
                array_push($found[$key][5], ...$shared);
            }
        }

        foreach ($found as [$field, $what, $generalCode, $codes, $zone, $shared]) {
            $validator->errors()->add($field, sprintf(
                '%s ya está programado en ese turno (%s, zona %s) en: %s.',
                $what,
                count($codes) === 1 || ! $generalCode ? implode(', ', $codes) : $generalCode,
                $zone,
                self::dateList($shared),
            ));
        }
    }

    /**
     * Quien pertenece a un grupo de personal activo queda reservado para ese
     * grupo en su turno y sus días, aunque el grupo todavía no esté
     * programado: si no, al programar ese grupo después la persona ya
     * estaría ocupada. En otro turno sí puede ir.
     *
     * No reservan a nadie el grupo de la propia programación ni los de
     * $ignoreGroupIds: la programación masiva pasa los grupos del lote, que se
     * comparan entre sí con la tripulación que de verdad llevan.
     *
     * @param  list<string>  $dates
     * @param  list<int>  $ignoreGroupIds
     */
    private static function checkGroupMembership(ValidatorContract $validator, array $data, array $dates, array $ignoreGroupIds): void
    {
        $weekdays = array_values(array_unique(array_map(fn (string $date) => (int) date('N', strtotime($date)), $dates)));
        $ignore = array_filter([...$ignoreGroupIds, (int) ($data['staffgroup_id'] ?? 0)]);

        $groups = StaffGroup::active()
            ->with('helpers:id')
            ->where('schedule_id', (int) $data['schedule_id'])
            ->when($ignore !== [], fn ($q) => $q->whereKeyNot($ignore))
            ->orderBy('name')
            ->get();

        $crew = self::crew($data);

        foreach ($groups as $group) {
            $shared = array_intersect($weekdays, array_map('intval', $group->weekdays ?? []));

            if ($shared === []) {
                continue;
            }

            sort($shared);
            $members = [(int) $group->driver_id, ...$group->helperIds()];

            foreach ($crew as $personId => [$field, $who]) {
                if (in_array($personId, $members, true)) {
                    $validator->errors()->add($field, sprintf(
                        '%s pertenece al grupo "%s" en ese turno (%s): no puede ir también en otro grupo del mismo turno.',
                        $who,
                        $group->name,
                        implode(', ', array_map(fn (int $day) => Scheduling::WEEKDAY_LABELS[$day], $shared)),
                    ));
                }
            }
        }
    }

    /**
     * Cada persona de la programación con el campo donde va su aviso y cómo
     * se la nombra ("El ayudante Díaz Lozano, Ana"). Una persona choca aunque
     * en la otra tenga otra función: no puede ir de conductor en un vehículo
     * y de ayudante en otro a la vez.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function crew(array $data): array
    {
        $crew = [(int) $data['driver_id'] => ['driver_id', 'El conductor']];

        foreach ($data['helper_ids'] ?? [] as $helperId) {
            $crew[(int) $helperId] ??= ['helper_ids', 'El ayudante'];
        }

        $names = User::findMany(array_keys($crew))->pluck('full_name', 'id');

        foreach ($crew as $personId => [$field, $role]) {
            $crew[$personId] = [$field, trim($role.' '.($names[$personId] ?? ''))];
        }

        return $crew;
    }

    /** @param  list<string>  $dates */
    private static function describeDates(array $dates, int $total): string
    {
        return count($dates) === $total ? 'ninguna de las fechas programadas' : self::dateList($dates);
    }

    /**
     * "03/05/2026 – 04/05/2026, 06/05/2026, 08/05/2026 y 10 fechas más": los
     * días seguidos se juntan en un tramo y, pasado el límite, se cuentan.
     * Un rango largo puede dejar decenas de fechas y no caben en un aviso.
     *
     * @param  list<string>  $dates
     */
    public static function dateList(array $dates, int $limit = 4): string
    {
        $format = fn (string $date) => date('d/m/Y', strtotime($date));
        $segments = [];

        foreach (self::normalizeDates($dates) as $date) {
            $last = array_key_last($segments);

            if ($last !== null && $date === date('Y-m-d', strtotime($segments[$last][1].' +1 day'))) {
                $segments[$last][1] = $date;
                $segments[$last][2]++;

                continue;
            }

            $segments[] = [$date, $date, 1];
        }

        $text = implode(', ', array_map(
            fn (array $segment) => $segment[0] === $segment[1]
                ? $format($segment[0])
                : $format($segment[0]).' – '.$format($segment[1]),
            array_slice($segments, 0, $limit),
        ));
        $rest = array_sum(array_column(array_slice($segments, $limit), 2));

        return $rest > 0 ? sprintf('%s y %d %s más', $text, $rest, $rest === 1 ? 'fecha' : 'fechas') : $text;
    }
}
