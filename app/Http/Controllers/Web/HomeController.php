<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRoute;
use App\Models\Zone;
use Illuminate\View\View;

class HomeController extends Controller
{
    /** Bienvenida tras iniciar sesión: información general y accesos rápidos. */
    public function welcome(): View
    {
        $today = now()->toDateString();

        return view('home.welcome', [
            'vehicles' => Vehicle::summary(),
            'people' => User::staffSummary(),
            'zonesCount' => Zone::count(),
            'routes' => VehicleRoute::daySummary($today),
        ]);
    }

    /** W02 Panel principal. */
    public function dashboard(): View
    {
        $today = now()->toDateString();

        return view('home.dashboard', [
            'vehicles' => Vehicle::summary(),
            'summary' => VehicleRoute::daySummary($today),
            'routes' => VehicleRoute::query()
                ->with(['vehicle', 'schedule', 'scheduling.zone'])
                ->onDate($today)
                ->orderBy('schedule_id')
                ->get(),
        ]);
    }
}
