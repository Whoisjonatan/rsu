<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleRoute extends Model
{
    protected $table = 'vehicleroutes';

    protected $fillable = [
        'scheduling_id', 'date_route', 'time_route', 'vehicle_id',
        'route_id', 'schedule_id', 'description', 'status', 'block_reason',
    ];

    protected function casts(): array
    {
        return ['date_route' => 'date:Y-m-d'];
    }

    public function scheduling(): BelongsTo
    {
        return $this->belongsTo(Scheduling::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function occupants(): HasMany
    {
        return $this->hasMany(VehicleOccupant::class, 'vehicleroute_id');
    }

    public function paths(): HasMany
    {
        return $this->hasMany(RoutePath::class, 'vehicleroute_id');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date_route', $date);
    }

    public function canStart(): bool
    {
        return $this->status === 'programado';
    }
}
