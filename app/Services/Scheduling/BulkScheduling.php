<?php

namespace App\Services\Scheduling;

use App\Models\Holiday;
use App\Models\Scheduling;
use App\Models\StaffGroup;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Programación masiva (W19): varios grupos de personal en un mismo rango de
 * fechas, de una vez.
 *
 * Cada grupo se valida con SchedulingRules, igual que en la programación
 * individual (contrato, vacaciones, vehículo, choques con lo ya programado),
 * y además contra los otros grupos del lote: dos grupos del mismo turno no
 * pueden llevar el mismo vehículo o la misma persona el mismo día. El lote se
 * guarda entero o no se guarda: con una sola inconsistencia no sale nada.
 *
 * Los feriados del rango que el usuario deja marcados no se programan (lo
 * normal); los que desmarca se trabajan aunque sean feriado.
 */
class BulkScheduling
{
    private const WEEKDAY_NAMES = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    /**
     * Cada grupo del lote, ya validado: lo que se guardaría, contra qué
     * programación (si amplía una) y sus avisos.
     *
     * @var list<array<string, mixed>>
     */
    private array $entries = [];

    /** @param  array<string, mixed>  $input  Lo validado con rules(). */
    public function __construct(private readonly array $input)
    {
        $this->check();
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            // Feriados del rango que NO se programan; el resto se trabaja.
            'skip_holidays' => ['nullable', 'array'],
            'skip_holidays.*' => ['date_format:Y-m-d'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.staffgroup_id' => ['required', 'integer', 'distinct', Rule::exists('staffgroups', 'id')->where('status', 'activo')],
            'rows.*.driver_id' => ['required', 'integer', 'exists:users,id'],
            'rows.*.helper_ids' => ['nullable', 'array', 'max:10'],
            'rows.*.helper_ids.*' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'La fecha de término no puede ser anterior a la de inicio.',
            'rows.required' => 'Elige al menos un grupo para programar.',
            'rows.min' => 'Elige al menos un grupo para programar.',
            'rows.*.staffgroup_id.exists' => 'Uno de los grupos no existe o ya no está activo.',
            'rows.*.staffgroup_id.distinct' => 'Un grupo aparece dos veces.',
            'rows.*.driver_id.required' => 'Cada grupo necesita un conductor.',
        ];
    }

    public function isValid(): bool
    {
        return collect($this->entries)->every(fn (array $entry) => $entry['errors'] === []);
    }

    /**
     * El resultado de la validación general, grupo por grupo.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $groups = array_map(fn (array $entry) => [
            'staffgroup_id' => $entry['group']->id,
            'name' => $entry['group']->name,
            'zone' => $entry['group']->zone?->name,
            'schedule' => $entry['group']->schedule?->name,
            'status' => match (true) {
                $entry['errors'] !== [] => 'error',
                $entry['warnings'] !== [] => 'warning',
                default => 'ok',
            },
            'occurrences' => count($entry['dates']),
            'uncovered' => $entry['uncovered'],
            'holidays' => $entry['holidays'],
            'errors' => $entry['errors'],
            'warnings' => $entry['warnings'],
        ], $this->entries);

        $count = fn (string $status) => count(array_filter($groups, fn (array $group) => $group['status'] === $status));

        return [
            'valid' => $this->isValid(),
            'totals' => [
                'groups' => count($groups),
                'ok' => $count('ok'),
                'warning' => $count('warning'),
                'error' => $count('error'),
                'occurrences' => array_sum(array_column($groups, 'occurrences')),
            ],
            'groups' => $groups,
        ];
    }

    /**
     * Guarda todo el lote en una transacción. Solo se llama si isValid().
     *
     * @return array{created: int, merged: int, routes: int}
     */
    public function save(): array
    {
        $totals = ['created' => 0, 'merged' => 0, 'routes' => 0];

        DB::transaction(function () use (&$totals) {
            foreach ($this->entries as $entry) {
                $scheduling = ($entry['target'] ?? new Scheduling)->saveAndSync($entry['validator']->validated());

                $totals[$entry['target'] ? 'merged' : 'created']++;
                $totals['routes'] += $scheduling->vehicleRoutes()->count();
            }
        });

        return $totals;
    }

    private function check(): void
    {
        $start = $this->input['start_date'];
        $end = $this->input['end_date'];
        $rows = $this->input['rows'];

        $groups = StaffGroup::with(['zone', 'schedule', 'vehicle'])
            ->findMany(array_column($rows, 'staffgroup_id'))
            ->keyBy('id');

        $holidays = Holiday::active()
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->orderBy('date')
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());

        $skip = SchedulingRules::normalizeDates($this->input['skip_holidays'] ?? []);
        $range = collect(CarbonPeriod::create($start, $end));

