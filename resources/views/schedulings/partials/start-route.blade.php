{{-- W21 Validar e iniciar recorrido. Estado y acciones en resources/js/components/start-route.js. --}}
@php
    $checkIcon = 'grid size-4 shrink-0 place-items-center rounded-full';
    $option = 'flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-[13px] transition';
@endphp

<x-modal show="routeId" close="close()" title="Validar e iniciar recorrido" description-expr="description">
    <x-modal.body>
        <div x-show="!route" class="h-40 animate-pulse rounded-lg bg-stone-100"></div>

        <template x-if="route">
            <div class="space-y-5">
                <section>
                    <h3 class="mb-2 text-[12px] font-semibold text-stone-700">Verificaciones</h3>
                    <ul class="space-y-1.5">
                        <li class="flex items-center gap-2 rounded-lg bg-stone-50 px-3 py-2 text-[13px]">
                            <span class="{{ $checkIcon }}"
                                :class="route.vehicle?.status === 'operativo' ? 'bg-brand-100 text-brand-700' : 'bg-amber-100 text-amber-700'">
                                <x-lucide-check class="size-3" x-show="route.vehicle?.status === 'operativo'" />
                                <x-lucide-x class="size-3" x-show="route.vehicle?.status !== 'operativo'" />
                            </span>
                            <span class="text-stone-700" x-text="'Vehículo ' + (route.vehicle?.plate ?? '') + ' disponible'"></span>
                        </li>
                        <template x-for="occupant in readiness?.occupants ?? []" :key="occupant.occupant_id">
                            <li class="flex items-center gap-2 rounded-lg bg-stone-50 px-3 py-2 text-[13px]">
                                <span class="{{ $checkIcon }}"
                                    :class="occupant.attended ? 'bg-brand-100 text-brand-700' : 'bg-amber-100 text-amber-700'">
                                    <x-lucide-check class="size-3" x-show="occupant.attended" />
                                    <x-lucide-x class="size-3" x-show="!occupant.attended" />
                                </span>
                                <span class="text-stone-700"
                                    x-text="'Asistencia del ' + (occupant.usertype ?? '').toLowerCase() + ': ' + occupant.full_name"></span>
                            </li>
                        </template>
                    </ul>

                    {{-- Un recorrido ya iniciado o bloqueado no tiene nada que validar: se explica por qué. --}}
                    <p x-show="readiness && !readiness.ready && route.status !== 'programado'"
                        class="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-[12px] text-stone-600" x-text="readiness?.blockers[0]"></p>
                </section>

                <section x-show="missing && route.status === 'programado'">
                    <h3 class="mb-2 text-[12px] font-semibold text-stone-700">Reemplazar por personal de reserva</h3>
                    <div class="space-y-1.5">
                        <template x-for="reserve in reserves" :key="reserve.id">
                            <label class="{{ $option }}"
                                :class="choice === reserve.id ? 'border-brand-300 bg-brand-50/60' : 'border-stone-200 hover:bg-stone-50'">
                                <input type="radio" name="reserve" class="size-4 accent-brand-600" :checked="choice === reserve.id"
                                    @change="choice = reserve.id">
                                <span class="flex-1 text-stone-700" x-text="reserve.full_name"></span>
                                <span class="text-[11px] text-stone-500"
                                    x-text="(reserve.attendance_status ?? 'sin marcar') + ' ' + shortTime(reserve.attendance_time)"></span>
                            </label>
                        </template>

                        <p x-show="reserves.length === 0" class="rounded-lg bg-amber-50 px-3 py-2 text-[12px] text-amber-800">
                            No hay personal de reserva con asistencia marcada para esta función.
                        </p>

                        <label class="{{ $option }}"
                            :class="choice === 'postpone' ? 'border-amber-300 bg-amber-50/60' : 'border-stone-200 hover:bg-stone-50'">
                            <input type="radio" name="reserve" class="size-4 accent-brand-600" :checked="choice === 'postpone'"
                                @change="choice = 'postpone'">
                            <span class="text-stone-700">No reemplazar y posponer el recorrido</span>
                        </label>
                    </div>
                </section>

                <p x-show="error" x-text="error" class="rounded-md bg-red-50 px-3 py-2 text-[12px] font-medium text-red-700"></p>

                <p class="text-[11px] text-stone-400">Al iniciar, el recorrido cambia a estado Iniciado y queda registrada la hora.</p>
            </div>
        </template>
    </x-modal.body>

    <x-modal.footer>
        <button type="button" class="btn btn-secondary" @click="close()">Cancelar</button>
        <button type="button" class="btn btn-primary" :disabled="busy || (!canStart && choice === null)" @click="submit()">
            <x-lucide-loader-circle class="size-3.5 animate-spin" x-show="busy" x-cloak />
            <x-lucide-play class="size-3.5" x-show="!busy" />
            <span x-text="actionLabel"></span>
        </button>
    </x-modal.footer>
</x-modal>
