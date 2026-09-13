<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $fillable = ['name', 'area', 'description', 'sector_id', 'district_id'];

    protected function casts(): array
    {
        return ['area' => 'decimal:3'];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function coords(): HasMany
    {
        return $this->hasMany(ZoneCoord::class)->orderBy('position');
    }

    public function routes(): BelongsToMany
    {
        return $this->belongsToMany(Route::class, 'routezones')->withTimestamps();
    }

    public function schedulings(): HasMany
    {
        return $this->hasMany(Scheduling::class);
    }
}
