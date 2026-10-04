<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PersonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $people = User::query()
            ->with(['usertype', 'zone'])
            ->filter($request->all())
            ->orderBy('lastname')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return PersonResource::collection($people);
    }

    public function store(PersonRequest $request): JsonResponse
    {
        $person = User::create($request->personData());

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
        $person->update($request->personData());

        return new PersonResource($person->load(['usertype', 'zone']));
    }

    public function destroy(Request $request, User $person): JsonResponse
    {
        if ($blocker = $person->deletionBlocker($request->user())) {
            return response()->json(['message' => $blocker], 409);
        }

        $person->deleteWithPhoto();

        return response()->json(null, 204);
    }

    /** Contadores de la barra superior de W07. */
    public function summary(): JsonResponse
    {
        return response()->json(User::staffSummary());
    }

    /** Fotografía del personal (W08): subir y eliminar. */
    public function uploadPhoto(Request $request, User $person): JsonResponse
    {
        $request->validate(PersonRequest::photoRules());

        $person->replacePhoto($request->file('photo'));

        return response()->json(new PersonResource($person->fresh()->load(['usertype', 'zone'])));
    }

    public function deletePhoto(User $person): JsonResponse
    {
        $person->replacePhoto(null);

        return response()->json(new PersonResource($person->fresh()->load(['usertype', 'zone'])));
    }
}
