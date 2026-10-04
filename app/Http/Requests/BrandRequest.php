<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Marca de vehículo (W03). La usan el API y la vista Blade. */
class BrandRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('brands', 'name')->ignore($this->route('brand'))],
            'description' => ['nullable', 'string', 'max:255'],
            // Solo la vista Blade manda el logo junto con la ficha; el API lo
            // sube aparte (POST brands/{brand}/logo).
            'logo' => ['nullable', ...self::logoRules()],
            'remove_logo' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Lo que se guarda en la tabla: sin el archivo ni la marca de borrado.
     *
     * @return array<string, mixed>
     */
    public function brandData(): array
    {
        return $this->safe()->only(['name', 'description']);
    }

    /**
     * @return list<string>
     */
    public static function logoRules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una marca con ese nombre.',
            'logo.mimes' => 'El logo debe ser JPG, PNG o WebP.',
            'logo.max' => 'El logo no puede pesar más de 2 MB.',
        ];
    }
}
