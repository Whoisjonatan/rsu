<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ChangeReason;
use App\Models\Schedule;
use App\Models\Scheduling;
use App\Models\Zone;
use App\Services\Scheduling\DailyDashboard;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard: monitoreo diario de las programaciones. Cuántas hay, cuántas
 * están completas (todo su personal marcó), cuántas incompletas y cuánto
 * personal falta. Llega con el día de hoy y todos los turnos.
 *
 * Los modales de la pantalla modifican la programación del día y reemplazan
 * a quien falta; guardan con SchedulingController::update.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        Scheduling::closeFinished();

        // Una fecha que no se entiende vuelve a hoy en vez de romper la pantalla.
        try {
            $date = Carbon::parse($request->query('date') ?: 'today')->toDateString();
        } catch (\Throwable) {
            $date = now()->toDateString();
        }

        $filters = [
            'date' => $date,
            'zone_id' => $request->query('zone_id'),
            'schedule_id' => $request->query('schedule_id'),
        ];

        $dashboard = new DailyDashboard($date, $filters);

        return view('dashboard.index', [
            'cards' => $dashboard->cards(),
            'totals' => $dashboard->totals(),
            'options' => $dashboard->options(),
            'reasons' => ChangeReason::options(),
            'filters' => $filters,
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
            'schedules' => Schedule::orderBy('time_start')->get(),
        ]);
    }
}
