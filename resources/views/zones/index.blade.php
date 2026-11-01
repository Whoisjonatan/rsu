{{-- W13 Zonas. --}}
<x-layouts.app title="Zonas"
    :subtitle="$zones->count().' zonas de recolección · '.$zones->where('coords_count', '>', 0)->count().' con perímetro definido'">
    <div x-data="crud({
        name: 'zone',
        storeUrl: @js(route('zones.store')),
        updateUrl: @js(route('zones.update', '__ID__')),
        destroyUrl: @js(route('zones.destroy', '__ID__')),
        defaults: {
            name: '', department_id: '', province_id: '', district_id: '', average_waste_kg: '', status: 'activa',
            description: '',
        },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <x-card-header title="Zonas de recolección"
                description="Cada zona pertenece a un distrito y agrupa las rutas y programaciones. El área se calcula al trazar el perímetro.">
                <div class="flex gap-2">
                    @can('zones.map')
                    <a href="{{ route('zones.map') }}" class="btn btn-secondary btn-sm">
                        <x-lucide-map class="size-3.5" />
                        Mapa
                    </a>
                    @endcan
                    @can('zones.create')
                    <button type="button" class="btn btn-primary btn-sm" @click="create()">
                        <x-lucide-plus class="size-3.5" />
                        Nueva zona
                    </button>
                    @endcan
                </div>
            </x-card-header>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Zona</th>
                            <th>Ubicación</th>
                            <th>Descripción</th>
                            <th class="text-right">Área</th>
                            <th class="text-right">Residuos promedio</th>
                            <th>Perímetro</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($zones as $zone)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-50 text-brand-600">
                                            <x-lucide-map-pin class="size-3.5" />
                                        </span>
                                        <span class="font-medium text-stone-900">{{ $zone->name }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="block text-stone-900">{{ $zone->district?->name ?? '—' }}</span>
                                    @if ($zone->district)
                                        <span class="block text-[11px] text-stone-500">
                                            {{ $zone->district->province?->name }} · {{ $zone->district->department?->name }}
                                        </span>
                                    @endif
                                </td>
                                <td class="max-w-64">
                                    @if ($zone->description)
                                        <span class="block truncate" title="{{ $zone->description }}">{{ $zone->description }}</span>
                                    @else
                                        <span class="text-stone-400">—</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap tabular-nums">
                                    {{ $zone->area !== null ? number_format((float) $zone->area, 3).' km²' : '—' }}
                                </td>
                                <td class="text-right whitespace-nowrap tabular-nums">
                                    {{ $zone->average_waste_kg !== null ? number_format((float) $zone->average_waste_kg, 2).' kg' : '—' }}
                                </td>
                                <td>
                                    @if ($zone->coords_count)
                                        <x-badge tone="green">{{ $zone->coords_count }} vértices</x-badge>
                                    @else
                                        <x-badge tone="amber">Sin definir</x-badge>
                                    @endif
                                </td>
                                <td>
                                    <x-badge :tone="Status::tone('zone', $zone->status)">{{ Status::label('zone', $zone->status) }}</x-badge>
                                </td>
                                <td>
                                    <x-row-actions :editable="auth()->user()->can('zones.edit')" :deletable="auth()->user()->can('zones.delete')" :label="$zone->name" :row="[
                                        'id' => $zone->id,
                                        'name' => $zone->name,
                                        'department_id' => (string) ($zone->district?->department_id ?? ''),
                                        'province_id' => (string) ($zone->district?->province_id ?? ''),
                                        'district_id' => (string) $zone->district_id,
                                        'average_waste_kg' => $zone->average_waste_kg !== null ? (string) (float) $zone->average_waste_kg : '',
                                        'status' => $zone->status,
                                        'description' => $zone->description,
                                    ]">
                                        @can('zones.perimeter')
                                        <a href="{{ route('zones.perimeter', $zone) }}" class="icon-btn" title="Dibujar perímetro"
                                            aria-label="Dibujar el perímetro de {{ $zone->name }}">
                                            <x-lucide-map-pin class="size-3.5" />
                                        </a>
                                        @endcan
                                    </x-row-actions>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="8" message="Todavía no hay zonas registradas" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <p class="mt-4 text-[12px] text-stone-500">
            El icono del mapa de cada fila abre el trazado de su perímetro, y al guardarlo se calcula el área.
        </p>

        {{-- Departamento → provincia → distrito: cada nivel filtra el siguiente. Solo se guarda el distrito. --}}
        <div x-data="{
            provinces: @js($provinces),
            districts: @js($districts),
            get departmentProvinces() {
                return this.provinces.filter((province) => String(province.department_id) === String(form.department_id))
            },
            get provinceDistricts() {
                return this.districts.filter((district) => String(district.province_id) === String(form.province_id))
            },
        }">
            <x-crud-form name="zone" title-new="Nueva zona" title-edit="Editar zona">
                <x-field label="Nombre de la zona" name="name" required live>
                    <input id="name" name="name" x-model="form.name" maxlength="120" required class="field"
                        :class="error('name') && 'field-error'">
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Departamento" name="department_id" required>
                        <select id="department_id" name="department_id" x-model="form.department_id" required class="field"
                            @change="form.province_id = ''; form.district_id = ''">
                            <option value="">Selecciona</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Provincia" name="province_id" required>
                        <select id="province_id" name="province_id" x-model="form.province_id" required class="field"
                            :disabled="!form.department_id" @change="form.district_id = ''">
                            <option value="">Selecciona</option>
                            <template x-for="province in departmentProvinces" :key="province.id">
                                <option :value="province.id" x-text="province.name"
                                    :selected="String(province.id) === String(form.province_id)"></option>
                            </template>
                        </select>
                    </x-field>

                    <x-field label="Distrito" name="district_id" required live>
                        <select id="district_id" name="district_id" x-model="form.district_id" required class="field"
                            :disabled="!form.province_id" :class="error('district_id') && 'field-error'">
                            <option value="">Selecciona</option>
                            <template x-for="district in provinceDistricts" :key="district.id">
                                <option :value="district.id" x-text="district.name"
                                    :selected="String(district.id) === String(form.district_id)"></option>
                            </template>
                        </select>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Residuos promedio (kg)" name="average_waste_kg" live>
                        <input id="average_waste_kg" name="average_waste_kg" type="number" step="0.01" min="0"
                            inputmode="decimal" placeholder="0.00" x-model="form.average_waste_kg" class="field tabular-nums"
                            :class="error('average_waste_kg') && 'field-error'">
                    </x-field>

                    <x-field label="Estado" name="status" required live>
                        <select id="status" name="status" x-model="form.status" required class="field"
                            :class="error('status') && 'field-error'">
                            @foreach (Status::options('zone') as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <x-field label="Descripción" name="description" live>
                    <input id="description" name="description" x-model="form.description" maxlength="255" class="field"
                        :class="error('description') && 'field-error'">
                </x-field>

                <p class="flex items-start gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-2.5 text-[12px] text-stone-600">
                    <x-lucide-info class="mt-0.5 size-3.5 shrink-0 text-stone-400" />
                    El área no se escribe: se calcula sola al trazar el perímetro de la zona en el mapa.
                </p>
            </x-crud-form>
        </div>

        <x-confirm-delete title="Eliminar zona">
            Se eliminarán también su perímetro y sus rutas. Una zona con programaciones no se puede eliminar.
        </x-confirm-delete>
    </div>
</x-layouts.app>
