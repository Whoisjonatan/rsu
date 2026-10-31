{{--
    Cambio masivo: formulario y, al guardar, el resumen para confirmar.
    Estado en resources/js/components/bulk-changes.js; las reglas, en
    App\Services\Scheduling\BulkRescheduling.
--}}
@php
    // Tarjetas del tipo de cambio: los mismos tonos que los badges del historial.
    $typeCards = [
        'turno' => ['Turno', 'Pasa las programaciones a otro turno.', 'clock', 'bg-sky-50 text-sky-700'],
        'conductor' => ['Conductor', 'Reemplaza al conductor asignado.', 'id-card', 'bg-brand-50 text-brand-700'],
        'ayudante' => ['Ocupante', 'Reemplaza a uno de los ayudantes.', 'users', 'bg-brand-50 text-brand-700'],
        'vehiculo' => ['Vehículo', 'Asigna otro vehículo a la ruta.', 'truck', 'bg-amber-50 text-amber-700'],
    ];

    $sectionIcon = 'grid size-8 shrink-0 place-items-center rounded-lg bg-stone-100 text-stone-600';
    $sectionTitle = 'text-[13px] font-semibold text-stone-900';
    $sectionHint = 'text-[12px] text-stone-500';
    $term = 'text-[11px] font-semibold tracking-wide text-stone-500 uppercase';
@endphp

