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
        return Attribute::get(fn () => Storage::url($this->image));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
