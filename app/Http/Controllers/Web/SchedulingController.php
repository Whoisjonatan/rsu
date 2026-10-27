<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchedulingRequest;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\Scheduling;
use App\Models\StaffGroup;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use App\Models\VehicleRoute;
use App\Models\Zone;
use App\Services\Scheduling\Rescheduling;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Programación (W17, W18), programación masiva (W19) y calendario semanal (W20).
 *
 * Guardar una programación escribe la regla maestra y genera los recorridos
 * de cada día (ver Scheduling::saveAndSync). Las partes en vivo de estas
 * pantallas (checklist de W18, simulación de W19, inicio de recorrido W21)
 * consultan el API con la sesión, para no tener dos juegos de reglas.
 */
class SchedulingController extends Controller
{
    /** W17 Listado de programaciones. */
    public function index(Request $request): View
    {
        Scheduling::closeFinished();

        // El rango acota a las vigentes en algún día entre "desde" y "hasta".
        // Llega marcado con hoy en los dos extremos (las del día); vaciarlos
        // enseña todas. Si el rango es un solo día, además decide de qué día
        // se muestra el estado del recorrido.
        $today = now()->toDateString();
        $filters = [
            'zone_id' => $request->query('zone_id'),
            'schedule_id' => $request->query('schedule_id'),
            'status' => $request->query('status'),
            'date_from' => $request->has('date_from') ? $request->query('date_from') : $today,
            'date_to' => $request->has('date_to') ? $request->query('date_to') : $today,
        ];

        // Un rango al revés se entiende igual: se ordenan los extremos.
        if (filled($filters['date_from']) && filled($filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
        }

        $filters['date'] = filled($filters['date_from']) && $filters['date_from'] === $filters['date_to']
            ? $filters['date_from']
            : null;

        $schedulings = Scheduling::query()
            // El historial de cambios lo pide el modal "Ver detalles".
            ->with([...Scheduling::RELATIONS, 'history'])
            ->filter($filters)
            ->orderByDesc('start_date')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        if (filled($filters['date'])) {
            Scheduling::attachDayStatus($schedulings->getCollection(), $filters['date']);
        }

        return view('schedulings.index', [
            'schedulings' => $schedulings,
            'filters' => $filters,
            'summary' => Scheduling::summary(),
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
            'schedules' => Schedule::orderBy('time_start')->get(),
        ]);
    }

    /** W18 Nueva programación. */
    public function create(Request $request): View
    {
        // "Programar" desde Grupos de personal llega con ?grupo=ID y lo deja elegido.
        return view('schedulings.form', [
            'scheduling' => null,
            'groupId' => $request->integer('grupo') ?: null,
            ...$this->formData(),
        ]);
    }

    public function store(SchedulingRequest $request): RedirectResponse
    {
        // Mismo grupo, mismo turno y fechas cruzadas: se amplía la que había.
        if ($target = $request->mergeTarget()) {
            $target->saveAndSync($request->validated());

            return redirect()->route('schedulings.index')->with(
                'success',
                "Ya existía la programación {$target->code} para ese grupo y turno: se actualizó al "
                .$target->start_date->format('d/m/Y').' – '.$target->end_date->format('d/m/Y')
                .' ('.$target->weekdaysLabel().').',
            );
        }

        $scheduling = (new Scheduling)->saveAndSync($request->validated());
        $count = $scheduling->vehicleRoutes()->count();

        return redirect()->route('schedulings.index')->with(
            'success',
            "Programación {$scheduling->code} registrada: {$count} ".($count === 1 ? 'recorrido generado.' : 'recorridos generados.'),
        );
    }

    /**
     * Modificar una programación: cambios de turno, vehículo o personal, cada
     * uno con su motivo, y el historial de los que ya se hicieron.
     */
    public function edit(Scheduling $scheduling): View|RedirectResponse
    {
        if ($scheduling->isFinished()) {
            return redirect()->route('schedulings.index')->with('error', "La programación {$scheduling->code} ya finalizó: no se puede modificar.");
        }

        $scheduling->load([...Scheduling::RELATIONS, 'staffGroup', 'history.user']);
        $data = $this->formData($scheduling);

        return view('schedulings.reschedule', [
            'scheduling' => $scheduling,
            'schedules' => $data['schedules'],
            // Operativos más el actual aunque esté en taller; como nuevo solo se ofrecen los operativos.
            'vehicles' => $data['vehicles'],
            'drivers' => $data['drivers'],
            'helpers' => $data['helpers'],
        ]);
    }

    /** Aplica los cambios agregados en la pantalla: la programación queda "reprogramada". */
    public function update(Request $request, Scheduling $scheduling): RedirectResponse
    {
        if ($scheduling->isFinished()) {
            return redirect()->route('schedulings.index')->with('error', "La programación {$scheduling->code} ya finalizó: no se puede modificar.");
        }

        $input = $request->validate(Rescheduling::rules(), Rescheduling::messages());
        $rescheduling = new Rescheduling($scheduling, $input['changes']);

        if ($rescheduling->errors() !== []) {
            return back()->withInput()->withErrors(['changes' => $rescheduling->errors()]);
        }

        $rescheduling->save($request->user()?->id);
        $count = count($input['changes']);

        return redirect()->route('schedulings.index')->with(
            'success',
            "Programación {$scheduling->code} reprogramada: {$count} ".($count === 1 ? 'cambio guardado.' : 'cambios guardados.'),
        );
    }

    public function destroy(Scheduling $scheduling): RedirectResponse
    {
        if ($blocker = $scheduling->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $scheduling->delete();

        return back()->with('success', "Programación {$scheduling->code} eliminada.");
    }

    /** Finalizar a mano, antes de su fecha de término. */
    public function finish(Scheduling $scheduling): RedirectResponse
    {
        if ($blocker = $scheduling->finishBlocker()) {
            return back()->with('error', $blocker);
        }

        $scheduling->finish();

        return back()->with('success', "Programación {$scheduling->code} finalizada.");
    }

    /**
     * W19 Programación masiva: todos los grupos de personal activos en un rango
     * de fechas. Validar y guardar van al API (BulkScheduling), que revisa el
     * lote entero y devuelve las inconsistencias grupo por grupo.
     */
    public function bulk(): View
    {
        $data = $this->formData();

        return view('schedulings.bulk', [
            ...$data,
            'groups' => $data['groups']->load(['zone', 'schedule', 'vehicle']),
            // Pocos al año: van todos y la pantalla muestra los del rango.
            'holidays' => Holiday::active()->orderBy('date')->get(['date', 'description']),
        ]);
    }

    /** W20 Calendario semanal. El inicio de recorrido (W21) es un modal de esta pantalla. */
    public function calendar(Request $request): View
    {
        $start = $request->filled('semana')
            ? CarbonImmutable::parse($request->query('semana'))
            : CarbonImmutable::now();
        // La semana empieza en lunes (ISO).
        $monday = $start->startOfWeek(CarbonImmutable::MONDAY);
        $days = collect(range(0, 6))->map(fn (int $offset) => $monday->addDays($offset));

        $routes = VehicleRoute::query()
            ->with(['vehicle', 'schedule', 'scheduling.zone', 'occupants.user.vacations'])
            ->whereDate('date_route', '>=', $days->first()->toDateString())
            ->whereDate('date_route', '<=', $days->last()->toDateString())
            ->when($request->filled('zone_id'), fn ($q) => $q->whereHas(
                'scheduling',
                fn ($s) => $s->where('zone_id', $request->integer('zone_id')),
            ))
            ->when($request->filled('schedule_id'), fn ($q) => $q->where('schedule_id', $request->integer('schedule_id')))
            ->orderBy('date_route')
            ->orderBy('schedule_id')
            ->get()
            ->groupBy(fn (VehicleRoute $route) => $route->date_route->toDateString());

        return view('schedulings.calendar', [
            'days' => $days,
            'routes' => $routes,
            'holidays' => Holiday::active()
                ->whereDate('date', '>=', $days->first()->toDateString())
                ->whereDate('date', '<=', $days->last()->toDateString())
                ->get()
                ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString()),
            'filters' => $request->only(['zone_id', 'schedule_id']),
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
            'schedules' => Schedule::orderBy('time_start')->get(),
        ]);
    }

    /**
     * Los combos de W18 y W19.
     *
     * @return array<string, mixed>
     */
    private function formData(?Scheduling $current = null): array
    {
        // Zona, turno y vehículo salen del grupo de personal, así que los
        // catálogos conservan los de cada grupo aunque ya no se ofrecerían
        // (zona inactiva, vehículo en taller): la ficha del grupo los nombra y
        // la validación dice por qué no se puede programar. Al editar se
        // conserva además el grupo de la programación aunque esté inactivo.
        $groups = StaffGroup::with('helpers:id')
            ->where(fn ($q) => $q->active()->when($current?->staffgroup_id, fn ($q) => $q->orWhere('id', $current->staffgroup_id)))
            ->orderBy('name')
            ->get();

        // Solo entra quien tiene un contrato vigente o por empezar: es el contrato
        // el que habilita a programar a alguien. La fecha exacta la sigue validando
        // SchedulingRules, porque la programación puede caer fuera del contrato.
        // Al editar, cada combo conserva a su titular aunque ya no cumpla, y la
        // tripulación de los grupos se conserva porque es la que se precarga.
        $today = now()->toDateString();
        $staff = fn (string $slug, array $keep) => User::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->ofFunction($slug)->whereHas('contracts', fn ($c) => $c
                    ->where(fn ($c) => $c->whereNull('end_date')->orWhere('end_date', '>=', $today))))
                ->when($keep !== [], fn ($q) => $q->orWhereIn('id', $keep)))
            ->orderBy('lastname')
            ->orderBy('name')
            ->get(['id', 'name', 'lastname', 'dni']);

        $keep = fn (iterable $ids) => collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $vehicleIds = $keep($groups->pluck('vehicle_id')->push($current?->vehicle_id));

        return [
            // Solo las activas, más las de los grupos y la de la programación.
            'zones' => Zone::selectable($groups->pluck('zone_id')->push($current?->zone_id))->orderBy('name')->get(['id', 'name']),
            'schedules' => Schedule::orderBy('time_start')->get(),
            // Solo los operativos, más los de los grupos y el de la programación.
            'vehicles' => Vehicle::with(['brand', 'model', 'type'])
                ->where(fn ($q) => $q->operational()->when($vehicleIds !== [], fn ($q) => $q->orWhereIn('id', $vehicleIds)))
                ->orderBy('code')
                ->get(),
            'drivers' => $staff(UserType::DRIVER, $keep($groups->pluck('driver_id')->push($current?->driver_id))),
            'helpers' => $staff(UserType::HELPER, $keep($groups->flatMap->helperIds()->concat($current?->helperIds() ?? []))),
            // Plantillas que precargan todo salvo las fechas.
            'groups' => $groups,
        ];
    }
}
