<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login del panel web. Acepta correo o DNI en el mismo campo, porque el
     * personal marca asistencia con DNI y clave (W11) y la maqueta usa un
     * unico campo "Usuario" (W01).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::findForLogin($data['username']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => 'Credenciales incorrectas',
            ]);
        }

        if ($user->status !== 'activo') {
            throw ValidationException::withMessages([
                'username' => 'El usuario esta inactivo',
            ]);
        }

        $user->tokens()->where('name', 'web')->delete();

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('usertype', 'zone'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesion cerrada']);
    }
}
