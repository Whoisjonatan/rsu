{{-- W10 Vacaciones. --}}
@php
    $summary = $vacationSummary;
    $years = range(now()->year + 1, now()->year - 3);
@endphp

<div class="space-y-5" x-data="crud({
    name: 'vacation',
    storeUrl: @js(route('vacations.store', $person)),
    updateUrl: @js(route('vacations.update', [$person, '__ID__'])),
    destroyUrl: @js(route('vacations.destroy', [$person, '__ID__'])),
    defaults: { year: @js((string) $summary['year']), start_date: '', end_date: '' },
    old: @js(session()->getOldInput()),
    errors: @js($errors->getMessages()),
})">
    @unless ($summary['eligible'])
        <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] font-medium text-amber-800">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" />
            Solo el personal nombrado o con contrato permanente vigente puede registrar vacaciones.
        </p>
    @endunless

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Días por año" :value="$summary['max_days']" tone="slate" />
        <x-stat-card label="Días programados" :value="$summary['scheduled_days']" tone="brand" />
        <x-stat-card label="Días pendientes" :value="$summary['pending_days']" tone="amber" />
        <x-card class="px-5 py-4">
            <p class="text-[11px] font-semibold tracking-wide text-stone-500 uppercase">Período</p>
            <form method="GET" x-data>
                <input type="hidden" name="tab" value="vacaciones">
                <select name="year" class="field mt-2 w-auto text-lg font-bold" aria-label="Período de vacaciones"
                    @change="$el.form.requestSubmit()">
                    @foreach ($years as $option)
                        <option value="{{ $option }}" @selected($option === $summary['year'])>{{ $option }}</option>
                    @endforeach
                </select>
            </form>
        </x-card>
    </div>

    <x-card>
        <x-card-header title="Períodos registrados"
            description="Las vacaciones programadas bloquean a la persona en el módulo de programación.">
            <button type="button" class="btn btn-primary btn-sm" @click="create()" @disabled(! $summary['eligible'])>
                <x-lucide-plus class="size-3.5" />
                Programar vacaciones
            </button>
        </x-card-header>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Período</th>
                        <th>Desde</th>
                        <th>Hasta</th>
                        <th class="text-right">Días</th>
                        <th>Estado</th>
                        <th class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vacations as $vacation)
                        <tr>
                            <td><span class="font-medium text-stone-900">{{ $vacation->year }}</span></td>
                            <td>{{ $vacation->start_date->format('d/m/Y') }}</td>
                            <td>{{ $vacation->end_date->format('d/m/Y') }}</td>
                            <td class="text-right tabular-nums">{{ $vacation->days }}</td>
                            <td>
                                <x-badge :tone="Status::tone('vacation', $vacation->status)">
                                    {{ Status::label('vacation', $vacation->status) }}
                                </x-badge>
                            </td>
                            <td>
                                <div class="flex justify-end">
                                    <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                        aria-label="Eliminar período"
                                        @click="askDelete(@js(['id' => $vacation->id, 'label' => $vacation->start_date->format('d/m/Y').' – '.$vacation->end_date->format('d/m/Y')]))">
                                        <x-lucide-trash-2 class="size-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" message="Esta persona todavía no tiene vacaciones registradas" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Los días se calculan del rango, con ambos extremos incluidos. --}}
    <div x-data="{
        get days() {
            const { start_date: from, end_date: to } = form
            if (!from || !to || to < from) return 0
            return Math.round((Date.parse(to) - Date.parse(from)) / 86400000) + 1
        },
        pending: {{ $summary['pending_days'] }},
        get exceeds() { return this.days > this.pending },
    }">
        <x-crud-form name="vacation" title-new="Programar vacaciones" title-edit="Editar vacaciones" submit-label="Programar">
            <x-field label="Período" name="year" required live>
                <select id="year" name="year" x-model="form.year" class="field" :class="error('year') && 'field-error'">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Desde" name="start_date" required live>
                    <input id="start_date" name="start_date" type="date" x-model="form.start_date" required class="field"
                        :class="error('start_date') && 'field-error'">
                </x-field>
                <x-field label="Hasta" name="end_date" required live>
                    <input id="end_date" name="end_date" type="date" x-model="form.end_date" :min="form.start_date"
                        required class="field" :class="error('end_date') && 'field-error'">
                </x-field>
            </div>

            <div>
                <label for="days" class="label">Días solicitados</label>
                <input id="days" :value="days || ''" readonly class="field max-w-28"
                    :class="(exceeds || error('days')) && 'field-error'">
                <p x-show="error('days') || exceeds" x-cloak class="mt-1.5 text-[11px] font-medium text-red-600"
                    x-text="error('days') || ('Solo quedan ' + pending + ' días pendientes')"></p>
                <p x-show="!(error('days') || exceeds)" class="mt-1.5 text-[11px] text-stone-500">Se calcula del rango elegido</p>
            </div>

            <p class="flex items-start gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-2.5 text-[12px] text-stone-600">
                <x-lucide-info class="mt-0.5 size-3.5 shrink-0 text-stone-400" />
                Máximo {{ $summary['max_days'] }} días por año. Quedan {{ $summary['pending_days'] }} pendientes en
                {{ $summary['year'] }}.
            </p>
        </x-crud-form>
    </div>

    <x-confirm-delete title="Eliminar período de vacaciones">
        Los días vuelven al saldo pendiente del período.
    </x-confirm-delete>
</div>
