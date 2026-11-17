{{-- Recorridos en vivo: posición de los camiones y trayecto GPS que manda la app del conductor. --}}
<x-layouts.app title="Recorridos en vivo" subtitle="Ubicación de los camiones en ruta, actualizada cada 5 segundos">
    <x-slot:actions>
        <form method="GET" action="{{ route('tracking.index') }}" class="flex items-center gap-2">
            <label for="date" class="sr-only">Fecha</label>
            <input id="date" type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}"
                class="field w-auto" onchange="this.form.submit()">
            @if ($date !== now()->toDateString())
                <a href="{{ route('tracking.index') }}" class="btn btn-secondary">Hoy</a>
            @endif
        </form>
    </x-slot:actions>

    <div class="grid gap-4 lg:grid-cols-[22rem_1fr]" x-data="liveRoutes({ date: @js($date), selected: @js($selected) })">
        {{-- En pantalla grande la columna mide lo mismo que el mapa y la lista hace scroll por dentro. --}}
        <div class="flex min-h-0 flex-col gap-4 lg:h-[calc(100vh-11rem)] lg:min-h-[32rem]">
            {{-- Contadores --}}
            <div class="grid shrink-0 grid-cols-2 gap-3">
                <x-card class="px-4 py-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500">En curso</p>
                    <p class="mt-1 flex items-center gap-2 text-2xl font-semibold text-stone-900">
                        <span class="relative flex size-2.5" x-show="counts.iniciado > 0">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-brand-400 opacity-75"></span>
                            <span class="relative inline-flex size-2.5 rounded-full bg-brand-500"></span>
                        </span>
                        <span x-text="counts.iniciado">0</span>
                    </p>
                </x-card>
                <x-card class="px-4 py-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500">Finalizados</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-900" x-text="counts.finalizado">0</p>
                </x-card>
            </div>

            {{-- Detalle del recorrido elegido --}}
            <template x-if="detail">
                <x-card class="flex max-h-[60%] shrink-0 flex-col overflow-hidden">
                    <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-3.5">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-stone-900" x-text="detail.code ?? 'Recorrido'"></span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                                    :class="detail.status === 'iniciado'
                                        ? (detail.signal_lost ? 'bg-amber-50 text-amber-700 ring-amber-600/20' : 'bg-brand-600 text-white ring-brand-600')
                                        : 'bg-sky-50 text-sky-700 ring-sky-600/20'"
                                    x-text="detail.status === 'iniciado' && detail.signal_lost ? 'Sin señal' : detail.status_label"></span>
                            </p>
                            <p class="mt-0.5 truncate text-[12px] text-stone-500" x-text="detail.zone ?? 'Sin zona'"></p>
                        </div>
                        <button type="button" class="icon-btn" title="Ver todos" aria-label="Ver todos los recorridos" @click="clearSelection()">
                            <x-lucide-x class="size-4" />
                        </button>
                    </header>

                    <dl class="grid min-h-0 grid-cols-2 gap-x-4 gap-y-3 overflow-y-auto px-5 py-4 text-[13px]">
                        <div>
                            <dt class="text-[11px] text-stone-500">Tiempo</dt>
                            <dd class="font-mono font-semibold text-stone-900" x-text="elapsed(detail, detail.fetchedAt)"></dd>
                        </div>
                        <div>
                            <dt class="text-[11px] text-stone-500">Ubicaciones</dt>
                            <dd class="font-semibold text-stone-900" x-text="detail.points_count"></dd>
                        </div>
                        <div>
                            <dt class="text-[11px] text-stone-500">Inicio</dt>
                            <dd class="text-stone-900" x-text="detail.started_at ?? '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-[11px] text-stone-500">Fin</dt>
                            <dd class="text-stone-900" x-text="detail.finished_at ?? 'En curso'"></dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-[11px] text-stone-500">Vehículo</dt>
                            <dd class="text-stone-900" x-text="detail.vehicle ?? '—'"></dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-[11px] text-stone-500">Conductor</dt>
                            <dd class="text-stone-900" x-text="detail.driver ?? '—'"></dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-[11px] text-stone-500">Ayudantes</dt>
                            <dd class="text-stone-900">
                                <template x-for="name in detail.helpers" :key="name">
                                    <span class="block" x-text="name"></span>
                                </template>
                                <em class="text-stone-400" x-show="!detail.helpers.length">Sin ayudante</em>
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-[11px] text-stone-500">Turno</dt>
                            <dd class="text-stone-900" x-text="detail.schedule ?? '—'"></dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-[11px] text-stone-500">Coordenadas actuales</dt>
                            <dd class="font-mono text-stone-900" x-text="coords(detail)"></dd>
                            <dd class="text-[11px]" :class="detail.signal_lost ? 'text-amber-700' : 'text-stone-500'"
                                x-show="detail.status === 'iniciado'" x-text="ago(detail)"></dd>
                        </div>
                        <template x-if="detail.ratings && detail.ratings.length">
                            <div class="col-span-2">
                                <dt class="flex items-center justify-between text-[11px] text-stone-500">
                                    <span>Calificación de los ciudadanos</span>
                                    <span class="font-semibold text-amber-600" x-text="'★ ' + detail.rating_average + ' · ' + detail.ratings.length + (detail.ratings.length === 1 ? ' opinión' : ' opiniones')"></span>
                                </dt>
                                <dd class="mt-1 space-y-1.5">
                                    <template x-for="(r, i) in detail.ratings" :key="i">
                                        <div class="rounded-md bg-amber-50/60 px-3 py-2">
                                            <p class="flex items-center justify-between text-[11px]">
                                                <span class="text-amber-600" x-text="'★'.repeat(r.stars) + '☆'.repeat(5 - r.stars)"></span>
                                                <span class="text-stone-400" x-text="r.citizen + ' · ' + r.at"></span>
                                            </p>
                                            <p class="mt-0.5 text-stone-700" x-show="r.comment" x-text="r.comment"></p>
                                        </div>
                                    </template>
                                </dd>
                            </div>
                        </template>
                        <template x-if="detail.observation">
                            <div class="col-span-2 rounded-md bg-stone-50 px-3 py-2">
                                <dt class="text-[11px] text-stone-500">Observación del conductor</dt>
                                <dd class="whitespace-pre-line text-stone-800" x-text="detail.observation"></dd>
                            </div>
                        </template>
                    </dl>
                </x-card>
            </template>

            {{-- Lista de recorridos del día --}}
            <x-card class="flex min-h-[14rem] flex-1 flex-col overflow-hidden">
                <x-card-header class="shrink-0" title="Recorridos del día" description="Toca uno para ver su trayecto en el mapa." />

                <p x-show="loading" class="px-5 py-8 text-center text-[12px] text-stone-400">Cargando…</p>

                <p x-show="!loading && !routes.length" x-cloak class="px-5 py-8 text-center text-[12px] text-stone-400">
                    <span x-show="isToday">Todavía no hay recorridos iniciados hoy. Aparecen aquí cuando el conductor inicia el recorrido en la app.</span>
                    <span x-show="!isToday">No hubo recorridos iniciados ese día.</span>
                </p>

                <ul class="max-h-[28rem] min-h-0 flex-1 divide-y divide-stone-100 overflow-y-auto lg:max-h-none" x-show="routes.length" x-cloak>
                    <template x-for="route in routes" :key="route.id">
                        <li>
                            <button type="button" @click="select(route.id)"
                                class="flex w-full cursor-pointer items-start gap-3 px-4 py-3 text-left hover:bg-stone-50"
                                :class="route.id === selectedId && 'bg-brand-50/60'">
                                <span class="mt-1 size-2.5 shrink-0 rounded-full"
                                    :class="route.status === 'iniciado' ? (route.signal_lost ? 'bg-amber-500' : 'bg-brand-500') : 'bg-stone-300'"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-[13px] font-semibold text-stone-900" x-text="route.code ?? 'Recorrido'"></span>
                                        <span class="font-mono text-[12px] text-stone-600" x-text="elapsed(route)"></span>
                                    </span>
                                    <span class="block truncate text-[12px] text-stone-600" x-text="[route.zone, route.vehicle].filter(Boolean).join(' · ')"></span>
                                    <span class="block truncate text-[12px] text-stone-500" x-text="route.driver"></span>
                                    <span class="mt-0.5 block text-[11px]"
                                        :class="route.status === 'iniciado' && route.signal_lost ? 'text-amber-700' : 'text-stone-400'"
                                        x-text="route.status === 'iniciado'
                                            ? ago(route)
                                            : 'Finalizado ' + (route.started_at ?? '') + ' – ' + (route.finished_at ?? '') + ' · ' + route.points_count + ' ubicaciones'"></span>
                                </span>
                            </button>
                        </li>
                    </template>
                </ul>
            </x-card>
        </div>

        {{-- Mapa --}}
        <x-card class="overflow-hidden">
            <div class="relative">
                <div x-ref="map" class="z-0 h-[calc(100vh-11rem)] min-h-[32rem] bg-stone-100"></div>

                <div class="pointer-events-none absolute inset-x-0 top-3 z-[400] flex justify-center px-3">
                    <p x-show="error" x-cloak x-text="error"
                        class="pointer-events-auto rounded-md bg-red-50 px-3 py-1.5 text-[12px] text-red-700 shadow"></p>
                </div>

                <button type="button" x-show="detail && detail.status === 'iniciado'" x-cloak @click="focusSelected()"
                    class="absolute right-3 bottom-8 z-[400] inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-white px-3 py-2 text-[12px] font-medium shadow ring-1 ring-stone-200 hover:bg-stone-50"
                    :class="follow ? 'text-brand-600' : 'text-stone-700'">
                    <x-lucide-locate-fixed class="size-4" />
                    <span x-text="follow ? 'Siguiendo al camión' : 'Seguir camión'"></span>
                </button>

                <div class="absolute bottom-8 left-3 z-[400] rounded-md bg-white/95 px-3 py-2 text-[11px] text-stone-600 shadow ring-1 ring-stone-200">
                    <p class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-brand-500"></span> En curso</p>
                    <p class="mt-1 flex items-center gap-2"><span class="size-2.5 rounded-full bg-amber-500"></span> Sin señal (+30 s)</p>
                    <p class="mt-1 flex items-center gap-2"><span class="size-2.5 rounded-full bg-stone-400"></span> Finalizado</p>
                </div>

                <p x-show="tilesFailed" x-cloak
                    class="pointer-events-none absolute inset-x-0 bottom-0 z-[400] bg-amber-50/95 px-3 py-2 text-center text-[11px] text-amber-800">
                    Sin conexión no se carga el mapa de fondo. Las posiciones se siguen mostrando.
                </p>
            </div>
        </x-card>
    </div>
</x-layouts.app>
