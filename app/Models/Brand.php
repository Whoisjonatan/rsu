<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Brand extends Model
{
    protected $fillable = ['name', 'description', 'logo'];

    // El front solo necesita la URL; la ruta interna del disco no sale en el JSON.
    protected $hidden = ['logo'];

    protected $appends = ['logo_url'];

    public function models(): HasMany
    {
        return $this->hasMany(BrandModel::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo ? Storage::disk('public')->url($this->logo) : null);
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        return $this->vehicles()->exists()
            ? 'No se puede eliminar: hay vehículos con esta marca.'
            : null;
    }

    /** Cambia el logo (o lo quita, con null) y borra el anterior del disco. */
    public function replaceLogo(?UploadedFile $file): void
    {
        if ($this->logo) {
            Storage::disk('public')->delete($this->logo);
        }

        $this->update(['logo' => $file?->store('brands', 'public')]);
    }

    public function deleteWithLogo(): void
    {
        if ($this->logo) {
            Storage::disk('public')->delete($this->logo);
        }

        $this->delete();
    }
}
