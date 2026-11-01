<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserTypeRequest;
use App\Models\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Los dos catálogos de la tabla `usertypes`, cada uno en su pantalla:
 *
 * - W06 Funciones del personal (Personal › Funciones): kind "personal".
 * - Tipos de usuario (Gestión de usuarios): kind "sistema", solo para un
 *   administrador.
 *
 * La ruta dice cuál es con su valor por defecto `kind` (ver routes/web.php).
 */
class UserTypeController extends Controller
{
    public function index(Request $request): View
    {
        $kind = $this->kind($request);

        return view('people.usertypes', [
            'kind' => $kind,
            'types' => UserType::where('kind', $kind)->withCount('users')->orderBy('name')->paginate(10),
        ]);
    }

    public function store(UserTypeRequest $request): RedirectResponse
    {
        $kind = $this->kind($request);
        $type = UserType::create($request->typeData(null, $kind));

        return back()->with('success', $this->message($kind, $type->name, 'registrad'));
    }

    public function update(UserTypeRequest $request, UserType $usertype): RedirectResponse
    {
        $kind = $this->kind($request);
        abort_unless($usertype->kind === $kind, 404);

        $usertype->update($request->typeData($usertype, $kind));

        return back()->with('success', $this->message($kind, $usertype->name, 'actualizad'));
    }

    public function destroy(Request $request, UserType $usertype): RedirectResponse
    {
        $kind = $this->kind($request);
        abort_unless($usertype->kind === $kind, 404);

        if ($blocker = $usertype->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $usertype->delete();

        return back()->with('success', $this->message($kind, $usertype->name, 'eliminad'));
    }

    /** El catálogo de la ruta. Quién entra lo deciden los permisos (usertypes.* o roles.*). */
    private function kind(Request $request): string
    {
        return $request->route('kind') === UserType::KIND_SYSTEM ? UserType::KIND_SYSTEM : UserType::KIND_STAFF;
    }

    /** "Función "X" registrada." o "Tipo de usuario "X" registrado.". */
    private function message(string $kind, string $name, string $verb): string
    {
        return $kind === UserType::KIND_SYSTEM
            ? "Tipo de usuario \"{$name}\" {$verb}o."
            : "Función \"{$name}\" {$verb}a.";
    }
}
