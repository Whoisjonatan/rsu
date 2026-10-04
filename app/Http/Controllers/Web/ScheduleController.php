<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleRequest;
use App\Models\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** W16 Turnos. */
class ScheduleController extends Controller
{
    public function index(): View
    {
        return view('schedules.index', [
            'schedules' => Schedule::withCount('schedulings')->orderBy('time_start')->get(),
        ]);
    }

    public function store(ScheduleRequest $request): RedirectResponse
    {
        $schedule = Schedule::create($request->validated());

        return back()->with('success', "Turno \"{$schedule->name}\" registrado.");
    }

    public function update(ScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        $schedule->update($request->validated());

        return back()->with('success', "Turno \"{$schedule->name}\" actualizado.");
    }

    public function destroy(Schedule $schedule): RedirectResponse
    {
        if ($blocker = $schedule->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $schedule->delete();

        return back()->with('success', "Turno \"{$schedule->name}\" eliminado.");
    }
}
