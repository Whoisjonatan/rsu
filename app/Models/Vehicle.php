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
}
