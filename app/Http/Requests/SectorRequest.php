<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sector de un distrito: el catálogo que agrupa zonas (W13). */
class SectorRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('sectors', 'name')
                    ->where('district_id', $this->input('district_id'))
                    ->ignore($this->route('sector')),
            ],
            'area' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'district_id' => ['required', 'exists:districts,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Ese distrito ya tiene un sector con ese nombre.'];
    }
}
