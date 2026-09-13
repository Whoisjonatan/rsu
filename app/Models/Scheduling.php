<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scheduling extends Model
{
    protected $fillable = [
        'zone_id', 'route_id', 'vehicle_id', 'schedule_id',
        'driver_id', 'helper_id', 'weekdays', 'start_date', 'end_date', 'status',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function helper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'helper_id');
    }

    public function vehicleRoutes(): HasMany
    {
        return $this->hasMany(VehicleRoute::class);
    }

    /**
     * Fechas que cubre la programacion, solo en los dias de semana marcados.
     *
     * @return list<string>
     */
    public function occurrenceDates(): array
    {
        $days = array_map('intval', $this->weekdays ?? []);

        return collect(CarbonPeriod::create($this->start_date, $this->end_date))
            ->filter(fn ($date) => in_array($date->dayOfWeekIso, $days, true))
            ->map(fn ($date) => $date->toDateString())
            ->values()
            ->all();
    }
}
