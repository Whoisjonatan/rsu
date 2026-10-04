<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleType extends Model
{
    protected $table = 'vehicletypes';

    protected $fillable = ['name', 'description'];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'type_id');
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        return $this->vehicles()->exists()
            ? 'No se puede eliminar: hay vehículos de este tipo.'
            : null;
    }
}
