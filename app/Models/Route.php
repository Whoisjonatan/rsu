<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    protected $fillable = [
        'name', 'latitude_start', 'longitude_start',
        'latitude_end', 'longitude_end', 'description', 'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude_start' => 'float',
            'longitude_start' => 'float',
            'latitude_end' => 'float',
            'longitude_end' => 'float',
        ];
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'routezones')->withTimestamps();
    }

    public function schedulings(): HasMany
    {
        return $this->hasMany(Scheduling::class);
    }
}
