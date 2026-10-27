<?php

namespace App\Services\Scheduling;

use App\Models\Contract;
use App\Models\Scheduling;
use App\Models\StaffGroup;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vacation;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;

/**
 * ¿Está libre esta persona (o este vehículo) para un grupo de personal?
 *
 * Un grupo no tiene fechas, solo turno y días de la semana, así que se mira
 * desde hoy en adelante:
 *
 * - **No disponible** (`problems`, impiden guardar un grupo activo): usuario
 *   inactivo, sin contrato vigente ni por empezar, de vacaciones hoy, conductor
 *   sin licencia vigente, o ya ocupado en ese turno y alguno de esos días por
 *   otro grupo activo o por una programación activa que sigue vigente.
 * - **Atención** (`warnings`, no impiden): vacaciones aprobadas o por aprobar
 *   más adelante, o contrato que termina pronto. Cuando se programe con
 *   fechas, SchedulingRules decidirá con esas fechas exactas.
 *
 * Las programaciones que salieron del mismo grupo no cuentan como ocupación:
 * es la propia tripulación del grupo.
 */
class StaffAvailability
{
    /** Días antes del fin de contrato a partir de los que se avisa. */
    private const CONTRACT_WARNING_DAYS = 30;

    /** @var array<int, list<string>> */
    private array $busyPeople = [];

    /** @var array<int, list<string>> */
    private array $busyVehicles = [];

    /**
     * @param  list<int>  $weekdays
     */
    public function __construct(
        private readonly ?int $scheduleId,
        private readonly array $weekdays,
        private readonly ?int $ignoreGroupId = null,
    ) {
        if ($scheduleId && $weekdays !== []) {
            $this->loadOccupation();
        }
    }

    /**
     * Personal que puede ir en un grupo: conductores y ayudantes activos con
     * contrato vigente o por empezar (con el contrato vencido no tiene
     * sentido ofrecerlos), más los que ya están en él aunque hayan dejado de
     * cumplir, para que el formulario no los pierda al editar.
     *
     * @param  list<int>  $keep
     * @return Collection<int, User>
     */
    public static function candidates(array $keep = []): Collection
    {
        $today = now()->toDateString();

        return User::query()
            ->with(['usertype', 'zone', 'contracts', 'vacations'])
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', 'activo')
                    ->whereHas('usertype', fn ($t) => $t->whereIn('slug', [UserType::DRIVER, UserType::HELPER]))
                    ->whereHas('contracts', fn ($c) => $c
                        ->where(fn ($c) => $c->whereNull('end_date')->orWhere('end_date', '>=', $today))))
                ->when($keep !== [], fn ($q) => $q->orWhereIn('id', $keep)))
            ->orderBy('lastname')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{available: bool, problems: list<string>, warnings: list<string>}
     */
    public function forPerson(User $person): array
    {
        $today = now()->toDateString();
        $problems = [];
        $warnings = [];

        if ($person->status !== 'activo') {
            $problems[] = 'Usuario inactivo.';
        }

        $contract = $person->contracts
            ->filter(fn (Contract $c) => $c->coversDate($today) || (! $c->isDeactivated() && $c->start_date->toDateString() > $today))
            ->sortBy('start_date')
            ->first();

        if (! $contract) {
            $problems[] = 'No tiene contrato vigente.';
        } elseif ($contract->start_date->toDateString() > $today) {
            $warnings[] = 'Su contrato empieza el '.$contract->start_date->format('d/m/Y').'.';
        } elseif ($contract->end_date && $contract->end_date->diffInDays(now()->startOfDay(), true) <= self::CONTRACT_WARNING_DAYS) {
            $warnings[] = 'Su contrato termina el '.$contract->end_date->format('d/m/Y').'.';
        }

        $vacations = $person->vacations
            ->filter(fn (Vacation $v) => $v->end_date->toDateString() >= $today)
            ->sortBy('start_date');

        $current = $vacations->first(fn (Vacation $v) => $v->coversDate($today));

        if ($current) {
            $problems[] = 'Está de vacaciones hasta el '.$current->end_date->format('d/m/Y').'.';
        }

        foreach ($vacations as $vacation) {
            if ($vacation->is($current)) {
                continue;
            }

            $range = 'del '.$vacation->start_date->format('d/m/Y').' al '.$vacation->end_date->format('d/m/Y');

            if (in_array($vacation->status, Vacation::APPROVED_STATUSES, true)) {
                $warnings[] = "Tiene vacaciones aprobadas {$range}.";
            } elseif ($vacation->status === 'pendientes') {
                $warnings[] = "Tiene vacaciones por aprobar {$range}.";
            }
        }

        if ($person->usertype?->slug === UserType::DRIVER && ! $person->hasValidLicense()) {
            $problems[] = 'Su licencia de conducir está vencida o no está registrada.';
        }

        array_push($problems, ...($this->busyPeople[$person->id] ?? []));

        return ['available' => $problems === [], 'problems' => $problems, 'warnings' => $warnings];
    }

    /**
     * @return array{available: bool, problems: list<string>}
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        $problems = $vehicle->status === 'operativo' ? [] : ['El vehículo no está operativo.'];
        array_push($problems, ...($this->busyVehicles[$vehicle->id] ?? []));

        return ['available' => $problems === [], 'problems' => $problems];
    }

    /**
     * Quién y qué ya está ocupado en ese turno y alguno de esos días: otros
     * grupos activos y programaciones activas que siguen vigentes.
     */
    private function loadOccupation(): void
    {
        $groups = StaffGroup::active()
            ->with(['helpers:id', 'schedule'])
            ->where('schedule_id', $this->scheduleId)
            ->when($this->ignoreGroupId, fn ($q) => $q->whereKeyNot($this->ignoreGroupId))
            ->get()
            ->filter(fn (StaffGroup $group) => $this->sharesDays($group->weekdays));

        foreach ($groups as $group) {
            $where = sprintf('el grupo "%s" (%s · %s)', $group->name, $group->weekdaysLabel(), $group->schedule?->name);

            $this->busyVehicles[$group->vehicle_id][] = "Ya está en {$where}.";

            foreach ([$group->driver_id, ...$group->helperIds()] as $userId) {
                $this->busyPeople[$userId][] = "Ya está en {$where}.";
            }
        }

        $schedulings = Scheduling::query()
            ->with(['helpers:id', 'schedule'])
            ->where('status', 'activa')
            ->where('schedule_id', $this->scheduleId)
            ->whereDate('end_date', '>=', now()->toDateString())
            ->when($this->ignoreGroupId, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('staffgroup_id')
                ->orWhere('staffgroup_id', '!=', $this->ignoreGroupId)))
            ->get()
            ->filter(fn (Scheduling $scheduling) => $this->sharesDays($scheduling->weekdays));

        foreach ($schedulings as $scheduling) {
            $where = sprintf(
                'la programación %s hasta el %s (%s · %s)',
                $scheduling->code,
                $scheduling->end_date->format('d/m/Y'),
                $scheduling->weekdaysLabel(),
                $scheduling->schedule?->name,
            );

            $this->busyVehicles[$scheduling->vehicle_id][] = "Está en {$where}.";

            foreach ([$scheduling->driver_id, ...$scheduling->helperIds()] as $userId) {
                $this->busyPeople[$userId][] = "Está en {$where}.";
            }
        }
    }

    private function sharesDays(?array $weekdays): bool
    {
        return array_intersect($this->weekdays, array_map('intval', $weekdays ?? [])) !== [];
    }
}
