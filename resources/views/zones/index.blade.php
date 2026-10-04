{{-- W13 Zonas. --}}
<x-layouts.app title="Zonas"
    :subtitle="$zones->count().' zonas de recolección · '.$zones->where('coords_count', '>', 0)->count().' con perímetro definido'">
    <div x-data="crud({
        name: 'zone',
        storeUrl: @js(route('zones.store')),
        updateUrl: @js(route('zones.update', '__ID__')),
        destroyUrl: @js(route('zones.destroy', '__ID__')),
        defaults: { name: '', district_id: '', sector_id: '', area: '', description: '' },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <x-card-header title="Zonas de recolección"
                description="Cada zona pertenece a un distrito y agrupa las rutas y programaciones.">
                <button type="button" class="btn btn-primary btn-sm" @click="create()">
                    <x-lucide-plus class="size-3.5" />
                    Nueva zona
                </button>
            </x-card-header>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Zona</th>
                            <th>Distrito</th>
                            <th>Sector</th>
                            <th class="text-right">Área</th>
                            <th>Perímetro</th>
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
                                <td>{{ $zone->district?->name ?? '—' }}</td>
                                <td>
                                    @if ($zone->sector)
                                        {{ $zone->sector->name }}
                                    @else
                                        <span class="text-stone-400">Sin sector</span>
                                    @endif
                                </td>
                                <td class="text-right tabular-nums">{{ $zone->area ? (float) $zone->area.' km²' : '—' }}</td>
                                <td>
                                    @if ($zone->coords_count)
                                        <x-badge tone="green">{{ $zone->coords_count }} vértices</x-badge>
                                    @else
                                        <x-badge tone="amber">Sin definir</x-badge>
                                    @endif
                                </td>
                                <td>
                                    <x-row-actions :label="$zone->name" :row="[
                                        'id' => $zone->id,
                                        'name' => $zone->name,
                                        'district_id' => (string) $zone->district_id,
                                        'sector_id' => (string) ($zone->sector_id ?? ''),
                                        'area' => $zone->area ? (string) (float) $zone->area : '',
                                        'description' => $zone->description,
                                    ]">
                                        <a href="{{ route('zones.perimeter', $zone) }}" class="icon-btn" title="Dibujar perímetro"
                                            aria-label="Dibujar el perímetro de {{ $zone->name }}">
                                            <x-lucide-map-pin class="size-3.5" />
                                        </a>
                                    </x-row-actions>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" message="Todavía no hay zonas registradas" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <p class="mt-4 text-[12px] text-stone-500">
            El icono del mapa de cada fila abre el trazado de su perímetro. Las rutas de las zonas se gestionan en
            <a href="{{ route('routes.index') }}" class="font-medium text-brand-500 hover:text-brand-600">Rutas</a>.
        </p>

        <div x-data="{
            sectors: @js($sectors),
            newSector: '',
            creating: false,
            sectorError: null,
            get districtSectors() { return this.sectors.filter((sector) => String(sector.district_id) === String(form.district_id)) },
            // Crear el sector sin salir del modal: va al API con la sesión.
            async addSector() {
                this.creating = true
                this.sectorError = null
                try {
                    const { data } = await window.api.post('/sectors', { name: this.newSector.trim(), district_id: form.district_id })
                    this.sectors.push(data)
                    form.sector_id = String(data.id)
                    this.newSector = ''
                } catch (error) {
                    this.sectorError = error.response?.data?.errors?.name?.[0] ?? error.response?.data?.message ?? 'No se pudo crear el sector'
                } finally {
                    this.creating = false
                }
            },
        }">
            <x-crud-form name="zone" title-new="Nueva zona" title-edit="Editar zona">
                <x-field label="Nombre de la zona" name="name" required live>
                    <input id="name" name="name" x-model="form.name" maxlength="120" required class="field"
                        :class="error('name') && 'field-error'">
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Distrito" name="district_id" required live>
                        {{-- Al cambiar de distrito el sector elegido deja de aplicar. --}}
                        <select id="district_id" name="district_id" x-model="form.district_id" required class="field"
                            :class="error('district_id') && 'field-error'" @change="form.sector_id = ''">
                            <option value="">Selecciona un distrito</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district->id }}">{{ $district->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Área (km²)" name="area" live>
                        <input id="area" name="area" type="number" step="0.001" min="0" x-model="form.area" class="field"
                            :class="error('area') && 'field-error'">
                    </x-field>
                </div>

                <x-field label="Sector" name="sector_id" live>
                    <select id="sector_id" name="sector_id" x-model="form.sector_id" :disabled="!form.district_id" class="field"
                        :class="error('sector_id') && 'field-error'">
                        <option value="">Sin sector</option>
                        <template x-for="sector in districtSectors" :key="sector.id">
                            <option :value="sector.id" x-text="sector.name" :selected="String(sector.id) === String(form.sector_id)"></option>
                        </template>
                    </select>
                    <p x-show="!form.district_id" class="mt-1.5 text-[11px] text-stone-500">Elige primero un distrito</p>
                </x-field>

                <div x-show="form.district_id" class="flex items-end gap-2">
                    <label class="flex-1">
                        <span class="label">Crear un sector nuevo</span>
                        <input x-model="newSector" placeholder="Nombre del sector" class="field" maxlength="120"
                            @keydown.enter.prevent="newSector.trim() && addSector()">
                    </label>
                    <button type="button" class="btn btn-secondary" :disabled="!newSector.trim() || creating" @click="addSector()">
                        <x-lucide-loader-circle class="size-4 animate-spin" x-show="creating" x-cloak />
                        Añadir
                    </button>
                </div>
                <p x-show="sectorError" x-text="sectorError" x-cloak class="-mt-2 text-[11px] font-medium text-red-600"></p>

                <x-field label="Descripción" name="description" live>
                    <input id="description" name="description" x-model="form.description" maxlength="255" class="field"
                        :class="error('description') && 'field-error'">
                </x-field>
            </x-crud-form>
        </div>

        <x-confirm-delete title="Eliminar zona">
            Se eliminarán también su perímetro y sus rutas. Una zona con programaciones no se puede eliminar.
        </x-confirm-delete>
    </div>
</x-layouts.app>
