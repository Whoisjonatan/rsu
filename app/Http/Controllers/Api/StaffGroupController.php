<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Scheduling\SchedulingRules;
use App\Services\Scheduling\StaffAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Grupos de personal: lo que el formulario consulta en vivo. */
class StaffGroupController extends Controller
{
    /**
     * Disponibilidad de todo el personal y los vehículos para un turno y
     * unos días. El formulario la pide al cambiar turno o días, y con ella
     * marca a cada persona y recomienda a las que están libres.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'schedule_id' => ['nullable', 'integer'],
            'weekdays' => ['nullable', 'array'],
            'ignore_id' => ['nullable', 'integer'],
            'keep' => ['nullable', 'array'],
        ]);

        $availability = new StaffAvailability(
            ($data['schedule_id'] ?? null) ?: null,
            SchedulingRules::normalizeWeekdays($data['weekdays'] ?? []),
            ($data['ignore_id'] ?? null) ?: null,
        );

        $keep = array_map('intval', $data['keep'] ?? []);

        return response()->json([
            'people' => StaffAvailability::candidates($keep)
                ->mapWithKeys(fn ($person) => [$person->id => $availability->forPerson($person)]),
            'vehicles' => Vehicle::all()
                ->mapWithKeys(fn (Vehicle $vehicle) => [$vehicle->id => $availability->forVehicle($vehicle)]),
        ]);
    }
}
