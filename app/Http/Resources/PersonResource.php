<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contract = $this->currentContract();

        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'name' => $this->name,
            'lastname' => $this->lastname,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'birthdate' => $this->birthdate?->toDateString(),
            'license' => $this->license,
            'license_category' => $this->license_category,
            'license_expiration' => $this->license_expiration?->toDateString(),
            'has_valid_license' => $this->hasValidLicense(),
            'photo_url' => $this->profile_photo_path
                ? asset('storage/'.$this->profile_photo_path)
                : null,
            'status' => $this->status,
            // Estado derivado de contrato y vacaciones, que es lo que pinta W07.
            'operational_status' => $this->operationalStatus(),
            'usertype_id' => $this->usertype_id,
            'zone_id' => $this->zone_id,
            'usertype' => $this->whenLoaded('usertype', fn () => [
                'id' => $this->usertype->id,
                'name' => $this->usertype->name,
                'slug' => $this->usertype->slug,
            ]),
            'zone' => $this->whenLoaded('zone', fn () => $this->zone ? [
                'id' => $this->zone->id,
                'name' => $this->zone->name,
            ] : null),
            'current_contract' => $contract ? [
                'id' => $contract->id,
                'code' => $contract->code,
                'type' => $contract->type,
                'start_date' => $contract->start_date->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
            ] : null,
        ];
    }
}
