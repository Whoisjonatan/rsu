<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\HolidayRequest;
use App\Models\Holiday;
use App\Services\Holidays\PeruHolidays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Feriados. Cada cambio rehace los recorridos de las programaciones que
 * cubren la fecha (Holiday::resyncSchedulings), porque un feriado activo no
 * lleva recorridos.
 */
class HolidayController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $request->query('status'),
        ];

        return view('holidays.index', [
            'holidays' => Holiday::filter($filters)->orderBy('date')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'summary' => Holiday::summary(),
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        $holiday = DB::transaction(function () use ($request) {
            $holiday = Holiday::create($request->validated());
            Holiday::resyncSchedulings([$holiday->date->toDateString()]);

            return $holiday;
        });

        return back()->with('success', "Feriado del {$holiday->date->format('d/m/Y')} registrado.");
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        DB::transaction(function () use ($request, $holiday) {
            $before = $holiday->date->toDateString();
            $holiday->update($request->validated());
            Holiday::resyncSchedulings([$before, $holiday->date->toDateString()]);
        });

        return back()->with('success', "Feriado del {$holiday->date->format('d/m/Y')} actualizado.");
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        DB::transaction(function () use ($holiday) {
            $holiday->delete();
            Holiday::resyncSchedulings([$holiday->date->toDateString()]);
        });

        return back()->with('success', "Feriado del {$holiday->date->format('d/m/Y')} eliminado.");
    }

    /**
     * Lo que cargaría "Cargar feriados Perú", marcando los ya registrados. El
     * modal de confirmación lo pide al abrirse, así la consulta al calendario
     * en línea no frena la carga del listado.
     */
    public function previewPeru(PeruHolidays $peru): JsonResponse
    {
        $year = now()->year;
        ['source' => $source, 'holidays' => $holidays] = $peru->forYear($year);
        $registered = Holiday::whereYear('date', $year)->pluck('date')->map(fn ($date) => $date->toDateString());

        return response()->json([
            'year' => $year,
            'source' => $source,
            'holidays' => array_map(fn (array $holiday) => $holiday + [
                'registered' => $registered->contains($holiday['date']),
            ], $holidays),
        ]);
    }

    /** Registra los feriados nacionales del año en curso que aún no estén. */
    public function loadPeru(PeruHolidays $peru): RedirectResponse
    {
        $year = now()->year;
        ['source' => $source, 'holidays' => $holidays] = $peru->forYear($year);

        $created = DB::transaction(function () use ($holidays) {
            $created = [];

            foreach ($holidays as $holiday) {
                $row = Holiday::firstOrCreate(['date' => $holiday['date']], [
                    'description' => $holiday['description'],
                    'status' => $holiday['status'],
                ]);

                if ($row->wasRecentlyCreated) {
                    $created[] = $holiday['date'];
                }
            }

            Holiday::resyncSchedulings($created);

            return count($created);
        });

        $offline = $source === 'local' ? ' No se pudo consultar el calendario en línea: se usó la lista interna.' : '';

        if ($created === 0) {
            return back()->with('success', "Los feriados de {$year} ya estaban registrados.{$offline}");
        }

        $skipped = count($holidays) - $created;

        return back()->with('success', sprintf(
            '%d %s de %d.%s%s',
            $created,
            $created === 1 ? 'feriado cargado' : 'feriados cargados',
            $year,
            $skipped > 0 ? " {$skipped} ya estaban registrados." : '',
            $offline,
        ));
    }
}
