{{-- Mapa general de zonas: todos los perímetros trazados de un vistazo. --}}
<x-layouts.app title="Mapa de zonas"
    :subtitle="$drawn->count().' zonas con perímetro · '.$pending->count().' sin trazar'">
    <x-slot:actions>
        @can('zones.view')
        <a href="{{ route('zones.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
        @endcan
    </x-slot:actions>

    <div class="grid gap-4 lg:grid-cols-[1fr_18rem]" x-data="mapPicker({ mode: 'polygon', readOnly: true, references: @js($shapes) })">
        <x-card>
            <x-card-header title="Zonas de recolección"
                description="Toca una zona para ver sus datos. En verde las activas; en gris punteado las inactivas." />
            <div class="p-3">
                <x-map height="560px" />
            </div>
        </x-card>

        <div class="space-y-4">
            <x-card>
                <header class="border-b border-stone-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-stone-900">Con perímetro</h2>
                    <p class="mt-0.5 text-[12px] text-stone-500">{{ $drawn->count() }} {{ $drawn->count() === 1 ? 'zona' : 'zonas' }}</p>
                </header>
                <ul class="max-h-80 divide-y divide-stone-100 overflow-y-auto text-[13px]">
                    @forelse ($drawn as $zone)
                        <li>
                            <button type="button" @click="focusReference({{ $zone->id }})"
                                class="flex w-full cursor-pointer items-center justify-between gap-2 px-4 py-2.5 text-left hover:bg-stone-50">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-stone-900">{{ $zone->name }}</span>
                                    <span class="block text-[11px] text-stone-500">
                                        {{ $zone->district?->name }} · {{ number_format((float) $zone->area, 3) }} km²
                                    </span>
                                </span>
                                <x-badge :tone="Status::tone('zone', $zone->status)">{{ Status::label('zone', $zone->status) }}</x-badge>
                            </button>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-[12px] text-stone-400">Todavía no se ha trazado ningún perímetro.</li>
                    @endforelse
                </ul>
            </x-card>

            @if ($pending->isNotEmpty())
                <x-card>
                    <header class="border-b border-stone-200 px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-stone-900">Sin perímetro</h2>
                        <p class="mt-0.5 text-[12px] text-stone-500">No aparecen en el mapa hasta que se trace su perímetro.</p>
                    </header>
                    <ul class="max-h-60 divide-y divide-stone-100 overflow-y-auto text-[13px]">
                        @foreach ($pending as $zone)
                            <li class="flex items-center justify-between gap-2 px-4 py-2.5">
                                <span class="min-w-0 truncate text-stone-700">{{ $zone->name }}</span>
                                @can('zones.perimeter')
                                <a href="{{ route('zones.perimeter', $zone) }}" class="shrink-0 text-[12px] font-medium text-brand-500 hover:text-brand-600">
                                    Trazar
                                </a>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>
    </div>
</x-layouts.app>
