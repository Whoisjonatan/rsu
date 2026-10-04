<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Turno (W16): nombre y rango horario, como pide el informe. */
class ScheduleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80', Rule::unique('schedules', 'name')->ignore($this->route('schedule'))],
            'time_start' => ['required', 'date_format:H:i'],
            'time_end' => ['required', 'date_format:H:i', 'after:time_start'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'time_end.after' => 'La hora de fin debe ser posterior a la de inicio.',
            'name.unique' => 'Ya existe un turno con ese nombre.',
        ];
    }
}
