<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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
        $user = $this->authenticate($request);

        return $this->issueToken($user, 'web');
    }

    /**
     * Login de la app móvil, con DNI y clave: entran el personal con la función
     * Conductor y los ciudadanos registrados. La función se revisa después de
     * la clave para no revelarla a quien no conoce la contraseña. El token se llama "mobile" para que entrar
     * desde el celular no cierre la sesión web del mismo usuario.
     */
    public function mobileLogin(Request $request): JsonResponse
    {
        $user = $this->authenticate($request);

        if (! in_array($user->usertype?->slug, [UserType::DRIVER, UserType::CITIZEN], true)) {
            throw ValidationException::withMessages([
                'username' => 'La aplicación es para conductores y ciudadanos registrados',
            ]);
        }

        return $this->issueToken($user, 'mobile');
    }

    /**
     * Registro de ciudadanos desde la app (el personal lo da de alta el
     * administrador en el panel). Queda con su zona y la sesión iniciada.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dni' => ['required', 'digits:8', 'unique:users,dni'],
            'name' => ['required', 'string', 'max:120'],
            'lastname' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'zone_id' => ['required', Rule::exists('zones', 'id')->where('status', 'activa')],
        ], [
            'dni.digits' => 'El DNI debe tener 8 dígitos.',
            'dni.unique' => 'Ya existe una cuenta con ese DNI.',
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'zone_id.required' => 'Selecciona tu zona.',
            'zone_id.exists' => 'La zona elegida no está disponible.',
        ]);

        $type = UserType::where('slug', UserType::CITIZEN)->firstOrFail();

        $user = User::create([
            'dni' => $data['dni'],
            'name' => trim($data['name']),
            'lastname' => trim($data['lastname']),
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'status' => 'activo',
            'usertype_id' => $type->id,
            'zone_id' => $data['zone_id'],
        ]);

        return $this->issueToken($user, 'mobile')->setStatusCode(201);
    }

    /** Valida usuario (correo o DNI), clave y que la cuenta esté activa. */
    private function authenticate(Request $request): User
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

        return $user;
    }

    /** Reemplaza el token anterior del mismo canal (web o mobile). */
    private function issueToken(User $user, string $channel): JsonResponse
    {
        $user->tokens()->where('name', $channel)->delete();

        return response()->json([
            'token' => $user->createToken($channel)->plainTextToken,
            'user' => new UserResource($user->load('usertype', 'zone')),
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
