<?php

namespace App\Services\Scheduling;

use App\Models\ChangeReason;
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
            // El reemplazo de un faltante desde el Dashboard: el nuevo tiene que haber marcado entrada en el turno.
            'changes.*.present_only' => ['sometimes', 'boolean'],
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

    /**
     * Deshace un cambio del historial y lo borra: la programación vuelve a
     * tener lo que tenía antes de ese cambio (el ayudante A que se cambió por
     * B vuelve a ser A).
     *
     * Solo se deshace el último cambio de cada cadena: si después B se cambió
     * por C, primero hay que eliminar ese (C vuelve a B) y luego este (B
     * vuelve a A). Lo que resulta se valida con SchedulingRules como cualquier
     * reprogramación (A tiene que seguir libre en el turno, con contrato...),
     * pero no la asistencia: deshacer un error no depende de quién marcó.
     *
     * @return string|null El motivo por el que no se puede, o null si se deshizo.
     */
    public static function revert(SchedulingChange $change): ?string
    {
        $scheduling = $change->scheduling;

        if (! $scheduling) {
            $change->delete();

            return null;
        }

        $scheduling->loadMissing('helpers');
        $today = now()->toDateString();
        $day = $scheduling->start_date->toDateString();
        $singleDay = $scheduling->end_date->toDateString() === $day;
        $route = $singleDay ? $scheduling->vehicleRoutes()->whereDate('date_route', $day)->first() : null;

        // Lo mismo que bloquea modificarla: deshacer sobre lo que ya pasó falsearía lo ocurrido.
        $lock = match (true) {
            $scheduling->isFinished() => "La programación {$scheduling->code} ya finalizó.",
            $singleDay && $day < $today => "El día de la programación {$scheduling->code} ya pasó.",
            $route && ! $route->canStart() => "El recorrido de {$scheduling->code} ya no está pendiente.",
            default => null,
        };

        if ($lock) {
            return "No se puede deshacer el cambio: {$lock}";
        }

        $section = SchedulingChange::section($change->type);
        $types = $section === 'personal' ? ['conductor', 'ayudante'] : [$change->type];

        $later = $scheduling->history()
            ->whereKeyNot($change->id)
            ->where('id', '>', $change->id)
            ->whereIn('type', $types)
            ->where('old_id', $change->new_id)
            ->first();

        if ($later) {
            return "Primero elimina el cambio posterior ({$later->old_value} → {$later->new_value}): este se deshace después.";
        }

        if (! $change->old_id) {
            return 'No se puede deshacer: el cambio no guardó el valor anterior.';
        }

        $payload = [
            ...$scheduling->only(['zone_id', 'staffgroup_id', 'route_id', 'vehicle_id', 'schedule_id', 'driver_id', 'notes']),
            'helper_ids' => $scheduling->helperIds(),
            'weekdays' => array_map('intval', $scheduling->weekdays ?? []),
            'worked_holidays' => $scheduling->worked_holidays ?? [],
            'start_date' => $day,
            'end_date' => $scheduling->end_date->toDateString(),
            // Sin más cambios en el historial vuelve a estar como se registró.
            'status' => $scheduling->history()->whereKeyNot($change->id)->exists() ? $scheduling->status : 'activa',
        ];

        $current = match ($change->type) {
            'turno' => (int) $payload['schedule_id'] === (int) $change->new_id,
            'vehiculo' => (int) $payload['vehicle_id'] === (int) $change->new_id,
            'conductor' => (int) $payload['driver_id'] === (int) $change->new_id,
            'ayudante' => in_array((int) $change->new_id, $payload['helper_ids'], true),
            default => false,
        };

        if (! $current) {
            return "No se puede deshacer: la programación ya no tiene {$change->new_value}.";
        }

        match ($change->type) {
            'turno' => $payload['schedule_id'] = (int) $change->old_id,
            'vehiculo' => $payload['vehicle_id'] = (int) $change->old_id,
            'conductor' => $payload['driver_id'] = (int) $change->old_id,
            // Vuelve al mismo asiento que ocupaba.
            'ayudante' => $payload['helper_ids'][array_search((int) $change->new_id, $payload['helper_ids'], true)] = (int) $change->old_id,
        };

        $validator = SchedulingRules::validator($payload, $scheduling->id);

        if ($validator->fails()) {
            return "No se puede volver a {$change->old_value}: ".$validator->errors()->first();
        }

        DB::transaction(function () use ($scheduling, $validator, $change) {
            $scheduling->saveAndSync($validator->validated());
            $change->delete();
        });

        return null;
    }

    /** @param  array<string, mixed>  $change */
    private function apply(int $number, array $change): void
    {
        $section = $change['type'];
        $reason = (string) $change['reason'];
        $description = filled($change['description'] ?? null) ? trim((string) $change['description']) : null;
        $prefix = "Cambio {$number}";

        // Los motivos salen de Gestión de cambios: los de la sección y los generales.
        $motive = ChangeReason::forSection($section)->where('name', $reason)->first();

        if (! $motive) {
            $this->errors[] = "{$prefix}: el motivo \"{$reason}\" no es válido para este cambio.";

            return;
        }

        if ($motive->requires_description && $description === null) {
            $this->errors[] = "{$prefix}: con el motivo \"{$motive->name}\" describe el motivo del cambio.";

            return;
        }

        $record = match ($section) {
            'turno' => $this->changeSchedule($prefix, (int) $change['new_id']),
            'vehiculo' => $this->changeVehicle($prefix, (int) $change['new_id']),
            'personal' => $this->changePerson($prefix, (int) $change['old_id'], (int) $change['new_id'], (bool) ($change['present_only'] ?? false)),
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
    private function changePerson(string $prefix, int $oldId, int $newId, bool $presentOnly = false): ?array
    {
        $isDriver = $oldId === (int) $this->payload['driver_id'];
        $helperIndex = array_search($oldId, $this->payload['helper_ids'], true);

        if (! $isDriver && $helperIndex === false) {
            $this->errors[] = "{$prefix}: la persona a reemplazar ya no está en la programación.";

            return null;
        }

        $people = User::with('usertype')->findMany([$oldId, $newId])->keyBy('id');
        $new = $people->get($newId);

        // En la programación de un día, quien ya marcó asistencia está
        // trabajando: solo se reemplaza al que falta.
        $day = $this->scheduling->start_date->toDateString();

        if ($this->scheduling->end_date->toDateString() === $day && $people->get($oldId)?->attendedOn($day, $this->scheduling->schedule_id)) {
            $this->errors[] = "{$prefix}: {$people->get($oldId)->full_name} ya marcó entrada en ese turno; solo se cambia al personal que falta.";

            return null;
        }

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

        // Quien entra en lugar de un faltante tiene que estar trabajando: con
        // la entrada marcada en el turno de la programación ese día.
        if ($presentOnly && ! $new->attendedOn($day, (int) $this->payload['schedule_id'])) {
            $this->errors[] = "{$prefix}: {$new->full_name} no marcó entrada en este turno; solo se reemplaza con personal presente.";

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
