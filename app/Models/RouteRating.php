<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Calificación de un ciudadano al servicio de un recorrido finalizado. */
class RouteRating extends Model
{
    protected $table = 'route_ratings';

    protected $fillable = ['vehicleroute_id', 'user_id', 'stars', 'comment'];

    protected function casts(): array
    {
        return ['stars' => 'integer'];
    }

    public function vehicleRoute(): BelongsTo
    {
        return $this->belongsTo(VehicleRoute::class, 'vehicleroute_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
