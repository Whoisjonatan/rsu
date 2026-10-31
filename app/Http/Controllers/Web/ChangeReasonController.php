<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeReasonRequest;
use App\Models\ChangeReason;
use App\Models\SchedulingChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gestión de cambios › Motivos: los que se eligen al cambiar el turno, el
 * vehículo o el personal de una programación (ver Rescheduling).
 */
class ChangeReasonController extends Controller
{
    public function index(Request $request): View
    {
        $type = array_key_exists((string) $request->query('tipo'), ChangeReason::TYPES) ? $request->query('tipo') : null;

        return view('change-reasons.index', [
            'reasons' => ChangeReason::query()
                ->when($type, fn ($q) => $q->where('type', $type))
                ->orderBy('name')
                ->get()
                // En el orden de la pantalla de reprogramación, los generales al final.
                ->sortBy(fn (ChangeReason $reason) => array_search($reason->type, array_keys(ChangeReason::TYPES), true))
                ->values(),
            // El historial guarda el texto: los usos se cuentan por nombre, en una sola consulta.
            'usage' => SchedulingChange::query()->selectRaw('reason, count(*) as total')->groupBy('reason')->pluck('total', 'reason'),
            'counts' => ChangeReason::query()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type'),
            'type' => $type,
        ]);
    }

    public function store(ChangeReasonRequest $request): RedirectResponse
    {
        $reason = ChangeReason::create($request->validated());

        return back()->with('success', "Motivo \"{$reason->name}\" registrado.");
    }

    public function update(ChangeReasonRequest $request, ChangeReason $reason): RedirectResponse
    {
        $reason->update($request->validated());

        return back()->with('success', "Motivo \"{$reason->name}\" actualizado.");
    }

    public function destroy(ChangeReason $reason): RedirectResponse
    {
        if ($blocker = $reason->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $reason->delete();

        return back()->with('success', "Motivo \"{$reason->name}\" eliminado.");
    }
}
