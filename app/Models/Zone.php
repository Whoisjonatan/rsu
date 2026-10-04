<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        return $this->schedulings()->exists()
            ? 'No se puede eliminar: la zona tiene programaciones registradas.'
            : null;
    }

    /**
     * Guarda el perímetro (W14) completo de una vez.
     *
     * Entero y no vértice a vértice: dibujar un polígono es añadir, mover y
     * borrar puntos hasta que cierra, e ir sincronizando cada clic dejaría
     * perímetros a medias si algo falla. Vacío significa "borrar el perímetro".
     *
     * @param  list<array{latitude: float|string, longitude: float|string}>  $coords
     */
    public function replaceCoords(array $coords): void
    {
        DB::transaction(function () use ($coords) {
            $this->coords()->delete();

            foreach (array_values($coords) as $position => $point) {
                $this->coords()->create([
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    // El orden importa: un polígono es una secuencia, no un
                    // conjunto; al revés dibujaría otra figura.
                    'position' => $position,
                ]);
            }
        });
    }

    /**
     * Reglas del perímetro. Con uno o dos puntos no se encierra nada.
     *
     * @return array<string, mixed>
     */
    public static function coordsRules(): array
    {
        return [
            'coords' => ['present', 'array', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_array($value) && $value !== [] && count($value) < 3) {
                    $fail('Un perímetro necesita al menos 3 puntos.');
                }
            }],
            'coords.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'coords.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
