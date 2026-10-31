<?php

namespace App\Http\Requests;

use App\Models\ChangeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Motivo de cambio (Gestión de cambios): nombre, a qué cambio aplica y si pide descripción. */
class ChangeReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // El checkbox desmarcado no viaja.
        $this->merge(['requires_description' => $this->boolean('requires_description')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Único en todo el catálogo: la reprogramación lo busca por su nombre.
            'name' => ['required', 'string', 'max:100', Rule::unique('change_reasons', 'name')->ignore($this->route('reason'))],
            'type' => ['required', Rule::in(array_keys(ChangeReason::TYPES))],
            'requires_description' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el motivo.',
            'name.unique' => 'Ya existe un motivo con ese nombre.',
            'type.required' => 'Elige a qué cambio aplica.',
            'type.in' => 'Elige a qué cambio aplica.',
        ];
    }
}
