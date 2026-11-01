<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de un usuario del sistema (Gestión de usuarios › Lista de
 * usuarios). Comparte la tabla `users` con el personal, pero solo admite los
 * tipos de usuario del sistema (UserType::KIND_SYSTEM).
 */
class SystemUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Quién puede crear o editar lo decide el permiso de la ruta (users.create, users.edit).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'lastname' => ['required', 'string', 'max:120'],
            'dni' => ['required', 'digits:8', Rule::unique('users', 'dni')->ignore($id)],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'usertype_id' => [
                'required',
                Rule::exists('usertypes', 'id')->where('kind', UserType::KIND_SYSTEM),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['activo', 'inactivo'])],
            // En el alta la foto viaja con el formulario; en la edición tiene el suyo.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ];
    }

    /**
     * Nadie se bloquea a sí mismo y el sistema no se queda sin administrador
     * activo.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var User|null $user */
                $user = $this->route('user');

                if (! $user) {
                    return;
                }

                $type = UserType::find($this->input('usertype_id'));
                $staysAdmin = $type?->slug === UserType::ADMIN && $this->input('status') === 'activo';

                if ($user->is($this->user())) {
                    if ($this->input('status') !== 'activo') {
                        $validator->errors()->add('status', 'No puedes desactivar tu propio usuario.');
                    }

                    if ($type && $type->id !== $user->usertype_id) {
                        $validator->errors()->add('usertype_id', 'No puedes cambiar tu propio tipo de usuario.');
                    }
                }

                if (! $staysAdmin && ($blocker = $user->lastAdminBlocker())) {
                    $validator->errors()->add('usertype_id', $blocker);
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
            'usertype_id.exists' => 'Elige un tipo de usuario válido.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'birthdate.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'photo.image' => 'La foto debe ser una imagen.',
            'photo.mimes' => 'La foto debe ser JPG o PNG.',
            'photo.max' => 'La foto no puede pesar más de 4 MB.',
        ];
    }

    /**
     * Lo validado, listo para guardar. Una contraseña vacía en la edición
     * significa "no la cambies".
     *
     * @return array<string, mixed>
     */
    public function userData(): array
    {
        $data = Arr::except($this->validated(), ['photo', 'password_confirmation']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }
}
