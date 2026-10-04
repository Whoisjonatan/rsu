{{-- W03 Catálogos de vehículos (los "maestros" del informe). --}}
@php
    $tabs = [
        'colores' => ['Colores', 'color', 'Nuevo color', 'Editar color', 'Se almacena el código RGB de cada color.'],
        'marcas' => ['Marcas', 'marca', 'Nueva marca', 'Editar marca', null],
        'modelos' => ['Modelos', 'modelo', 'Nuevo modelo', 'Editar modelo', 'Cada modelo pertenece a una marca.'],
        'tipos' => ['Tipos', 'tipo', 'Nuevo tipo', 'Editar tipo', null],
    ];
    [$plural, $singular, $titleNew, $titleEdit, $description] = $tabs[$tab];

    $routes = [
        'colores' => 'vehiclecolors',
        'marcas' => 'brands',
        'modelos' => 'brandmodels',
        'tipos' => 'vehicletypes',
    ];
    $resource = $routes[$tab];

    $defaults = match ($tab) {
        'colores' => ['name' => '', 'description' => '', 'hex' => '#808080'],
        'marcas' => ['name' => '', 'description' => '', 'logo_url' => null, 'remove_logo' => false],
        'modelos' => ['name' => '', 'description' => '', 'code' => '', 'brand_id' => ''],
        'tipos' => ['name' => '', 'description' => ''],
    };

    $placeholders = [
        'colores' => 'Buscar por nombre, código HEX o descripción…',
        'marcas' => 'Buscar por nombre o descripción…',
        'modelos' => 'Buscar por modelo, código, marca o descripción…',
        'tipos' => 'Buscar por nombre o descripción…',
    ];
@endphp

