{{-- Modificar programación: cambios de turno, vehículo y personal. Estado en resources/js/components/scheduling-changes.js. --}}
@php
    // Por sección, de Gestión de cambios › Motivos.
    $reasons = \App\Models\ChangeReason::options();

    $original = [
        'schedule_id' => (string) $scheduling->schedule_id,
        'vehicle_id' => (string) $scheduling->vehicle_id,
        'driver_id' => (string) $scheduling->driver_id,
        'helper_ids' => array_map('strval', $scheduling->helperIds()),
    ];

    $catalogs = [
        'schedules' => $schedules->map->only('id', 'label'),
        'vehicles' => $vehicles->map(fn ($vehicle) => [
            'id' => $vehicle->id,
            'label' => trim($vehicle->plate.' · '.$vehicle->brand?->name.' '.$vehicle->model?->name),
            'operational' => $vehicle->status === 'operativo',
            'seats' => $vehicle->helperSeats(),
            'crew' => $vehicle->crewLabel(),
        ]),
        'drivers' => $drivers->map->only('id', 'full_name'),
        'helpers' => $helpers->map->only('id', 'full_name'),
    ];

    // Si el servidor rechazó los cambios, vuelven a la tabla para corregirlos.
    $pending = collect(old('changes', []))->map(fn ($change) => [
        'type' => (string) ($change['type'] ?? ''),
        'old_id' => (string) ($change['old_id'] ?? ''),
        'new_id' => (string) ($change['new_id'] ?? ''),
        'reason' => (string) ($change['reason'] ?? ''),
        'description' => (string) ($change['description'] ?? ''),
    ])->values();

    $history = $scheduling->history->sortByDesc('id')->map(fn ($change) => [
        'id' => $change->id,
        'type' => $change->typeLabel(),
        'old' => $change->old_value ?? '—',
        'new' => $change->new_value,
        'reason' => $change->reason,
        'description' => $change->description,
        'date' => $change->created_at->format('d/m/Y H:i'),
        'user' => $change->user?->full_name ?? '—',
    ])->values();

    $serverErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => str_starts_with($key, 'changes'))
        ->flatten()
        ->unique();
@endphp

