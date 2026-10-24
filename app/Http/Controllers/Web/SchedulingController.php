<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchedulingRequest;
use App\Models\Holiday;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Scheduling;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use App\Models\VehicleRoute;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Programación (W17, W18), edición masiva (W19) y calendario semanal (W20).
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
        // La fecha hace dos cosas: acota a las vigentes ese día y decide de
        // qué día se muestra el estado. Llega marcada con hoy; vaciarla
        // enseña todas.
        $filters = [
            'zone_id' => $request->query('zone_id'),
            'schedule_id' => $request->query('schedule_id'),
            'status' => $request->query('status'),
            'date' => $request->has('date') ? $request->query('date') : now()->toDateString(),
        ];

        $schedulings = Scheduling::query()
            ->with(Scheduling::RELATIONS)
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
    public function create(): View
    {
        return view('schedulings.form', ['scheduling' => null, ...$this->formData()]);
    }

    public function store(SchedulingRequest $request): RedirectResponse
    {
        $scheduling = (new Scheduling)->saveAndSync($request->validated());
        $count = $scheduling->vehicleRoutes()->count();

        return redirect()->route('schedulings.index')->with(
            'success',
            "Programación {$scheduling->code} registrada: {$count} ".($count === 1 ? 'recorrido generado.' : 'recorridos generados.'),
        );
    }

    /** W18 Edición individual. */
    public function edit(Scheduling $scheduling): View
    {
        return view('schedulings.form', ['scheduling' => $scheduling, ...$this->formData($scheduling)]);
    }

    public function update(SchedulingRequest $request, Scheduling $scheduling): RedirectResponse
    {
        $scheduling->saveAndSync($request->validated());

        return redirect()->route('schedulings.index')->with('success', "Programación {$scheduling->code} actualizada.");
    }

    public function destroy(Scheduling $scheduling): RedirectResponse
    {
        if ($blocker = $scheduling->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $scheduling->delete();

        return back()->with('success', "Programación {$scheduling->code} eliminada.");
    }

    /**
     * W19 Edición masiva. La lista va completa (sin paginar): para elegir a
     * mano hay que ver todas las de la zona. Aplicar los cambios va al API,
     * que valida fila a fila y devuelve qué se aplicó y qué no.
     */
    public function bulk(Request $request): View
    {
        return view('schedulings.bulk', [
            'schedulings' => Scheduling::query()
                ->with(['zone', 'schedule', 'vehicle'])
                ->filter(['zone_id' => $request->query('zone_id')])
                ->orderByDesc('start_date')
                ->orderBy('id')
                ->get(),
            'zoneFilter' => $request->query('zone_id'),
            ...$this->formData(),
            // Aquí las zonas solo filtran lo ya programado: van todas, también las inactivas.
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
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
        // Solo entra quien tiene un contrato vigente o por empezar: es el contrato
        // el que habilita a programar a alguien. La fecha exacta la sigue validando
        // SchedulingRules, porque la programación puede caer fuera del contrato.
        // Al editar, cada combo conserva a su titular aunque ya no cumpla.
        $today = now()->toDateString();
        $staff = fn (string $slug, ?int $keep) => User::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->ofFunction($slug)->whereHas('contracts', fn ($c) => $c
                    ->where(fn ($c) => $c->whereNull('end_date')->orWhere('end_date', '>=', $today))))
                ->when($keep, fn ($q) => $q->orWhere('id', $keep)))
            ->orderBy('lastname')
            ->orderBy('name')
            ->get(['id', 'name', 'lastname', 'dni']);

        return [
            // Solo las activas; al editar se conserva la zona de la programación.
            'zones' => Zone::selectable([$current?->zone_id])->orderBy('name')->get(['id', 'name']),
            'routes' => Route::with('zones:id')->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn (Route $route) => [
                    'id' => $route->id,
                    'name' => $route->name,
                    'status' => $route->status,
                    'zone_ids' => $route->zones->pluck('id'),
                ]),
            'schedules' => Schedule::orderBy('time_start')->get(),
            // Solo los operativos; al editar se mantiene el asignado aunque ya
            // no lo esté, para que el combo no lo pierda.
            'vehicles' => Vehicle::with(['brand', 'model'])
                ->where(fn ($q) => $q->operational()->when($current, fn ($q) => $q->orWhere('id', $current->vehicle_id)))
                ->orderBy('code')
                ->get(),
            'drivers' => $staff(UserType::DRIVER, $current?->driver_id),
            'helpers' => $staff(UserType::HELPER, $current?->helper_id),
        ];
    }
}
