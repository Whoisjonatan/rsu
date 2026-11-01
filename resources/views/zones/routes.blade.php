{{-- W15 Rutas de la zona. --}}
@php
    // Solo las que tienen los dos extremos: media pareja desplazaría todas las
    // siguientes y saldrían cruzadas en la vista general.
    $complete = $routes->filter(fn ($route) => $route->latitude_start !== null && $route->latitude_end !== null)->values();
    $overviewPoints = $complete->flatMap(fn ($route) => [
        ['latitude' => $route->latitude_start, 'longitude' => $route->longitude_start],
        ['latitude' => $route->latitude_end, 'longitude' => $route->longitude_end],
    ])->values();
@endphp

<x-layouts.app title="Rutas" subtitle="Cada ruta indica su punto de partida y de fin dentro de una o más zonas">
    <div x-data="crud({
        name: 'route',
        storeUrl: @js(route('routes.store')),
        updateUrl: @js(route('routes.update', '__ID__')),
        destroyUrl: @js(route('routes.destroy', '__ID__')),
        defaults: { name: '', description: '', status: 'activa', zone_ids: [], latitude_start: '', longitude_start: '', latitude_end: '', longitude_end: '' },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <form method="GET" class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-200 p-3" x-data
                @change="$el.requestSubmit()">
                <select name="zone_id" class="field w-auto min-w-44" aria-label="Filtrar por zona">
                    <option value="">Zona: todas</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected($zoneFilter == $zone->id)>{{ $zone->name }}</option>
                    @endforeach
                </select>
                @can('zones.edit')
                <button type="button" class="btn btn-primary btn-sm"
                    @click="create({ zone_ids: @js($zones->firstWhere('id', $zoneFilter)?->isActive() ? [(string) $zoneFilter] : []) })">
                    <x-lucide-plus class="size-3.5" />
                    Nueva ruta
                </button>
                @endcan
            </form>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ruta</th>
                            <th>Zonas</th>
                            <th>Partida → Fin</th>
                            <th>Uso</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($routes as $route)
                            <tr>
                                <td><span class="font-medium text-stone-800">{{ $route->name }}</span></td>
                                <td>{{ $route->zones->pluck('name')->implode(', ') ?: '—' }}</td>
                                <td>
                                    <span class="font-mono text-[11px] text-stone-500 tabular-nums">
                                        {{ number_format($route->latitude_start, 4) }}, {{ number_format($route->longitude_start, 4) }}
                                        → {{ number_format($route->latitude_end, 4) }}, {{ number_format($route->longitude_end, 4) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($route->schedulings_count)
                                        {{ $route->schedulings_count }} {{ $route->schedulings_count === 1 ? 'programación' : 'programaciones' }}
                                    @else
                                        <em class="text-stone-400">Sin usar</em>
                                    @endif
                                </td>
                                <td>
                                    <x-badge :tone="Status::tone('active', $route->status)">{{ Status::label('active', $route->status) }}</x-badge>
                                </td>
                                <td>
                                    <x-row-actions :editable="auth()->user()->can('zones.edit')" :deletable="auth()->user()->can('zones.edit')" :label="$route->name" :row="[
                                        ...$route->only('id', 'name', 'description', 'status', 'latitude_start', 'longitude_start', 'latitude_end', 'longitude_end'),
                                        'zone_ids' => $route->zones->pluck('id')->map(fn ($id) => (string) $id)->all(),
                                    ]" />
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" message="No hay rutas registradas en esta zona" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        @if ($complete->isNotEmpty())
            <x-card class="mt-4">
                <x-card-header title="Vista general"
                    description="Todas las rutas del filtro actual. La línea es recta: se guardan los extremos, no el camino." />
                <div class="p-3" x-data="mapPicker({ mode: 'endpoints', readOnly: true, points: @js($overviewPoints), pairLabels: @js($complete->pluck('name')) })">
                    <x-map height="320px" />
                </div>
            </x-card>
        @endif

        <x-crud-form name="route" title-new="Nueva ruta" title-edit="Editar ruta" size="lg" submit-label="Guardar ruta">
            <p class="-mt-1 text-[12px] text-stone-500">
                Toca el mapa para marcar la partida (A) y el fin (B). Un tercer toque reinicia.
            </p>

            {{-- El mapa se recarga con los extremos de la ruta cada vez que se abre el modal. --}}
            <div class="space-y-4" x-data="mapPicker({ mode: 'endpoints' })" x-init="
                const load = () => setPoints(form.latitude_start !== '' && form.latitude_start !== null
                    ? [{ latitude: form.latitude_start, longitude: form.longitude_start }, ...(form.latitude_end !== '' && form.latitude_end !== null ? [{ latitude: form.latitude_end, longitude: form.longitude_end }] : [])]
                    : [])
                if (open) load()
                $watch('open', (value) => value && load())
            ">
                <x-map height="300px" />

                <input type="hidden" name="latitude_start" :value="points[0]?.latitude ?? ''">
                <input type="hidden" name="longitude_start" :value="points[0]?.longitude ?? ''">
                <input type="hidden" name="latitude_end" :value="points[1]?.latitude ?? ''">
                <input type="hidden" name="longitude_end" :value="points[1]?.longitude ?? ''">

                <div class="grid gap-2 sm:grid-cols-2">
                    <p class="flex items-center gap-2 rounded-lg bg-stone-50 px-3 py-2 text-[12px]">
                        <x-lucide-map-pin class="size-3.5 shrink-0 text-brand-600" />
                        <span class="font-mono text-stone-600"
                            x-text="points[0] ? points[0].latitude.toFixed(5) + ', ' + points[0].longitude.toFixed(5) : 'Marca la partida'"></span>
                    </p>
                    <p class="flex items-center gap-2 rounded-lg bg-stone-50 px-3 py-2 text-[12px]">
                        <x-lucide-map-pin class="size-3.5 shrink-0 text-red-600" />
                        <span class="font-mono text-stone-600"
                            x-text="points[1] ? points[1].latitude.toFixed(5) + ', ' + points[1].longitude.toFixed(5) : 'Marca el fin'"></span>
                    </p>
                </div>

                <p x-show="points.length < 2" class="text-[11px] text-stone-500">Marca los dos extremos para poder guardar.</p>
            </div>

            <p x-show="error('latitude_start')" x-text="error('latitude_start')" x-cloak
                class="rounded-md bg-amber-50 px-3 py-2 text-[12px] font-medium text-amber-800"></p>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Nombre" name="name" required live>
                    <input id="name" name="name" x-model="form.name" maxlength="120" required class="field"
                        :class="error('name') && 'field-error'">
                </x-field>
                <x-field label="Estado" name="status" required live>
                    <select id="status" name="status" x-model="form.status" class="field">
                        @foreach (Status::options('active') as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <x-field label="Descripción" name="description" live>
                <input id="description" name="description" x-model="form.description" maxlength="255" class="field"
                    :class="error('description') && 'field-error'">
            </x-field>

            <div>
                <span class="label">Zonas <span class="ml-0.5 text-brand-600">*</span></span>
                <div class="flex flex-wrap gap-1.5">
                    {{-- Una zona inactiva solo aparece si la ruta ya la tenía, para poder quitarla. --}}
                    @foreach ($zones as $zone)
                        <button type="button" :aria-pressed="form.zone_ids.includes('{{ $zone->id }}')"
                            @unless ($zone->isActive()) x-show="form.zone_ids.includes('{{ $zone->id }}')" @endunless
                            @click="form.zone_ids = form.zone_ids.includes('{{ $zone->id }}')
                                ? form.zone_ids.filter((id) => id !== '{{ $zone->id }}')
                                : [...form.zone_ids, '{{ $zone->id }}']"
                            :class="form.zone_ids.includes('{{ $zone->id }}')
                                ? 'border-brand-600 bg-brand-600 text-white'
                                : 'border-stone-300 text-stone-600 hover:border-stone-400'"
                            class="cursor-pointer rounded-md border px-3 py-1.5 text-[12px] font-medium">
                            {{ $zone->name }}
                            @unless ($zone->isActive())
                                <span class="font-normal opacity-75">(inactiva)</span>
                            @endunless
                        </button>
                    @endforeach
                </div>
                <template x-for="id in form.zone_ids" :key="id">
                    <input type="hidden" name="zone_ids[]" :value="id">
                </template>
                <p x-show="error('zone_ids')" x-text="error('zone_ids')" x-cloak class="mt-1.5 text-[11px] font-medium text-red-600"></p>
            </div>
        </x-crud-form>

        <x-confirm-delete title="Eliminar ruta">
            Si alguna programación la usa, no se podrá borrar.
        </x-confirm-delete>
    </div>
</x-layouts.app>
