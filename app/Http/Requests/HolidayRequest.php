<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Feriado: fecha (única), descripción y estado. */
class HolidayRequest extends FormRequest
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
            'date' => ['required', 'date', Rule::unique('holidays', 'date')->ignore($this->route('holiday'))],
            'description' => ['required', 'string', 'max:150'],
            'status' => ['required', Rule::in(['activo', 'inactivo'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.unique' => 'Ya hay un feriado registrado en esa fecha.',
        ];
    }
}
