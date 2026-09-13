<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleOccupant extends Model
{
    protected $table = 'vehicleoccupants';

    protected $fillable = [
        'vehicleroute_id', 'user_id', 'usertype_id', 'status', 'replaced_user_id',
    ];

    public function vehicleRoute(): BelongsTo
    {
        return $this->belongsTo(VehicleRoute::class, 'vehicleroute_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usertype(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'usertype_id');
    }

    public function replacedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_user_id');
    }
}
