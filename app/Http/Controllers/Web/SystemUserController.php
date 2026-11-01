<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Http\Requests\SystemUserRequest;
use App\Models\User;
use App\Models\UserType;
use App\Services\Dni\DniLookup;
use App\Support\ListUrl;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gestión de usuarios › Lista de usuarios: los usuarios del sistema
 * (Administrador y Supervisor), que son los que entran al panel.
 *
 * Viven en la misma tabla `users` que el personal operativo, pero se
 * gestionan aquí y no salen en Personal. Quién entra y qué puede hacer lo
 * deciden los permisos de "Lista de usuarios" (middleware "permission").
 */
class SystemUserController extends Controller
{
    public function index(Request $request): View
    {
        ListUrl::remember('users.index');

        $filters = $request->only(['q', 'usertype_id', 'status']);
        $search = trim((string) ($filters['q'] ?? ''));

        $users = User::query()
            ->systemUsers()
            ->with('usertype')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('dni', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('lastname', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when(filled($filters['usertype_id'] ?? null), fn ($q) => $q->where('usertype_id', (int) $filters['usertype_id']))
            ->when(in_array($filters['status'] ?? null, ['activo', 'inactivo'], true), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('lastname')
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $all = User::systemUsers();

        return view('users.index', [
            'users' => $users,
            'summary' => [
                'total' => (clone $all)->count(),
                'activos' => (clone $all)->where('status', 'activo')->count(),
            ],
            'types' => UserType::systemUsers()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, DniLookup $lookup): View
    {

        return view('users.form', ['user' => null, ...$this->formData($lookup)]);
    }

    public function store(SystemUserRequest $request): RedirectResponse
    {
        $user = User::create($request->userData());

        if ($request->hasFile('photo')) {
            $user->replacePhoto($request->file('photo'));
        }

        return redirect()->to(ListUrl::get('users.index'))->with('success', "Usuario {$user->full_name} registrado.");
    }

    public function edit(Request $request, User $user, DniLookup $lookup): View
    {
        abort_unless($user->isSystemUser(), 404);

        return view('users.form', ['user' => $user->load('usertype'), ...$this->formData($lookup)]);
    }

    public function update(SystemUserRequest $request, User $user): RedirectResponse
    {
        abort_unless($user->isSystemUser(), 404);

        $user->update($request->userData());

        return redirect()->to(ListUrl::get('users.index'))->with('success', "Usuario {$user->full_name} actualizado.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isSystemUser(), 404);

        if ($blocker = $user->deletionBlocker($request->user())) {
            return back()->with('error', $blocker);
        }

        $user->deleteWithPhoto();

        return back()->with('success', "Usuario {$user->full_name} eliminado.");
    }

    /** Foto de perfil: subir y eliminar desde la edición. */
    public function uploadPhoto(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isSystemUser(), 404);

        $request->validate(PersonRequest::photoRules(), [
            'photo.mimes' => 'La foto debe ser JPG o PNG.',
            'photo.max' => 'La foto no puede pesar más de 4 MB.',
        ]);

        $user->replacePhoto($request->file('photo'));

        return back()->with('success', 'Foto de perfil actualizada.');
    }

    public function deletePhoto(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isSystemUser(), 404);

        $user->replacePhoto(null);

        return back()->with('success', 'Foto de perfil eliminada.');
    }

    /**
     * Permisos del usuario: de cada módulo, qué puede ver y hacer
     * (App\Support\Permissions). El Administrador los tiene todos y nadie
     * cambia los suyos propios.
     */
    public function permissions(Request $request, User $user): View
    {
        abort_unless($user->isSystemUser(), 404);

        return view('users.permissions', [
            'user' => $user->load('usertype'),
            'tree' => Permissions::tree(),
            'granted' => $user->isAdmin() ? Permissions::all() : ($user->permissions ?? []),
            'locked' => $this->permissionsLock($request, $user),
        ]);
    }

    public function updatePermissions(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isSystemUser(), 404);

        if ($lock = $this->permissionsLock($request, $user)) {
            return back()->with('error', $lock);
        }

        $data = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        // En el orden del catálogo, sin repetidos.
        $granted = array_values(array_intersect(Permissions::all(), $data['permissions'] ?? []));
        $user->forceFill(['permissions' => $granted])->save();

        return redirect()->to(ListUrl::get('users.index'))
            ->with('success', sprintf('Permisos de %s guardados: %d de %d.', $user->full_name, count($granted), count(Permissions::all())));
    }

    /** Por qué no se pueden cambiar los permisos de este usuario, o null si se puede. */
    private function permissionsLock(Request $request, User $user): ?string
    {
        return match (true) {
            $user->isAdmin() => 'Es Administrador: tiene todos los permisos y no se le pueden quitar.',
            $user->is($request->user()) => 'No puedes cambiar tus propios permisos.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function formData(DniLookup $lookup): array
    {
        return [
            'types' => UserType::systemUsers()->orderBy('name')->get(['id', 'name', 'slug', 'description']),
            'dniLookup' => $lookup->isEnabled() ? $lookup->provider() : null,
        ];
    }
}
