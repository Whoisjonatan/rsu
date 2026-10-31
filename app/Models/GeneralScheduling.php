<?php

namespace App\Models;

use App\Services\Scheduling\SchedulingRules;
use App\Support\Sequence;
use App\Support\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Programación general: lo que se registra en W18 o en la masiva (W19), "del
 * 03/11 al 30/11, Lun–Vie". No genera recorridos por sí misma: al registrarla
 * se desglosa en una programación por día (Scheduling), y cada una se
 * modifica, finaliza o elimina por separado. Esta queda como el registro de
 * lo que se pidió y agrupa sus días en la pestaña "General" de W17.
 */
class GeneralScheduling extends Model
{
    protected $table = 'general_schedulings';

    /** Relaciones que pinta la pestaña "General" de W17. */
    public const RELATIONS = ['zone', 'staffGroup', 'vehicle', 'schedule'];

    protected $fillable = [
        'zone_id', 'staffgroup_id', 'vehicle_id', 'schedule_id',
        'weekdays', 'worked_holidays', 'start_date', 'end_date', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $general) {
            $general->code ??= self::nextCode();
        });
    }

    /** Correlativo GEN-NNNN, aparte del PRG-NNNN de cada día. */
    public static function nextCode(): string
    {
        $highest = fn () => static::query()
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 4))
            ->max() ?? 0;

        return sprintf('GEN-%04d', Sequence::next('general_schedulings', $highest));
    }

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'worked_holidays' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Registra lo validado por SchedulingRules: la general y una programación
     * por cada día que cubre, salvo los que el grupo ya tiene programados en
     * ese turno ($skipDates). Con $target no se crea otra general: los días
     * nuevos se suman a esa y su rango se amplía.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $skipDates
     * @return Collection<int, Scheduling> Los días creados.
     */
    public static function register(array $data, array $skipDates = [], ?self $target = null): Collection
    {
        return DB::transaction(function () use ($data, $skipDates, $target) {
            $general = $target ?? new self;
            $general->fill($target ? SchedulingRules::mergeInto($target, $data) : $data)->save();

            $worked = SchedulingRules::normalizeDates($data['worked_holidays'] ?? []);
            $base = collect($data)->except(['weekdays', 'worked_holidays', 'start_date', 'end_date', 'skip_dates'])->all();

            return collect(SchedulingRules::occurrenceDates([...$data, 'skip_dates' => $skipDates]))
                ->map(fn (string $date) => (new Scheduling)->saveAndSync([
                    ...$base,
                    'general_scheduling_id' => $general->id,
                    'start_date' => $date,
                    'end_date' => $date,
                    'weekdays' => [(int) date('N', strtotime($date))],
                    'worked_holidays' => in_array($date, $worked, true) ? [$date] : [],
                ]))
                ->values();
        });
    }

    /**
     * Filtros de la pestaña "General" de W17. El rango de fechas deja las que
     * se cruzan con él en al menos un día.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['zone_id'] ?? null), fn ($q) => $q->where('zone_id', (int) $filters['zone_id']))
            ->when(filled($filters['schedule_id'] ?? null), fn ($q) => $q->where('schedule_id', (int) $filters['schedule_id']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->withStatus((string) $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($q) => $q->whereDate('end_date', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($q) => $q->whereDate('start_date', '<=', $filters['date_to']));
    }

    /** El estado que resulta de sus días (ver status()). */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'activa' => $query->whereHas('schedulings', fn ($q) => $q->inForce()),
            'inactiva' => $query->whereHas('schedulings', fn ($q) => $q->where('status', 'inactiva'))
                ->whereDoesntHave('schedulings', fn ($q) => $q->inForce()),
            'finalizada' => $query->whereHas('schedulings')
                ->whereDoesntHave('schedulings', fn ($q) => $q->where('status', '!=', 'finalizada')),
            default => $query,
        };
    }

    /**
     * No tiene estado propio: está activa mientras le quede un día en pie,
     * finalizada cuando todos terminaron e inactiva si los que quedan están
     * desactivados. Espera cargados sus días.
     */
    public function status(): string
    {
        $statuses = $this->schedulings->pluck('status');

        return match (true) {
            $statuses->intersect(Scheduling::IN_FORCE)->isNotEmpty() => 'activa',
            $statuses->isNotEmpty() && $statuses->every(fn (string $status) => $status === 'finalizada') => 'finalizada',
            default => 'inactiva',
        };
    }

    /**
     * Cuántos días tiene en cada estado: "20 días · 3 reprogramados".
     *
     * @return array<string, int>
     */
    public function dayCounts(): array
    {
        return $this->schedulings->countBy('status')->all();
    }

    public function isFinished(): bool
    {
        return $this->status() === 'finalizada';
    }

    /** "Lun, Mié, Vie" para las listas. */
    public function weekdaysLabel(): string
    {
        return (new Scheduling(['weekdays' => $this->weekdays]))->weekdaysLabel();
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        $started = VehicleRoute::query()
            ->whereIn('scheduling_id', $this->schedulings()->select('id'))
            ->where('status', '!=', 'programado')
            ->count();

        return $started > 0
            ? sprintf(
                'No se puede eliminar: la programación %s ya tiene %d %s. Finalízala o elimina los días que no empezaron desde la pestaña Detallada.',
                $this->code,
                $started,
                $started === 1 ? 'recorrido iniciado' : 'recorridos iniciados',
            )
            : null;
    }

    /** Por qué no se puede finalizar a mano, o null si se puede. */
    public function finishBlocker(): ?string
    {
        if (! $this->schedulings()->inForce()->exists()) {
            return "La programación {$this->code} no tiene días en pie.";
        }

        if ($this->start_date->toDateString() > now()->toDateString()) {
            return "La programación {$this->code} todavía no empieza: elimínala o desactiva sus días.";
        }

        return null;
    }

    /**
     * Finaliza hoy todos sus días en pie: los de hoy y antes pasan a
     * finalizada (ver Scheduling::finish) y los de mañana en adelante que no
     * arrancaron se eliminan. El rango termina hoy.
     */
    public function finish(): void
    {
        $today = now()->toDateString();

        DB::transaction(function () use ($today) {
            foreach ($this->schedulings()->inForce()->get() as $day) {
                if ($day->start_date->toDateString() <= $today) {
                    $day->finish();
                } elseif (! $day->deletionBlocker()) {
                    $day->delete();
                }
            }

            if ($this->exists && $this->end_date->toDateString() > $today) {
                $this->update(['end_date' => $today]);
            }
        });
    }

    /**
     * Lo que muestra el modal "Ver detalles" de la pestaña General: datos de
     * lo registrado y sus días. Espera cargados RELATIONS y los días con
     * sus relaciones.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [
            'code' => $this->code,
            'status' => [Status::label('scheduling', $this->status()), Status::tone('scheduling', $this->status())],
            'group' => $this->staffGroup?->name,
            'zone' => $this->zone?->name,
            'schedule' => $this->schedule?->label,
            'vehicle' => $this->vehicle?->plate,
            'dates' => $this->start_date->format('d/m/Y').' al '.$this->end_date->format('d/m/Y'),
            'weekdays' => $this->weekdaysLabel(),
            'days' => $this->schedulings->map(fn (Scheduling $day) => [
                'id' => $day->id,
                'code' => $day->code,
                'date' => $day->dateLabel(),
                'vehicle' => $day->vehicle?->plate ?? '—',
                'schedule' => $day->schedule?->name ?? '—',
                'driver' => $day->driver?->full_name ?? '—',
                'helpers' => $day->helpersLabel() ?: '—',
                'status' => [Status::label('scheduling', $day->status), Status::tone('scheduling', $day->status)],
            ])->values()->all(),
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function staffGroup(): BelongsTo
    {
        return $this->belongsTo(StaffGroup::class, 'staffgroup_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** Sus días, en orden de fecha. */
    public function schedulings(): HasMany
    {
        return $this->hasMany(Scheduling::class)->orderBy('start_date')->orderBy('id');
    }
}
