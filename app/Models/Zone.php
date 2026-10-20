<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Zone extends Model
{
    /** Radio terrestre (WGS84) con el que se calcula el área. */
    private const EARTH_RADIUS_M = 6378137;

    /** Resolución de la rejilla con la que overlapsFor() estima el área común. */
    private const OVERLAP_GRID = 80;

    /** Por debajo de esto (m² y fracción de la zona más chica) el cruce es ruido del trazado. */
    private const OVERLAP_MIN_M2 = 500;

    private const OVERLAP_MIN_SHARE = 0.01;

    /** `area` no viene del formulario: la escribe replaceCoords() desde el perímetro. */
    protected $fillable = ['name', 'area', 'average_waste_kg', 'description', 'status', 'district_id'];

    protected function casts(): array
    {
        return ['area' => 'decimal:3', 'average_waste_kg' => 'decimal:2'];
    }

    public function isActive(): bool
    {
        return $this->status === 'activa';
    }

    /**
     * Las zonas que se ofrecen al programar o al crear rutas: solo las activas,
     * más las que ya tenga asignadas lo que se edita, para no perderlas del combo.
     *
     * @param  iterable<int>  $keepIds
     */
    public function scopeSelectable(Builder $query, iterable $keepIds = []): Builder
    {
        $keepIds = collect($keepIds)->filter()->all();

        return $query->where(fn (Builder $q) => $q
            ->where('status', 'activa')
            ->when($keepIds !== [], fn (Builder $q) => $q->orWhereIn('id', $keepIds)));
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
     * El área de la zona se recalcula con el perímetro nuevo.
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

            $this->update(['area' => self::areaFromCoords($coords)]);
        });
    }

    /**
     * Área en km² del polígono que forman los vértices, sobre la esfera
     * terrestre (la misma fórmula que Turf.js y el mapa de W14), o null si no
     * hay figura. Con menos de 3 puntos no se encierra nada.
     *
     * @param  list<array{latitude: float|string, longitude: float|string}>  $coords
     */
    public static function areaFromCoords(array $coords): ?float
    {
        $points = array_values($coords);
        $count = count($points);

        if ($count < 3) {
            return null;
        }

        $total = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $previous = $points[($i - 1 + $count) % $count];
            $current = $points[$i];
            $next = $points[($i + 1) % $count];

            $total += (deg2rad((float) $next['longitude']) - deg2rad((float) $previous['longitude']))
                * sin(deg2rad((float) $current['latitude']));
        }

        return round(abs($total) * self::EARTH_RADIUS_M ** 2 / 2 / 1_000_000, 3);
    }

    /**
     * Otras zonas cuyo perímetro se cruza con estos vértices, con cuánto terreno
     * comparten. Es un aviso, no un bloqueo: lo usa W14 mientras se dibuja y al
     * guardar.
     *
     * El área común se estima con una rejilla de OVERLAP_GRID² puntos sobre el
     * rectángulo que comparten las dos figuras: exacto de sobra para un aviso
     * y sin recortar polígonos. Las franjas de pocos metros que salen al trazar
     * pegado al borde de la zona vecina se ignoran (OVERLAP_MIN_*).
     *
     * @param  list<array{latitude: float|string, longitude: float|string}>  $coords
     * @return list<array{name: string, area_km2: float}>
     */
    public static function overlapsFor(array $coords, ?int $ignoreId = null): array
    {
        if (count($coords) < 3) {
            return [];
        }

        // Plano local en metros, centrado en la figura nueva: a la escala de un
        // distrito la deformación es despreciable.
        $lat0 = deg2rad(array_sum(array_column($coords, 'latitude')) / count($coords));
        $project = fn (array $point) => [
            (float) $point['longitude'] * 111_320 * cos($lat0),
            (float) $point['latitude'] * 110_574,
        ];

        $shape = array_map($project, array_values($coords));
        $shapeArea = self::planarArea($shape);
        $overlaps = [];

        $others = static::query()
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->has('coords')
            ->with('coords')
            ->orderBy('name')
            ->get();

        foreach ($others as $other) {
            $otherShape = $other->coords->map(fn (ZoneCoord $coord) => $project($coord->only('latitude', 'longitude')))->all();
            $shared = self::sharedArea($shape, $otherShape);
            $smaller = min($shapeArea, self::planarArea($otherShape));

            if ($shared >= self::OVERLAP_MIN_M2 && $shared >= $smaller * self::OVERLAP_MIN_SHARE) {
                $overlaps[] = ['name' => $other->name, 'area_km2' => round($shared / 1_000_000, 3)];
            }
        }

        return $overlaps;
    }

    /**
     * Área en m² que comparten dos polígonos del plano local.
     *
     * @param  list<array{0: float, 1: float}>  $a
     * @param  list<array{0: float, 1: float}>  $b
     */
    private static function sharedArea(array $a, array $b): float
    {
        [$minX, $minY, $maxX, $maxY] = self::boundsOf($a);
        [$minX2, $minY2, $maxX2, $maxY2] = self::boundsOf($b);

        // Rectángulo común; si no hay, las figuras ni se tocan.
        $minX = max($minX, $minX2);
        $minY = max($minY, $minY2);
        $maxX = min($maxX, $maxX2);
        $maxY = min($maxY, $maxY2);

        if ($minX >= $maxX || $minY >= $maxY) {
            return 0.0;
        }

        $stepX = ($maxX - $minX) / self::OVERLAP_GRID;
        $stepY = ($maxY - $minY) / self::OVERLAP_GRID;
        $inside = 0;

        for ($i = 0; $i < self::OVERLAP_GRID; $i++) {
            for ($j = 0; $j < self::OVERLAP_GRID; $j++) {
                // Centro de cada celda.
                $point = [$minX + ($i + 0.5) * $stepX, $minY + ($j + 0.5) * $stepY];

                if (self::contains($a, $point) && self::contains($b, $point)) {
                    $inside++;
                }
            }
        }

        return $inside * $stepX * $stepY;
    }

    /** Punto dentro del polígono, por el cruce de un rayo con sus lados. */
    private static function contains(array $polygon, array $point): bool
    {
        [$x, $y] = $point;
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $polygon[$i];
            [$xj, $yj] = $polygon[$j];

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function boundsOf(array $polygon): array
    {
        $xs = array_column($polygon, 0);
        $ys = array_column($polygon, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /** Área en m² en el plano local (fórmula del lazo). */
    private static function planarArea(array $polygon): float
    {
        $sum = 0.0;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $sum += $polygon[$j][0] * $polygon[$i][1] - $polygon[$i][0] * $polygon[$j][1];
        }

        return abs($sum) / 2;
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
