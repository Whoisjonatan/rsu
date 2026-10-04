<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * W01 Inicio de sesión del panel web, con sesión por cookie.
 *
 * El API conserva su propio login por token (Api\AuthController) para la app
 * móvil; las reglas de quién puede entrar son las mismas en los dos.
 */
class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::findForLogin($data['username']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['username' => 'Credenciales incorrectas']);
        }

        if ($user->status !== 'activo') {
            throw ValidationException::withMessages(['username' => 'El usuario está inactivo']);
        }

        // "Mantener sesión activa" deja la cookie de recuerdo; sin marcarlo,
        // la sesión muere al cerrar el navegador (SESSION_EXPIRE_ON_CLOSE),
        // que es lo prudente en una PC compartida.
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('welcome'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
