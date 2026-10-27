<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContractRequest;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/** W09 Contratos del personal (pestaña de la ficha). */
class ContractController extends Controller
{
    public function store(ContractRequest $request, User $person): RedirectResponse
    {
        $contract = $person->contracts()->create($request->contractData());

        return back()->with('success', "Contrato {$contract->code} registrado.");
    }

    public function update(ContractRequest $request, User $person, Contract $contract): RedirectResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $contract->update($request->contractData());

        return back()->with('success', "Contrato {$contract->code} actualizado.");
    }

    /** Corta el contrato desde hoy: la persona deja de poder ser programada. */
    public function deactivate(User $person, Contract $contract): RedirectResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        if (! $contract->canBeDeactivated()) {
            return back()->with('error', "El contrato {$contract->code} ya no está activo.");
        }

        $contract->forceFill(['deactivated_at' => now()->toDateString()])->save();

        return back()->with('success', "Contrato {$contract->code} desactivado.");
    }

    public function reactivate(User $person, Contract $contract): RedirectResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $contract->forceFill(['deactivated_at' => null])->save();

        return back()->with('success', "Contrato {$contract->code} reactivado.");
    }

    public function destroy(User $person, Contract $contract): RedirectResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $contract->delete();

        return back()->with('success', "Contrato {$contract->code} eliminado.");
    }
}
