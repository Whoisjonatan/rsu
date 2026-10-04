<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vacation;
use App\Models\Zone;
use App\Services\Dni\DniLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PersonController extends Controller
{
    /** W07 Listado de personal. */
    public function index(Request $request): View
    {
        $filters = $request->only(['q', 'usertype_id', 'contract_type', 'state']);

        $people = User::query()
            ->with(['usertype', 'zone'])
            ->filter(['search' => $filters['q'] ?? null, ...$filters])
            ->orderBy('lastname')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('people.index', [
            'people' => $people,
            'summary' => User::staffSummary(),
            'types' => UserType::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    /** W08 Ficha de personal: alta. */
    public function create(DniLookup $lookup): View
    {
        return view('people.form', ['person' => null, 'tab' => 'datos', ...$this->formData($lookup)]);
    }

    public function store(PersonRequest $request): RedirectResponse
    {
        $person = User::create($request->personData());

        // Tras el alta se queda en la ficha, para poder cargar foto y contratos.
        return redirect()
            ->route('people.edit', [$person, 'tab' => 'contratos'])
            ->with('success', "{$person->full_name} registrado. Ahora registra su contrato.");
    }

    /** W08 Ficha de personal, con las pestañas de contratos (W09) y vacaciones (W10). */
    public function edit(Request $request, User $person, DniLookup $lookup): View
    {
        $tab = in_array($request->query('tab'), ['contratos', 'vacaciones'], true) ? $request->query('tab') : 'datos';
        $year = $request->integer('year', (int) now()->year);

        return view('people.form', [
            'person' => $person->load('usertype'),
            'tab' => $tab,
            'contracts' => $person->contracts()->orderByDesc('start_date')->get(),
            'vacations' => $person->vacations()->orderByDesc('start_date')->get(),
            'vacationSummary' => Vacation::summaryFor($person, $year),
            ...$this->formData($lookup),
        ]);
    }

    public function update(PersonRequest $request, User $person): RedirectResponse
    {
        $person->update($request->personData());

        return redirect()->route('people.index')->with('success', "Ficha de {$person->full_name} actualizada.");
    }

    public function destroy(Request $request, User $person): RedirectResponse
    {
        if ($blocker = $person->deletionBlocker($request->user())) {
            return back()->with('error', $blocker);
        }

        $person->deleteWithPhoto();

        return back()->with('success', "{$person->full_name} eliminado.");
    }

    /** Fotografía del personal (W08): subir y eliminar. */
    public function uploadPhoto(Request $request, User $person): RedirectResponse
    {
        $request->validate(PersonRequest::photoRules(), [
            'photo.mimes' => 'La foto debe ser JPG o PNG.',
            'photo.max' => 'La foto no puede pesar más de 4 MB.',
        ]);

        $person->replacePhoto($request->file('photo'));

        return back()->with('success', 'Fotografía actualizada.');
    }

    public function deletePhoto(User $person): RedirectResponse
    {
        $person->replacePhoto(null);

        return back()->with('success', 'Fotografía eliminada.');
    }

    /**
     * Combos de la ficha y el estado de la consulta de DNI: si no hay
     * proveedor configurado, el botón "Traer datos" ni aparece.
     *
     * @return array<string, mixed>
     */
    private function formData(DniLookup $lookup): array
    {
        return [
            'types' => UserType::orderBy('name')->get(['id', 'name', 'slug']),
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
            'dniLookup' => $lookup->isEnabled() ? $lookup->provider() : null,
        ];
    }
}
