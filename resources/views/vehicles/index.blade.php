{{-- W04 Listado de vehículos. --}}
<x-layouts.app title="Vehículos"
    :subtitle="$summary['total'].' vehículos registrados · '.$summary['operativo'].' operativos · '.$summary['mantenimiento'].' en mantenimiento'">
    <x-slot:actions>
        @can('vehicles.create')
        <a href="{{ route('vehicles.create') }}" class="btn btn-primary">
            <x-lucide-plus class="size-4" />
            Nuevo vehículo
        </a>
        @endcan
    </x-slot:actions>

    <div x-data="{
        toDelete: null,
        gallery: null,
        index: 0,
        get deleteAction() { return this.toDelete ? @js(route('vehicles.destroy', '__ID__')).replace('__ID__', this.toDelete.id) : '' },
        openGallery(vehicle) {
            this.gallery = vehicle
            // Empieza por la de perfil.
            this.index = Math.max(vehicle.images.findIndex((image) => image.profile), 0)
        },
        step(delta) {
            const total = this.gallery.images.length
            this.index = (this.index + delta + total) % total
        },
    }" @keydown.left.window="gallery?.images.length > 1 && step(-1)"
        @keydown.right.window="gallery?.images.length > 1 && step(1)">
        <x-card>
            <x-search-bar :value="$filters['q'] ?? ''" placeholder="Buscar por placa, código o modelo…">
                <select name="brand_id" class="field w-auto min-w-40" aria-label="Filtrar por marca" data-autosubmit>
                    <option value="">Marca: todas</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(($filters['brand_id'] ?? '') == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>
                <select name="type_id" class="field w-auto min-w-40" aria-label="Filtrar por tipo" data-autosubmit>
                    <option value="">Tipo: todos</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected(($filters['type_id'] ?? '') == $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="field w-auto min-w-40" aria-label="Filtrar por estado" data-autosubmit>
                    <option value="">Estado: todos</option>
                    @foreach (Status::options('vehicle') as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-search-bar>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Imagen</th>
                            <th>Código</th>
                            <th>Placa</th>
                            <th>Marca / Modelo</th>
                            <th>Tipo</th>
                            <th class="text-right">Año</th>
                            <th class="text-right">Capacidad</th>
                            <th>Estado</th>
                            <th>Fecha de creación</th>
                            <th>Fecha de modificación</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vehicles as $vehicle)
                            @php
                                $profile = $vehicle->images->firstWhere('profile', true);
                            @endphp
                            <tr>
                                <td>
                                    @if ($profile)
                                        <img src="{{ $profile->url }}" alt="Vehículo {{ $vehicle->code }}" loading="lazy"
                                            class="h-10 w-14 rounded-md border border-stone-200 object-cover">
                                    @else
                                        <span title="Sin imagen"
                                            class="grid h-10 w-14 place-items-center rounded-md border border-dashed border-stone-300 text-stone-400">
                                            <x-lucide-truck class="size-4" />
                                        </span>
                                    @endif
                                </td>
                                <td><span class="font-medium text-stone-900">{{ $vehicle->code }}</span></td>
                                <td><span class="font-mono text-stone-700">{{ $vehicle->plate }}</span></td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        @if ($vehicle->color)
                                            <span class="size-2.5 shrink-0 rounded-full ring-1 ring-stone-300 ring-inset"
                                                style="background-color: {{ $vehicle->color->hex }}"
                                                title="{{ $vehicle->color->name }}"></span>
                                        @endif
                                        <span>{{ $vehicle->brand?->name }} / {{ $vehicle->model?->name }}</span>
                                    </div>
                                </td>
                                <td>{{ $vehicle->type?->name }}</td>
                                <td class="text-right">{{ $vehicle->year }}</td>
                                <td class="text-right tabular-nums">
                                    {{ (float) $vehicle->load_capacity }} Tn · {{ $vehicle->occupant_capacity }} p.
                                </td>
                                <td>
                                    <x-badge :tone="Status::tone('vehicle', $vehicle->status)">
                                        {{ Status::label('vehicle', $vehicle->status) }}
                                    </x-badge>
                                </td>
                                <td><x-datetime :value="$vehicle->created_at" /></td>
                                <td><x-datetime :value="$vehicle->updated_at" /></td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        @can('vehicles.images')
                                        <button type="button" class="icon-btn" title="Ver imágenes"
                                            aria-label="Ver imágenes de {{ $vehicle->code }}"
                                            @click="openGallery(@js([
                                                'code' => $vehicle->code,
                                                'subtitle' => "{$vehicle->plate} · {$vehicle->brand?->name} / {$vehicle->model?->name}",
                                                'images' => $vehicle->images->map->only('id', 'url', 'profile')->values(),
                                            ]))">
                                            <x-lucide-eye class="size-3.5" />
                                        </button>
                                        @endcan
                                        @can('vehicles.edit')
                                        <a href="{{ route('vehicles.edit', $vehicle) }}" class="icon-btn" title="Editar"
                                            aria-label="Editar {{ $vehicle->code }}">
                                            <x-lucide-pencil class="size-3.5" />
                                        </a>
                                        @endcan
                                        @can('vehicles.delete')
                                        <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                            aria-label="Eliminar {{ $vehicle->code }}"
                                            @click="toDelete = @js(['id' => $vehicle->id, 'label' => "{$vehicle->code} · {$vehicle->plate}. Esta acción no se puede deshacer."])">
                                            <x-lucide-trash-2 class="size-3.5" />
                                        </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="11" message="No hay vehículos que coincidan con los filtros" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $vehicles->links() }}
        </x-card>

        {{-- Visor de las fotos de un vehículo. --}}
        <x-modal show="gallery" close="gallery = null" size="lg" title-expr="gallery ? 'Imágenes de ' + gallery.code : ''"
            description-expr="gallery?.subtitle">
            <x-modal.body>
                <template x-if="gallery && gallery.images.length === 0">
                    <div class="grid aspect-video place-items-center rounded-lg border border-dashed border-stone-300 text-stone-400">
                        <span class="text-center">
                            <x-lucide-image-off class="mx-auto size-6" />
                            <span class="mt-2 block text-[12px] font-medium">Este vehículo no tiene imágenes</span>
                        </span>
                    </div>
                </template>

                <template x-if="gallery && gallery.images.length > 0">
                    <div class="space-y-3">
                        <div class="relative aspect-video overflow-hidden rounded-lg bg-stone-900">
                            <img :src="gallery.images[index].url" alt="" class="size-full object-contain">
                            <span x-show="gallery.images[index].profile"
                                class="absolute top-2 left-2 rounded bg-brand-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">Perfil</span>

                            <template x-if="gallery.images.length > 1">
                                <div>
                                    <button type="button" aria-label="Imagen anterior" @click="step(-1)"
                                        class="absolute top-1/2 left-2 -translate-y-1/2 cursor-pointer rounded-full bg-white/85 p-1.5 text-stone-700 shadow transition hover:bg-white">
                                        <x-lucide-chevron-left class="size-4" />
                                    </button>
                                    <button type="button" aria-label="Imagen siguiente" @click="step(1)"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 cursor-pointer rounded-full bg-white/85 p-1.5 text-stone-700 shadow transition hover:bg-white">
                                        <x-lucide-chevron-right class="size-4" />
                                    </button>
                                    <span x-text="(index + 1) + ' / ' + gallery.images.length"
                                        class="absolute right-2 bottom-2 rounded bg-stone-900/70 px-1.5 py-0.5 text-[10px] font-medium text-white tabular-nums"></span>
                                </div>
                            </template>
                        </div>

                        <div class="flex gap-2 overflow-x-auto pb-1" x-show="gallery.images.length > 1">
                            <template x-for="(image, position) in gallery.images" :key="image.id">
                                <button type="button" :aria-label="'Ver imagen ' + (position + 1)" @click="index = position"
                                    class="h-14 w-20 shrink-0 cursor-pointer overflow-hidden rounded-md border-2 transition"
                                    :class="position === index ? 'border-brand-500' : 'border-transparent opacity-60 hover:opacity-100'">
                                    <img :src="image.url" alt="" loading="lazy" class="size-full object-cover">
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </x-modal.body>
        </x-modal>

        <x-confirm-delete title="Eliminar vehículo">
            Se eliminarán también sus imágenes. Un vehículo con programaciones o recorridos registrados no se
            puede eliminar.
        </x-confirm-delete>
    </div>
</x-layouts.app>
