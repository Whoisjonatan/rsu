<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Zona de recolección (W13). El perímetro se guarda aparte (W14) y de él sale
 * el área, así que no se escribe aquí. Departamento y provincia se eligen en
 * el formulario pero no se guardan: los da el distrito.
 */
class ZoneRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120', Rule::unique('zones', 'name')->ignore($this->route('zone'))],
            'district_id' => ['required', 'exists:districts,id'],
            'average_waste_kg' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(['activa', 'inactiva'])],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Ya existe una zona con ese nombre.'];
    }
}
