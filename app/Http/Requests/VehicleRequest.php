<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use App\Models\VehicleType;
use Closure;
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
                'bail', 'required', 'string', 'max:7',
                'regex:'.Vehicle::PLATE_REGEX,
                // Una placa es alfanumerica: "123456" o "ABCDEF" no son placas.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! preg_match('/[A-Z]/', $value) || ! preg_match('/[0-9]/', $value)) {
                        $fail('La placa debe combinar letras y números.');
                    }
                },
                function (string $attribute, mixed $value, Closure $fail) use ($id) {
                    $taken = Vehicle::withPlate($value)
                        ->when($id, fn ($query) => $query->whereKeyNot($id))
                        ->exists();

                    if ($taken) {
                        $fail('Ya existe un vehículo con esa placa.');
                    }
                },
            ],
            'name' => ['nullable', 'string', 'max:120'],
            // Un vehiculo no puede ser de un año que todavia no llega.
            'year' => ['required', 'integer', 'min:1950', 'max:'.now()->year],
            'occupant_capacity' => ['required', 'integer', 'min:0', 'max:60'],
            'load_capacity' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'fuel_capacity' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'compaction_capacity' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'engine_number' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['operativo', 'mantenimiento', 'baja'])],
            'brand_id' => ['required', 'exists:brands,id'],
            'model_id' => ['required', Rule::exists('brandmodels', 'id')->where('brand_id', $this->input('brand_id'))],
            'type_id' => ['required', 'exists:vehicletypes,id'],
            'color_id' => ['nullable', 'exists:vehiclecolors,id'],
            // Solo en el alta: las fotos viajan junto con la ficha (multipart)
            // y profile_image es el indice de la que queda como perfil.
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'profile_image' => ['sometimes', 'integer', 'min:0'],
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

        // La capacidad de compactación solo se guarda en un compactador: si
        // el tipo es otro, se descarta lo que venga (el formulario ya la
        // deshabilita, pero el API también la recibe).
        $type = VehicleType::find($this->input('type_id'));

        if ($type && ! $type->isCompactor()) {
            $this->merge(['compaction_capacity' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.required' => 'La placa es obligatoria.',
            'plate.max' => 'La placa tiene como máximo 7 caracteres.',
            'plate.regex' => 'La placa debe tener el formato XXXXXX, XX-XXXX o XXX-XXX.',
            'code.required' => 'El código es obligatorio.',
            'code.max' => 'El código tiene como máximo 30 caracteres.',
            'code.unique' => 'Ya existe un vehículo con ese código.',
            'year.required' => 'Indica el año del vehículo.',
            'year.integer' => 'El año debe ser un número entero.',
            'year.min' => 'El año no puede ser anterior a 1950.',
            'year.max' => 'El año no puede ser posterior a '.now()->year.'.',
            'model_id.exists' => 'El modelo elegido no pertenece a la marca seleccionada.',
            'images.max' => 'Puedes subir como máximo 10 imágenes.',
            'images.*.image' => 'Uno de los archivos no es una imagen.',
            'images.*.mimes' => 'Las imágenes deben ser JPG, PNG o WEBP.',
            'images.*.max' => 'Cada imagen puede pesar como máximo 4 MB.',
        ];
    }
}
