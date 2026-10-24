<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Grupo de personal: plantilla de programación con turno, zona, vehículo,
 * días y tripulación. Al programar desde un grupo solo falta el rango de
 * fechas; las validaciones de fechas (contrato, vacaciones, duplicidad) las
 * sigue haciendo SchedulingRules al guardar la programación.
 */
class StaffGroup extends Model
{
    protected $table = 'staffgroups';

    public const RELATIONS = ['schedule', 'zone', 'vehicle.type', 'driver', 'helpers'];

    protected $fillable = ['name', 'schedule_id', 'zone_id', 'vehicle_id', 'driver_id', 'weekdays', 'status'];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** Ayudantes, en el orden en que se eligieron. */
    public function helpers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staffgrouphelpers', 'staffgroup_id', 'user_id')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'activo');
    }

    /** Grupos donde la persona va de conductor o de ayudante. */
    public function scopeWithMember(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('driver_id', $userId)
            ->orWhereHas('helpers', fn (Builder $h) => $h->whereKey($userId)));
    }

    /** @return list<int> */
    public function helperIds(): array
    {
        return $this->helpers->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Guarda el grupo y sus ayudantes juntos.
     *
     * @param  array<string, mixed>  $data  Lo validado por StaffGroupRequest.
     */
    public function saveWithHelpers(array $data): self
    {
        DB::transaction(function () use ($data) {
            $this->fill($data)->save();

            $this->helpers()->sync(collect($data['helper_ids'] ?? [])->values()->mapWithKeys(
                fn (int $id, int $index) => [$id => ['position' => $index + 1]],
            )->all());

            $this->unsetRelation('helpers');
        });

        return $this;
    }

    /** "Lun, Mié, Vie". */
    public function weekdaysLabel(): string
    {
        $days = array_map('intval', $this->weekdays ?? []);
        sort($days);

        return implode(', ', array_map(fn (int $day) => Scheduling::WEEKDAY_LABELS[$day] ?? (string) $day, $days));
    }

    /**
     * Lo que el grupo precarga en una programación nueva: todo menos las
     * fechas.
     *
     * @return array<string, mixed>
     */
    public function schedulingDefaults(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'zone_id' => (string) $this->zone_id,
            'schedule_id' => (string) $this->schedule_id,
            'vehicle_id' => (string) $this->vehicle_id,
            'driver_id' => (string) $this->driver_id,
            'helper_ids' => array_map('strval', $this->helperIds()),
            'weekdays' => array_map('intval', $this->weekdays ?? []),
        ];
    }
}
