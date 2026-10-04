<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserTypeRequest;
use App\Models\UserType;
use Illuminate\Http\JsonResponse;

/**
 * Funciones del personal (W06). El informe exige que Conductor y Ayudante
 * existan siempre y no se puedan eliminar: van marcadas con `is_system`.
 */
class UserTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            UserType::withCount('users')->orderBy('name')->get()
        );
    }

    public function store(UserTypeRequest $request): JsonResponse
    {
        return response()->json(UserType::create($request->typeData()), 201);
    }

    public function update(UserTypeRequest $request, UserType $usertype): JsonResponse
    {
        $usertype->update($request->typeData($usertype));

        return response()->json($usertype);
    }

    public function destroy(UserType $usertype): JsonResponse
    {
        if ($blocker = $usertype->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $usertype->delete();

        return response()->json(null, 204);
    }
}
