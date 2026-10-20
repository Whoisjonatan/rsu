<?php

namespace App\Services\Scheduling;

use App\Models\Scheduling;
use App\Models\User;
use App\Models\Zone;
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
            'route_id' => ['nullable', Rule::exists('routes', 'id')],
            'vehicle_id' => [
                'required',
                // Un vehiculo de baja no puede programarse; en mantenimiento
                // tampoco, porque W21 lo bloquearia el dia del recorrido.
                Rule::exists('vehicles', 'id')->where('status', 'operativo'),
            ],
            'schedule_id' => ['required', 'exists:schedules,id'],
            'driver_id' => ['required', 'exists:users,id'],
            'helper_id' => ['nullable', 'different:driver_id', 'exists:users,id'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(['activa', 'inactiva'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'vehicle_id.exists' => 'El vehículo no existe o no está operativo.',
            'helper_id.different' => 'El ayudante no puede ser la misma persona que el conductor.',
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

    /** Validador completo, reglas base mas las cuatro del informe. */
    public static function validator(array $data, ?int $ignoreId = null): ValidatorContract
    {
        $data['weekdays'] = self::normalizeWeekdays($data['weekdays'] ?? null);

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

            self::checkZone($validator, $data, $ignoreId);

            $dates = self::occurrenceDates($data);

            if ($dates === []) {
                $validator->errors()->add(
                    'weekdays',
                    'Los días elegidos no caen dentro del rango de fechas: la programación no tendría ningún recorrido.',
                );

                return;
            }

            self::checkPerson($validator, $data, 'driver_id', 'El conductor', $dates);

            if (! empty($data['helper_id'])) {
                self::checkPerson($validator, $data, 'helper_id', 'El ayudante', $dates);
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
     * Validaciones 1 y 2: solo personal contratado y sin vacaciones.
     *
     * @param  list<string>  $dates
     */
    private static function checkPerson(ValidatorContract $validator, array $data, string $field, string $label, array $dates): void
    {
        $person = User::with(['contracts', 'vacations'])->find($data[$field] ?? null);

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
            ->with(['vehicle', 'driver', 'helper', 'zone'])
            ->get();

        $weekdays = self::normalizeWeekdays($data['weekdays'] ?? null);
        $helperId = ! empty($data['helper_id']) ? (int) $data['helper_id'] : null;

        foreach ($candidates as $other) {
            if (array_intersect($weekdays, array_map('intval', $other->weekdays ?? [])) === []) {
                continue;
            }

            $clashes = array_filter([
                'vehicle_id' => $other->vehicle_id === (int) $data['vehicle_id']
                    ? 'El vehículo '.($other->vehicle->code ?? '')
                    : null,
                'driver_id' => $other->driver_id === (int) $data['driver_id']
                    ? 'El conductor '.($other->driver->full_name ?? '')
                    : null,
                'helper_id' => $helperId !== null && in_array($helperId, [$other->helper_id, $other->driver_id], true)
                    ? 'El ayudante '.(User::find($helperId)?->full_name ?? '')
                    : null,
            ]);

            foreach ($clashes as $field => $what) {
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
