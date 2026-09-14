<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El personal de una salida. "reemplazado" marca al saliente y guarda en
 * replaced_user_id quién entró en su lugar, que es lo que W21 necesita mostrar.
 *
 * @mixin \App\Models\VehicleOccupant
 */
class VehicleOccupantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'full_name' => $this->whenLoaded('user', fn () => $this->user->full_name),
            'dni' => $this->whenLoaded('user', fn () => $this->user->dni),
            'usertype_id' => $this->usertype_id,
            'usertype' => $this->whenLoaded('usertype', fn () => $this->usertype->name),
            'status' => $this->status,
            'replaced_user_id' => $this->replaced_user_id,
            'replaced_by' => $this->whenLoaded('replacedUser', fn () => $this->replacedUser?->full_name),
        ];
    }
}
