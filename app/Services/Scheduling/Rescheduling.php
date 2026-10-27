<?php

namespace App\Services\Scheduling;

use App\Models\Schedule;
use App\Models\Scheduling;
use App\Models\SchedulingChange;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reprogramar: cambiar el turno, el vehículo o el personal de una
 * programación ya registrada, con un motivo por cada cambio.
 *
 * Los cambios se aplican en el orden en que se agregaron sobre lo que la
 * programación tiene ahora. Lo que resulta se valida con SchedulingRules,
 * igual que un alta (contrato, vacaciones, choques de turno, cupo del
 * vehículo), y se guarda con el historial en una sola transacción. La
 * programación pasa a "reprogramada" y sus recorridos pendientes de hoy en
 * adelante se rehacen; lo pasado y lo ya iniciado no se toca.
 */
class Rescheduling
{
    /** Lo que quedará la programación, en el formato de SchedulingRules. */
    private array $payload;

    /** @var list<array<string, mixed>> */
    private array $records = [];

    /** @var list<string> */
    private array $errors = [];

    private ?ValidatorContract $validator = null;

    /** @param  list<array<string, mixed>>  $changes  Lo validado con rules(). */
    public function __construct(private readonly Scheduling $scheduling, array $changes)
    {
        $scheduling->loadMissing('helpers');

        $this->payload = [
            ...$scheduling->only(['zone_id', 'staffgroup_id', 'route_id', 'vehicle_id', 'schedule_id', 'driver_id', 'notes']),
            'helper_ids' => $scheduling->helperIds(),
            'weekdays' => array_map('intval', $scheduling->weekdays ?? []),
            'worked_holidays' => $scheduling->worked_holidays ?? [],
            'start_date' => $scheduling->start_date->toDateString(),
            'end_date' => $scheduling->end_date->toDateString(),
            'status' => 'reprogramada',
        ];

        foreach (array_values($changes) as $index => $change) {
            $this->apply($index + 1, $change);
        }

        if ($this->errors === []) {
            $this->validator = SchedulingRules::validator($this->payload, $scheduling->id);

            if ($this->validator->fails()) {
                $this->errors = array_values(array_unique($this->validator->errors()->all()));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:20'],
            'changes.*.type' => ['required', Rule::in(['turno', 'vehiculo', 'personal'])],
            'changes.*.old_id' => ['nullable', 'required_if:changes.*.type,personal', 'integer'],
            'changes.*.new_id' => ['required', 'integer'],
            'changes.*.reason' => ['required', 'string', 'max:100'],
            'changes.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'changes.required' => 'Agrega al menos un cambio.',
            'changes.min' => 'Agrega al menos un cambio.',
            'changes.*.old_id.required_if' => 'Elige a qué persona se reemplaza.',
            'changes.*.new_id.required' => 'Falta el nuevo valor de un cambio.',
            'changes.*.reason.required' => 'Elige el motivo de cada cambio.',
        ];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Guarda la programación reprogramada y su historial. Solo si errors() está vacío. */
    public function save(?int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $this->scheduling->saveAndSync($this->validator->validated());

            foreach ($this->records as $record) {
                $this->scheduling->history()->create([...$record, 'user_id' => $userId]);
            }
        });
    }

    /** @param  array<string, mixed>  $change */
    private function apply(int $number, array $change): void
    {
        $section = $change['type'];
        $reason = (string) $change['reason'];
        $description = filled($change['description'] ?? null) ? trim((string) $change['description']) : null;
        $prefix = "Cambio {$number}";

        if (! in_array($reason, SchedulingChange::REASONS[$section], true)) {
            $this->errors[] = "{$prefix}: el motivo \"{$reason}\" no es válido.";

            return;
        }

        if ($reason === SchedulingChange::OTHER && $description === null) {
            $this->errors[] = "{$prefix}: con el motivo \"Otro\" describe el motivo del cambio.";

            return;
        }

        $record = match ($section) {
            'turno' => $this->changeSchedule($prefix, (int) $change['new_id']),
            'vehiculo' => $this->changeVehicle($prefix, (int) $change['new_id']),
            'personal' => $this->changePerson($prefix, (int) $change['old_id'], (int) $change['new_id']),
        };

        if ($record) {
            $this->records[] = [...$record, 'reason' => $reason, 'description' => $description];
        }
    }

    private function changeSchedule(string $prefix, int $newId): ?array
    {
        $old = Schedule::find($this->payload['schedule_id']);
        $new = Schedule::find($newId);

        if (! $new) {
            $this->errors[] = "{$prefix}: el turno no existe.";

            return null;
        }

        if ($new->id === (int) $this->payload['schedule_id']) {
            $this->errors[] = "{$prefix}: la programación ya está en el turno {$new->name}.";

            return null;
        }

        $this->payload['schedule_id'] = $new->id;

        return ['type' => 'turno', 'old_id' => $old?->id, 'new_id' => $new->id, 'old_value' => $old?->label, 'new_value' => $new->label];
    }

    private function changeVehicle(string $prefix, int $newId): ?array
    {
        $old = Vehicle::find($this->payload['vehicle_id']);
        $new = Vehicle::find($newId);

        if (! $new) {
            $this->errors[] = "{$prefix}: el vehículo no existe.";

            return null;
        }

        if ($new->id === (int) $this->payload['vehicle_id']) {
            $this->errors[] = "{$prefix}: la programación ya lleva el vehículo {$new->plate}.";

            return null;
        }

        $this->payload['vehicle_id'] = $new->id;

        return ['type' => 'vehiculo', 'old_id' => $old?->id, 'new_id' => $new->id, 'old_value' => self::vehicleLabel($old), 'new_value' => self::vehicleLabel($new)];
    }

    /** Reemplaza a una persona de la tripulación por otra de su misma función. */
    private function changePerson(string $prefix, int $oldId, int $newId): ?array
    {
        $isDriver = $oldId === (int) $this->payload['driver_id'];
        $helperIndex = array_search($oldId, $this->payload['helper_ids'], true);

        if (! $isDriver && $helperIndex === false) {
            $this->errors[] = "{$prefix}: la persona a reemplazar ya no está en la programación.";

            return null;
        }

        $people = User::with('usertype')->findMany([$oldId, $newId])->keyBy('id');
        $new = $people->get($newId);

        if (! $new) {
            $this->errors[] = "{$prefix}: el nuevo personal no existe.";

            return null;
        }

        if ($newId === (int) $this->payload['driver_id'] || in_array($newId, $this->payload['helper_ids'], true)) {
            $this->errors[] = "{$prefix}: {$new->full_name} ya va en esta programación.";

            return null;
        }

        $slug = $isDriver ? UserType::DRIVER : UserType::HELPER;

        if ($new->usertype?->slug !== $slug) {
            $this->errors[] = sprintf('%s: %s no tiene la función de %s.', $prefix, $new->full_name, $isDriver ? 'conductor' : 'ayudante');

            return null;
        }

        if ($isDriver) {
            $this->payload['driver_id'] = $newId;
        } else {
            // Conserva el asiento: el reemplazo ocupa el lugar del que sale.
            $this->payload['helper_ids'][$helperIndex] = $newId;
        }

        return [
            'type' => $isDriver ? 'conductor' : 'ayudante',
            'old_id' => $oldId,
            'new_id' => $newId,
            'old_value' => $people->get($oldId)?->full_name,
            'new_value' => $new->full_name,
        ];
    }

    private static function vehicleLabel(?Vehicle $vehicle): ?string
    {
        return $vehicle ? trim("{$vehicle->plate} · {$vehicle->code}") : null;
    }
}
