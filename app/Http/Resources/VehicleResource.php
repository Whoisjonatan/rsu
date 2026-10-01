<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'plate' => $this->plate,
            'name' => $this->name,
            'year' => $this->year,
            'occupant_capacity' => $this->occupant_capacity,
            'load_capacity' => (float) $this->load_capacity,
            'fuel_capacity' => $this->fuel_capacity !== null ? (float) $this->fuel_capacity : null,
            'compaction_capacity' => $this->compaction_capacity !== null ? (float) $this->compaction_capacity : null,
            'engine_number' => $this->engine_number,
            'description' => $this->description,
            'status' => $this->status,
            'brand_id' => $this->brand_id,
            'model_id' => $this->model_id,
            'type_id' => $this->type_id,
            'color_id' => $this->color_id,
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'model' => $this->whenLoaded('model', fn () => [
                'id' => $this->model->id,
                'name' => $this->model->name,
            ]),
            'type' => $this->whenLoaded('type', fn () => [
                'id' => $this->type->id,
                'name' => $this->type->name,
            ]),
            'color' => $this->whenLoaded('color', fn () => $this->color ? [
                'id' => $this->color->id,
                'name' => $this->color->name,
                'hex' => $this->color->hex,
            ] : null),
            'images' => VehicleImageResource::collection($this->whenLoaded('images')),
            'profile_image' => $this->whenLoaded(
                'profileImage',
                fn () => $this->profileImage ? new VehicleImageResource($this->profileImage) : null,
            ),
        ];
    }
}
