<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un recorrido de un dia concreto: lo que pinta el calendario (W20) y lo que
 * valida e inicia W21.
 *
 * @mixin \App\Models\VehicleRoute
 */
class VehicleRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheduling_id' => $this->scheduling_id,
            'date_route' => $this->date_route?->toDateString(),
            'time_route' => $this->time_route,
            'status' => $this->status,
            'block_reason' => $this->block_reason,
            'description' => $this->description,
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'code' => $this->vehicle->code,
                'plate' => $this->vehicle->plate,
                'status' => $this->vehicle->status,
            ]),
            'schedule' => $this->whenLoaded('schedule', fn () => [
                'id' => $this->schedule->id,
                'name' => $this->schedule->name,
                'label' => $this->schedule->label,
                'time_start' => $this->schedule->time_start,
                'time_end' => $this->schedule->time_end,
            ]),
            // La zona no cuelga del recorrido sino de su programacion, pero el
            // calendario agrupa por zona: se sube aquí para no obligar al front
            // a navegar dos niveles.
            'zone' => $this->whenLoaded('scheduling', fn () => $this->scheduling?->zone ? [
                'id' => $this->scheduling->zone->id,
                'name' => $this->scheduling->zone->name,
            ] : null),
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'id' => $this->route->id,
                'name' => $this->route->name,
            ] : null),
            'occupants' => VehicleOccupantResource::collection($this->whenLoaded('occupants')),
            // Vacaciones registradas después de programar. El calendario pinta
            // "Vacaciones" en la tarjeta en vez de "Programado", porque ese
            // recorrido no va a poder salir tal y como está.
            'staff_on_vacation' => $this->whenLoaded('occupants', fn () => $this->staffOnVacation()),
        ];
    }
}
