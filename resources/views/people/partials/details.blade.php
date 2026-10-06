{{-- Pestaña "Datos personales" de W08: fotografía y ficha. --}}
@php
    $value = fn (string $field, $default = '') => old($field, $person?->{$field} ?? $default);
    $date = fn (string $field) => old($field, $person?->{$field}?->toDateString() ?? '');
    $fieldClass = fn (string $field) => $errors->has($field) ? 'field field-error' : 'field';

    /** Categorías de licencia de conducir vigentes en Perú. */
    $licenseCategories = ['A-I', 'A-IIa', 'A-IIb', 'A-IIIa', 'A-IIIb', 'A-IIIc', 'B-IIc'];
    $driverIds = $types->where('slug', 'conductor')->pluck('id')->map(fn ($id) => (string) $id)->values();
@endphp

<div class="grid items-start gap-5 xl:grid-cols-[300px_minmax(0,1fr)]">
    {{--
        Fotografía. En la edición tiene formularios propios que guardan al instante.
        En el alta el archivo viaja con la ficha (atributo form="person-form"), así
        que se elige antes de registrar y se guarda junto con la persona.
    --}}
    <x-card>
        <x-card-header title="Fotografía"
            :description="$person ? null : 'Se guardará al registrar a la persona.'" />
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
            <div class="grid aspect-3/4 w-full place-items-center overflow-hidden rounded-lg border border-stone-200 bg-stone-50">
                @if ($person?->photo_url)
                    <img src="{{ $person->photo_url }}" alt="" class="size-full object-cover">
                @else
                    <img x-show="preview" x-cloak :src="preview" alt="" class="size-full object-cover">
                    <span x-show="!preview" class="text-center text-stone-300">
                        <x-lucide-user-round class="mx-auto size-10" stroke-width="1.5" />
                        <span class="mt-2 block text-[11px] font-semibold tracking-wide text-stone-400 uppercase">Sin fotografía</span>
                    </span>
                @endif
            </div>

            <div class="mt-4 space-y-2">
                @if ($person)
                    <form method="POST" action="{{ route('people.photo.store', $person) }}" enctype="multipart/form-data"
                        x-ref="photoForm">
                        @csrf
                        <input type="file" name="photo" accept="image/jpeg,image/png" class="hidden" x-ref="photo"
                            @change="if ($event.target.files.length) { sending = true; $refs.photoForm.submit() }">
                        <button type="button" class="btn btn-secondary w-full" :disabled="sending" @click="$refs.photo.click()">
                            <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                            <x-lucide-image-plus class="size-4" x-show="!sending" />
                            Cargar imagen
                        </button>
                    </form>

                    @if ($person->photo_url)
                        <form method="POST" action="{{ route('people.photo.destroy', $person) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger w-full">
                                <x-lucide-trash-2 class="size-4" />
                                Eliminar imagen
                            </button>
                        </form>
                    @endif
                @else
                    <input type="file" name="photo" form="person-form" accept="image/jpeg,image/png" class="hidden"
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
            @if (! $person && $errors->any() && ! $errors->has('photo'))
                {{-- El navegador no conserva el archivo elegido tras un error de validación. --}}
                <p class="mt-3 text-[11px] font-medium text-amber-700">Si habías elegido una foto, vuelve a cargarla.</p>
            @endif
            <p class="mt-3 text-[11px] text-stone-500">Formatos admitidos: JPG o PNG · máx. 4 MB.</p>
        </div>
    </x-card>

    <form method="POST" id="person-form" class="space-y-5" enctype="multipart/form-data"
        action="{{ $person ? route('people.update', $person) : route('people.store') }}"
        x-data="{
            sending: false,
            usertypeId: @js((string) $value('usertype_id')),
            driverIds: @js($driverIds),
            get isDriver() { return this.driverIds.includes(this.usertypeId) },
        }"
        @submit="sending = true">
        @csrf
        @if ($person)
            @method('PUT')
        @endif

        <x-card>
            <x-card-header title="Datos personales"
                description="El DNI debe tener 8 dígitos y no repetirse; el correo debe ser válido y único." />
            <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
                {{--
                    Consulta de DNI (opcional): si hay proveedor configurado, "Traer datos"
                    rellena apellidos y nombres. Consulta el API con la cookie de sesión.
                --}}
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
                            <p x-show="!feedback" class="mt-1.5 text-[11px] text-stone-400">
                                {{ $dniLookup }} · solo trae apellidos y nombres
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

                <x-field label="Correo electrónico" name="email" required>
                    <input id="email" name="email" type="email" value="{{ $value('email') }}" maxlength="255"
                        class="{{ $fieldClass('email') }}" required>
                </x-field>

                <x-field label="Teléfono" name="phone">
                    <input id="phone" name="phone" value="{{ $value('phone') }}" maxlength="20" class="{{ $fieldClass('phone') }}">
                </x-field>

                <x-field label="Fecha de nacimiento" name="birthdate">
                    <input id="birthdate" name="birthdate" type="date" value="{{ $date('birthdate') }}"
                        max="{{ now()->subDay()->toDateString() }}" class="{{ $fieldClass('birthdate') }}">
                </x-field>

                <x-field label="Domicilio" name="address" class="sm:col-span-2 lg:col-span-3">
                    <input id="address" name="address" value="{{ $value('address') }}" maxlength="255"
                        class="{{ $fieldClass('address') }}">
                </x-field>
            </div>
        </x-card>

        <x-card>
            <x-card-header title="Función y asignación"
                description="La licencia es obligatoria y debe estar vigente para la función Conductor." />
            <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
                <x-field label="Función" name="usertype_id" required>
                    <select id="usertype_id" name="usertype_id" x-model="usertypeId" class="{{ $fieldClass('usertype_id') }}" required>
                        <option value="">Selecciona una función</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Zona asignada" name="zone_id">
                    <select id="zone_id" name="zone_id" class="{{ $fieldClass('zone_id') }}">
                        <option value="">Sin zona asignada</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}" @selected($value('zone_id') == $zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Estado" name="status" required>
                    <select id="status" name="status" class="{{ $fieldClass('status') }}">
                        <option value="activo" @selected($value('status', 'activo') === 'activo')>Activo</option>
                        <option value="inactivo" @selected($value('status') === 'inactivo')>Inactivo</option>
                    </select>
                </x-field>

                <div>
                    <label for="license" class="label">
                        N.° de licencia
                        <span class="ml-0.5 text-brand-600" x-show="isDriver" x-cloak>*</span>
                    </label>
                    <input id="license" name="license" value="{{ $value('license') }}" maxlength="20"
                        :required="isDriver" class="{{ $fieldClass('license') }}">
                    @error('license')
                        <p class="mt-1.5 flex items-center gap-1 text-[11px] font-medium text-red-600">
                            <x-lucide-circle-alert class="size-3.5 shrink-0" />
                            {{ $message }}
                        </p>
                    @else
                        <p class="mt-1.5 text-[11px] text-stone-500" x-text="isDriver ? 'Obligatoria para conductores' : 'Opcional'"></p>
                    @enderror
                </div>

                <x-field label="Categoría de licencia" name="license_category">
                    <select id="license_category" name="license_category" class="{{ $fieldClass('license_category') }}">
                        <option value="">—</option>
                        @foreach ($licenseCategories as $category)
                            <option value="{{ $category }}" @selected($value('license_category') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Vencimiento de licencia" name="license_expiration">
                    <input id="license_expiration" name="license_expiration" type="date"
                        value="{{ $date('license_expiration') }}" class="{{ $fieldClass('license_expiration') }}">
                </x-field>
            </div>
        </x-card>

        <x-card>
            <x-card-header title="Acceso al sistema" :description="$person
                ? 'Déjala en blanco para no cambiar la contraseña actual.'
                : 'Con estas credenciales la persona marcará su asistencia (DNI + clave).'" />
            <div class="p-5">
                <x-field label="Contraseña" name="password" hint="Mínimo 8 caracteres" :required="! $person" class="max-w-xs">
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="8"
                        @required(! $person) class="{{ $fieldClass('password') }}">
                </x-field>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ ListUrl::get('people.index') }}" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary" :disabled="sending">
                <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                <x-lucide-save class="size-4" x-show="!sending" />
                {{ $person ? 'Guardar cambios' : 'Registrar personal' }}
            </button>
        </div>
    </form>
</div>