<x-layouts.app :title="'Modificar '.$scheduling->code" subtitle="Programación › Cambios de turno, vehículo o personal">
    <x-slot:actions>
        <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <form method="POST" action="{{ route('schedulings.update', $scheduling) }}" class="space-y-4"
        x-data="schedulingChanges({ original: @js($original), catalogs: @js($catalogs), reasons: @js($reasons), pending: @js($pending), history: @js($history) })"
        @submit="submit($event)">
        @csrf
        @method('PUT')

        <x-card>
            <x-card-header :title="'Programación '.$scheduling->code"
                description="Los cambios se aplican solo a este día, y a su recorrido si todavía no inició. Los demás días de la programación general no cambian.">
                <x-badge :tone="Status::tone('scheduling', $scheduling->status)">{{ Status::label('scheduling', $scheduling->status) }}</x-badge>
            </x-card-header>
            <dl class="grid gap-4 p-5 text-[12px] sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-stone-500">Grupo</dt>
                    <dd class="font-medium text-stone-800">{{ $scheduling->staffGroup?->name ?? 'Sin grupo' }}</dd>
                </div>
                <div>
                    <dt class="text-stone-500">Zona</dt>
                    <dd class="font-medium text-stone-800">{{ $scheduling->zone?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-stone-500">Fecha</dt>
                    <dd class="font-medium text-stone-800">{{ $scheduling->dateLabel() }}</dd>
                </div>
                <div>
                    <dt class="text-stone-500">Programación general</dt>
                    <dd class="font-mono font-medium text-stone-800">{{ $scheduling->general?->code ?? '—' }}</dd>
                </div>
            </dl>
        </x-card>

        @if ($serverErrors->isNotEmpty())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[12px] text-red-700" role="alert">
                <p class="flex items-center gap-2 font-semibold">
                    <x-lucide-circle-alert class="size-4" />
                    No se guardó: corrige estos cambios.
                </p>
                <ul class="mt-1.5 list-disc space-y-0.5 pl-6">
                    @foreach ($serverErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- Cambio de turno --}}
            <x-card>
                <x-card-header title="Cambio de turno" />
                <div class="space-y-3 p-5">
                    <div>
                        <p class="label">Turno actual</p>
                        <p class="rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-[13px] text-stone-700" x-text="currentSchedule"></p>
                    </div>
                    <x-field label="Nuevo turno" for="turno_new" required>
                        <select id="turno_new" x-model="forms.turno.new_id" class="field">
                            <option value="">Selecciona un turno</option>
                            <template x-for="item in scheduleOptions" :key="item.id">
                                <option :value="item.id" x-text="item.label"></option>
                            </template>
                        </select>
                    </x-field>
                    <x-field label="Motivo predefinido" for="turno_reason" required>
                        <select id="turno_reason" x-model="forms.turno.reason" class="field">
                            <option value="">Selecciona un motivo</option>
                            @foreach ($reasons['turno'] as $reason)
                                <option value="{{ $reason['name'] }}">{{ $reason['name'] }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field label="Motivo del cambio" for="turno_description">
                        <textarea id="turno_description" rows="2" maxlength="500" x-model="forms.turno.description" class="field"
                            :placeholder="needsDescription('turno', forms.turno.reason) ? 'Obligatorio con este motivo' : 'Opcional'"></textarea>
                    </x-field>
                    <p x-show="formErrors.turno" x-text="formErrors.turno" x-cloak class="text-[11px] font-medium text-red-600"></p>
                    <button type="button" class="btn btn-secondary w-full justify-center" @click="addChange('turno')">
                        <x-lucide-plus class="size-4" />
                        Agregar cambio
                    </button>
                </div>
            </x-card>

            {{-- Cambio de vehículo --}}
            <x-card>
                <x-card-header title="Cambio de vehículo" />
                <div class="space-y-3 p-5">
                    <div>
                        <p class="label">Vehículo actual</p>
                        <p class="rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-[13px] text-stone-700">
                            <span x-text="currentVehicle?.label ?? '—'"></span>
                            <span x-show="currentVehicle && !currentVehicle.operational" class="text-amber-700">(no operativo)</span>
                        </p>
                    </div>
                    <x-field label="Nuevo vehículo" for="vehiculo_new" required>
                        <select id="vehiculo_new" x-model="forms.vehiculo.new_id" class="field">
                            <option value="">Selecciona un vehículo</option>
                            <template x-for="item in vehicleOptions" :key="item.id">
                                <option :value="item.id" x-text="item.label + ' · ' + item.crew"></option>
                            </template>
                        </select>
                    </x-field>
                    <p x-show="seatsWarning" x-text="seatsWarning" x-cloak class="text-[11px] font-medium text-amber-700"></p>
                    <x-field label="Motivo predefinido" for="vehiculo_reason" required>
                        <select id="vehiculo_reason" x-model="forms.vehiculo.reason" class="field">
                            <option value="">Selecciona un motivo</option>
                            @foreach ($reasons['vehiculo'] as $reason)
                                <option value="{{ $reason['name'] }}">{{ $reason['name'] }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field label="Motivo del cambio" for="vehiculo_description">
                        <textarea id="vehiculo_description" rows="2" maxlength="500" x-model="forms.vehiculo.description" class="field"
                            :placeholder="needsDescription('vehiculo', forms.vehiculo.reason) ? 'Obligatorio con este motivo' : 'Opcional'"></textarea>
                    </x-field>
                    <p x-show="formErrors.vehiculo" x-text="formErrors.vehiculo" x-cloak class="text-[11px] font-medium text-red-600"></p>
                    <button type="button" class="btn btn-secondary w-full justify-center" @click="addChange('vehiculo')">
                        <x-lucide-plus class="size-4" />
                        Agregar cambio
                    </button>
                </div>
            </x-card>

            {{-- Cambio de personal --}}
            <x-card>
                <x-card-header title="Cambio de personal" />
                <div class="space-y-3 p-5">
                    <x-field label="Personal actual" for="personal_old" required>
                        <select id="personal_old" x-model="forms.personal.old_id" @change="forms.personal.new_id = ''" class="field">
                            <option value="">Selecciona un personal</option>
                            <template x-for="member in crew" :key="member.id">
                                <option :value="member.id" x-text="member.label"></option>
                            </template>
                        </select>
                    </x-field>
                    <x-field label="Nuevo personal" for="personal_new" required>
                        <select id="personal_new" x-model="forms.personal.new_id" class="field" :disabled="!selectedMember">
                            <option value="" x-text="selectedMember ? 'Selecciona ' + (selectedMember.role === 'conductor' ? 'un conductor' : 'un ayudante') : 'Primero elige a quién reemplazar'"></option>
                            <template x-for="person in personOptions" :key="person.id">
                                <option :value="person.id" x-text="person.full_name"></option>
                            </template>
                        </select>
                    </x-field>
                    <x-field label="Motivo predefinido" for="personal_reason" required>
                        <select id="personal_reason" x-model="forms.personal.reason" class="field">
                            <option value="">Selecciona un motivo</option>
                            @foreach ($reasons['personal'] as $reason)
                                <option value="{{ $reason['name'] }}">{{ $reason['name'] }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field label="Motivo del cambio" for="personal_description">
                        <textarea id="personal_description" rows="2" maxlength="500" x-model="forms.personal.description" class="field"
                            :placeholder="needsDescription('personal', forms.personal.reason) ? 'Obligatorio con este motivo' : 'Opcional'"></textarea>
                    </x-field>
                    <p x-show="formErrors.personal" x-text="formErrors.personal" x-cloak class="text-[11px] font-medium text-red-600"></p>
                    <button type="button" class="btn btn-secondary w-full justify-center" @click="addChange('personal')">
                        <x-lucide-plus class="size-4" />
                        Agregar cambio
                    </button>
                </div>
            </x-card>
        </div>

        <x-card>
            <x-card-header title="Cambios registrados"
                description="Los pendientes se guardan al pulsar «Guardar cambios»; la programación pasará a Reprogramada." />

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tipo de cambio</th>
                            <th>Valor anterior</th>
                            <th>Valor nuevo</th>
                            <th>Motivo</th>
                            <th>Descripción</th>
                            <th class="text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Los pendientes van siempre arriba y completos: hay que verlos todos antes de guardar. --}}
                        <template x-for="(row, index) in pendingRows" :key="index">
                            <tr class="bg-amber-50/50">
                                <td x-text="row.typeLabel"></td>
                                <td x-text="row.old ?? '—'"></td>
                                <td class="font-medium text-stone-900" x-text="row.new ?? '—'"></td>
                                <td x-text="row.reason"></td>
                                <td class="max-w-72 whitespace-normal" x-text="row.description || '—'"></td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        <x-badge tone="amber">Pendiente</x-badge>
                                        <button type="button" class="icon-btn icon-btn-danger" title="Quitar" aria-label="Quitar cambio"
                                            @click="removeChange(index)">
                                            <x-lucide-trash-2 class="size-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        {{-- Los ya guardados: historial del más reciente al más antiguo, no se quitan. --}}
                        <template x-for="change in pagedHistory" :key="change.id">
                            <tr>
                                <td x-text="change.type"></td>
                                <td x-text="change.old"></td>
                                <td class="font-medium text-stone-900" x-text="change.new"></td>
                                <td x-text="change.reason"></td>
                                <td class="max-w-72 whitespace-normal" x-text="change.description ?? '—'"></td>
                                <td class="text-right">
                                    <span x-bind:title="'Por ' + change.user">
                                        <x-badge tone="green">Aplicado</x-badge>
                                    </span>
                                    <span class="mt-0.5 block text-[11px] text-stone-400" x-text="change.date"></span>
                                </td>
                            </tr>
                        </template>

                        <tr x-show="pendingRows.length === 0 && history.length === 0">
                            <td colspan="6" class="py-10 text-center text-[13px] text-stone-500">
                                Todavía no hay cambios. Usa «Agregar cambio» en las secciones de arriba.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <x-pager page="historyPage" total="history.length" per-page="historyPerPage" />
            <p x-show="history.length > historyPerPage" x-cloak class="-mt-2 px-4 pb-3 text-[11px] text-stone-400">
                La paginación es del historial; los cambios pendientes se muestran siempre arriba.
            </p>

            <template x-for="(change, index) in pending" :key="index">
                <div>
                    <input type="hidden" :name="`changes[${index}][type]`" :value="change.type">
                    <input type="hidden" :name="`changes[${index}][old_id]`" :value="change.old_id">
                    <input type="hidden" :name="`changes[${index}][new_id]`" :value="change.new_id">
                    <input type="hidden" :name="`changes[${index}][reason]`" :value="change.reason">
                    <input type="hidden" :name="`changes[${index}][description]`" :value="change.description">
                </div>
            </template>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-stone-200 px-5 py-4">
                <p class="mr-auto text-[12px] text-stone-500"
                    x-text="pending.length === 0 ? 'Agrega al menos un cambio para poder guardar.' : pending.length + (pending.length === 1 ? ' cambio pendiente.' : ' cambios pendientes.')"></p>
                <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary" :disabled="pending.length === 0 || submitting">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="submitting" x-cloak />
                    <x-lucide-save class="size-4" x-show="!submitting" />
                    Guardar cambios
                </button>
            </div>
        </x-card>
    </form>
</x-layouts.app>
