<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Schedule extends Model
{
    protected $fillable = ['name', 'time_start', 'time_end', 'description'];

    protected $appends = ['label'];

    /** "Mañana (06:00 - 12:00)", como lo muestran los selectores de turno. */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => sprintf(
            '%s (%s - %s)',
            $this->name,
            substr((string) $this->time_start, 0, 5),
            substr((string) $this->time_end, 0, 5),
        ));
    }

    public function schedulings(): HasMany
    {
        return $this->hasMany(Scheduling::class);
    }

    public function vehicleRoutes(): HasMany
    {
        return $this->hasMany(VehicleRoute::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        return $this->schedulings()->exists() || $this->vehicleRoutes()->exists()
            ? 'No se puede eliminar: el turno tiene programaciones o recorridos.'
            : null;
    }

    /** Hora de inicio del turno en la fecha dada. */
    public function startsAt(CarbonImmutable $date): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $this->time_start));

        return $date->startOfDay()->addHours($hour)->addMinutes($minute);
    }

    /**
     * Ventana de marcacion del turno para esa fecha.
     *
     * @return array{opens: CarbonImmutable, grace: CarbonImmutable, closes: CarbonImmutable}
     */
    public function markingWindow(CarbonImmutable $date): array
    {
        $start = $this->startsAt($date);

        return [
            'opens' => $start->subMinutes(Attendance::OPENS_BEFORE),
            'grace' => $start->addMinutes(Attendance::GRACE_AFTER),
            'closes' => $start->addMinutes(Attendance::CLOSES_AFTER),
        ];
    }

    public function windowCovers(CarbonImmutable $moment): bool
    {
        $window = $this->markingWindow($moment);

        return $moment->betweenIncluded($window['opens'], $window['closes']);
    }

    /**
     * El turno cuya ventana de marcacion cubre ese instante. Si varias se
     * solapan, gana la que empieza mas cerca.
     */
    public static function forMarking(CarbonImmutable $moment): ?self
    {
        return static::all()
            ->filter(fn (self $schedule) => $schedule->windowCovers($moment))
            ->sortBy(fn (self $schedule) => abs($schedule->startsAt($moment)->diffInSeconds($moment)))
            ->first();
    }

    /**
     * Las ventanas de todos los turnos, para mostrarlas en el terminal cuando
     * la marcacion cae fuera de horario.
     *
     * @return Collection<int, array{name: string, opens: string, closes: string}>
     */
    public static function markingWindows(CarbonImmutable $date): Collection
    {
        return static::orderBy('time_start')->get()->map(function (self $schedule) use ($date) {
            $window = $schedule->markingWindow($date);

            return [
                'name' => $schedule->name,
                'opens' => $window['opens']->format('H:i'),
                'closes' => $window['closes']->format('H:i'),
            ];
        });
    }
}
