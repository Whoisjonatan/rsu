<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class PersonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $today = now()->toDateString();
        $search = $request->string('search')->toString();

        $people = User::query()
            ->with(['usertype', 'zone'])
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('dni', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('lastname', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when($request->filled('usertype_id'), fn (Builder $q) => $q
                ->where('usertype_id', $request->integer('usertype_id')))
            ->when($request->filled('contract_type'), fn (Builder $q) => $q
                ->whereHas('contracts', fn (Builder $c) => $c
                    ->where('type', $request->string('contract_type')->toString())
                    ->activeOn($today)))
            // "Estado" aquí es el derivado de W07, no la columna `status`.
            ->when($request->filled('state'), function (Builder $q) use ($request, $today) {
                match ($request->string('state')->toString()) {
                    'activo' => $q->where('status', 'activo')
                        ->whereHas('contracts', fn (Builder $c) => $c->activeOn($today))
                        ->whereDoesntHave('vacations', fn (Builder $v) => $v->covering($today)),
                    'vacaciones' => $q->whereHas('vacations', fn (Builder $v) => $v->covering($today)),
                    'culminado' => $q->where('status', 'activo')
                        ->has('contracts')
                        ->whereDoesntHave('contracts', fn (Builder $c) => $c->activeOn($today)),
                    'sin_contrato' => $q->where('status', 'activo')->doesntHave('contracts'),
                    'inactivo' => $q->where('status', 'inactivo'),
                    default => $q,
                };
            })
            ->orderBy('lastname')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return PersonResource::collection($people);
    }

    public function store(PersonRequest $request): JsonResponse
    {
        $person = User::create($request->validated());

        // ->response() envuelve en "data", igual que update(): si store devuelve
        // el recurso desnudo, el cliente recibe dos formas distintas del mismo
        // objeto según el verbo.
        return (new PersonResource($person->load(['usertype', 'zone'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $person): PersonResource
    {
        return new PersonResource($person->load(['usertype', 'zone']));
    }

    public function update(PersonRequest $request, User $person): PersonResource
    {
        $data = $request->validated();

        // Una contraseña vacía en la edición significa "no la cambies".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $person->update($data);

        return new PersonResource($person->load(['usertype', 'zone']));
    }

    public function destroy(User $person): JsonResponse
    {
        if ($person->occupancies()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: la persona tiene recorridos asignados.',
            ], 409);
        }

        if ($person->id === request()->user()->id) {
            return response()->json([
                'message' => 'No puedes eliminar tu propio usuario.',
            ], 409);
        }

        $person->delete();

        return response()->json(null, 204);
    }

    /** Contadores de la barra superior de W07. */
    public function summary(): JsonResponse
    {
        $today = now()->toDateString();

        return response()->json([
            'total' => User::count(),
            'con_contrato' => User::whereHas('contracts', fn (Builder $c) => $c->activeOn($today))->count(),
            'de_vacaciones' => User::whereHas('vacations', fn (Builder $v) => $v->covering($today))->count(),
        ]);
    }

    /** Fotografía del personal (W08): subir y eliminar. */
    public function uploadPhoto(Request $request, User $person): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ]);

        if ($person->profile_photo_path) {
            Storage::disk('public')->delete($person->profile_photo_path);
        }

        $person->update([
            'profile_photo_path' => $request->file('photo')->store('people', 'public'),
        ]);

        return response()->json(new PersonResource($person->fresh()->load(['usertype', 'zone'])));
    }

    public function deletePhoto(User $person): JsonResponse
    {
        if ($person->profile_photo_path) {
            Storage::disk('public')->delete($person->profile_photo_path);
            $person->update(['profile_photo_path' => null]);
        }

        return response()->json(new PersonResource($person->fresh()->load(['usertype', 'zone'])));
    }
}
