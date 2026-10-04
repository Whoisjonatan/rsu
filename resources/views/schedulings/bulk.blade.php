{{-- W19 Edición masiva de programaciones. Estado y llamadas al API en resources/js/components/bulk-edit.js. --}}
@php
    $fields = [
        'schedule_id' => ['Cambiar turno', $schedules->map(fn ($row) => [$row->id, $row->label])],
        'vehicle_id' => ['Cambiar vehículo', $vehicles->map(fn ($row) => [$row->id, $row->plate])],
        'driver_id' => ['Cambiar conductor', $drivers->map(fn ($row) => [$row->id, $row->full_name])],
        'helper_id' => ['Cambiar ayudante', $helpers->map(fn ($row) => [$row->id, $row->full_name])],
        'end_date' => ['Cambiar fecha de término', null],
    ];
@endphp

<x-layouts.app title="Edición masiva" subtitle="Marca las programaciones que quieres cambiar">
    <x-slot:actions>
        <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <div class="grid gap-4 lg:grid-cols-[1fr_22rem]" x-data="bulkEdit({ ids: @js($schedulings->pluck('id')) })">
        <x-card>
            <header class="border-b border-stone-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-stone-900">Programaciones seleccionadas</h2>
                <p class="mt-0.5 text-[12px] text-stone-500"
                    x-text="selected.length > 0
                        ? selected.length + (selected.length === 1 ? ' programación seleccionada' : ' programaciones seleccionadas') + '. Se aplicarán los mismos cambios a todas.'
                        : 'Se aplicarán los mismos cambios a todas las filas marcadas.'"></p>
            </header>

            <div class="flex flex-wrap items-center gap-2 border-b border-stone-200 p-3">
                <button type="button" class="btn btn-secondary btn-sm" @click="selectAll()">Seleccionar todas</button>
                <button type="button" class="btn btn-secondary btn-sm" @click="selected = []">Quitar selección</button>

                <form method="GET" class="ml-auto" x-data @change="$el.requestSubmit()">
                    <select name="zone_id" class="field w-auto min-w-44" aria-label="Filtrar por zona">
                        <option value="">Zona: todas</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}" @selected($zoneFilter == $zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="space-y-2 p-3">
                @forelse ($schedulings as $row)
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-[13px] transition"
                        :class="selected.includes({{ $row->id }}) ? 'border-brand-300 bg-brand-50/60' : 'border-stone-200 hover:bg-stone-50'">
                        <input type="checkbox" class="size-4 shrink-0 accent-brand-600" value="{{ $row->id }}"
                            x-model.number="selected">
                        <span class="w-20 shrink-0 font-mono font-medium text-stone-900">{{ $row->code }}</span>
                        <span class="flex-1 truncate text-stone-600">{{ $row->zone?->name }}</span>
                        <span class="w-20 shrink-0 text-stone-500">{{ $row->schedule?->name }}</span>
                        <span class="w-24 shrink-0 font-mono text-stone-500">{{ $row->vehicle?->plate }}</span>

                        {{-- Tras aplicar, la fila dice qué le pasó: sin esto solo se ve un total. --}}
                        <span x-show="outcome({{ $row->id }})?.ok" x-cloak
                            class="flex shrink-0 items-center gap-1 text-[11px] font-medium text-brand-700">
                            <x-lucide-check class="size-3.5" /> Aplicado
                        </span>
                        <span x-show="outcome({{ $row->id }}) && !outcome({{ $row->id }}).ok" x-cloak
                            :title="outcome({{ $row->id }})?.errors?.join(' · ')"
                            class="flex shrink-0 items-center gap-1 text-[11px] font-medium text-amber-700">
                            <x-lucide-triangle-alert class="size-3.5" /> Rechazado
                        </span>
                    </label>
                @empty
                    <p class="py-12 text-center text-[13px] text-stone-500">No hay programaciones en esta zona.</p>
                @endforelse
            </div>

            @if ($schedulings->isNotEmpty())
                <p class="border-t border-stone-200 px-4 py-3 text-[12px] text-stone-500"
                    x-text="selected.length + ' de {{ $schedulings->count() }} seleccionadas'"></p>
            @endif
        </x-card>

        <div class="space-y-4">
            <x-card>
                <x-card-header title="Cambios a aplicar" />
                <div class="space-y-4 p-5">
                    @foreach ($fields as $key => [$label, $options])
                        <div>
                            <label class="mb-1.5 flex items-center gap-2 text-[12px] font-medium text-stone-700">
                                <input type="checkbox" class="size-4 accent-brand-600" value="{{ $key }}" x-model="active">
                                {{ $label }}
                            </label>
                            @if ($options)
                                <select class="field" :disabled="!active.includes('{{ $key }}')" x-model="values.{{ $key }}"
                                    aria-label="{{ $label }}">
                                    <option value="" x-text="active.includes('{{ $key }}') ? 'Elige un valor' : 'Sin cambios'"></option>
                                    @foreach ($options as [$id, $text])
                                        <option value="{{ $id }}">{{ $text }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="date" class="field" :disabled="!active.includes('{{ $key }}')"
                                    x-model="values.{{ $key }}" aria-label="{{ $label }}">
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Sale ANTES de aplicar: es una simulación, no el resultado. --}}
                <div x-show="simulation && simulation.rechazadas.length > 0 && !result" x-cloak
                    class="mx-5 mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5">
                    <p class="flex items-start gap-2 text-[12px] font-medium text-amber-800">
                        <x-lucide-triangle-alert class="mt-px size-3.5 shrink-0" />
                        <span x-text="simulation?.rechazadas.length + (simulation?.rechazadas.length === 1 ? ' programación quedaría rechazada' : ' programaciones quedarían rechazadas')"></span>
                    </p>
                    <ul class="mt-1.5 space-y-1 text-[11px] text-amber-700">
                        <template x-for="row in (simulation?.rechazadas ?? []).slice(0, 3)" :key="row.id">
                            <li><span class="font-mono font-semibold" x-text="row.code"></span>: <span x-text="row.errors[0]"></span></li>
                        </template>
                    </ul>
                </div>

                <p class="px-5 pb-3 text-[11px] text-stone-500">
                    El sistema rechazará las filas que incumplan una validación y aplicará el resto.
                </p>

                <div class="flex items-center justify-end gap-2 border-t border-stone-200 px-5 py-4">
                    <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">Cancelar</a>
                    <button type="button" class="btn btn-primary" :disabled="!ready || busy" @click="apply()">
                        <x-lucide-loader-circle class="size-4 animate-spin" x-show="busy" x-cloak />
                        <x-lucide-layers class="size-4" x-show="!busy" />
                        Aplicar cambios
                    </button>
                </div>

                <p x-show="error" x-text="error" x-cloak
                    class="mx-5 mb-5 rounded-md bg-red-50 px-3 py-2 text-[12px] font-medium text-red-700"></p>
            </x-card>

            <template x-if="result">
                <x-card>
                    <header class="border-b border-stone-200 px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-stone-900">Resultado</h2>
                        <p class="mt-0.5 text-[12px] text-stone-500" x-text="result.message"></p>
                    </header>
                    <div class="space-y-2 p-5 text-[12px]">
                        <p x-show="result.aplicadas.length > 0" class="flex items-start gap-2 text-brand-700">
                            <x-lucide-check class="mt-px size-3.5 shrink-0" />
                            <span x-text="'Aplicado a ' + result.aplicadas.map((row) => row.code).join(', ')"></span>
                        </p>
                        <template x-for="row in result.rechazadas" :key="row.id">
                            <p class="flex items-start gap-2 text-amber-700">
                                <x-lucide-triangle-alert class="mt-px size-3.5 shrink-0" />
                                <span><span class="font-mono font-semibold" x-text="row.code"></span>: <span x-text="row.errors[0]"></span></span>
                            </p>
                        </template>
                        <a href="{{ request()->fullUrl() }}" class="link inline-block pt-1">Recargar la lista con los cambios →</a>
                    </div>
                </x-card>
            </template>
        </div>
    </div>
</x-layouts.app>
