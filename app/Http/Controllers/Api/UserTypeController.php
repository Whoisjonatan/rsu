<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = Str::slug($data['name']);
        $data['is_system'] = false;

        return response()->json(UserType::create($data), 201);
    }

    public function update(Request $request, UserType $usertype): JsonResponse
    {
        $data = $this->validated($request, $usertype->id);

        // El slug de una función predefinida no se toca: hay código que lo usa.
        if (! $usertype->is_system) {
            $data['slug'] = Str::slug($data['name']);
        }

        $usertype->update($data);

        return response()->json($usertype);
    }

    public function destroy(UserType $usertype): JsonResponse
    {
        if ($usertype->is_system) {
            return response()->json([
                'message' => "La función \"{$usertype->name}\" es predefinida y no se puede eliminar.",
            ], 409);
        }

        if ($usertype->users()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay personal con esta función.',
            ], 409);
        }

        $usertype->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('usertypes', 'name')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
