<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePath extends Model
{
    protected $table = 'routepaths';

    protected $fillable = ['latitude', 'longitude', 'vehicleroute_id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function vehicleRoute(): BelongsTo
    {
        return $this->belongsTo(VehicleRoute::class, 'vehicleroute_id');
    }
}
