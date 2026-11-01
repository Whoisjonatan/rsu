<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ChangeReason;
use App\Models\Schedule;
use App\Models\SchedulingChange;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use App\Models\Zone;
use App\Services\Scheduling\BulkRescheduling;
use App\Services\Scheduling\Rescheduling;
use App\Support\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Gestión de cambios › Cambios: el historial de todos los cambios hechos a
 * las programaciones (turno, vehículo y personal), del más reciente al más
 * antiguo. Se filtra por la fecha en que se hizo el cambio y por su tipo.
 *
 * Desde aquí también se hace el cambio masivo (BulkRescheduling): el modal
 * pide el resumen (bulkPreview) y, si el usuario confirma, lo aplica
 * (bulkStore).
 */
class SchedulingChangeController extends Controller
{
    /** Filtro de tipo: el personal junta conductor y ayudante. */
    public const FILTER_TYPES = [
        'turno' => ['Turno', ['turno']],
        'vehiculo' => ['Vehículo', ['vehiculo']],
        'personal' => ['Personal', ['conductor', 'ayudante']],
    ];

    public function index(Request $request): View
    {
        $filters = [
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'type' => array_key_exists((string) $request->query('type'), self::FILTER_TYPES) ? $request->query('type') : null,
        ];

        // Un rango al revés se entiende igual: se ordenan los extremos.
        if (filled($filters['date_from']) && filled($filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
        }

        $changes = SchedulingChange::query()
            ->with(['user', 'scheduling' => fn ($q) => $q->with(['zone', 'schedule', 'general'])])
            ->when(filled($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->when($filters['type'], fn ($q) => $q->whereIn('type', self::FILTER_TYPES[$filters['type']][1]))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('scheduling-changes.index', [
            'changes' => $changes,
            'details' => $this->details($changes->getCollection()),
            'filters' => $filters,
            'types' => array_map(fn (array $type) => $type[0], self::FILTER_TYPES),
            'bulk' => $this->bulkCatalogs(),
        ]);
    }

    /**
     * Lo que enseña el modal "Ver" de cada fila, por id de cambio.
     *
     * Los valores anterior y nuevo se arman con lo que se guardó en el
     * momento del cambio (old_value, new_value) y se completan con el
     * registro actual (DNI, placa, nombre del vehículo). El turno sale entero
     * del texto guardado, que ya trae sus horas: si después se editara el
     * turno, el historial seguiría diciendo lo que era.
     *
     * @param  Collection<int, SchedulingChange>  $changes
     * @return array<int, array<string, mixed>>
     */
    private function details($changes): array
    {
        $ids = fn (array $types) => $changes->whereIn('type', $types)->flatMap(fn ($c) => [$c->old_id, $c->new_id])->filter()->unique()->values();
        $vehicles = Vehicle::whereKey($ids(['vehiculo']))->get()->keyBy('id');
        $people = User::whereKey($ids(['conductor', 'ayudante']))->get(['id', 'name', 'lastname', 'dni'])->keyBy('id');

        $side = function (SchedulingChange $change, ?int $id, ?string $value) use ($vehicles, $people): array {
            if ($value === null) {
                return [];
            }

            return match ($change->type) {
                'turno' => preg_match('/^(.*) \((\d{2}:\d{2}) - (\d{2}:\d{2})\)$/', $value, $m)
                    ? [['Turno', $m[1], 'clock'], ['Hora de entrada', $m[2], 'log-in'], ['Hora de salida', $m[3], 'log-out']]
                    : [['Turno', $value, 'clock']],
                'vehiculo' => ($vehicle = $vehicles->get($id))
                    ? [['Código', $vehicle->code, 'hash'], ['Vehículo', $vehicle->name ?: '—', 'truck'], ['Placa', $vehicle->plate, 'rectangle-horizontal']]
                    : [['Vehículo', $value, 'truck']],
                default => [
                    ['Nombre', $value, 'user'],
                    ['DNI', $people->get($id)?->dni ?? '—', 'id-card'],
                    ['Función', $change->type === 'conductor' ? 'Conductor' : 'Ayudante', 'briefcase'],
                ],
            };
        };

        return $changes->mapWithKeys(fn (SchedulingChange $change) => [$change->id => [
            'id' => $change->id,
            'type' => $change->type,
            'type_label' => $change->typeLabel(),
            'date' => $change->created_at->format('d/m/Y'),
            'time' => $change->created_at->format('H:i'),
            'user' => $change->user?->full_name ?? '—',
            'reason' => $change->reason,
            'description' => $change->description,
            'old' => $side($change, $change->old_id, $change->old_value),
            'new' => $side($change, $change->new_id, $change->new_value),
            'scheduling' => [
                'code' => $change->scheduling?->code ?? '—',
                'general' => $change->scheduling?->general?->code,
                'date' => $change->scheduling?->dateLabel(),
                'zone' => $change->scheduling?->zone?->name ?? '—',
                'schedule' => $change->scheduling?->schedule?->name ?? '—',
                'status' => $change->scheduling ? Status::label('scheduling', $change->scheduling->status) : null,
                'status_tone' => $change->scheduling ? Status::tone('scheduling', $change->scheduling->status) : 'slate',
            ],
        ]])->all();
    }

    /**
     * Elimina el cambio y lo deshace: la programación vuelve al turno,
     * vehículo o personal que tenía antes (ver Rescheduling::revert). Si eso
     * ya no es posible, no se borra nada y se dice por qué.
     */
    public function destroy(SchedulingChange $change): RedirectResponse
    {
        $change->loadMissing('scheduling');
        $code = $change->scheduling?->code;

        if ($error = Rescheduling::revert($change)) {
            return back()->with('error', $error);
        }

        return back()->with('success', $change->old_value
            ? "Cambio eliminado: la programación {$code} vuelve a tener {$change->old_value} en lugar de {$change->new_value}."
            : "Cambio de {$change->typeLabel()} de la programación {$code} eliminado.");
    }

    /** El resumen del cambio masivo: qué programaciones cambian y cuáles se omiten. No guarda nada. */
    public function bulkPreview(Request $request): JsonResponse
    {
        $input = BulkRescheduling::validator($request->all())->validate();

        return response()->json((new BulkRescheduling($input))->preview());
    }

    /** Aplica el cambio masivo ya confirmado. La página se recarga para enseñar el aviso. */
    public function bulkStore(Request $request): JsonResponse
    {
        $request->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Confirma que aplicarás los cambios.']);
        $input = BulkRescheduling::validator($request->all())->validate();
        $result = (new BulkRescheduling($input))->apply($request->user()?->id);

        if ($result['applied'] === 0) {
            return response()->json(['message' => 'No se modificó ninguna programación: ninguna de las del rango puede cambiar.'], 422);
        }

        $message = sprintf(
            'Cambio masivo aplicado: %s.',
            $result['applied'] === 1 ? '1 programación reprogramada' : "{$result['applied']} programaciones reprogramadas",
        );

        if ($result['skipped'] > 0) {
            $message .= sprintf(' %s.', $result['skipped'] === 1 ? 'Se omitió 1' : "Se omitieron {$result['skipped']}");
        }

        session()->flash('success', $message);

        return response()->json(['message' => $message, ...$result]);
    }

    /**
     * Los combos del modal de cambio masivo. Lo que se reemplaza puede ser
     * cualquiera (aunque ya no esté operativo o sin contrato: justo por eso se
     * cambia); lo nuevo, solo lo que se puede programar.
     *
     * @return array<string, mixed>
     */
    private function bulkCatalogs(): array
    {
        $today = now()->toDateString();
        $people = fn (string $slug) => User::query()
            ->ofFunction($slug)
            ->withExists(['contracts as has_contract' => fn ($c) => $c
                ->where(fn ($c) => $c->whereNull('end_date')->orWhere('end_date', '>=', $today))])
            ->orderBy('lastname')
            ->orderBy('name')
            ->get(['id', 'name', 'lastname', 'status'])
            ->map(fn (User $person) => [
                'id' => $person->id,
                'label' => $person->full_name,
                'selectable' => $person->status === 'activo' && $person->has_contract,
            ])
            ->values();

        return [
            'types' => BulkRescheduling::TYPES,
            'sections' => BulkRescheduling::REASON_SECTIONS,
            'reasons' => ChangeReason::options(),
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
            'options' => [
                'turno' => Schedule::orderBy('time_start')->get()
                    ->map(fn (Schedule $schedule) => ['id' => $schedule->id, 'label' => $schedule->label, 'selectable' => true]),
                'vehiculo' => Vehicle::orderBy('code')->get()
                    ->map(fn (Vehicle $vehicle) => [
                        'id' => $vehicle->id,
                        'label' => BulkRescheduling::vehicleLabel($vehicle),
                        'selectable' => $vehicle->status === 'operativo',
                    ]),
                'conductor' => $people(UserType::DRIVER),
                'ayudante' => $people(UserType::HELPER),
            ],
        ];
    }
}
