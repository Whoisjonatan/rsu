<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleColor extends Model
{
    protected $table = 'vehiclecolors';

    protected $fillable = ['name', 'description', 'red', 'green', 'blue'];

    protected $appends = ['hex'];

    protected function casts(): array
    {
        return [
            'red' => 'integer',
            'green' => 'integer',
            'blue' => 'integer',
        ];
    }

    protected function hex(): Attribute
    {
        return Attribute::get(
            fn () => sprintf('#%02X%02X%02X', $this->red, $this->green, $this->blue)
        );
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'color_id');
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        return $this->vehicles()->exists()
            ? 'No se puede eliminar: hay vehículos con este color.'
            : null;
    }
}
