<?php

namespace App\Services\Scheduling;

use App\Models\Scheduling;
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
 * - la edicion masiva de W19, que valida N filas en una sola peticion.
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
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(['activa', 'inactiva'])],
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
     * cruzan con una programación que sigue en pie, no crea otra: amplía esa.
     * Devuelve la que se ampliaría, o null si toca crear una nueva.
     */
    public static function mergeTarget(array $data): ?Scheduling
    {
        if (blank($data['staffgroup_id'] ?? null) || blank($data['schedule_id'] ?? null)) {
            return null;
        }

        [$start, $end] = self::dateRange($data);

        if ($start === null || $start > $end) {
            return null;
        }

        return Scheduling::query()
            ->where('staffgroup_id', (int) $data['staffgroup_id'])
            ->where('schedule_id', (int) $data['schedule_id'])
            ->where('status', '!=', 'finalizada')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Lo que queda al ampliar $target con lo nuevo: el rango cubre los dos,
     * los días se suman y el resto (tripulación, estado, notas) es lo nuevo.
     *
     * @return array<string, mixed>
     */
    public static function mergeInto(Scheduling $target, array $data): array
    {
        [$start, $end] = self::dateRange($data);

        return [
            ...$data,
            'start_date' => min($target->start_date->toDateString(), $start),
            'end_date' => max($target->end_date->toDateString(), $end),
            'weekdays' => self::normalizeWeekdays([
                ...array_map('intval', $target->weekdays ?? []),
                ...self::normalizeWeekdays($data['weekdays'] ?? null),
            ]),
        ];
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

    /** Validador completo, reglas base mas las cuatro del informe. */
    public static function validator(array $data, ?int $ignoreId = null): ValidatorContract
    {
        $data['weekdays'] = self::normalizeWeekdays($data['weekdays'] ?? null);
        $data['helper_ids'] = self::normalizeHelpers($data['helper_ids'] ?? [$data['helper_id'] ?? null]);

        $validator = Validator::make($data, self::rules(), self::messages());

        self::attachBusinessRules($validator, $ignoreId);

        return $validator;
    }

    /**
     * Las cuatro validaciones del informe. Se añaden sobre un validador ya
     * hecho para que el FormRequest pueda usarlas desde withValidator().
     */
    public static function attachBusinessRules(ValidatorContract $validator, ?int $ignoreId = null): void
    {
        $validator->after(function (ValidatorContract $validator) use ($ignoreId) {
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

            if ($dates === []) {
                $validator->errors()->add(
                    'weekdays',
                    'Los días elegidos no caen dentro del rango de fechas o son todos feriados: la programación no tendría ningún recorrido.',
                );

                return;
            }

            self::checkPerson($validator, (int) $data['driver_id'], 'driver_id', 'El conductor', $dates);

            foreach (User::findMany($data['helper_ids']) as $helper) {
                self::checkPerson($validator, $helper->id, 'helper_ids', "El ayudante {$helper->full_name}", $dates);
            }

            self::checkOverlap($validator, $data, $ignoreId);
        });
    }

    /**
     * Fechas reales que cubriria la programacion. Se arma un Scheduling sin
     * guardar para no repetir la logica de expansion de dias.
     *
     * @return list<string>
     */
    public static function occurrenceDates(array $data): array
    {
        return (new Scheduling([
            'weekdays' => self::normalizeWeekdays($data['weekdays'] ?? null),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]))->occurrenceDates();
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
     * Coincidir es solaparse en rango de fechas Y compartir algun dia de la
     * semana: dos programaciones del mismo mes no chocan si una es de lunes y
     * la otra de martes.
     */
    private static function checkOverlap(ValidatorContract $validator, array $data, ?int $ignoreId): void
    {
        $candidates = Scheduling::query()
            ->where('status', 'activa')
            ->where('schedule_id', (int) $data['schedule_id'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->with(['vehicle', 'helpers', 'zone'])
            ->get();

        $weekdays = self::normalizeWeekdays($data['weekdays'] ?? null);

        // Cada persona de esta programación, con el campo donde va su aviso.
        // Una persona choca aunque en la otra tenga otra función: no puede
        // ir de conductor en un vehículo y de ayudante en otro a la vez.
        $crew = [(int) $data['driver_id'] => ['driver_id', 'El conductor']];

        foreach ($data['helper_ids'] ?? [] as $helperId) {
            $crew[(int) $helperId] ??= ['helper_ids', 'El ayudante'];
        }

        $names = User::findMany(array_keys($crew))->pluck('full_name', 'id');

        foreach ($candidates as $other) {
            if (array_intersect($weekdays, array_map('intval', $other->weekdays ?? [])) === []) {
                continue;
            }

            $clashes = [];

            if ($other->vehicle_id === (int) $data['vehicle_id']) {
                $clashes[] = ['vehicle_id', 'El vehículo '.($other->vehicle->code ?? '')];
            }

            $otherCrew = [$other->driver_id, ...$other->helperIds()];

            foreach ($crew as $personId => [$field, $role]) {
                if (in_array($personId, $otherCrew, true)) {
                    $clashes[] = [$field, $role.' '.($names[$personId] ?? '')];
                }
            }

            foreach ($clashes as [$field, $what]) {
                $validator->errors()->add($field, sprintf(
                    '%s ya está programado en ese turno y esos días (%s, zona %s, del %s al %s).',
                    trim($what),
                    $other->code,
                    $other->zone->name ?? '—',
                    $other->start_date->format('d/m/Y'),
                    $other->end_date->format('d/m/Y'),
                ));
            }
        }
    }

    /**
     * Un rango largo puede dejar decenas de fechas fuera; listarlas todas no
     * cabe en el aviso del formulario.
     *
     * @param  list<string>  $dates
     */
    private static function describeDates(array $dates, int $total): string
    {
        $format = fn (string $date) => date('d/m/Y', strtotime($date));

        if (count($dates) === $total) {
            return 'ninguna de las fechas programadas';
        }

        if (count($dates) <= 3) {
            return implode(', ', array_map($format, $dates));
        }

        return sprintf(
            '%d de las %d fechas (la primera, el %s)',
            count($dates),
            $total,
            $format($dates[0]),
        );
    }
}
