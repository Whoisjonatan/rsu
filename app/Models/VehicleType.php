<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VehicleType extends Model
{
    protected $table = 'vehicletypes';

    protected $fillable = ['name', 'description'];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'type_id');
    }

    /**
     * La capacidad de compactación solo tiene sentido en un compactador. El
     * tipo no tiene un código propio, así que se reconoce por el nombre, sin
     * importar tildes ni mayúsculas ("Compactador", "Compactadora"...).
     */
    public function isCompactor(): bool
    {
        return str_starts_with(Str::slug($this->name), 'compactador');
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        return $this->vehicles()->exists()
            ? 'No se puede eliminar: hay vehículos de este tipo.'
            : null;
    }
}
