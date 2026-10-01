<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
