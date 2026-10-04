<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Modelo de una marca (W03). */
class BrandModelRequest extends FormRequest
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
                'required', 'string', 'max:100',
                Rule::unique('brandmodels', 'name')
                    ->where('brand_id', $this->input('brand_id'))
                    ->ignore($this->route('brandmodel')),
            ],
            'code' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['required', 'exists:brands,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Esa marca ya tiene un modelo con ese nombre.',
        ];
    }
}
