<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Route extends Model
{
    /**
     * Guarda la ruta y sus zonas juntas (W15): una ruta pertenece al menos a
     * una zona, así que no debe quedar a medias.
     *
     * @param  array<string, mixed>  $data  Lo validado por RouteRequest, con zone_ids.
     */
    public function saveWithZones(array $data): self
    {
        DB::transaction(function () use ($data) {
            $this->fill($data)->save();
            $this->zones()->sync($data['zone_ids']);
        });

        return $this;
    }

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

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        // Una ruta en uso no se borra: dejaría programaciones apuntando al aire.
        $count = $this->schedulings()->count();

        return $count > 0
            ? sprintf(
                'No se puede eliminar: la ruta está usada por %d %s. Cámbiala a inactiva.',
                $count,
                $count === 1 ? 'programación' : 'programaciones',
            )
            : null;
    }
}
