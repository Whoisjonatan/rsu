<?php

namespace App\Services\Scheduling;

use App\Models\ChangeReason;
use App\Models\Schedule;
use App\Models\Scheduling;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use App\Models\Zone;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Cambio masivo (Gestión de cambios › Cambios): reemplazar un turno, un
 * conductor, un ocupante (ayudante) o un vehículo en todas las programaciones
 * de un rango de fechas, en algunas zonas o en todas.
 *
 * Entran las programaciones vigentes de hoy en adelante que tienen ahora lo
 * que se reemplaza. Cada una pasa por Rescheduling, igual que un cambio
 * hecho a mano: mismas reglas, mismo historial, queda "reprogramada". Las que
 * no pueden cambiar (recorrido ya iniciado, la persona ya marcó, choque con
 * otra programación...) se omiten y preview() dice por qué.
 */
class BulkRescheduling
{
    /** Tipos de cambio masivo, con su etiqueta. */
    public const TYPES = [
        'turno' => 'Cambio de turno',
        'conductor' => 'Cambio de conductor',
        'ayudante' => 'Cambio de ocupante',
        'vehiculo' => 'Cambio de vehículo',
    ];

    /** La sección de los motivos (ChangeReason) de cada tipo. */
    public const REASON_SECTIONS = [
        'turno' => 'turno',
        'conductor' => 'personal',
        'ayudante' => 'personal',
        'vehiculo' => 'vehiculo',
    ];

    /** Un año como mucho: más es casi seguro un error de fecha. */
    private const MAX_DAYS = 366;

    /** @param  array<string, mixed>  $input  Lo validado con validator(). */
    public function __construct(private readonly array $input) {}

    /**
     * Valida el formulario: fechas, tipo, qué se reemplaza por qué, y que el
     * motivo sea de ese tipo de cambio (con descripción si la pide).
     *
     * @param  array<string, mixed>  $data
     */
    public static function validator(array $data): ValidatorContract
    {
        $validator = Validator::make($data, [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'old_id' => ['required', 'integer'],
            'new_id' => ['required', 'integer', 'different:old_id'],
            'reason' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'date_from.required' => 'Elige la fecha de inicio.',
            'date_to.required' => 'Elige la fecha de fin.',
            'date_to.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'type.required' => 'Elige el tipo de cambio.',
            'old_id.required' => 'Elige qué se reemplaza.',
            'new_id.required' => 'Elige el nuevo valor.',
            'new_id.different' => 'El nuevo valor tiene que ser distinto del que se reemplaza.',
            'reason.required' => 'Elige el motivo.',
        ]);

        $validator->after(function (ValidatorContract $validator) use ($data) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $days = (strtotime($data['date_to']) - strtotime($data['date_from'])) / 86400 + 1;

            if ($days > self::MAX_DAYS) {
                $validator->errors()->add('date_to', 'El rango no puede pasar de un año.');
            }

            if ($data['date_to'] < now()->toDateString()) {
                $validator->errors()->add('date_to', 'El rango ya pasó: solo se cambian programaciones de hoy en adelante.');
            }

            if (self::label($data['type'], (int) $data['old_id']) === null) {
                $validator->errors()->add('old_id', 'Lo que se reemplaza no existe.');
            }

            if (self::label($data['type'], (int) $data['new_id']) === null) {
                $validator->errors()->add('new_id', 'El nuevo valor no existe.');
            }

            $motive = ChangeReason::forSection(self::REASON_SECTIONS[$data['type']])->where('name', $data['reason'])->first();

            if (! $motive) {
                $validator->errors()->add('reason', 'Ese motivo no es para este tipo de cambio.');
            } elseif ($motive->requires_description && blank($data['description'] ?? null)) {
                $validator->errors()->add('description', "Con el motivo «{$motive->name}» describe el cambio.");
            }
        });

