<?php

namespace App\Http\Resources;

use App\Models\Scheduling;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Scheduling
 */
class SchedulingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // El PRG-NNNN que W17 muestra como primera columna.
            'code' => $this->code,
            // La programación general de la que este registro es un día.
            'general_scheduling_id' => $this->general_scheduling_id,
            'general' => $this->whenLoaded('general', fn () => $this->general ? [
                'id' => $this->general->id,
                'code' => $this->general->code,
                'start_date' => $this->general->start_date->toDateString(),
                'end_date' => $this->general->end_date->toDateString(),
            ] : null),
            'zone_id' => $this->zone_id,
            'zone' => $this->whenLoaded('zone', fn () => [
                'id' => $this->zone->id,
                'name' => $this->zone->name,
            ]),
            'route_id' => $this->route_id,
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'id' => $this->route->id,
                'name' => $this->route->name,
            ] : null),
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'code' => $this->vehicle->code,
                'plate' => $this->vehicle->plate,
                'status' => $this->vehicle->status,
            ]),
            'schedule_id' => $this->schedule_id,
            'schedule' => $this->whenLoaded('schedule', fn () => [
                'id' => $this->schedule->id,
                'name' => $this->schedule->name,
                // "Mañana (06:00 - 12:00)": el mismo texto que usan los
                // selectores de turno, ya armado por el modelo.
                'label' => $this->schedule->label,
                'time_start' => $this->schedule->time_start,
                'time_end' => $this->schedule->time_end,
            ]),
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => [
                'id' => $this->driver->id,
                'full_name' => $this->driver->full_name,
                'dni' => $this->driver->dni,
            ]),
            'helper_ids' => $this->whenLoaded('helpers', fn () => $this->helperIds()),
            'helpers' => $this->whenLoaded('helpers', fn () => $this->helpers->map(fn ($helper) => [
                'id' => $helper->id,
                'full_name' => $helper->full_name,
                'dni' => $helper->dni,
            ])),
            // Compatibilidad con los clientes de un solo ayudante: el primero.
            'helper_id' => $this->whenLoaded('helpers', fn () => $this->helpers->first()?->id),
            'helper' => $this->whenLoaded('helpers', fn () => ($first = $this->helpers->first()) ? [
                'id' => $first->id,
                'full_name' => $first->full_name,
                'dni' => $first->dni,
            ] : null),
            'weekdays' => array_map('intval', $this->weekdays ?? []),
            'weekdays_label' => $this->weekdaysLabel(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'staffgroup_id' => $this->staffgroup_id,
            'notes' => $this->notes,
            // Cuantos recorridos genera y cuantos ya arrancaron: es lo que W17
            // muestra en la fila sin tener que abrir la programacion.
            'routes_count' => $this->whenCounted('vehicleRoutes'),
            'started_count' => $this->when(
                isset($this->started_routes_count),
                fn () => (int) $this->started_routes_count,
            ),
            // Solo cuando se consulta con ?date=: el estado del recorrido de
            // ESE día, que es lo que pinta la columna ESTADO de W17.
            'day_status' => $this->when(isset($this->day_status), fn () => $this->day_status),
            'day_route_id' => $this->when(isset($this->day_status), fn () => $this->day_route_id),
            'day_block_reason' => $this->when(isset($this->day_status), fn () => $this->day_block_reason),
            // Vacaciones registradas después de programar: el aviso de W17.
            'vacation_conflicts' => $this->when(
                isset($this->vacation_conflicts),
                fn () => $this->vacation_conflicts,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
