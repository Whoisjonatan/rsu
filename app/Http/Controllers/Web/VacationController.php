<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\VacationRequest;
use App\Models\User;
use App\Models\Vacation;
use Illuminate\Http\RedirectResponse;

/**
 * W10 Vacaciones del personal (pestaña de la ficha). Toda solicitud entra
 * pendiente; aprobada pasa a programada y rechazada queda cerrada.
 */
class VacationController extends Controller
{
    public function store(VacationRequest $request, User $person): RedirectResponse
    {
        $vacation = $person->vacations()->create([...$request->vacationData(), 'status' => 'pendientes']);

        return back()->with('success', "Solicitud de vacaciones registrada: {$vacation->days} días, pendiente de aprobación.");
    }

    public function update(VacationRequest $request, User $person, Vacation $vacation): RedirectResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->editBlocker()) {
            return back()->with('error', $blocker);
        }

        $vacation->update($request->vacationData());

        return back()->with('success', 'Solicitud de vacaciones actualizada.');
    }

    public function approve(User $person, Vacation $vacation): RedirectResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->decisionBlocker()) {
            return back()->with('error', $blocker);
        }

        // El contrato pudo terminar mientras la solicitud esperaba.
        if (! $person->isVacationEligible()) {
            return back()->with('error', 'Solo el personal nombrado o con contrato permanente puede tener vacaciones.');
        }

        $vacation->update(['status' => 'programadas']);

        return back()->with('success', 'Vacaciones aprobadas y programadas.');
    }

    public function reject(User $person, Vacation $vacation): RedirectResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->decisionBlocker()) {
            return back()->with('error', $blocker);
        }

        $vacation->update(['status' => 'rechazadas']);

        return back()->with('success', 'Solicitud de vacaciones rechazada.');
    }

    public function destroy(User $person, Vacation $vacation): RedirectResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        if ($blocker = $vacation->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $vacation->delete();

        return back()->with('success', 'Período de vacaciones eliminado.');
    }
}