<x-layouts.app title="Catálogos de vehículos"
    subtitle="Colores, marcas, modelos y tipos que alimentan la ficha del vehículo">
    <nav class="mb-5 flex gap-1 border-b border-stone-200" aria-label="Catálogos">
        @foreach ($tabs as $key => [$label])
            <a href="{{ route('vehicle-catalogs.index', ['tab' => $key]) }}"
                @if ($key === $tab) aria-current="page" @endif @class([
                    '-mb-px flex items-center gap-2 border-b-2 px-4 py-2 text-[13px] font-medium transition',
                    'border-brand-600 text-brand-700' => $key === $tab,
                    'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-700' => $key !== $tab,
                ])>
                {{ $label }}
                <span @class([
                    'rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                    'bg-brand-50 text-brand-700' => $key === $tab,
                    'bg-stone-100 text-stone-500' => $key !== $tab,
                ])>{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    <div x-data="crud({
        name: @js($tab),
        storeUrl: @js(route("$resource.store")),
        updateUrl: @js(route("$resource.update", '__ID__')),
        destroyUrl: @js(route("$resource.destroy", '__ID__')),
        defaults: @js($defaults),
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <x-card-header :title="$plural" :description="$description">
                <button type="button" class="btn btn-primary btn-sm" @click="create()">
                    <x-lucide-plus class="size-3.5" />
                    {{ $titleNew }}
                </button>
            </x-card-header>

            <x-search-bar :value="$search" :placeholder="$placeholders[$tab]" :keep="['tab' => $tab]" />

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            @if ($tab === 'colores')
                                <th>Color</th>
                                <th>HEX</th>
                            @elseif ($tab === 'marcas')
                                <th>Marca</th>
                            @elseif ($tab === 'modelos')
                                <th>Modelo</th>
                                <th>Marca</th>
                            @else
                                <th>Tipo</th>
                            @endif
                            <th>Descripción</th>
                            @if ($tab === 'marcas')
                                <th class="text-right!">Modelos</th>
                            @endif
                            <th class="text-right!">Vehículos</th>
                            <th class="w-24"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                @if ($tab === 'colores')
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <span class="size-4 rounded ring-1 ring-stone-300 ring-inset"
                                                style="background-color: {{ $row->hex }}"></span>
                                            <span class="font-medium text-stone-900">{{ $row->name }}</span>
                                        </div>
                                    </td>
                                    <td><span class="font-mono text-[12px] text-stone-500">{{ $row->hex }}</span></td>
                                @elseif ($tab === 'marcas')
                                    <td>
                                        <div class="flex items-center gap-2.5">
                                            <span
                                                class="grid size-8 shrink-0 place-items-center overflow-hidden rounded-md bg-white ring-1 ring-stone-200 ring-inset">
                                                @if ($row->logo_url)
                                                    <img src="{{ $row->logo_url }}" alt="" class="size-full object-contain p-0.5">
                                                @else
                                                    <span class="text-[12px] font-semibold text-stone-400 uppercase">
                                                        {{ mb_substr(trim($row->name), 0, 1) ?: '?' }}
                                                    </span>
                                                @endif
                                            </span>
                                            <span class="font-medium text-stone-900">{{ $row->name }}</span>
                                        </div>
                                    </td>
                                @elseif ($tab === 'modelos')
                                    <td><span class="font-medium text-stone-900">{{ $row->name }}</span></td>
                                    <td>{{ $row->brand?->name }}</td>
                                @else
                                    <td><span class="font-medium text-stone-900">{{ $row->name }}</span></td>
                                @endif

                                <td><span class="text-stone-500">{{ $row->description ?: '—' }}</span></td>
                                @if ($tab === 'marcas')
                                    <td class="text-right tabular-nums">{{ $row->models_count }}</td>
                                @endif
                                <td class="text-right tabular-nums">{{ $row->vehicles_count }}</td>
                                <td>
                                    <x-row-actions :label="$row->name" :row="match ($tab) {
                                        'colores' => $row->only('id', 'name', 'description', 'hex'),
                                        'marcas' => $row->only('id', 'name', 'description', 'logo_url'),
                                        'modelos' => $row->only('id', 'name', 'description', 'code', 'brand_id'),
                                        'tipos' => $row->only('id', 'name', 'description'),
                                    }" />
                                </td>
                            </tr>
                        @empty
                            <x-empty-row :colspan="$tab === 'tipos' ? 4 : 5"
                                :message="$search !== '' ? 'No hay coincidencias' : 'No hay registros'" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $rows->links() }}
        </x-card>

        <x-crud-form :name="$tab" :title-new="$titleNew" :title-edit="$titleEdit" :files="$tab === 'marcas'">
            @if ($tab === 'modelos')
                <x-field label="Marca" name="brand_id" for="brand_id" required live>
                    <select id="brand_id" name="brand_id" x-model="form.brand_id" class="field"
                        :class="error('brand_id') && 'field-error'">
                        <option value="">Selecciona una marca</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </x-field>
            @endif

            <x-field label="Nombre" name="name" required live>
                <input id="name" name="name" x-model="form.name" class="field" :class="error('name') && 'field-error'"
                    maxlength="100" required>
            </x-field>

            @if ($tab === 'modelos')
                <x-field label="Código" name="code" live>
                    <input id="code" name="code" x-model="form.code" class="field" maxlength="30"
                        :class="error('code') && 'field-error'">
                </x-field>
            @endif

            @if ($tab === 'colores')
                {{-- El selector nativo solo acepta #rrggbb: mientras se escribe a mano, se queda con el último válido. --}}
                <div x-data="{ lastValid: '#808080' }" x-effect="if (/^#?[0-9a-f]{6}$/i.test(form.hex)) lastValid = '#' + form.hex.replace('#', '').toUpperCase()">
                    <label for="hex" class="label">Color <span class="ml-0.5 text-brand-600">*</span></label>
                    <div class="flex items-center gap-3">
                        <input type="color" aria-label="Seleccionar color" :value="lastValid"
                            @input="form.hex = $event.target.value.toUpperCase()"
                            class="h-10 w-14 shrink-0 cursor-pointer rounded-md border border-stone-300 bg-white p-1">
                        <input id="hex" name="hex" x-model="form.hex" maxlength="7" placeholder="#1E7A3C"
                            class="field font-mono uppercase"
                            :class="(error('hex') || error('red')) && 'field-error'">
                    </div>
                    <p x-show="error('hex') || error('red')" x-text="error('hex') || error('red')" x-cloak
                        class="mt-1.5 text-[11px] font-medium text-red-600"></p>
                    <p x-show="!(error('hex') || error('red'))" class="mt-1.5 text-[11px] text-stone-500">
                        Elige el color o escribe su código HEX.
                    </p>
                </div>
            @endif

            <x-field label="Descripción" name="description" live>
                <input id="description" name="description" x-model="form.description" class="field" maxlength="255"
                    :class="error('description') && 'field-error'">
            </x-field>

            @if ($tab === 'marcas')
                {{-- Logo: se elige aquí, se ve la vista previa y se sube junto con la marca. --}}
                <div x-data="{ preview: null }"
                    x-init="$watch('open', () => { preview = null; $refs.logo.value = ''; form.remove_logo = false })">
                    <p class="label">Logo</p>
                    <div class="flex items-center gap-4">
                        <span
                            class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-md bg-white ring-1 ring-stone-200 ring-inset">
                            <template x-if="preview || (!form.remove_logo && form.logo_url)">
                                <img :src="preview || form.logo_url" alt="" class="size-full object-contain p-0.5">
                            </template>
                            <template x-if="!(preview || (!form.remove_logo && form.logo_url))">
                                <span class="text-2xl font-semibold text-stone-400 uppercase"
                                    x-text="(form.name || '').trim().charAt(0) || '?'"></span>
                            </template>
                        </span>
                        <div class="space-y-2">
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-secondary btn-sm" @click="$refs.logo.click()">
                                    <x-lucide-image-plus class="size-3.5" />
                                    <span x-text="preview || (!form.remove_logo && form.logo_url) ? 'Cambiar logo' : 'Cargar logo'"></span>
                                </button>
                                <button type="button" class="btn btn-ghost btn-sm hover:bg-red-50 hover:text-red-600"
                                    x-show="preview || (!form.remove_logo && form.logo_url)"
                                    @click="preview = null; $refs.logo.value = ''; form.remove_logo = true">
                                    <x-lucide-trash-2 class="size-3.5" />
                                    Quitar
                                </button>
                            </div>
                            <p class="text-[11px] text-stone-500">JPG, PNG o WebP · máx. 2 MB.</p>
                        </div>
                    </div>
                    <input type="file" name="logo" x-ref="logo" accept="image/jpeg,image/png,image/webp" class="hidden"
                        @change="const file = $event.target.files[0]; preview = file ? URL.createObjectURL(file) : null; if (file) form.remove_logo = false">
                    {{-- Vacío cuando no se quita: "0" se leería como verdadero al reabrir con old(). --}}
                    <input type="hidden" name="remove_logo" :value="form.remove_logo ? 1 : ''">
                    {{-- Solo para que old() lo conserve si el modal se reabre tras un error. --}}
                    <input type="hidden" name="logo_url" :value="form.logo_url ?? ''">
                    <p x-show="error('logo')" x-text="error('logo')" x-cloak
                        class="mt-1.5 text-[11px] font-medium text-red-600"></p>
                </div>
            @endif
        </x-crud-form>

        <x-confirm-delete :title="'Eliminar '.$singular">
            No se puede eliminar si hay vehículos que lo usan.
        </x-confirm-delete>
    </div>
</x-layouts.app>
