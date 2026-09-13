<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
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
        $id = $this->route('vehicle')?->id;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('vehicles', 'code')->ignore($id)],
            'plate' => [
                'required', 'string', 'max:10',
                'regex:'.Vehicle::PLATE_REGEX,
                Rule::unique('vehicles', 'plate')->ignore($id),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'year' => ['required', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'occupant_capacity' => ['required', 'integer', 'min:0', 'max:60'],
            'load_capacity' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'engine_number' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['operativo', 'mantenimiento', 'baja'])],
            'brand_id' => ['required', 'exists:brands,id'],
            'model_id' => ['required', Rule::exists('brandmodels', 'id')->where('brand_id', $this->input('brand_id'))],
            'type_id' => ['required', 'exists:vehicletypes,id'],
            'color_id' => ['nullable', 'exists:vehiclecolors,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('plate')) {
            $this->merge(['plate' => strtoupper(trim((string) $this->input('plate')))]);
        }

        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.regex' => 'La placa debe tener el formato XXXXXX, XX-XXXX o XXX-XXX.',
            'plate.unique' => 'Ya existe un vehiculo con esa placa.',
            'code.unique' => 'Ya existe un vehiculo con ese codigo.',
            'year.max' => 'El ano no puede ser posterior al proximo ano.',
            'model_id.exists' => 'El modelo elegido no pertenece a la marca seleccionada.',
        ];
    }
}
