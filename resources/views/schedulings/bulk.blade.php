{{-- W19 Programación masiva. Estado y llamadas al API en resources/js/components/bulk-scheduling.js. --}}
@php
    $catalogs = [
        'groups' => $groups->map(fn ($group) => [
            ...$group->schedulingDefaults(),
            'zone' => $group->zone?->name,
            'schedule' => $group->schedule?->name,
            'schedule_label' => $group->schedule?->label,
            'plate' => $group->vehicle?->plate,
            'operational' => $group->vehicle?->status === 'operativo',
            'seats' => $group->vehicle?->helperSeats() ?? 0,
            'days' => $group->weekdaysLabel(),
        ])->values(),
        'helpers' => $helpers->map->only('id', 'full_name'),
        'holidays' => $holidays->map(fn ($holiday) => [
            'date' => $holiday->date->toDateString(),
            'description' => $holiday->description,
        ]),
    ];
@endphp

<x-layouts.app title="Programación masiva" subtitle="Programación › Varios grupos en un rango de fechas">
    <x-slot:actions>
        <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <div class="space-y-4" x-data="bulkScheduling({ catalogs: @js($catalogs) })">
        <x-card>
            <x-card-header title="Rango de fechas"
                description="Se programan todos los grupos marcados del inicio al término, en los días de cada grupo." />

            <div class="space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Fecha de inicio" for="start_date" required>
                        <input id="start_date" type="date" x-model="form.start_date" class="field" required>
                    </x-field>
                    <x-field label="Fecha de término" for="end_date" required>
                        <input id="end_date" type="date" x-model="form.end_date" :min="form.start_date" class="field" required>
                    </x-field>
                    <x-field label="Turno" for="schedule_id">
                        <select id="schedule_id" x-model="form.schedule_id" @change="page = 1" class="field">
                            <option value="">Todos los turnos</option>
                            @foreach ($schedules as $schedule)
                                <option value="{{ $schedule->id }}">{{ $schedule->label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                {{-- Marcado = no se programa ese día, aunque el grupo trabaje ese día de la semana. --}}
                <div x-show="form.start_date && form.end_date" x-cloak
                    class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-[12px] text-blue-800">
                    <p class="flex items-start gap-2 font-medium">
                        <x-lucide-calendar-x class="mt-px size-3.5 shrink-0" />
                        <span x-text="holidays.length === 0
                            ? 'No hay feriados en este rango.'
                            : (holidays.length === 1 ? 'Feriado encontrado' : holidays.length + ' feriados encontrados') + ': los marcados no se programan.'"></span>
                    </p>
                    <div x-show="holidays.length" class="mt-2 flex flex-wrap gap-2 pl-5.5">
                        <template x-for="holiday in holidays" :key="holiday.date">
                            <label class="flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5 transition"
                                :class="isSkipped(holiday.date) ? 'border-blue-300 bg-white' : 'border-amber-300 bg-amber-50 text-amber-800'">
                                <input type="checkbox" class="size-4 accent-brand-600" :checked="isSkipped(holiday.date)"
                                    @change="toggleHoliday(holiday.date)">
                                <span>
                                    <span class="font-medium" x-text="holidayDay(holiday.date)"></span>
                                    <span x-show="holiday.description" x-text="'· ' + holiday.description"></span>
                                    <span x-show="!isSkipped(holiday.date)" class="font-medium">(se trabaja)</span>
                                </span>
                            </label>
                        </template>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <x-card-header title="Grupos a programar"
                description="Marca los grupos que entran. El conductor y los ayudantes de cada uno se pueden cambiar.">
                <label class="flex shrink-0 cursor-pointer items-center gap-2 rounded-md border border-stone-200 px-2.5 py-1.5 text-[12px] text-stone-600 hover:bg-stone-50">
                    <input type="checkbox" class="size-4 accent-brand-600" :checked="allSelected" @change="toggleAll()">
                    <span x-text="selectedRows.length + ' de ' + visibleRows.length + (visibleRows.length === 1 ? ' marcado' : ' marcados')"></span>
                </label>
            </x-card-header>

            <div x-show="visibleRows.length > 0" class="flex flex-wrap items-center gap-2 border-b border-stone-200 px-4 py-3">
                <div class="relative w-full sm:w-72">
                    <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                    <input type="search" x-model="search" @input="page = 1" class="field pl-9"
                        placeholder="Buscar grupo o zona" aria-label="Buscar grupo o zona">
                </div>
                <p x-show="search.trim()" x-cloak class="text-[12px] text-stone-500"
                    x-text="shownRows.length + (shownRows.length === 1 ? ' coincidencia' : ' coincidencias') + '. La búsqueda solo filtra lo que ves: los grupos marcados entran igual.'"></p>
            </div>

            <div class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                <template x-for="row in pagedRows" :key="row.id">
                    <article class="flex flex-col overflow-hidden rounded-xl border bg-white shadow-sm transition" :class="cardClass(row)"
                        :id="'card-' + row.id">
                        {{-- Toda la cabecera marca o desmarca el grupo. --}}
                        <label class="flex cursor-pointer items-start gap-3 border-b border-stone-100 px-4 py-3"
                            :class="row.include ? 'bg-brand-50/40' : 'bg-stone-50'">
                            <input type="checkbox" class="mt-0.5 size-4 shrink-0 accent-brand-600" x-model="row.include"
                                :aria-label="'Programar ' + group(row.id).name">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-semibold text-stone-900" x-text="group(row.id).name"></span>
                                <span class="mt-0.5 flex items-center gap-1 text-[11px] text-stone-500">
                                    <x-lucide-map-pin class="size-3 shrink-0" />
                                    <span x-text="group(row.id).zone ?? 'Sin zona'"></span>
                                </span>
                            </span>
                            <span x-show="row.include && result(row.id)" class="shrink-0">
                                <x-badge tone="red" x-show="result(row.id)?.status === 'error'">Con errores</x-badge>
                                <x-badge tone="amber" x-show="result(row.id)?.status === 'warning'">Con advertencias</x-badge>
                                <x-badge tone="green" x-show="result(row.id)?.status === 'ok'">Correcto</x-badge>
                            </span>
                        </label>

                        <div class="space-y-2.5 px-4 py-3 text-[12px] text-stone-600">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                                <span class="flex items-center gap-1.5" :title="group(row.id).schedule_label">
                                    <x-lucide-clock class="size-3.5 text-stone-400" />
                                    <span x-text="group(row.id).schedule_label ?? '—'"></span>
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <x-lucide-truck class="size-3.5 text-stone-400" />
                                    <span class="font-mono" x-text="group(row.id).plate ?? '—'"></span>
                                    <span x-show="!group(row.id).operational" class="font-medium text-amber-700">(no operativo)</span>
                                </span>
                            </div>
                            <div class="flex gap-1" aria-label="Días de trabajo">
                                @foreach (\App\Models\Scheduling::WEEKDAY_LABELS as $day => $label)
                                    <span class="w-9 rounded py-0.5 text-center text-[10px] font-semibold"
                                        :class="group(row.id).weekdays.includes({{ $day }}) ? 'bg-brand-600 text-white' : 'bg-stone-100 text-stone-400'">{{ $label }}</span>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-auto space-y-2 border-t border-stone-100 px-4 py-3">
                            <div>
                                <label class="label" :for="'driver_' + row.id">Conductor</label>
                                <select :id="'driver_' + row.id" x-model="row.driver_id" :disabled="!row.include" class="field">
                                    <option value="">Selecciona un conductor</option>
                                    @foreach ($drivers as $person)
                                        <option value="{{ $person->id }}">{{ $person->full_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <template x-for="(helper, index) in row.helper_ids" :key="index">
                                <div>
                                    <label class="label" :for="'helper_' + row.id + '_' + index" x-text="'Ayudante ' + (index + 1)"></label>
                                    <select :id="'helper_' + row.id + '_' + index" x-model="row.helper_ids[index]" :disabled="!row.include" class="field">
                                        <option value="">Sin ayudante</option>
                                        <template x-for="person in helperOptions(row, index)" :key="person.id">
                                            <option :value="person.id" :selected="String(person.id) === String(row.helper_ids[index])"
                                                x-text="person.full_name"></option>
                                        </template>
                                    </select>
                                </div>
                            </template>
                            <p x-show="row.helper_ids.length === 0" class="text-[12px] text-stone-400">Este vehículo solo lleva al conductor.</p>
                        </div>

                        {{-- Tras validar: lo esencial aquí; el detalle, en el resultado de abajo. --}}
                        <button type="button" x-show="row.include && result(row.id)" @click="showResult(row.id)"
                            class="flex cursor-pointer items-center justify-between gap-2 border-t px-4 py-2 text-left text-[11px] font-medium"
                            :class="{
                                'border-red-100 bg-red-50 text-red-700': result(row.id)?.status === 'error',
                                'border-amber-100 bg-amber-50 text-amber-700': result(row.id)?.status === 'warning',
                                'border-brand-100 bg-brand-50 text-brand-700': result(row.id)?.status === 'ok',
                            }">
                            <span x-text="resultSummary(row.id)"></span>
                            <x-lucide-arrow-down class="size-3.5 shrink-0" />
                        </button>
                    </article>
                </template>

                <p x-show="visibleRows.length === 0" class="col-span-full py-12 text-center text-[13px] text-stone-500">
                    @if ($groups->isEmpty())
                        No hay grupos de personal activos. <a href="{{ route('staffgroups.create') }}" class="link">Crea uno</a> para poder programar.
                    @else
                        Ningún grupo activo en este turno.
                    @endif
                </p>
                <p x-show="visibleRows.length > 0 && shownRows.length === 0" x-cloak
                    class="col-span-full py-12 text-center text-[13px] text-stone-500">
                    Ningún grupo coincide con la búsqueda.
                </p>
            </div>

            <x-pager page="page" total="shownRows.length" per-page="perPage" />

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-stone-200 px-5 py-4">
                <p x-show="hint" x-text="hint" class="mr-auto text-[12px] text-stone-500"></p>
                <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="button" class="btn btn-secondary" :disabled="!complete || checking" @click="validate()">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="checking" x-cloak />
                    <x-lucide-shield-check class="size-4" x-show="!checking" />
                    Validar disponibilidad
                </button>
                <button type="button" class="btn btn-primary" :disabled="!canSave" @click="save()">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="saving" x-cloak />
                    <x-lucide-save class="size-4" x-show="!saving" />
                    Guardar programación masiva
                </button>
            </div>

            <p x-show="error" x-text="error" x-cloak
                class="mx-5 mb-5 rounded-md bg-red-50 px-3 py-2 text-[12px] font-medium text-red-700"></p>
        </x-card>

        {{-- Resultado de la validación general: un panel por grupo, abiertos los que tienen algo que corregir. --}}
        <template x-if="report">
            <x-card x-ref="report">
                <header class="border-b border-stone-200 px-5 py-3.5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold"
                        :class="report.valid ? 'text-brand-700' : 'text-red-600'">
                        <x-lucide-triangle-alert class="size-4" x-show="!report.valid" />
                        <x-lucide-circle-check class="size-4" x-show="report.valid" />
                        Resultado de validación general
                    </h2>
                    <p class="mt-0.5 text-[12px] text-stone-500"
                        x-text="report.totals.groups + (report.totals.groups === 1 ? ' grupo' : ' grupos')
                            + ' · ' + report.totals.ok + ' correctos · ' + report.totals.warning + ' con advertencias · ' + report.totals.error + ' con errores'
                            + ' · ' + report.totals.occurrences + (report.totals.occurrences === 1 ? ' programación individual' : ' programaciones individuales')
                            + (report.valid ? '. Ya puedes guardar.' : '. Corrige los errores y vuelve a validar.')"></p>
                </header>

                <div class="space-y-3 p-4">
                    <template x-for="item in report.groups" :key="item.staffgroup_id">
                        <div class="overflow-hidden rounded-lg border" :id="'result-' + item.staffgroup_id"
                            :class="{ 'border-red-200': item.status === 'error', 'border-amber-200': item.status === 'warning', 'border-brand-200': item.status === 'ok' }">
                            <button type="button" @click="toggleResult(item.staffgroup_id)"
                                class="flex w-full cursor-pointer items-center gap-2 bg-sky-50/70 px-4 py-2.5 text-left">
                                <x-lucide-users class="size-4 shrink-0 text-stone-600" />
                                <span class="text-[13px] font-medium text-stone-900" x-text="item.name"></span>
                                <span class="text-[11px] text-stone-500" x-text="'- ' + (item.zone ?? '—') + ' - ' + (item.schedule ?? '—')"></span>
                                <span class="ml-auto flex shrink-0 items-center gap-1.5">
                                    <x-badge tone="red" x-show="item.errors.length">Con errores</x-badge>
                                    <x-badge tone="amber" x-show="item.warnings.length">Con advertencias</x-badge>
                                    <x-badge tone="green" x-show="item.status === 'ok'">Correcto</x-badge>
                                    <x-lucide-chevron-up class="size-4 text-stone-500 transition"
                                        x-bind:class="collapsed.includes(item.staffgroup_id) && 'rotate-180'" />
                                </span>
                            </button>

                            <div x-show="!collapsed.includes(item.staffgroup_id)"
                                class="space-y-2.5 border-l-4 px-4 py-3 text-[12px]"
                                :class="{ 'border-l-red-500 bg-red-50': item.status === 'error', 'border-l-amber-400 bg-amber-50/60': item.status === 'warning', 'border-l-brand-500 bg-brand-50/50': item.status === 'ok' }">
                                <p x-show="item.uncovered" class="flex items-start gap-1.5 text-sky-700">
                                    <x-lucide-calendar-days class="mt-px size-3.5 shrink-0" />
                                    <span><span class="font-semibold">Días no cubiertos:</span> <span x-text="item.uncovered"></span></span>
                                </p>
                                <p x-show="item.holidays" class="flex items-start gap-1.5 text-sky-700">
                                    <x-lucide-calendar-x class="mt-px size-3.5 shrink-0" />
                                    <span x-text="item.holidays"></span>
                                </p>

                                <div x-show="item.errors.length">
                                    <p class="flex items-center gap-1.5 font-semibold text-red-600">
                                        <x-lucide-circle-x class="size-3.5" /> Errores:
                                    </p>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-red-600">
                                        <template x-for="message in item.errors" :key="message">
                                            <li x-text="message"></li>
                                        </template>
                                    </ul>
                                </div>

                                <div x-show="item.warnings.length">
                                    <p class="flex items-center gap-1.5 font-semibold text-amber-700">
                                        <x-lucide-triangle-alert class="size-3.5" /> Advertencias:
                                    </p>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-amber-700">
                                        <template x-for="message in item.warnings" :key="message">
                                            <li x-text="message"></li>
                                        </template>
                                    </ul>
                                </div>

                                <p x-show="!item.errors.length" class="flex items-center gap-1.5 font-medium text-brand-700">
                                    <x-lucide-circle-check class="size-3.5" />
                                    <span x-text="'Sin errores: generará ' + item.occurrences + (item.occurrences === 1 ? ' programación individual.' : ' programaciones individuales (una por día).')"></span>
                                </p>

                                {{-- Con varias páginas la card puede no estar a la vista. --}}
                                <button type="button" x-show="item.errors.length" @click="goToCard(item.staffgroup_id)"
                                    class="link inline-flex cursor-pointer items-center gap-1 text-[12px]">
                                    <x-lucide-pencil class="size-3.5" />
                                    Corregir en su card
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </x-card>
        </template>
    </div>
</x-layouts.app>
