<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VehicleImage extends Model
{
    protected $table = 'vehicleimages';

    protected $fillable = ['image', 'profile', 'vehicle_id'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['profile' => 'boolean'];
    }

    protected function url(): Attribute
    {
        // Se guardan en el disco "public"; el disco por defecto es "local".
        return Attribute::get(fn () => Storage::disk('public')->url($this->image));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** La deja como única imagen de perfil de su vehículo. */
    public function makeProfile(): void
    {
        DB::transaction(function () {
            static::where('vehicle_id', $this->vehicle_id)->update(['profile' => false]);
            $this->update(['profile' => true]);
        });
    }

    /**
     * Borra la imagen y su archivo. Si era la de perfil, la siguiente imagen
     * disponible pasa a serlo.
     */
    public function deleteAndPromote(): void
    {
        Storage::disk('public')->delete($this->image);
        $wasProfile = $this->profile;
        $this->delete();

        if ($wasProfile) {
            static::where('vehicle_id', $this->vehicle_id)->oldest('id')->first()?->update(['profile' => true]);
        }
    }
}
