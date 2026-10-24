<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffGroupRequest;
use App\Models\Schedule;
use App\Models\StaffGroup;
use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use App\Models\Zone;
use App\Services\Scheduling\StaffAvailability;
use App\Support\Status;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Grupos de personal: plantillas para programar más rápido. La
 * disponibilidad en vivo del formulario la da el API
 * (Api\StaffGroupController::availability).
 */
class StaffGroupController extends Controller
{
    public function index(): View
    {
        return view('staffgroups.index', [
            'groups' => StaffGroup::with(StaffGroup::RELATIONS)->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('staffgroups.form', ['group' => null, ...$this->formData()]);
    }

    public function store(StaffGroupRequest $request): RedirectResponse
    {
        $group = (new StaffGroup)->saveWithHelpers($request->validated());

        return redirect()->route('staffgroups.index')->with('success', "Grupo \"{$group->name}\" registrado.");
    }

    public function edit(StaffGroup $staffgroup): View
    {
        return view('staffgroups.form', ['group' => $staffgroup->load('helpers'), ...$this->formData($staffgroup)]);
    }

    public function update(StaffGroupRequest $request, StaffGroup $staffgroup): RedirectResponse
    {
        $staffgroup->saveWithHelpers($request->validated());

        return redirect()->route('staffgroups.index')->with('success', "Grupo \"{$staffgroup->name}\" actualizado.");
    }

    public function destroy(StaffGroup $staffgroup): RedirectResponse
    {
        // Las programaciones que salieron de él se quedan: solo pierden el vínculo.
        $staffgroup->delete();

        return back()->with('success', "Grupo \"{$staffgroup->name}\" eliminado.");
    }

    /**
     * Los combos y el personal con su ficha. Al editar se conservan la zona,
     * el vehículo y las personas del grupo aunque ya no estén activos.
     *
     * @return array<string, mixed>
     */
    private function formData(?StaffGroup $group = null): array
    {
        $keep = $group ? [$group->driver_id, ...$group->helperIds()] : [];

        return [
            'schedules' => Schedule::orderBy('time_start')->get(),
            'zones' => Zone::selectable([$group?->zone_id])->orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::with(['type', 'brand', 'model'])
                ->where(fn ($q) => $q->operational()->when($group, fn ($q) => $q->orWhere('id', $group->vehicle_id)))
                ->orderBy('code')
                ->get()
                ->map(fn (Vehicle $vehicle) => [
                    'id' => $vehicle->id,
                    'code' => $vehicle->code,
                    'plate' => $vehicle->plate,
                    'type' => $vehicle->type?->name,
                    'brand' => trim(($vehicle->brand?->name ?? '').' '.($vehicle->model?->name ?? '')),
                    'capacity' => $vehicle->occupant_capacity,
                    'seats' => $vehicle->helperSeats(),
                ]),
            'people' => StaffAvailability::candidates($keep)->map(fn (User $person) => $this->card($person))->values(),
        ];
    }

    /**
     * Lo que se muestra de cada persona al elegirla y en el buscador.
     *
     * @return array<string, mixed>
     */
    private function card(User $person): array
    {
        $contract = $person->currentContract();

        return [
            'id' => $person->id,
            'full_name' => $person->full_name,
            'dni' => $person->dni,
            'role' => $person->usertype?->slug === UserType::DRIVER ? 'driver' : 'helper',
            'function' => $person->usertype?->name,
            'phone' => $person->phone,
            'zone_id' => $person->zone_id,
            'zone' => $person->zone?->name,
            'contract' => $contract
                ? Status::label('contract', $contract->type).($contract->end_date ? ' hasta el '.$contract->end_date->format('d/m/Y') : ' · indefinido')
                : null,
            'license' => $person->license
                ? trim($person->license.' '.($person->license_category ?? '')).($person->license_expiration ? ' · vence '.$person->license_expiration->format('d/m/Y') : '')
                : null,
            'photo' => $person->photo_url,
        ];
    }
}
