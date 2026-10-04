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

    <div class="grid gap-4 lg:grid-cols-[1fr_18rem]" x-data="mapPicker({ mode: 'polygon', points: @js($points) })">
        <x-card>
            <x-card-header title="Dibuja el perímetro"
                description="Toca el mapa para añadir vértices, arrástralos para moverlos y toca un vértice para quitarlo." />
            <div class="p-3">
                <x-map height="460px" />
            </div>
        </x-card>

        <div class="space-y-4">
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
                            <li class="flex items-center justify-between gap-2 px-4 py-2">
                                <span class="text-stone-400" x-text="index + 1"></span>
                                <span class="font-mono text-stone-600 tabular-nums"
                                    x-text="point.latitude.toFixed(5) + ', ' + point.longitude.toFixed(5)"></span>
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
