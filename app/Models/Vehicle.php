<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Vehicle extends Model
{
    /** Formatos de placa aceptados por el informe: XXXXXX, XX-XXXX y XXX-XXX. */
    public const PLATE_REGEX = '/^([A-Z0-9]{6}|[A-Z0-9]{2}-[A-Z0-9]{4}|[A-Z0-9]{3}-[A-Z0-9]{3})$/';

    protected $fillable = [
        'name', 'code', 'plate', 'year', 'occupant_capacity', 'load_capacity',
        'fuel_capacity', 'compaction_capacity', 'engine_number', 'description', 'status',
        'brand_id', 'model_id', 'type_id', 'color_id',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'occupant_capacity' => 'integer',
            'load_capacity' => 'decimal:2',
            'fuel_capacity' => 'decimal:2',
            'compaction_capacity' => 'decimal:2',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(BrandModel::class, 'model_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'type_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(VehicleColor::class, 'color_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(VehicleImage::class);
    }

    public function profileImage(): HasOne
    {
        return $this->hasOne(VehicleImage::class)->where('profile', true);
    }

    /**
     * Guarda la foto en disco y la registra. Queda como perfil si se pide o
     * si el vehiculo aun no tiene una; en ese caso desplaza a la anterior.
     */
    public function addImage(UploadedFile $file, bool $asProfile = false): VehicleImage
    {
        $path = $file->store('vehicles/'.$this->id, 'public');
        $asProfile = $asProfile || ! $this->images()->where('profile', true)->exists();

        try {
            return DB::transaction(function () use ($path, $asProfile) {
                if ($asProfile) {
                    $this->images()->update(['profile' => false]);
                }

                return $this->images()->create(['image' => $path, 'profile' => $asProfile]);
            });
        } catch (Throwable $e) {
            Storage::disk('public')->delete($path);

            throw $e;
        }
    }

    public function schedulings(): HasMany
    {
        return $this->hasMany(Scheduling::class);
    }

    public function vehicleRoutes(): HasMany
    {
        return $this->hasMany(VehicleRoute::class);
    }

    /**
     * Misma placa sin importar el guion: B2K841 y B2K-841 son el mismo vehiculo.
     */
    public function scopeWithPlate(Builder $query, string $plate): Builder
    {
        return $query->whereRaw("REPLACE(plate, '-', '') = ?", [str_replace('-', '', strtoupper($plate))]);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('status', 'operativo');
    }

    /** Asientos para ayudantes: la capacidad de ocupantes menos el conductor. */
    public function helperSeats(): int
    {
        return max(0, $this->occupant_capacity - 1);
    }

    /** "Capacidad: 3 · 1 conductor + 2 ayudantes", lo que muestran los selectores de vehículo. */
    public function crewLabel(): string
    {
        $seats = $this->helperSeats();

        return sprintf(
            'Capacidad: %d · 1 conductor + %s',
            $this->occupant_capacity,
            $seats === 1 ? '1 ayudante' : "{$seats} ayudantes",
        );
    }

    /**
     * Busca por codigo, placa, nombre o modelo.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('code', 'like', $like)
                ->orWhere('plate', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhereHas('model', fn (Builder $m) => $m->where('name', 'like', $like))
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $like));
        });
    }

    /**
     * Contadores por estado: la barra superior de W04 y el panel (W02).
     *
     * @return array{total: int, operativo: int, mantenimiento: int, baja: int}
     */
    public static function summary(): array
    {
        $counts = static::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'operativo' => (int) ($counts['operativo'] ?? 0),
            'mantenimiento' => (int) ($counts['mantenimiento'] ?? 0),
            'baja' => (int) ($counts['baja'] ?? 0),
        ];
    }

    /**
     * Por qué no se puede eliminar, o null si se puede. Lo comparten el API y
     * las vistas Blade para no tener dos versiones de la misma regla.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->schedulings()->exists() || $this->vehicleRoutes()->exists()) {
            return 'No se puede eliminar: el vehículo tiene programaciones o recorridos registrados.';
        }

        if (StaffGroup::where('vehicle_id', $this->id)->exists()) {
            return 'No se puede eliminar: el vehículo está en un grupo de personal.';
        }

        return null;
    }

    /**
     * Elimina el vehículo y sus fotos. Las filas de vehicleimages caen en
     * cascada; los archivos hay que borrarlos a mano.
     */
    public function deleteWithImages(): void
    {
        $this->delete();
        Storage::disk('public')->deleteDirectory('vehicles/'.$this->id);
    }

    /**
     * Alta de la ficha junto con sus fotos. Si una foto falla no queda un
     * vehículo a medias, ni archivos huérfanos en disco.
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $images
     */
    public static function createWithImages(array $data, array $images = [], int $profileIndex = 0): self
    {
        $created = null;

        try {
            return DB::transaction(function () use ($data, $images, $profileIndex, &$created) {
                $created = static::create($data);

                foreach (array_values($images) as $index => $file) {
                    $created->addImage($file, $index === $profileIndex);
                }

                return $created;
            });
        } catch (Throwable $e) {
            if ($created) {
                Storage::disk('public')->deleteDirectory('vehicles/'.$created->id);
            }

            throw $e;
        }
    }
}
