{{-- W10 Vacaciones. Toda solicitud entra pendiente y se aprueba o rechaza desde la tabla. --}}
@php
    $summary = $vacationSummary;
    $years = range(now()->year + 1, now()->year - 3);
@endphp

<div class="space-y-5" x-data="crud({
    name: 'vacation',
    storeUrl: @js(route('vacations.store', $person)),
    updateUrl: @js(route('vacations.update', [$person, '__ID__'])),
    destroyUrl: @js(route('vacations.destroy', [$person, '__ID__'])),
    defaults: { year: @js((string) $summary['year']), start_date: '', end_date: '', notes: '' },
    old: @js(session()->getOldInput()),
    errors: @js($errors->getMessages()),
})">
    @unless ($summary['eligible'])
        <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] font-medium text-amber-800">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" />
            Solo el personal nombrado o con contrato permanente vigente puede registrar vacaciones.
        </p>
    @endunless

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <x-stat-card label="Días por año" :value="$summary['max_days']" tone="slate" />
        <x-stat-card label="Días programados" :value="$summary['scheduled_days']" tone="brand" />
        <x-stat-card label="Por aprobar" :value="$summary['requested_days']" tone="amber" />
        <x-stat-card label="Días disponibles" :value="$summary['pending_days']" tone="slate" />
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

    {{-- Rechazar pide confirmación porque no tiene vuelta atrás. --}}
    <div x-data="{
        toReject: null,
        get rejectAction() {
            return this.toReject ? @js(route('vacations.reject', [$person, '__ID__'])).replace('__ID__', this.toReject.id) : ''
        },
    }">
        <x-card>
            <x-card-header title="Períodos registrados"
                description="Las solicitudes entran pendientes. Solo las aprobadas bloquean a la persona en el módulo de programación.">
                <button type="button" class="btn btn-primary btn-sm" @click="create()" @disabled(! $summary['eligible'])>
                    <x-lucide-plus class="size-3.5" />
                    Registrar vacaciones
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
                            <th>Nota</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vacations as $vacation)
                            @php
                                $range = $vacation->start_date->format('d/m/Y').' – '.$vacation->end_date->format('d/m/Y');
                            @endphp
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
                                <td class="max-w-56">
                                    @if ($vacation->notes)
                                        <span class="block truncate" title="{{ $vacation->notes }}">{{ $vacation->notes }}</span>
                                    @else
                                        <span class="text-stone-400">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        @if ($vacation->isPending())
                                            <form method="POST" action="{{ route('vacations.approve', [$person, $vacation]) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="icon-btn icon-btn-success" title="Aprobar"
                                                    aria-label="Aprobar vacaciones {{ $range }}">
                                                    <x-lucide-check class="size-3.5" />
                                                </button>
                                            </form>
                                            <button type="button" class="icon-btn icon-btn-danger" title="Rechazar"
                                                aria-label="Rechazar vacaciones {{ $range }}"
                                                @click="toReject = @js(['id' => $vacation->id, 'label' => $range])">
                                                <x-lucide-x class="size-3.5" />
                                            </button>
                                            <button type="button" class="icon-btn" title="Editar"
                                                aria-label="Editar vacaciones {{ $range }}"
                                                @click="edit(@js([
                                                    'id' => $vacation->id,
                                                    'year' => (string) $vacation->year,
                                                    'start_date' => $vacation->start_date->toDateString(),
                                                    'end_date' => $vacation->end_date->toDateString(),
                                                    'notes' => $vacation->notes,
                                                ]))">
                                                <x-lucide-pencil class="size-3.5" />
                                            </button>
                                        @endif
                                        @if ($blocker = $vacation->deletionBlocker())
                                            <span class="grid size-7 place-items-center text-stone-300" title="{{ $blocker }}">
                                                <x-lucide-lock class="size-3.5" />
                                            </span>
                                        @else
                                            <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                                aria-label="Eliminar vacaciones {{ $range }}"
                                                @click="askDelete(@js(['id' => $vacation->id, 'label' => $range]))">
                                                <x-lucide-trash-2 class="size-3.5" />
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="7" message="Esta persona todavía no tiene vacaciones registradas" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-modal show="toReject" close="toReject = null" size="sm" title="Rechazar solicitud de vacaciones"
            description-expr="toReject?.label">
            <form method="POST" :action="rejectAction">
                @csrf
                @method('PATCH')
                <x-modal.body>
                    <p class="text-[13px] text-stone-600">
                        Una solicitud rechazada ya no se puede editar, eliminar ni aprobar. Sus días vuelven al saldo
                        disponible del período.
                    </p>
                </x-modal.body>
                <x-modal.footer>
                    <button type="button" class="btn btn-secondary" @click="toReject = null">Cancelar</button>
                    <button type="submit" class="btn bg-red-600 text-white hover:bg-red-700 active:bg-red-800">Rechazar</button>
                </x-modal.footer>
            </form>
        </x-modal>
    </div>

    {{--
        Los días se calculan del rango, con ambos extremos incluidos. El saldo
        de las tarjetas es del período elegido arriba; al editar, los días de la
        propia solicitud vuelven a estar disponibles. Para otro período el
        servidor hace la cuenta.
    --}}
    <div x-data="{
        get days() {
            const { start_date: from, end_date: to } = form
            if (!from || !to || to < from) return 0
            return Math.round((Date.parse(to) - Date.parse(from)) / 86400000) + 1
        },
        summaryYear: @js((string) $summary['year']),
        ownDays: @js($vacations->where('year', $summary['year'])->pluck('days', 'id')),
        get available() {
            return {{ $summary['pending_days'] }} + (editingId ? (this.ownDays[editingId] ?? 0) : 0)
        },
        get exceeds() { return form.year === this.summaryYear && this.days > this.available },
    }">
        <x-crud-form name="vacation" title-new="Registrar vacaciones" title-edit="Editar solicitud de vacaciones">
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
                    x-text="error('days') || ('Solo quedan ' + available + ' días disponibles')"></p>
                <p x-show="!(error('days') || exceeds)" class="mt-1.5 text-[11px] text-stone-500">Se calcula del rango elegido</p>
            </div>

            <x-field label="Nota" name="notes" live hint="Opcional · máximo 500 caracteres">
                <textarea id="notes" name="notes" rows="3" maxlength="500" x-model="form.notes" class="field"
                    :class="error('notes') && 'field-error'"></textarea>
            </x-field>

            <p class="flex items-start gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-2.5 text-[12px] text-stone-600">
                <x-lucide-info class="mt-0.5 size-3.5 shrink-0 text-stone-400" />
                Máximo {{ $summary['max_days'] }} días por año. Quedan {{ $summary['pending_days'] }} disponibles en
                {{ $summary['year'] }}. La solicitud queda pendiente hasta que se apruebe o rechace.
            </p>
        </x-crud-form>
    </div>

    <x-confirm-delete title="Eliminar período de vacaciones">
        Se borra el registro y sus días vuelven al saldo disponible del período.
    </x-confirm-delete>
</div>
