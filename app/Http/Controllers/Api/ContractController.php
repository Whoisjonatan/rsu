<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContractRequest;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Contratos del personal (W09). Anidado bajo /people/{person}/contracts.
 * Las reglas de contratación están en ContractRequest.
 */
class ContractController extends Controller
{
    public function index(User $person): JsonResponse
    {
        return response()->json(
            $person->contracts()->orderByDesc('start_date')->get()
        );
    }

    public function store(ContractRequest $request, User $person): JsonResponse
    {
        return response()->json($person->contracts()->create($request->contractData()), 201);
    }

    public function update(ContractRequest $request, User $person, Contract $contract): JsonResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $contract->update($request->contractData());

        return response()->json($contract->fresh());
    }

    public function destroy(User $person, Contract $contract): JsonResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $contract->delete();

        return response()->json(null, 204);
    }
}
