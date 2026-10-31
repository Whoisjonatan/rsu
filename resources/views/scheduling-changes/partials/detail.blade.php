{{--
    Detalle de un cambio del historial (botón Ver). Lo arma
    SchedulingChangeController::details(); se abre con `detail = {...}`.
--}}
@php
    // Íconos que pueden venir en cada valor; Alpine no puede pintar un componente por nombre.
    $valueIcons = ['clock', 'log-in', 'log-out', 'hash', 'truck', 'rectangle-horizontal', 'user', 'id-card', 'briefcase'];
    $typeChips = [
        'turno' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'vehiculo' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'conductor' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        'ayudante' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
    ];
    $term = 'text-[11px] font-semibold tracking-wide text-stone-500 uppercase';
@endphp

<x-modal show="detail" close="detail = null" size="lg" title-expr="detail ? 'Detalle del cambio #' + detail.id : ''"
    description-expr="detail ? 'Registrado el ' + detail.date + ' a las ' + detail.time : ''">
    <template x-if="detail">
        <x-modal.body class="max-h-[70vh] space-y-5 overflow-y-auto">
            {{-- Programación afectada --}}
            <div class="flex flex-wrap items-center gap-3 rounded-xl bg-stone-50 px-4 py-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-white text-stone-600 ring-1 ring-stone-200">
                    <x-lucide-calendar-days class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="{{ $term }}">Programación afectada</p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[13px]">
                        <span class="font-mono font-semibold text-stone-900" x-text="detail.scheduling.code"></span>
                        <span class="text-stone-500"
                            x-text="[detail.scheduling.date, 'Zona ' + detail.scheduling.zone, detail.scheduling.schedule].filter(Boolean).join(' · ')"></span>
                    </p>
                </div>
                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[12px] font-semibold ring-1 ring-inset"
                    :class="{{ Js::from($typeChips) }}[detail.type]">
                    <x-lucide-arrow-left-right class="size-3.5" />
                    <span x-text="detail.type_label"></span>
                </span>
            </div>

            {{-- Antes y después --}}
            <div class="grid items-stretch gap-3 md:grid-cols-[1fr_auto_1fr]">
                @foreach ([['old', 'Valor anterior', 'Cómo estaba antes del cambio', 'border-red-100', 'bg-red-50 text-red-600', 'circle-minus', 'text-stone-600'],
                           ['new', 'Valor nuevo', 'Cómo quedó después del cambio', 'border-brand-200', 'bg-brand-50 text-brand-700', 'circle-plus', 'font-semibold text-brand-800']] as [$side, $title, $hint, $border, $chip, $icon, $valueClass])
                    @if ($side === 'new')
                        <div class="flex items-center justify-center">
                            <span class="grid size-9 place-items-center rounded-full border border-stone-200 bg-white text-stone-400 shadow-sm">
                                <x-lucide-arrow-right class="hidden size-4 md:block" />
                                <x-lucide-arrow-down class="size-4 md:hidden" />
                            </span>
                        </div>
                    @endif
                    <section class="overflow-hidden rounded-xl border {{ $border }}">
                        <header class="flex items-center gap-2.5 border-b {{ $border }} px-4 py-2.5">
                            <span class="grid size-7 place-items-center rounded-md {{ $chip }}">
                                <x-dynamic-component :component="'lucide-'.$icon" class="size-4" />
                            </span>
                            <div>
                                <h3 class="text-[13px] font-semibold text-stone-900">{{ $title }}</h3>
                                <p class="text-[11px] text-stone-500">{{ $hint }}</p>
                            </div>
                        </header>
                        <dl class="divide-y divide-stone-100 px-4">
                            <template x-for="item in detail.{{ $side }}" :key="item[0]">
                                <div class="flex items-center gap-3 py-2.5">
                                    <span class="grid size-7 shrink-0 place-items-center rounded-md bg-stone-100 text-stone-500">
                                        @foreach ($valueIcons as $valueIcon)
                                            <template x-if="item[2] === @js($valueIcon)">
                                                <x-dynamic-component :component="'lucide-'.$valueIcon" class="size-3.5" />
                                            </template>
                                        @endforeach
                                    </span>
                                    <div class="min-w-0">
                                        <dt class="{{ $term }}" x-text="item[0]"></dt>
                                        <dd class="truncate text-[13px] {{ $valueClass }}" x-text="item[1]" :title="item[1]"></dd>
                                    </div>
                                </div>
                            </template>
                            <p x-show="!detail.{{ $side }}.length" class="py-4 text-center text-[12px] text-stone-400">Sin valor registrado.</p>
                        </dl>
                    </section>
                @endforeach
            </div>

            {{-- Información del cambio --}}
            <section>
                <h3 class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-stone-900">
                    <x-lucide-info class="size-4 text-stone-500" />
                    Información del cambio
                </h3>
                <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-stone-50 px-3.5 py-3">
                        <x-lucide-arrow-left-right class="size-4 text-stone-400" />
                        <p class="{{ $term }} mt-2">Tipo de cambio</p>
                        <p class="mt-0.5 text-[13px] font-medium text-stone-900" x-text="detail.type_label"></p>
                    </div>
                    <div class="rounded-xl bg-stone-50 px-3.5 py-3">
                        <x-lucide-user-cog class="size-4 text-stone-400" />
                        <p class="{{ $term }} mt-2">Realizado por</p>
                        <p class="mt-0.5 truncate text-[13px] font-medium text-stone-900" x-text="detail.user" :title="detail.user"></p>
                    </div>
                    <div class="rounded-xl bg-stone-50 px-3.5 py-3">
                        <x-lucide-calendar-clock class="size-4 text-stone-400" />
                        <p class="{{ $term }} mt-2">Fecha del cambio</p>
                        <p class="mt-0.5 text-[13px] font-medium text-stone-900 tabular-nums" x-text="detail.date + ' ' + detail.time"></p>
                    </div>
                    <div class="rounded-xl bg-stone-50 px-3.5 py-3">
                        <x-lucide-clipboard-list class="size-4 text-stone-400" />
                        <p class="{{ $term }} mt-2">Programación</p>
                        <p class="mt-0.5 font-mono text-[13px] font-semibold text-stone-900" x-text="detail.scheduling.code"></p>
                        <p class="text-[11px] text-stone-500">
                            <span x-text="detail.scheduling.general ? 'General ' + detail.scheduling.general : ''"></span>
                            <span x-show="detail.scheduling.status" x-text="(detail.scheduling.general ? ' · ' : '') + detail.scheduling.status"></span>
                        </p>
                    </div>
                </div>
            </section>

            {{-- Motivo --}}
            <section class="flex gap-3 rounded-xl border-l-4 border-brand-500 bg-brand-50/50 px-4 py-3">
                <x-lucide-quote class="mt-0.5 size-4 shrink-0 text-brand-600" />
                <div>
                    <p class="{{ $term }}">Motivo del cambio</p>
                    <p class="mt-0.5 text-[13px] font-medium text-stone-900" x-text="detail.reason"></p>
                    <p x-show="detail.description" class="mt-0.5 text-[13px] text-stone-600" x-text="detail.description"></p>
                </div>
            </section>
        </x-modal.body>
    </template>
    <x-modal.footer>
        <button type="button" class="btn btn-secondary" @click="detail = null">Cerrar</button>
    </x-modal.footer>
</x-modal>
