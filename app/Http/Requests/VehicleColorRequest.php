<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Color de vehículo (W03). La tabla guarda los tres canales RGB, como pide el
 * informe; el formulario Blade los captura como un código HEX y aquí se
 * convierten. El API puede seguir mandando red, green y blue sueltos.
 */
class VehicleColorRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('vehiclecolors', 'name')->ignore($this->route('vehiclecolor'))],
            'description' => ['nullable', 'string', 'max:255'],
            'hex' => ['sometimes', 'nullable', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            'red' => ['required', 'integer', 'min:0', 'max:255'],
            'green' => ['required', 'integer', 'min:0', 'max:255'],
            'blue' => ['required', 'integer', 'min:0', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $hex = ltrim(trim((string) $this->input('hex')), '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $this->merge([
                'red' => hexdec(substr($hex, 0, 2)),
                'green' => hexdec(substr($hex, 2, 2)),
                'blue' => hexdec(substr($hex, 4, 2)),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function colorData(): array
    {
        return $this->safe()->only(['name', 'description', 'red', 'green', 'blue']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $hex = 'Ingresa un código HEX válido, por ejemplo #1E7A3C.';

        return [
            'name.unique' => 'Ya existe un color con ese nombre.',
            'hex.regex' => $hex,
            'red.required' => $hex,
            'green.required' => $hex,
            'blue.required' => $hex,
        ];
    }
}
