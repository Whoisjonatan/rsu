{{--
    Selector de una persona del grupo dentro de staffGroupForm. $seat es la
    expresión JS del asiento ("'driver'" o "'h' + index"). Sin nadie elegido
    muestra el buscador; con alguien, su ficha y su disponibilidad, y si no
    está disponible, a quién se recomienda en su lugar.
--}}
@php
    $placeholder = $placeholder ?? 'Buscar por DNI, nombre o apellido';
@endphp

<div class="relative" x-data="{ get slot() { return {{ $seat }} } }" @click.outside="close(slot)"
    @keydown.escape="close(slot)">
    {{-- Buscador --}}
    <div x-show="!value(slot)">
        <div class="relative">
            <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
            <input type="search" :data-picker="slot" x-model="pickers[slot].query" @focus="open(slot)" @input="open(slot)"
                placeholder="{{ $placeholder }}" autocomplete="off" class="field pl-9"
                :aria-label="'{{ $label }}'">
        </div>

        <div x-show="pickers[slot].open" x-cloak
            class="absolute inset-x-0 z-20 mt-1 max-h-72 overflow-y-auto rounded-lg border border-stone-200 bg-white py-1 shadow-lg">
            <template x-for="person in matches(slot)" :key="person.id">
                <button type="button" @click="choose(slot, person.id)"
                    class="flex w-full cursor-pointer items-center gap-3 px-3 py-2 text-left hover:bg-stone-50">
                    <span class="size-2 shrink-0 rounded-full"
                        :class="{ free: 'bg-brand-500', warning: 'bg-amber-500', busy: 'bg-red-500', unknown: 'bg-stone-300' }[state(person.id)]"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13px] font-medium text-stone-900" x-text="person.full_name"></span>
                        <span class="block truncate text-[11px] text-stone-500"
                            x-text="'DNI ' + person.dni + (person.zone ? ' · ' + person.zone : '')"></span>
                    </span>
                    <span class="shrink-0 text-[11px]"
                        :class="{ free: 'text-brand-700', warning: 'text-amber-700', busy: 'text-red-600', unknown: 'text-stone-400' }[state(person.id)]"
                        x-text="stateLabel(person.id)"></span>
                </button>
            </template>
            <p x-show="matches(slot).length === 0" class="px-3 py-4 text-center text-[12px] text-stone-500">
                Nadie coincide con la búsqueda.
            </p>
        </div>

        <p x-show="recommendations(slot).length" class="mt-1.5 flex flex-wrap items-center gap-1 text-[11px] text-stone-500">
            <span>Disponibles:</span>
            <template x-for="person in recommendations(slot)" :key="person.id">
                <button type="button" @click="choose(slot, person.id)"
                    class="cursor-pointer rounded-full bg-brand-50 px-2 py-0.5 font-medium text-brand-700 hover:bg-brand-100"
                    x-text="person.full_name"></button>
            </template>
        </p>
    </div>

    {{-- Ficha de la persona elegida --}}
    <template x-if="value(slot) && person(value(slot))">
        <div class="rounded-lg border px-4 py-3"
            :class="{ free: 'border-brand-200 bg-brand-50/40', warning: 'border-amber-200 bg-amber-50/50', busy: 'border-red-200 bg-red-50/50', unknown: 'border-stone-200' }[state(value(slot))]">
            <div class="flex items-start gap-3">
                <template x-if="person(value(slot)).photo">
                    <img :src="person(value(slot)).photo" alt="" class="size-10 shrink-0 rounded-full object-cover">
                </template>
                <template x-if="!person(value(slot)).photo">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-100 text-[13px] font-semibold text-brand-700"
                        x-text="person(value(slot)).full_name.charAt(0)"></span>
                </template>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <p class="text-[13px] font-semibold text-stone-900" x-text="person(value(slot)).full_name"></p>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                            :class="{ free: 'bg-brand-50 text-brand-700 ring-brand-600/20', warning: 'bg-amber-50 text-amber-700 ring-amber-600/20', busy: 'bg-red-50 text-red-700 ring-red-600/20', unknown: 'bg-stone-100 text-stone-600 ring-stone-500/20' }[state(value(slot))]"
                            x-text="stateLabel(value(slot))"></span>
                    </div>
                    <dl class="mt-1 grid gap-x-4 gap-y-0.5 text-[11px] text-stone-600 sm:grid-cols-2">
                        <div><dt class="inline text-stone-400">DNI</dt> <dd class="inline font-mono" x-text="person(value(slot)).dni"></dd></div>
                        <div><dt class="inline text-stone-400">Función</dt> <dd class="inline" x-text="person(value(slot)).function ?? '—'"></dd></div>
                        <div><dt class="inline text-stone-400">Teléfono</dt> <dd class="inline" x-text="person(value(slot)).phone ?? '—'"></dd></div>
                        <div><dt class="inline text-stone-400">Zona</dt> <dd class="inline" x-text="person(value(slot)).zone ?? '—'"></dd></div>
                        <div class="sm:col-span-2"><dt class="inline text-stone-400">Contrato</dt> <dd class="inline" x-text="person(value(slot)).contract ?? 'Sin contrato vigente'"></dd></div>
                        <div class="sm:col-span-2" x-show="slot === 'driver'"><dt class="inline text-stone-400">Licencia</dt> <dd class="inline" x-text="person(value(slot)).license ?? 'No registrada'"></dd></div>
                    </dl>
                </div>

                <button type="button" class="icon-btn shrink-0" title="Cambiar" :aria-label="'Cambiar {{ $label }}'"
                    @click="clear(slot)">
                    <x-lucide-x class="size-3.5" />
                </button>
            </div>

            <ul x-show="statusOf(value(slot))?.problems.length" class="mt-2 space-y-0.5 text-[12px] text-red-700">
                <template x-for="problem in statusOf(value(slot))?.problems ?? []" :key="problem">
                    <li class="flex items-start gap-1.5"><x-lucide-circle-alert class="mt-0.5 size-3.5 shrink-0" /><span x-text="problem"></span></li>
                </template>
            </ul>
            <ul x-show="statusOf(value(slot))?.warnings.length" class="mt-2 space-y-0.5 text-[12px] text-amber-700">
                <template x-for="warning in statusOf(value(slot))?.warnings ?? []" :key="warning">
                    <li class="flex items-start gap-1.5"><x-lucide-triangle-alert class="mt-0.5 size-3.5 shrink-0" /><span x-text="warning"></span></li>
                </template>
            </ul>

            {{-- No disponible: se pide elegir a otro y se recomienda a quién. --}}
            <div x-show="state(value(slot)) === 'busy'" class="mt-3 border-t border-red-200 pt-2.5">
                <p class="text-[12px] font-medium text-red-700">Selecciona a otra persona.</p>
                <div x-show="recommendations(slot).length" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] text-stone-600">
                    <span>Recomendados:</span>
                    <template x-for="person in recommendations(slot)" :key="person.id">
                        <button type="button" @click="choose(slot, person.id)"
                            class="flex cursor-pointer items-center gap-1 rounded-full border border-brand-200 bg-white px-2 py-0.5 font-medium text-brand-700 hover:bg-brand-50">
                            <x-lucide-check class="size-3" />
                            <span x-text="person.full_name + (String(person.zone_id) === String(form.zone_id) ? ' · misma zona' : '')"></span>
                        </button>
                    </template>
                </div>
                <p x-show="!recommendations(slot).length" class="mt-1 text-[11px] text-stone-500">
                    No hay nadie más disponible en ese turno y esos días.
                </p>
            </div>
        </div>
    </template>
</div>