        foreach ($rows as $row) {
            $group = $groups->get((int) $row['staffgroup_id']);
            $weekdays = SchedulingRules::normalizeWeekdays($group->weekdays ?? []);
            $inGroupDays = $holidays->filter(fn (Holiday $holiday) => in_array($holiday->date->dayOfWeekIso, $weekdays, true));
            $skipped = $inGroupDays->keys()->intersect($skip)->values()->all();
            $worked = $inGroupDays->keys()->diff($skip)->values()->all();

            $payload = [
                'staffgroup_id' => $group->id,
                'zone_id' => $group->zone_id,
                'schedule_id' => $group->schedule_id,
                'vehicle_id' => $group->vehicle_id,
                'driver_id' => (int) $row['driver_id'],
                'helper_ids' => SchedulingRules::normalizeHelpers($row['helper_ids'] ?? []),
                'weekdays' => $weekdays,
                'worked_holidays' => $worked,
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'activa',
            ];

            // Igual que en el alta individual: si el grupo ya tiene una
            // programación en ese turno que se cruza, se amplía esa.
            $target = SchedulingRules::mergeTarget($payload);
            $data = $target ? SchedulingRules::mergeInto($target, $payload) : $payload;
            // Los grupos del lote no reservan a su gente de siempre: se
            // comparan entre sí con la que llevan aquí (checkBetweenRows).
            $validator = SchedulingRules::validator($data, $target?->id, $groups->keys()->all());
            $validator->fails();

            $warnings = [];

            if ($target) {
                $warnings[] = sprintf(
                    'Ya tiene la programación %s en ese turno (del %s al %s): se ampliará y quedará del %s al %s.',
                    $target->code,
                    $target->start_date->format('d/m/Y'),
                    $target->end_date->format('d/m/Y'),
                    date('d/m/Y', strtotime($data['start_date'])),
                    date('d/m/Y', strtotime($data['end_date'])),
                );
            }

            if ($worked !== []) {
                $warnings[] = 'Se programará en feriado: '.SchedulingRules::dateList($worked).'.';
            }

            $uncovered = $range->reject(fn ($date) => in_array($date->dayOfWeekIso, $weekdays, true))->count();

            $this->entries[] = [
                'group' => $group,
                'validator' => $validator,
                'target' => $target,
                // Solo las del rango pedido: lo que la ampliación ya tenía no es nuevo.
                'dates' => SchedulingRules::occurrenceDates($payload),
                // Con + y no con ...: el spread renumeraría los ids.
                'crew' => [$payload['driver_id'] => 'conductor'] + array_fill_keys($payload['helper_ids'], 'ayudante'),
                'errors' => array_values(array_unique($validator->errors()->all())),
                'warnings' => $warnings,
                'uncovered' => $uncovered > 0
                    ? sprintf(
                        '%d %s (el grupo solo trabaja: %s)',
                        $uncovered,
                        $uncovered === 1 ? 'día no cubierto' : 'días no cubiertos',
                        implode(', ', array_map(fn (int $day) => self::WEEKDAY_NAMES[$day], $weekdays)),
                    )
                    : null,
                'holidays' => $skipped !== [] ? 'Sin recorrido por feriado: '.SchedulingRules::dateList($skipped).'.' : null,
            ];
        }

        $this->checkBetweenRows();
    }

    /**
     * Lo que SchedulingRules no ve porque aún no está guardado: dos grupos
     * del lote en el mismo turno con el mismo vehículo o la misma persona el
     * mismo día. El aviso sale en los dos grupos.
     */
    private function checkBetweenRows(): void
    {
        $names = User::findMany(collect($this->entries)->flatMap(fn (array $entry) => array_keys($entry['crew']))->unique()->all())
            ->pluck('full_name', 'id');

        foreach ($this->entries as $i => $a) {
            foreach ($this->entries as $j => $b) {
                if ($i === $j || $a['group']->schedule_id !== $b['group']->schedule_id) {
                    continue;
                }

                $shared = array_values(array_intersect($a['dates'], $b['dates']));

                if ($shared === []) {
                    continue;
                }

                $when = 'en ese turno: '.SchedulingRules::dateList($shared).'.';

                if ($a['group']->vehicle_id === $b['group']->vehicle_id) {
                    $this->entries[$i]['errors'][] = sprintf(
                        'El vehículo %s también va en el grupo "%s" %s',
                        $a['group']->vehicle?->plate ?? '',
                        $b['group']->name,
                        $when,
                    );
                }

                foreach (array_intersect_key($a['crew'], $b['crew']) as $personId => $role) {
                    $this->entries[$i]['errors'][] = sprintf(
                        'El %s %s también va en el grupo "%s" (de %s) %s',
                        $role,
                        $names[$personId] ?? '',
                        $b['group']->name,
                        $b['crew'][$personId],
                        $when,
                    );
                }
            }
        }
    }
}
