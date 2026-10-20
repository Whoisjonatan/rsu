{{-- W14 Perímetro de la zona. --}}
@php
    // Si la validación falló, se vuelve con lo que el usuario había dibujado.
    $points = old('coords_json')
        ? json_decode(old('coords_json'), true)
        : $zone->coords->map(fn ($coord) => ['latitude' => $coord->latitude, 'longitude' => $coord->longitude])->all();
@endphp

<x-layouts.app :title="'Perímetro · '.$zone->name" subtitle="Zonas y rutas › Dibujar el perímetro de la zona">
    <x-slot:actions>
        <a href="{{ route('zones.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <div class="grid gap-4 lg:grid-cols-[1fr_18rem]"
        x-data="mapPicker({ mode: 'polygon', points: @js($points), references: @js($references) })">
        <x-card>
            <x-card-header title="Dibuja el perímetro"
                description="Toca el mapa para añadir vértices y arrástralos para moverlos. Para quitar uno, tócalo en el mapa o usa la ✕ de la lista." />

            {{-- Buscar una dirección solo mueve el mapa: no añade ningún vértice. --}}
            <div class="relative px-3 pt-3">
                <form class="flex gap-2" @submit.prevent="searchAddress()">
                    <label class="relative flex-1">
                        <span class="sr-only">Buscar una dirección</span>
                        <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                        <input type="search" x-model="search.query" placeholder="Busca una calle, avenida o lugar (p. ej. Av. Balta, Chiclayo)"
                            class="field pl-9" @input="search.done = false; search.error = null"
                            @keydown.escape="search.results = []; search.done = false">
                    </label>
                    <button type="submit" class="btn btn-secondary" :disabled="search.loading || search.query.trim().length < 3">
                        <x-lucide-loader-circle class="size-4 animate-spin" x-show="search.loading" x-cloak />
                        Buscar
                    </button>
                </form>

                <div x-show="search.results.length > 1 || (search.done && search.results.length === 0) || search.error" x-cloak
                    @click.outside="search.results = []; search.done = false"
                    class="absolute inset-x-3 top-full z-[1000] mt-1 overflow-hidden rounded-lg border border-stone-200 bg-white shadow-lg">
                    <template x-for="(place, index) in search.results" :key="index">
                        <button type="button" @click="goTo(place)"
                            class="flex w-full cursor-pointer items-start gap-2 px-3 py-2 text-left text-[12px] text-stone-700 hover:bg-stone-50">
                            <x-lucide-map-pin class="mt-0.5 size-3.5 shrink-0 text-stone-400" />
                            <span x-text="place.label"></span>
                        </button>
                    </template>
                    <p x-show="search.done && search.results.length === 0 && !search.error" class="px-3 py-2.5 text-[12px] text-stone-500">
                        No se encontró esa dirección. Prueba con menos palabras o agrega el distrito.
                    </p>
                    <p x-show="search.error" x-text="search.error" class="px-3 py-2.5 text-[12px] font-medium text-red-600"></p>
                </div>
            </div>

            <div class="p-3">
                <x-map height="460px" />
            </div>

            @if ($references->isNotEmpty())
                <p class="flex items-center gap-2 px-4 pb-3 text-[11px] text-stone-500">
                    <span class="inline-block size-3 rounded-sm border border-dashed border-stone-500 bg-stone-300/50"></span>
                    En gris, los perímetros de las otras zonas ({{ $references->count() }}), para no trazar encima de ellos.
                </p>
            @endif
        </x-card>

        <div class="space-y-4">
            <x-card class="px-5 py-4">
                <p class="text-[11px] font-semibold tracking-wide text-stone-500 uppercase">Área de la zona</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-stone-900 tabular-nums"
                    x-text="areaKm2 === null ? '—' : areaKm2.toLocaleString('es-PE', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' km²'">
                </p>
                <p class="mt-1 text-[11px] text-stone-500">Se calcula del perímetro y se guarda con él.</p>
            </x-card>

            {{--
                Aviso de cruce con otras zonas. Lo calcula el servidor
                (Zone::overlapsFor), el mismo que lo repite al guardar; aquí se
                pide un momento después del último cambio, no en cada clic.
            --}}
            <div x-data="{
                overlaps: [],
                timer: null,
                sequence: 0,
                init() {
                    this.check()
                    this.$watch('points', () => {
                        clearTimeout(this.timer)
                        this.timer = setTimeout(() => this.check(), 400)
                    })
                },
                async check() {
                    const ticket = ++this.sequence
                    if (points.length < 3) return (this.overlaps = [])
                    try {
                        const { data } = await window.api.post(@js("/zones/{$zone->id}/overlaps"), { coords: points })
                        // Si mientras tanto se movió otro vértice, esta respuesta ya no vale.
                        if (ticket === this.sequence) this.overlaps = data
                    } catch {
                        // Es solo un aviso: si falla, se sigue dibujando igual.
                    }
                },
            }" x-show="overlaps.length > 0" x-cloak>
                <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[12px] text-amber-800" role="status">
                    <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p class="font-semibold">Este perímetro se cruza con otra zona</p>
                        <ul class="mt-1 space-y-0.5">
                            <template x-for="overlap in overlaps" :key="overlap.name">
                                <li>
                                    <span class="font-medium" x-text="overlap.name"></span>:
                                    <span class="tabular-nums" x-text="overlap.area_km2.toLocaleString('es-PE', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' km² en común'"></span>
                                </li>
                            </template>
                        </ul>
                        <p class="mt-1.5 text-amber-700">Puedes guardar igual, pero revisa que no estés repitiendo terreno.</p>
                    </div>
                </div>
            </div>

            <x-card>
                <header class="border-b border-stone-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-stone-900">Vértices</h2>
                    <p class="mt-0.5 text-[12px] text-stone-500"
                        x-text="points.length === 0 ? 'Sin perímetro dibujado' : points.length + (points.length === 1 ? ' punto' : ' puntos')"></p>
                </header>

                <div class="max-h-64 overflow-y-auto">
                    <p x-show="points.length === 0" class="px-5 py-8 text-center text-[12px] text-stone-400">
                        Un perímetro necesita al menos 3 puntos.
                    </p>
                    <ol class="divide-y divide-stone-100 text-[12px]" x-show="points.length > 0">
                        <template x-for="(point, index) in points" :key="index + '-' + point.latitude + '-' + point.longitude">
                            <li class="flex items-center gap-2 py-1.5 pr-2 pl-4">
                                <span class="w-5 text-stone-400" x-text="index + 1"></span>
                                <span class="flex-1 font-mono text-stone-600 tabular-nums"
                                    x-text="point.latitude.toFixed(5) + ', ' + point.longitude.toFixed(5)"></span>
                                <button type="button" class="icon-btn icon-btn-danger" title="Eliminar vértice"
                                    :aria-label="'Eliminar el vértice ' + (index + 1)" @click="removePoint(index)">
                                    <x-lucide-x class="size-3.5" />
                                </button>
                            </li>
                        </template>
                    </ol>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-stone-200 px-4 py-3">
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="points.length === 0" @click="undo()">
                        <x-lucide-undo-2 class="size-3.5" />
                        Deshacer
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" :disabled="points.length === 0" @click="clear()">
                        <x-lucide-eraser class="size-3.5" />
                        Limpiar
                    </button>
                </div>
            </x-card>

            <x-card>
                <form method="POST" action="{{ route('zones.perimeter.update', $zone) }}" class="p-4"
                    x-data="{ sending: false }" @submit="sending = true">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="coords_json" :value="JSON.stringify(points)">

                    <button type="submit" class="btn btn-primary w-full"
                        :disabled="sending || (points.length > 0 && points.length < 3)">
                        <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                        <x-lucide-save class="size-4" x-show="!sending" />
                        Guardar perímetro
                    </button>

                    <p x-show="points.length > 0 && points.length < 3" x-cloak class="mt-2 text-[11px] text-amber-700"
                        x-text="'Faltan ' + (3 - points.length) + ' para cerrar la figura.'"></p>

                    @error('coords')
                        <p class="mt-2 text-[12px] font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </form>
            </x-card>
        </div>
    </div>
</x-layouts.app>
