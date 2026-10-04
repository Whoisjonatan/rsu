<?php

namespace App\Http\Requests;

use App\Models\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Función del personal (W06). El informe exige que Conductor y Ayudante
 * existan siempre: van marcadas con `is_system`.
 */
class UserTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80', Rule::unique('usertypes', 'name')->ignore($this->route('usertype'))],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Lo que se guarda. El slug sale del nombre, salvo en una función
     * predefinida: hay código que la busca por su slug y no debe cambiar.
     *
     * @return array<string, mixed>
     */
    public function typeData(?UserType $existing = null): array
    {
        $data = $this->validated();

        if (! $existing?->is_system) {
            $data['slug'] = Str::slug($data['name']);
        }

        if (! $existing) {
            $data['is_system'] = false;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Ya existe una función con ese nombre.'];
    }
}
