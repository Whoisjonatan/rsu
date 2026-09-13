<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lastname' => $this->lastname,
            'full_name' => $this->full_name,
            'dni' => $this->dni,
            'email' => $this->email,
            'phone' => $this->phone,
            'license' => $this->license,
            'address' => $this->address,
            'birthdate' => $this->birthdate?->toDateString(),
            'status' => $this->status,
            'usertype' => $this->whenLoaded('usertype', fn () => [
                'id' => $this->usertype->id,
                'name' => $this->usertype->name,
                'slug' => $this->usertype->slug,
            ]),
            'zone' => $this->whenLoaded('zone', fn () => [
                'id' => $this->zone?->id,
                'name' => $this->zone?->name,
            ]),
        ];
    }
}
