{{-- Gestión de usuarios › Registrar / editar un usuario del sistema. --}}
@php
    $value = fn (string $field, $default = '') => old($field, $user?->{$field} ?? $default);
    $date = fn (string $field) => old($field, $user?->{$field}?->toDateString() ?? '');
    $fieldClass = fn (string $field) => $errors->has($field) ? 'field field-error' : 'field';
    $isSelf = $user?->is(auth()->user());
@endphp

<x-layouts.app :title="$user ? $user->full_name : 'Registrar usuario'"
    :subtitle="$user ? 'Gestión de usuarios › Editar usuario · DNI '.$user->dni : 'Gestión de usuarios › Registrar un usuario del sistema'">
    <x-slot:actions>
        <a href="{{ ListUrl::get('users.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <div class="grid items-start gap-5 xl:grid-cols-[300px_minmax(0,1fr)]">
        {{--
            Foto de perfil. En la edición tiene formularios propios que guardan al
            instante; en el alta el archivo viaja con el formulario (form="user-form").
        --}}
        <x-card>
            <x-card-header title="Foto de perfil" :description="$user ? null : 'Se guardará al registrar al usuario.'" />
            <div class="p-5" x-data="{
                sending: false,
                preview: null,
                pick(event) {
                    const file = event.target.files[0]
                    if (this.preview) URL.revokeObjectURL(this.preview)
                    this.preview = file ? URL.createObjectURL(file) : null
                },
                clear() {
                    this.$refs.photo.value = ''
                    this.pick({ target: this.$refs.photo })
                },
            }">
                <div class="mx-auto grid aspect-square w-full max-w-60 place-items-center overflow-hidden rounded-full border border-stone-200 bg-stone-50">
                    @if ($user?->photo_url)
                        <img src="{{ $user->photo_url }}" alt="" class="size-full object-cover">
                    @else
                        <img x-show="preview" x-cloak :src="preview" alt="" class="size-full object-cover">
                        <span x-show="!preview" class="text-center text-stone-300">
                            <x-lucide-user-round class="mx-auto size-10" stroke-width="1.5" />
                            <span class="mt-2 block text-[11px] font-semibold tracking-wide text-stone-400 uppercase">Sin foto</span>
                        </span>
                    @endif
                </div>

                <div class="mt-4 space-y-2">
                    @if ($user)
                        <form method="POST" action="{{ route('users.photo.store', $user) }}" enctype="multipart/form-data" x-ref="photoForm">
                            @csrf
                            <input type="file" name="photo" accept="image/jpeg,image/png" class="hidden" x-ref="photo"
                                @change="if ($event.target.files.length) { sending = true; $refs.photoForm.submit() }">
                            <button type="button" class="btn btn-secondary w-full" :disabled="sending" @click="$refs.photo.click()">
                                <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                                <x-lucide-image-plus class="size-4" x-show="!sending" />
                                Cargar imagen
                            </button>
                        </form>

                        @if ($user->photo_url)
                            <form method="POST" action="{{ route('users.photo.destroy', $user) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger w-full">
                                    <x-lucide-trash-2 class="size-4" />
                                    Eliminar imagen
                                </button>
                            </form>
                        @endif
                    @else
                        <input type="file" name="photo" form="user-form" accept="image/jpeg,image/png" class="hidden"
                            x-ref="photo" @change="pick($event)">
                        <button type="button" class="btn btn-secondary w-full" @click="$refs.photo.click()">
                            <x-lucide-image-plus class="size-4" />
                            <span x-text="preview ? 'Cambiar imagen' : 'Cargar imagen'">Cargar imagen</span>
                        </button>
                        <button type="button" class="btn btn-danger w-full" x-show="preview" x-cloak @click="clear()">
                            <x-lucide-trash-2 class="size-4" />
                            Quitar imagen
                        </button>
                    @endif
                </div>

                @error('photo')
                    <p class="mt-3 text-[11px] font-medium text-red-600">{{ $message }}</p>
                @enderror
                @if (! $user && $errors->any() && ! $errors->has('photo'))
                    {{-- El navegador no conserva el archivo elegido tras un error de validación. --}}
                    <p class="mt-3 text-[11px] font-medium text-amber-700">Si habías elegido una foto, vuelve a cargarla.</p>
                @endif
                <p class="mt-3 text-[11px] text-stone-500">Formatos admitidos: JPG o PNG · máx. 4 MB.</p>
            </div>
        </x-card>

        <form method="POST" id="user-form" class="space-y-5" enctype="multipart/form-data"
            action="{{ $user ? route('users.update', $user) : route('users.store') }}"
            x-data="{ sending: false }" @submit="sending = true">
            @csrf
            @if ($user)
                @method('PUT')
            @endif

            <x-card>
                <x-card-header title="Datos personales"
                    description="El DNI debe tener 8 dígitos y no repetirse; el correo debe ser válido y único." />
                <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    {{-- Consulta de DNI (opcional): si hay proveedor configurado, "Traer datos" rellena apellidos y nombres. --}}
                    <div x-data="{
                        dni: @js((string) $value('dni')),
                        loading: false,
                        feedback: null,
                        get ready() { return /^\d{8}$/.test(this.dni) },
                        async lookup() {
                            this.loading = true
                            this.feedback = null
                            try {
                                const { data } = await window.api.get('/dni-lookup/' + this.dni)
                                document.getElementById('lastname').value = data.person.lastname
                                document.getElementById('name').value = data.person.name
                                this.feedback = { ok: true, text: 'Datos traídos de ' + data.source }
                            } catch (error) {
                                this.feedback = { ok: false, text: error.response?.data?.message ?? 'No se pudo consultar el DNI' }
                            } finally {
                                this.loading = false
                            }
                        },
                    }">
                        <x-field label="DNI" name="dni" required>
                            <input id="dni" name="dni" x-model="dni" inputmode="numeric" maxlength="8" pattern="\d{8}"
                                title="8 dígitos" class="{{ $fieldClass('dni') }} font-mono" required>
                        </x-field>

                        @if ($dniLookup)
                            <div class="mt-2">
                                <button type="button" class="btn btn-secondary btn-sm" :disabled="!ready || loading"
                                    :title="ready ? '' : 'Escribe los 8 dígitos del DNI'" @click="lookup()">
                                    <x-lucide-loader-circle class="size-3.5 animate-spin" x-show="loading" x-cloak />
                                    <x-lucide-search class="size-3.5" x-show="!loading" />
                                    Traer datos
                                </button>
                                <p x-show="feedback" x-cloak class="mt-1.5 flex items-start gap-1 text-[11px] font-medium"
                                    :class="feedback?.ok ? 'text-brand-700' : 'text-amber-700'">
                                    <x-lucide-circle-check class="mt-px size-3 shrink-0" x-show="feedback?.ok" />
                                    <span x-text="feedback?.text"></span>
                                </p>
                            </div>
                        @endif
                    </div>

                    <x-field label="Apellidos" name="lastname" required>
                        <input id="lastname" name="lastname" value="{{ $value('lastname') }}" maxlength="120"
                            class="{{ $fieldClass('lastname') }}" required>
                    </x-field>

                    <x-field label="Nombres" name="name" required>
                        <input id="name" name="name" value="{{ $value('name') }}" maxlength="120"
                            class="{{ $fieldClass('name') }}" required>
                    </x-field>

                    <x-field label="Fecha de nacimiento" name="birthdate">
                        <input id="birthdate" name="birthdate" type="date" value="{{ $date('birthdate') }}"
                            max="{{ now()->subDay()->toDateString() }}" class="{{ $fieldClass('birthdate') }}">
                    </x-field>

                    <x-field label="Correo electrónico" name="email" required>
                        <input id="email" name="email" type="email" value="{{ $value('email') }}" maxlength="255"
                            class="{{ $fieldClass('email') }}" required>
                    </x-field>

                    <x-field label="Teléfono" name="phone">
                        <input id="phone" name="phone" value="{{ $value('phone') }}" maxlength="20" inputmode="tel"
                            class="{{ $fieldClass('phone') }}">
                    </x-field>

                    <x-field label="Dirección" name="address" class="sm:col-span-2 lg:col-span-3">
                        <input id="address" name="address" value="{{ $value('address') }}" maxlength="255"
                            class="{{ $fieldClass('address') }}">
                    </x-field>
                </div>
            </x-card>

            <x-card>
                <x-card-header title="Acceso al sistema" :description="$user
                    ? 'Deja la contraseña en blanco para no cambiarla.'
                    : 'Entrará al panel con su correo o DNI y esta contraseña.'" />
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Tipo de usuario" name="usertype_id" required>
                        <select id="usertype_id" name="usertype_id" class="{{ $fieldClass('usertype_id') }}" required
                            @if ($isSelf) disabled @endif>
                            <option value="">Selecciona un tipo de usuario</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}" @selected((string) $value('usertype_id') === (string) $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @if ($isSelf)
                            {{-- Un select deshabilitado no se envía: el valor va aparte. --}}
                            <input type="hidden" name="usertype_id" value="{{ $user->usertype_id }}">
                        @endif
                    </x-field>

                    <x-field label="Estado" name="status" required>
                        <select id="status" name="status" class="{{ $fieldClass('status') }}" @if ($isSelf) disabled @endif>
                            <option value="activo" @selected($value('status', 'activo') === 'activo')>Activo</option>
                            <option value="inactivo" @selected($value('status') === 'inactivo')>Inactivo</option>
                        </select>
                        @if ($isSelf)
                            <input type="hidden" name="status" value="activo">
                        @endif
                    </x-field>

                    <x-field label="Contraseña" name="password" hint="Mínimo 8 caracteres" :required="! $user">
                        <input id="password" name="password" type="password" autocomplete="new-password" minlength="8"
                            @required(! $user) class="{{ $fieldClass('password') }}">
                    </x-field>

                    <x-field label="Confirmar contraseña" name="password_confirmation" :required="! $user">
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                            minlength="8" @required(! $user) class="field">
                    </x-field>
                </div>
                @if ($isSelf)
                    <p class="border-t border-stone-200 px-5 py-3 text-[12px] text-stone-500">
                        Es tu propio usuario: no puedes cambiar tu tipo de usuario ni desactivarte.
                    </p>
                @endif
            </x-card>

            <div class="flex justify-end gap-2">
                <a href="{{ ListUrl::get('users.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary" :disabled="sending">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                    <x-lucide-save class="size-4" x-show="!sending" />
                    {{ $user ? 'Guardar cambios' : 'Registrar usuario' }}
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
