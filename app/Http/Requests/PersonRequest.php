<?php

namespace App\Http\Requests;

use App\Models\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PersonRequest extends FormRequest
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
        $id = $this->route('person')?->id;

        return [
            'dni' => [
                'required', 'digits:8',
                Rule::unique('users', 'dni')->ignore($id),
            ],
            'lastname' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($id),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'usertype_id' => ['required', 'exists:usertypes,id'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'status' => ['required', Rule::in(['activo', 'inactivo'])],
            'license' => ['nullable', 'string', 'max:20'],
            'license_category' => ['nullable', 'string', 'max:10'],
            'license_expiration' => ['nullable', 'date'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8'],
        ];
    }

    /**
     * El informe pide "validar DNI, licencia, email", y W08 lo concreta:
     * la licencia es obligatoria y debe estar vigente para la funcion Conductor.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = UserType::find($this->input('usertype_id'));

                if ($type?->slug !== UserType::DRIVER) {
                    return;
                }

                if (blank($this->input('license'))) {
                    $validator->errors()->add(
                        'license',
                        'Un conductor necesita número de licencia.',
                    );

                    return;
                }

                $expiration = $this->input('license_expiration');

                if ($expiration && strtotime($expiration) < strtotime(date('Y-m-d'))) {
                    $validator->errors()->add('license_expiration', 'La licencia no está vigente.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('dni')) {
            $this->merge(['dni' => preg_replace('/\D/', '', (string) $this->input('dni'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dni.digits' => 'El DNI debe tener 8 dígitos.',
            'dni.unique' => 'Ya existe una persona con ese DNI.',
            'email.email' => 'El formato de correo no es válido.',
            'email.unique' => 'Ya existe una persona con ese correo.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }
}