<div x-data="bulkChanges({
        catalogs: @js($bulk),
        previewUrl: @js(route('scheduling-changes.bulk.preview')),
        storeUrl: @js(route('scheduling-changes.bulk.store')),
    })" @open-bulk-change.window="openModal()">
    <x-modal show="open" close="close()" size="xl" title="Cambio masivo"
        description="Reemplaza un turno, conductor, ocupante o vehículo en todas las programaciones de un rango de fechas.">

        {{-- Pasos --}}
        <div class="flex items-center gap-3 border-b border-stone-200 bg-stone-50/70 px-5 py-3 text-[12px]">
            <div class="flex items-center gap-2" :class="step === 'form' ? 'text-stone-900' : 'text-stone-500'">
                <span class="grid size-6 place-items-center rounded-full text-[11px] font-semibold"
                    :class="step === 'form' ? 'bg-brand-600 text-white' : 'bg-brand-100 text-brand-700'">
                    <span x-show="step === 'form'">1</span>
                    <x-lucide-check class="size-3.5" x-show="step !== 'form'" x-cloak />
                </span>
                <span class="font-medium">Datos del cambio</span>
            </div>
            <span class="h-px w-10 bg-stone-300"></span>
            <div class="flex items-center gap-2" :class="step === 'summary' ? 'text-stone-900' : 'text-stone-400'">
                <span class="grid size-6 place-items-center rounded-full text-[11px] font-semibold"
                    :class="step === 'summary' ? 'bg-brand-600 text-white' : 'bg-stone-200 text-stone-500'">2</span>
                <span class="font-medium">Resumen y confirmación</span>
            </div>
        </div>

        {{-- Paso 1: formulario --}}
        <template x-if="step === 'form'">
            <div>
                <x-modal.body class="max-h-[68vh] space-y-6 overflow-y-auto">
                    {{-- Tipo de cambio --}}
                    <section class="space-y-3">
                        <div class="flex items-center gap-3">
                            <span class="{{ $sectionIcon }}"><x-lucide-arrow-left-right class="size-4" /></span>
                            <div>
                                <h3 class="{{ $sectionTitle }}">¿Qué quieres cambiar?</h3>
                                <p class="{{ $sectionHint }}">Elige el tipo de cambio que se aplicará a todas las programaciones.</p>
                            </div>
                        </div>
                        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4" role="radiogroup" aria-label="Tipo de cambio">
                            @foreach ($typeCards as $key => [$label, $hint, $icon, $chip])
                                <button type="button" role="radio" :aria-checked="form.type === @js($key)" @click="pickType(@js($key))"
                                    class="relative flex cursor-pointer flex-col items-start gap-2 rounded-xl border bg-white p-3.5 text-left transition"
                                    :class="form.type === @js($key)
                                        ? 'border-brand-500 ring-2 ring-brand-500/20'
                                        : 'border-stone-200 hover:border-stone-300 hover:bg-stone-50'">
                                    <span class="grid size-9 place-items-center rounded-lg {{ $chip }}">
                                        <x-dynamic-component :component="'lucide-'.$icon" class="size-4.5" />
                                    </span>
                                    <span>
                                        <span class="block text-[13px] font-semibold text-stone-900">Cambio de {{ mb_strtolower($label) }}</span>
                                        <span class="mt-0.5 block text-[11px] leading-snug text-stone-500">{{ $hint }}</span>
                                    </span>
                                    <span x-show="form.type === @js($key)" x-cloak
                                        class="absolute top-3 right-3 grid size-5 place-items-center rounded-full bg-brand-600 text-white">
                                        <x-lucide-check class="size-3" />
                                    </span>
                                </button>
                            @endforeach
                        </div>
                        <p x-show="error('type')" x-text="error('type')" x-cloak class="text-[11px] font-medium text-red-600"></p>
                    </section>

                    {{-- Periodo y alcance --}}
                    <section class="space-y-3 border-t border-stone-200 pt-5">
                        <div class="flex items-center gap-3">
                            <span class="{{ $sectionIcon }}"><x-lucide-calendar-range class="size-4" /></span>
                            <div class="flex-1">
                                <h3 class="{{ $sectionTitle }}">Periodo y alcance</h3>
                                <p class="{{ $sectionHint }}">Solo se cambian las programaciones de hoy en adelante.</p>
                            </div>
                            <span x-show="rangeDays" x-cloak class="rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-medium text-stone-600 tabular-nums"
                                x-text="rangeDays === 1 ? '1 día' : rangeDays + ' días'"></span>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Fecha de inicio" name="date_from" required live>
                                <input id="date_from" type="date" x-model="form.date_from" class="field" :class="error('date_from') && 'field-error'">
                            </x-field>
                            <x-field label="Fecha de fin" name="date_to" required live>
                                <input id="date_to" type="date" x-model="form.date_to" :min="form.date_from" class="field" :class="error('date_to') && 'field-error'">
                            </x-field>
                        </div>
                        <div>
                            <div class="mb-1.5 flex items-center justify-between">
                                <span class="label mb-0">Zonas</span>
                                <button type="button" x-show="form.zone_ids.length" x-cloak @click="form.zone_ids = []"
                                    class="cursor-pointer text-[11px] font-medium text-brand-700 hover:underline">Quitar selección</button>
                            </div>
                            <div class="flex flex-wrap gap-1.5 rounded-lg border border-dashed border-stone-300 p-2">
                                <template x-for="zone in catalogs.zones" :key="zone.id">
                                    <button type="button" @click="toggleZone(zone.id)" :aria-pressed="form.zone_ids.includes(zone.id)"
                                        class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-[12px] font-medium transition"
                                        :class="form.zone_ids.includes(zone.id)
                                            ? 'border-brand-600 bg-brand-600 text-white'
                                            : 'border-stone-200 bg-white text-stone-600 hover:border-stone-300 hover:bg-stone-50'">
                                        <x-lucide-map-pin class="size-3.5" />
                                        <span x-text="zone.name"></span>
                                    </button>
                                </template>
                            </div>
                            <p class="mt-1.5 flex items-center gap-1.5 text-[11px] text-stone-500">
                                <x-lucide-info class="size-3.5 shrink-0" />
                                <span x-text="form.zone_ids.length
                                    ? 'Se aplicará en ' + form.zone_ids.length + (form.zone_ids.length === 1 ? ' zona.' : ' zonas.')
                                    : 'Sin zonas marcadas se aplica en todas las zonas.'"></span>
                            </p>
                        </div>
                    </section>

                    {{-- Recurso: lo que sale y lo que entra --}}
                    <section class="space-y-3 border-t border-stone-200 pt-5">
                        <div class="flex items-center gap-3">
                            <span class="{{ $sectionIcon }}"><x-lucide-repeat class="size-4" /></span>
                            <div>
                                <h3 class="{{ $sectionTitle }}" x-text="'¿Qué ' + noun(1) + ' se reemplaza?'"></h3>
                                <p class="{{ $sectionHint }}"
                                    x-text="form.type === 'vehiculo' ? 'Como nuevo solo se ofrecen vehículos operativos.' : (form.type === 'turno' ? 'Las programaciones pasarán al turno nuevo.' : 'Como nuevo solo se ofrece personal activo con contrato vigente.')"></p>
                            </div>
                        </div>
                        <div class="grid items-stretch gap-3 sm:grid-cols-[1fr_auto_1fr]">
                            <div class="rounded-xl border border-stone-200 bg-stone-50 p-3.5">
                                <label for="old_id" class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-stone-500 uppercase">
                                    <x-lucide-circle-minus class="size-3.5 text-red-500" />
                                    <span x-text="noun() + ' actual'"></span>
                                    <span class="text-brand-600">*</span>
                                </label>
                                <select id="old_id" x-model="form.old_id" @change="if (form.new_id === form.old_id) form.new_id = ''"
                                    class="field mt-2 bg-white" :class="error('old_id') && 'field-error'">
                                    <option value="" x-text="'Selecciona el ' + noun(1)"></option>
                                    <template x-for="item in oldOptions" :key="item.id">
                                        <option :value="String(item.id)" x-text="item.label"></option>
                                    </template>
                                </select>
                                <p x-show="error('old_id')" x-text="error('old_id')" x-cloak class="mt-1.5 text-[11px] font-medium text-red-600"></p>
                            </div>
                            <div class="flex items-center justify-center">
                                <span class="grid size-9 place-items-center rounded-full border border-stone-200 bg-white text-stone-400 shadow-sm">
                                    <x-lucide-arrow-right class="hidden size-4 sm:block" />
                                    <x-lucide-arrow-down class="size-4 sm:hidden" />
                                </span>
                            </div>
                            <div class="rounded-xl border border-brand-200 bg-brand-50/50 p-3.5">
                                <label for="new_id" class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-brand-700 uppercase">
                                    <x-lucide-circle-plus class="size-3.5" />
                                    <span x-text="'Nuevo ' + noun(1)"></span>
                                    <span>*</span>
                                </label>
                                <select id="new_id" x-model="form.new_id" class="field mt-2 bg-white" :class="error('new_id') && 'field-error'">
                                    <option value="" x-text="'Selecciona el nuevo ' + noun(1)"></option>
                                    <template x-for="item in newOptions" :key="item.id">
                                        <option :value="String(item.id)" x-text="item.label"></option>
                                    </template>
                                </select>
                                <p x-show="error('new_id')" x-text="error('new_id')" x-cloak class="mt-1.5 text-[11px] font-medium text-red-600"></p>
                            </div>
                        </div>
                    </section>

                    {{-- Motivo --}}
                    <section class="space-y-3 border-t border-stone-200 pt-5">
                        <div class="flex items-center gap-3">
                            <span class="{{ $sectionIcon }}"><x-lucide-message-square-text class="size-4" /></span>
                            <div>
                                <h3 class="{{ $sectionTitle }}">Motivo del cambio</h3>
                                <p class="{{ $sectionHint }}">Queda en el historial de cada programación modificada.</p>
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Motivo" name="reason" required live hint="De Gestión de cambios › Motivos.">
                                <select id="reason" x-model="form.reason" class="field" :class="error('reason') && 'field-error'">
                                    <option value="">Selecciona un motivo</option>
                                    <template x-for="reason in reasonOptions" :key="reason.name">
                                        <option :value="reason.name" x-text="reason.name"></option>
                                    </template>
                                </select>
                            </x-field>
                            <x-field label="Detalle del motivo" name="description" live>
                                <input id="description" type="text" maxlength="500" x-model="form.description" class="field"
                                    :class="error('description') && 'field-error'"
                                    :placeholder="needsDescription ? 'Obligatorio con este motivo' : 'Opcional: complementa el motivo'">
                            </x-field>
                        </div>
                        <div class="flex gap-3 rounded-lg border-l-4 px-4 py-3"
                            :class="fullDescription ? 'border-brand-500 bg-brand-50/50' : 'border-stone-200 bg-stone-50'">
                            <x-lucide-quote class="mt-0.5 size-4 shrink-0" ::class="fullDescription ? 'text-brand-600' : 'text-stone-300'" />
                            <div>
                                <p class="{{ $term }}">Descripción completa del cambio</p>
                                <p class="mt-0.5 text-[13px]" :class="fullDescription ? 'text-stone-800' : 'text-stone-400'"
                                    x-text="fullDescription || 'Se completa sola con el motivo y su detalle.'"></p>
                            </div>
                        </div>
                    </section>

                    <p x-show="failure" x-text="failure" class="rounded-md bg-red-50 px-3 py-2 text-[12px] font-medium text-red-700" role="alert"></p>
                </x-modal.body>
                <x-modal.footer class="items-center">
                    <p class="mr-auto hidden text-[12px] text-stone-500 sm:block">Al guardar verás el resumen; nada se aplica hasta confirmar.</p>
                    <button type="button" class="btn btn-secondary" @click="close()">Cancelar</button>
                    <button type="button" class="btn btn-primary" :disabled="busy" @click="review()">
                        <x-lucide-loader-circle class="size-3.5 animate-spin" x-show="busy" x-cloak />
                        <x-lucide-save class="size-3.5" x-show="!busy" />
                        Guardar
                    </button>
                </x-modal.footer>
            </div>
        </template>

        {{-- Paso 2: resumen para confirmar --}}
        <template x-if="step === 'summary' && summary">
            <div>
                <x-modal.body class="max-h-[68vh] space-y-5 overflow-y-auto">
                    {{-- Cifras, como los mosaicos del Dashboard --}}
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="relative overflow-hidden rounded-xl bg-brand-600 px-5 py-4 text-white shadow-sm">
                            <x-lucide-circle-check class="absolute -right-2 -bottom-3 size-20 text-white/15" />
                            <p class="relative text-3xl font-bold tabular-nums" x-text="summary.applies"></p>
                            <p class="relative mt-1 text-[13px] font-medium text-white/90"
                                x-text="summary.applies === 1 ? 'Programación se modificará' : 'Programaciones se modificarán'"></p>
                        </div>
                        <div class="relative overflow-hidden rounded-xl px-5 py-4 shadow-sm"
                            :class="summary.skipped ? 'bg-amber-500 text-white' : 'bg-white text-stone-500 ring-1 ring-stone-200'">
                            <x-lucide-circle-slash class="absolute -right-2 -bottom-3 size-20" ::class="summary.skipped ? 'text-white/20' : 'text-stone-100'" />
                            <p class="relative text-3xl font-bold tabular-nums" x-text="summary.skipped"></p>
                            <p class="relative mt-1 text-[13px] font-medium" x-text="summary.skipped === 1 ? 'Se omitirá' : 'Se omitirán'"></p>
                        </div>
                        <div class="relative overflow-hidden rounded-xl bg-white px-5 py-4 text-stone-700 shadow-sm ring-1 ring-stone-200">
                            <x-lucide-calendar-range class="absolute -right-2 -bottom-3 size-20 text-stone-100" />
                            <p class="relative text-3xl font-bold tabular-nums" x-text="summaryDays"></p>
                            <p class="relative mt-1 text-[13px] font-medium text-stone-500" x-text="summaryDays === 1 ? 'Día en el rango' : 'Días en el rango'"></p>
                        </div>
                    </div>

                    {{-- La operación --}}
                    <section class="overflow-hidden rounded-xl border border-stone-200">
                        <header class="flex items-center gap-2 border-b border-stone-200 bg-stone-50 px-4 py-2.5">
                            <x-lucide-clipboard-list class="size-4 text-stone-500" />
                            <h3 class="text-[13px] font-semibold text-stone-900">Resumen de la operación</h3>
                            <x-badge tone="blue" class="ml-auto" x-text="summary.type"></x-badge>
                        </header>
                        <div class="grid gap-0 md:grid-cols-[1fr_1.3fr]">
                            <dl class="space-y-3 border-b border-stone-200 p-4 md:border-r md:border-b-0">
                                <div class="flex gap-3">
                                    <x-lucide-calendar-days class="mt-0.5 size-4 shrink-0 text-stone-400" />
                                    <div>
                                        <dt class="{{ $term }}">Periodo</dt>
                                        <dd class="mt-0.5 text-[13px] font-medium text-stone-900 tabular-nums"
                                            x-text="shortDate(summary.date_from) + ' → ' + shortDate(summary.date_to)"></dd>
                                        <dd x-show="summary.from_today" class="mt-0.5 text-[11px] text-stone-500">Los días anteriores a hoy no se cambian.</dd>
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <x-lucide-map-pin class="mt-0.5 size-4 shrink-0 text-stone-400" />
                                    <div>
                                        <dt class="{{ $term }}">Ámbito de aplicación</dt>
                                        <dd class="mt-0.5 text-[13px] font-medium text-stone-900" x-text="summary.zones"></dd>
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <x-lucide-message-square-text class="mt-0.5 size-4 shrink-0 text-stone-400" />
                                    <div>
                                        <dt class="{{ $term }}">Motivo</dt>
                                        <dd class="mt-0.5 text-[13px] text-stone-800" x-text="[summary.reason, summary.description].filter(Boolean).join(' - ')"></dd>
                                    </div>
                                </div>
                            </dl>
                            <div class="flex flex-col justify-center gap-2 p-4">
                                <div class="flex items-center gap-3 rounded-lg border border-red-100 bg-red-50/60 px-3.5 py-2.5">
                                    <x-lucide-circle-minus class="size-4 shrink-0 text-red-500" />
                                    <div class="min-w-0">
                                        <p class="{{ $term }}">Sale</p>
                                        <p class="truncate text-[13px] font-medium text-stone-600 line-through decoration-red-300" x-text="summary.old"></p>
                                    </div>
                                </div>
                                <x-lucide-arrow-down class="mx-auto size-4 text-stone-300" />
                                <div class="flex items-center gap-3 rounded-lg border border-brand-200 bg-brand-50 px-3.5 py-2.5">
                                    <x-lucide-circle-plus class="size-4 shrink-0 text-brand-600" />
                                    <div class="min-w-0">
                                        <p class="{{ $term }}">Entra</p>
                                        <p class="truncate text-[13px] font-semibold text-brand-800" x-text="summary.new"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Día por día --}}
                    <section x-show="summary.rows.length" class="overflow-hidden rounded-xl border border-stone-200">
                        <header class="flex flex-wrap items-center gap-2 border-b border-stone-200 px-4 py-2.5">
                            <h3 class="text-[13px] font-semibold text-stone-900">Programaciones del rango</h3>
                            <div class="ml-auto flex gap-1 rounded-lg bg-stone-100 p-0.5 text-[12px]">
                                @foreach (['all' => 'Todas', 'ok' => 'Se modifican', 'skipped' => 'Se omiten'] as $key => $label)
                                    <button type="button" @click="rowFilter = @js($key)"
                                        class="cursor-pointer rounded-md px-2.5 py-1 font-medium transition"
                                        :class="rowFilter === @js($key) ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'">
                                        {{ $label }}
                                        <span class="tabular-nums text-stone-400"
                                            x-text="{{ $key === 'all' ? 'summary.rows.length' : ($key === 'ok' ? 'summary.applies' : 'summary.skipped') }}"></span>
                                    </button>
                                @endforeach
                            </div>
                        </header>
                        <ul class="max-h-60 divide-y divide-stone-100 overflow-y-auto">
                            <template x-for="row in filteredRows" :key="row.id">
                                <li class="flex items-start gap-3 px-4 py-2.5">
                                    <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
                                        :class="row.problem ? 'bg-amber-100 text-amber-700' : 'bg-brand-100 text-brand-700'">
                                        <x-lucide-check class="size-3.5" x-show="!row.problem" />
                                        <x-lucide-minus class="size-3.5" x-show="row.problem" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-x-2 text-[13px]">
                                            <span class="font-mono font-medium text-stone-900" x-text="row.code"></span>
                                            <span class="text-stone-500" x-text="row.date + ' · ' + row.zone + ' · ' + row.schedule"></span>
                                        </p>
                                        <p x-show="row.problem" class="mt-0.5 text-[12px] text-amber-800" x-text="row.problem"></p>
                                    </div>
                                    <x-badge tone="green" x-show="!row.problem">Se modifica</x-badge>
                                    <x-badge tone="amber" x-show="row.problem">Se omite</x-badge>
                                </li>
                            </template>
                            <li x-show="!filteredRows.length" class="px-4 py-6 text-center text-[12px] text-stone-500">No hay programaciones en este filtro.</li>
                        </ul>
                    </section>
                    <div x-show="!summary.rows.length" class="rounded-xl border border-dashed border-stone-300 px-4 py-8 text-center">
                        <x-lucide-search-x class="mx-auto size-6 text-stone-300" />
                        <p class="mt-2 text-[13px] font-medium text-stone-700">No hay nada que cambiar</p>
                        <p class="mt-0.5 text-[12px] text-stone-500">Ninguna programación vigente del rango tiene lo que se quiere reemplazar.</p>
                    </div>

                    {{-- Confirmación --}}
                    <div x-show="summary.applies" class="overflow-hidden rounded-xl border border-amber-300">
                        <div class="flex items-start gap-3 bg-amber-50 px-4 py-3">
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-amber-100 text-amber-700">
                                <x-lucide-triangle-alert class="size-4" />
                            </span>
                            <div class="text-[13px] text-amber-900">
                                <p class="font-semibold">Esta operación es irreversible</p>
                                <p class="mt-0.5">
                                    Se modificarán <strong x-text="summary.applies"></strong>
                                    <span x-text="summary.applies === 1 ? 'programación registrada' : 'programaciones registradas'"></span>
                                    en el rango seleccionado. Cada cambio quedará en el historial y la programación pasará a Reprogramada.
                                </p>
                            </div>
                        </div>
                        <label class="flex cursor-pointer items-center gap-2.5 border-t border-amber-200 bg-white px-4 py-3 text-[13px] text-stone-800 transition hover:bg-stone-50">
                            <input type="checkbox" x-model="confirmed" class="size-4 shrink-0 accent-brand-600">
                            Confirmo que aplicaré estos cambios a las programaciones del rango seleccionado.
                        </label>
                    </div>

                    <p x-show="failure" x-text="failure" class="rounded-md bg-red-50 px-3 py-2 text-[12px] font-medium text-red-700" role="alert"></p>
                </x-modal.body>
                <x-modal.footer class="items-center">
                    <button type="button" class="btn btn-secondary mr-auto" :disabled="busy" @click="back()">
                        <x-lucide-arrow-left class="size-3.5" />
                        Volver a editar
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="busy" @click="close()">Cancelar</button>
                    <button type="button" class="btn btn-primary" :disabled="!confirmed || !summary.applies || busy" @click="apply()">
                        <x-lucide-loader-circle class="size-3.5 animate-spin" x-show="busy" x-cloak />
                        <x-lucide-circle-check class="size-3.5" x-show="!busy" />
                        <span x-text="summary.applies ? 'Confirmar y aplicar ' + summary.applies : 'Confirmar'"></span>
                    </button>
                </x-modal.footer>
            </div>
        </template>
    </x-modal>
</div>
