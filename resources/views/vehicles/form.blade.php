{{-- W05 Ficha de vehículo: alta y edición. --}}
@php
    $editing = $vehicle !== null;
    $value = fn (string $field, $default = '') => old($field, $vehicle?->{$field} ?? $default);
    $fieldClass = fn (string $field) => $errors->has($field) ? 'field field-error' : 'field';
    // Los decimales salen de la base como "12.50"; en el campo se ven mejor sin ceros de más.
    $number = fn (string $field) => old($field, $vehicle?->{$field} !== null ? (float) $vehicle?->{$field} : '');
@endphp

<x-layouts.app :title="$editing ? 'Vehículo '.$vehicle->code : 'Nuevo vehículo'"
    :subtitle="$editing ? 'Vehículos › Editar ficha' : 'Vehículos › Registrar un vehículo'">
    <x-slot:actions>
        <a href="{{ route('vehicles.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <form id="vehicle-form" method="POST" enctype="multipart/form-data" class="space-y-5"
            action="{{ $editing ? route('vehicles.update', $vehicle) : route('vehicles.store') }}"
            x-data="{
                sending: false,
                brandId: @js((string) $value('brand_id')),
                modelId: @js((string) $value('model_id')),
                models: @js($models),
                get brandModels() { return this.models.filter((model) => String(model.brand_id) === this.brandId) },
            }"
            x-init="$watch('brandId', () => {
                // Si la marca cambia, el modelo elegido deja de pertenecerle: se limpia.
                if (!brandModels.some((model) => String(model.id) === modelId)) modelId = ''
            })"
            @submit="sending = true">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <x-card>
                <x-card-header title="Identificación"
                    description="El código y la placa no pueden repetirse entre vehículos." />
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Código" name="code" required>
                        <input id="code" name="code" value="{{ $value('code') }}" placeholder="VEH-001" maxlength="30"
                            class="{{ $fieldClass('code') }} uppercase" required>
                    </x-field>

                    <x-field label="Placa" name="plate" hint="Letras y números: XXXXXX, XX-XXXX o XXX-XXX" required>
                        <input id="plate" name="plate" value="{{ $value('plate') }}" placeholder="B2K-841" maxlength="7"
                            class="{{ $fieldClass('plate') }} font-mono uppercase" required>
                    </x-field>

                    <x-field label="Nombre" name="name" hint="Opcional, para identificarlo en los listados"
                        class="sm:col-span-2">
                        <input id="name" name="name" value="{{ $value('name') }}" placeholder="Compactador Toyota Dyna"
                            maxlength="120" class="{{ $fieldClass('name') }}">
                    </x-field>
                </div>
            </x-card>

            <x-card>
                <x-card-header title="Clasificación" description="El modelo debe pertenecer a la marca seleccionada." />
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Marca" name="brand_id" required>
                        <select id="brand_id" name="brand_id" x-model="brandId" class="{{ $fieldClass('brand_id') }}" required>
                            <option value="">Selecciona una marca</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Modelo" name="model_id" required>
                        <select id="model_id" name="model_id" x-model="modelId" :disabled="!brandId"
                            class="{{ $fieldClass('model_id') }}" required>
                            <option value="">Selecciona un modelo</option>
                            <template x-for="model in brandModels" :key="model.id">
                                <option :value="model.id" x-text="model.name" :selected="String(model.id) === modelId"></option>
                            </template>
                        </select>
                        <p x-show="!brandId" class="mt-1.5 text-[11px] text-stone-500">Elige primero una marca</p>
                    </x-field>

                    <x-field label="Tipo" name="type_id" required>
                        <select id="type_id" name="type_id" class="{{ $fieldClass('type_id') }}" required>
                            <option value="">Selecciona un tipo</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}" @selected($value('type_id') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Color" name="color_id">
                        <select id="color_id" name="color_id" class="{{ $fieldClass('color_id') }}">
                            <option value="">Sin color asignado</option>
                            @foreach ($colors as $color)
                                <option value="{{ $color->id }}" @selected($value('color_id') == $color->id)>{{ $color->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
            </x-card>

            <x-card>
                <x-card-header title="Características" />
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Año" name="year" required>
                        <input id="year" name="year" type="number" inputmode="numeric" placeholder="2020"
                            min="1950" max="{{ now()->year }}" value="{{ $value('year') }}"
                            class="{{ $fieldClass('year') }}" required>
                    </x-field>

                    <x-field label="N.° de motor" name="engine_number">
                        <input id="engine_number" name="engine_number" value="{{ $value('engine_number') }}" maxlength="60"
                            class="{{ $fieldClass('engine_number') }}">
                    </x-field>

                    <x-field label="Capacidad de carga (Tn)" name="load_capacity" required>
                        <input id="load_capacity" name="load_capacity" type="number" step="0.01" min="0"
                            value="{{ $editing ? $number('load_capacity') : old('load_capacity', 0) }}"
                            class="{{ $fieldClass('load_capacity') }}" required>
                    </x-field>

                    <x-field label="Capacidad de compactación (Tn)" name="compaction_capacity" hint="Solo para compactadores">
                        <input id="compaction_capacity" name="compaction_capacity" type="number" step="0.01" min="0"
                            value="{{ $number('compaction_capacity') }}" class="{{ $fieldClass('compaction_capacity') }}">
                    </x-field>

                    <x-field label="Capacidad de combustible (L)" name="fuel_capacity" required>
                        <input id="fuel_capacity" name="fuel_capacity" type="number" step="0.01" min="0" placeholder="100"
                            value="{{ $number('fuel_capacity') }}" class="{{ $fieldClass('fuel_capacity') }}" required>
                    </x-field>

                    <x-field label="Capacidad de ocupantes" name="occupant_capacity"
                        hint="Cuántas personas pueden ir en el vehículo" required>
                        <input id="occupant_capacity" name="occupant_capacity" type="number" min="0" max="60"
                            value="{{ $value('occupant_capacity', 0) }}" class="{{ $fieldClass('occupant_capacity') }}" required>
                    </x-field>

                    <x-field label="Estado" name="status" required>
                        <select id="status" name="status" class="{{ $fieldClass('status') }}">
                            <option value="operativo" @selected($value('status', 'operativo') === 'operativo')>Operativo</option>
                            <option value="mantenimiento" @selected($value('status') === 'mantenimiento')>En mantenimiento</option>
                            <option value="baja" @selected($value('status') === 'baja')>De baja</option>
                        </select>
                    </x-field>

                    <x-field label="Descripción" name="description" class="sm:col-span-2">
                        <textarea id="description" name="description" rows="3" maxlength="255"
                            class="{{ $fieldClass('description') }}">{{ $value('description') }}</textarea>
                    </x-field>
                </div>
            </x-card>

            {{-- Los errores de las fotos (images.0, images.1…) no tienen campo propio. --}}
            @php
                $imageError = collect($errors->getMessages())
                    ->first(fn ($messages, $key) => str_starts_with($key, 'images') || $key === 'profile_image');
            @endphp
            @if ($imageError && ! $editing)
                <p class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-[13px] font-medium text-red-700">
                    {{ $imageError[0] }}
                </p>
            @endif

            <div class="flex justify-end gap-2">
                <a href="{{ route('vehicles.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary" :disabled="sending">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                    <x-lucide-save class="size-4" x-show="!sending" />
                    {{ $editing ? 'Guardar cambios' : 'Registrar vehículo' }}
                </button>
            </div>
        </form>

        @include('vehicles.partials.images')
    </div>
</x-layouts.app>