        return $validator;
    }

    /**
     * El resumen antes de confirmar: lo que se cambia y, día por día, si se
     * aplica o por qué se omite. No guarda nada.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $rows = $this->candidates()->map(function (Scheduling $scheduling) {
            $problem = $this->problem($scheduling);

            return [
                'id' => $scheduling->id,
                'code' => $scheduling->code,
                'date' => $scheduling->dateLabel(),
                'zone' => $scheduling->zone?->name ?? '—',
                'schedule' => $scheduling->schedule?->name ?? '—',
                'problem' => is_string($problem) ? $problem : null,
            ];
        });

        return [
            'type' => self::TYPES[$this->input['type']],
            'date_from' => $this->input['date_from'],
            'date_to' => $this->input['date_to'],
            'zones' => $this->zonesLabel(),
            'old' => self::label($this->input['type'], (int) $this->input['old_id']),
            'new' => self::label($this->input['type'], (int) $this->input['new_id']),
            'reason' => $this->input['reason'],
            'description' => $this->input['description'] ?? null,
            'applies' => $rows->whereNull('problem')->count(),
            'skipped' => $rows->whereNotNull('problem')->count(),
            'rows' => $rows->values()->all(),
            // Los días anteriores a hoy no se cambian aunque estén en el rango.
            'from_today' => $this->input['date_from'] < now()->toDateString(),
        ];
    }

    /**
     * Aplica el cambio a las que pueden cambiar, en una sola transacción. Se
     * vuelve a revisar cada una: entre el resumen y la confirmación alguien
     * pudo marcar asistencia o iniciar un recorrido.
     *
     * @return array{applied: int, skipped: int}
     */
    public function apply(?int $userId): array
    {
        return DB::transaction(function () use ($userId) {
            $applied = 0;
            $skipped = 0;

            foreach ($this->candidates() as $scheduling) {
                $result = $this->problem($scheduling);

                if (is_string($result)) {
                    $skipped++;

                    continue;
                }

                $result->save($userId);
                $applied++;
            }

            return ['applied' => $applied, 'skipped' => $skipped];
        });
    }

    /**
     * Las programaciones del rango y las zonas que tienen ahora lo que se
     * reemplaza, de hoy en adelante.
     *
     * @return Collection<int, Scheduling>
     */
    private function candidates(): Collection
    {
        $from = max($this->input['date_from'], now()->toDateString());
        $oldId = (int) $this->input['old_id'];

        return Scheduling::query()
            ->with(['zone', 'schedule', 'helpers'])
            ->withExists(['vehicleRoutes as route_started' => fn ($q) => $q->where('status', '!=', 'programado')])
            ->inForce()
            ->whereDate('end_date', '>=', $from)
            ->whereDate('start_date', '<=', $this->input['date_to'])
            ->when(filled($this->input['zone_ids'] ?? null), fn ($q) => $q->whereIn('zone_id', array_map('intval', $this->input['zone_ids'])))
            ->when($this->input['type'] === 'turno', fn ($q) => $q->where('schedule_id', $oldId))
            ->when($this->input['type'] === 'vehiculo', fn ($q) => $q->where('vehicle_id', $oldId))
            ->when($this->input['type'] === 'conductor', fn ($q) => $q->where('driver_id', $oldId))
            ->when($this->input['type'] === 'ayudante', fn ($q) => $q->whereHas('helpers', fn ($h) => $h->whereKey($oldId)))
            ->orderBy('start_date')
            ->orderBy('schedule_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Por qué esta programación no puede cambiar, o la reprogramación lista
     * para guardarse si puede.
     */
    private function problem(Scheduling $scheduling): string|Rescheduling
    {
        if ($scheduling->route_started) {
            return 'El recorrido ya inició.';
        }

        $type = $this->input['type'];
        $rescheduling = new Rescheduling($scheduling, [[
            'type' => self::REASON_SECTIONS[$type],
            'old_id' => in_array($type, ['conductor', 'ayudante'], true) ? (int) $this->input['old_id'] : null,
            'new_id' => (int) $this->input['new_id'],
            'reason' => $this->input['reason'],
            'description' => $this->input['description'] ?? null,
        ]]);

        // Rescheduling numera los cambios ("Cambio 1: ..."): aquí hay uno solo.
        return $rescheduling->errors() === []
            ? $rescheduling
            : preg_replace('/^Cambio \d+: /', '', $rescheduling->errors()[0]);
    }

    private function zonesLabel(): string
    {
        $ids = array_map('intval', $this->input['zone_ids'] ?? []);

        return $ids === []
            ? 'Todas las zonas'
            : Zone::whereKey($ids)->orderBy('name')->pluck('name')->implode(', ');
    }

    /** Cómo se nombra lo que se reemplaza o lo nuevo, o null si no existe. */
    public static function label(string $type, int $id): ?string
    {
        return match ($type) {
            'turno' => Schedule::find($id)?->label,
            'vehiculo' => self::vehicleLabel(Vehicle::find($id)),
            'conductor' => User::ofFunction(UserType::DRIVER)->find($id)?->full_name,
            'ayudante' => User::ofFunction(UserType::HELPER)->find($id)?->full_name,
            default => null,
        };
    }

    /** "VEH-001 · Toyota Dyna 300 · B2K-841". */
    public static function vehicleLabel(?Vehicle $vehicle): ?string
    {
        return $vehicle
            ? implode(' · ', array_filter([$vehicle->code, $vehicle->name, $vehicle->plate], 'filled'))
            : null;
    }
}
