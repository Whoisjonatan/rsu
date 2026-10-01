<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class VehicleImage extends Model
{
    protected $table = 'vehicleimages';

    protected $fillable = ['image', 'profile', 'vehicle_id'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['profile' => 'boolean'];
    }

    protected function url(): Attribute
    {
        // Se guardan en el disco "public"; el disco por defecto es "local".
        return Attribute::get(fn () => Storage::disk('public')->url($this->image));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
