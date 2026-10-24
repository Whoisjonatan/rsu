<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserType;
use App\Models\Vehicle;
use App\Services\Scheduling\SchedulingRules;
use App\Services\Scheduling\StaffAvailability;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grupo de personal. Además de los campos, comprueba que la tripulación quepa
 * en el vehículo y, si el grupo queda activo, que todos estén disponibles en
 * ese turno y esos días (StaffAvailability). Las advertencias no impiden
 * guardar.
 */
class StaffGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('staffgroups', 'name')->ignore($this->route('staffgroup'))],
            'schedule_id' => ['required', 'exists:schedules,id'],
            'zone_id' => ['required', 'exists:zones,id'],
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'driver_id' => ['required', 'exists:users,id'],
            'helper_ids' => ['nullable', 'array', 'max:10'],
            'helper_ids.*' => ['integer', 'exists:users,id'],
            'status' => ['required', Rule::in(['activo', 'inactivo'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un grupo con ese nombre.',
            'weekdays.required' => 'Marca al menos un día de trabajo.',
            'weekdays.min' => 'Marca al menos un día de trabajo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weekdays' => SchedulingRules::normalizeWeekdays($this->input('weekdays')),
            'helper_ids' => SchedulingRules::normalizeHelpers($this->input('helper_ids')),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            $people = User::with(['usertype', 'contracts', 'vacations'])
                ->findMany([$data['driver_id'], ...$data['helper_ids']])
                ->keyBy('id');
            $driver = $people[(int) $data['driver_id']];

            if ($driver->usertype?->slug !== UserType::DRIVER) {
                $validator->errors()->add('driver_id', "{$driver->full_name} no tiene la función de conductor.");
            }

            if (in_array($driver->id, $data['helper_ids'], true)) {
                $validator->errors()->add('helper_ids', 'El conductor no puede ir también como ayudante.');
            }

            foreach ($data['helper_ids'] as $helperId) {
                $helper = $people[$helperId];

                if ($helper->usertype?->slug !== UserType::HELPER) {
                    $validator->errors()->add('helper_ids', "{$helper->full_name} no tiene la función de ayudante.");
                }
            }

            if (count($data['helper_ids']) > $vehicle->helperSeats()) {
                $validator->errors()->add('helper_ids', "El vehículo {$vehicle->code} admite {$vehicle->helperSeats()} ".
                    ($vehicle->helperSeats() === 1 ? 'ayudante.' : 'ayudantes.'));
            }

            // Un grupo inactivo es solo un borrador: no ocupa a nadie.
            if ($data['status'] !== 'activo') {
                return;
            }

            $availability = new StaffAvailability(
                (int) $data['schedule_id'],
                $data['weekdays'],
                $this->route('staffgroup')?->id,
            );

            foreach ($availability->forVehicle($vehicle)['problems'] as $problem) {
                $validator->errors()->add('vehicle_id', "Vehículo {$vehicle->code}: {$problem}");
            }

            foreach ($people as $person) {
                $field = $person->id === $driver->id ? 'driver_id' : 'helper_ids';

                foreach ($availability->forPerson($person)['problems'] as $problem) {
                    $validator->errors()->add($field, "{$person->full_name}: {$problem}");
                }
            }
        });
    }
}
