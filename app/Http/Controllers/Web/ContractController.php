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

    public function destroy(User $person, Contract $contract): RedirectResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $contract->delete();

        return back()->with('success', "Contrato {$contract->code} eliminado.");
    }
}
