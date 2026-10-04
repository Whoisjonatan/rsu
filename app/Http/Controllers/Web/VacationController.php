<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\VacationRequest;
use App\Models\User;
use App\Models\Vacation;
use Illuminate\Http\RedirectResponse;

/** W10 Vacaciones del personal (pestaña de la ficha). */
class VacationController extends Controller
{
    public function store(VacationRequest $request, User $person): RedirectResponse
    {
        $vacation = $person->vacations()->create($request->vacationData());

        return back()->with('success', "Vacaciones programadas: {$vacation->days} días.");
    }

    public function update(VacationRequest $request, User $person, Vacation $vacation): RedirectResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        $vacation->update($request->vacationData());

        return back()->with('success', 'Vacaciones actualizadas.');
    }

    public function destroy(User $person, Vacation $vacation): RedirectResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        $vacation->delete();

        return back()->with('success', 'Período de vacaciones eliminado.');
    }
}
