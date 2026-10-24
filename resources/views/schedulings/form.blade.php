{{-- W18 Nueva programación (y edición individual). --}}
@php
    $value = fn (string $field, $default = '') => (string) old($field, $scheduling?->{$field} ?? $default);
    $fieldClass = fn (string $field) => $errors->has($field) ? 'field field-error' : 'field';

    // Zona, turno y vehículo no se eligen aquí: vienen del grupo de personal
    // y viajan en campos ocultos.
    $form = [
        'zone_id' => $value('zone_id'),
        'vehicle_id' => $value('vehicle_id'),
        'schedule_id' => $value('schedule_id'),
        'driver_id' => $value('driver_id'),
        'helper_ids' => array_map('strval', old('helper_ids', $scheduling?->helperIds() ?? [])),
        'staffgroup_id' => (string) old('staffgroup_id', $scheduling?->staffgroup_id ?? ($groupId ?? '')),
        'weekdays' => array_map('intval', old('weekdays', $scheduling?->weekdays ?? [1, 2, 3, 4, 5])),
        'start_date' => old('start_date', $scheduling?->start_date?->toDateString() ?? ''),
        'end_date' => old('end_date', $scheduling?->end_date?->toDateString() ?? ''),
        'status' => $value('status', 'activa'),
        'notes' => $value('notes'),
    ];

    $catalogs = [
        'zones' => $zones,
        'schedules' => $schedules->map->only('id', 'label'),
        'vehicles' => $vehicles->map(fn ($vehicle) => [
            'id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'name' => trim($vehicle->brand?->name.' '.$vehicle->model?->name),
            'operational' => $vehicle->status === 'operativo',
            'seats' => $vehicle->helperSeats(),
            'crew' => $vehicle->crewLabel(),
            'detail' => $vehicle->code.' · '.($vehicle->type?->name ?? 'Sin tipo'),
        ]),
        'drivers' => $drivers->map->only('id', 'full_name'),
        'helpers' => $helpers->map->only('id', 'full_name'),
        'groups' => $groups->map->schedulingDefaults(),
    ];

    $checks = [
        'contrato' => 'Personal con contrato vigente',
        'vehiculo' => 'Vehículo disponible',
        'duplicidad' => 'Sin duplicidad de turno',
        'vacaciones' => 'Personal sin vacaciones',
    ];

    // Los errores de lo que trae el grupo no tienen campo propio donde salir.
    $groupErrors = collect(['staffgroup_id', 'zone_id', 'schedule_id', 'vehicle_id'])
        ->flatMap(fn (string $field) => $errors->get($field));
@endphp

<x-layouts.app :title="$scheduling ? 'Programación '.$scheduling->code : 'Nueva programación'"
    subtitle="Programación › Crear o editar">
    <x-slot:actions>
        <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <form method="POST" class="grid gap-4 lg:grid-cols-[1fr_20rem]"
        action="{{ $scheduling ? route('schedulings.update', $scheduling) : route('schedulings.store') }}"
        x-data="schedulingForm({ id: @js($scheduling?->id), isNew: @js($scheduling === null), form: @js($form), catalogs: @js($catalogs) })"
        @submit="submit($event)">
        @csrf
        @if ($scheduling)
            @method('PUT')
        @endif

        <input type="hidden" name="zone_id" :value="form.zone_id">
        <input type="hidden" name="schedule_id" :value="form.schedule_id">
        <input type="hidden" name="vehicle_id" :value="form.vehicle_id">

        <x-card>
            <x-card-header title="Datos de la programación"
                description="El grupo de personal trae la zona, el turno, el vehículo y la tripulación; tú pones las fechas." />

            <div class="space-y-4 p-5">
                <div class="rounded-lg border border-brand-200 bg-brand-50/50 p-4">
                    <x-field label="Grupo de personal" for="staffgroup_id" :required="$scheduling === null">
                        <select id="staffgroup_id" name="staffgroup_id" x-model="form.staffgroup_id" @change="applyGroup(form.staffgroup_id)"
                            class="{{ $groupErrors->isNotEmpty() ? 'field field-error' : 'field' }}" @required($scheduling === null)>
                            {{-- Las programaciones de antes de los grupos se editan sin uno. --}}
                            <option value="">{{ $scheduling && ! $scheduling->staffgroup_id ? 'Sin grupo (programación anterior a los grupos)' : 'Selecciona un grupo' }}</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}{{ $group->status !== 'activo' ? ' (inactivo)' : '' }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    @if ($groups->isEmpty())
                        <p class="mt-2 text-[12px] text-stone-500">
                            No hay grupos de personal activos.
                            <a href="{{ route('staffgroups.create') }}" class="link">Crea uno</a> para poder programar.
                        </p>
                    @endif

                    <p x-show="groupLoaded" x-cloak class="mt-2 flex items-start gap-2 text-[12px] text-brand-700">
                        <x-lucide-circle-check class="mt-px size-4 shrink-0" />
                        <span x-text="groupLoaded"></span>
                    </p>

                    {{-- Lo que el grupo fija y no se cambia aquí. --}}
                    <dl x-show="form.zone_id" x-cloak class="mt-3 grid gap-3 border-t border-brand-200 pt-3 text-[12px] sm:grid-cols-3">
                        <div>
                            <dt class="text-stone-500">Zona</dt>
                            <dd class="font-medium text-stone-800" x-text="label('zones', form.zone_id) ?? '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Turno</dt>
                            <dd class="font-medium text-stone-800" x-text="label('schedules', form.schedule_id, 'label') ?? '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Vehículo</dt>
                            <dd class="font-medium text-stone-800">
                                <span class="font-mono" x-text="vehicle?.plate ?? '—'"></span>
                                <span x-show="vehicle?.name" x-text="' · ' + vehicle?.name"></span>
                                <span x-show="vehicle && !vehicle.operational" class="text-amber-700">(no operativo)</span>
                            </dd>
                            <dd x-show="vehicle" class="mt-0.5 text-[11px] text-stone-500" x-text="vehicle ? vehicle.detail + ' · ' + vehicle.crew : ''"></dd>
                        </div>
                    </dl>

                    @foreach ($groupErrors as $message)
                        <p class="mt-1.5 flex items-center gap-1 text-[11px] font-medium text-red-600">
                            <x-lucide-circle-alert class="size-3.5 shrink-0" />
                            {{ $message }}
                        </p>
                    @endforeach
                </div>

                {{-- Tripulación en una fila: conductor y un selector por asiento de ayudante. --}}
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Conductor" name="driver_id" required>
                        <select id="driver_id" name="driver_id" x-model="form.driver_id" class="{{ $fieldClass('driver_id') }}" required>
                            <option value="">Selecciona un conductor</option>
                            @foreach ($drivers as $person)
                                <option value="{{ $person->id }}">{{ $person->full_name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    {{-- Un selector por asiento: la capacidad del vehículo menos el conductor. --}}
                    <div x-show="!vehicle || seats === 0">
                        <p class="label">Ayudantes</p>
                        <p class="rounded-md border border-dashed border-stone-300 px-3 py-2.5 text-[12px] text-stone-500"
                            x-text="vehicle ? 'Este vehículo solo lleva al conductor.' : 'Elige un grupo para saber cuántos ayudantes caben.'"></p>
                    </div>

                    <template x-for="(helper, index) in form.helper_ids" :key="index">
                        <div>
                            <label :for="'helper_' + index" class="label" x-text="'Ayudante ' + (index + 1)"></label>
                            <select :id="'helper_' + index" x-model="form.helper_ids[index]"
                                class="{{ $errors->has('helper_ids') || $errors->has('helper_ids.*') ? 'field field-error' : 'field' }}">
                                <option value="">Sin ayudante</option>
                                <template x-for="person in helperOptions(index)" :key="person.id">
                                    <option :value="person.id" :selected="String(person.id) === String(form.helper_ids[index])"
                                        x-text="person.full_name"></option>
                                </template>
                            </select>
                        </div>
                    </template>
                    <template x-for="id in form.helper_ids.filter(Boolean)" :key="id">
                        <input type="hidden" name="helper_ids[]" :value="id">
                    </template>
                    @if ($errors->has('helper_ids') || $errors->has('helper_ids.*'))
                        <div class="sm:col-span-3">
                            @foreach (array_merge($errors->get('helper_ids'), ...array_values($errors->get('helper_ids.*'))) as $message)
                                <p class="text-[11px] font-medium text-red-600">{{ $message }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Fecha de inicio" name="start_date" required>
                        <input id="start_date" name="start_date" type="date" x-model="form.start_date"
                            class="{{ $fieldClass('start_date') }}" required>
                    </x-field>

                    <x-field label="Fecha de término" name="end_date" required>
                        <input id="end_date" name="end_date" type="date" x-model="form.end_date" :min="form.start_date"
                            class="{{ $fieldClass('end_date') }}" required>
                    </x-field>
                </div>

                <div class="grid gap-4 md:grid-cols-[auto_1fr]">
                    <div>
                        <span class="label">Días de la semana <span class="ml-0.5 text-brand-600">*</span></span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (\App\Models\Scheduling::WEEKDAY_LABELS as $day => $label)
                                <button type="button" :aria-pressed="form.weekdays.includes({{ $day }})"
                                    @click="toggleWeekday({{ $day }})"
                                    :class="form.weekdays.includes({{ $day }})
                                        ? 'border-brand-600 bg-brand-600 text-white'
                                        : 'border-stone-300 bg-white text-stone-400 hover:border-stone-400 hover:text-stone-600'"
                                    class="w-14 cursor-pointer rounded-md border px-3 py-2 text-[12px] font-semibold transition">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        <template x-for="day in form.weekdays" :key="day">
                            <input type="hidden" name="weekdays[]" :value="day">
                        </template>
                        @error('weekdays')
                            <p class="mt-1.5 text-[11px] font-medium text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-field label="Estado" name="status" required>
                        <select id="status" name="status" x-model="form.status" class="{{ $fieldClass('status') }}">
                            @foreach (Status::options('active') as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <x-field label="Observaciones" name="notes">
                    <textarea id="notes" name="notes" rows="3" maxlength="500" x-model="form.notes"
                        class="{{ $fieldClass('notes') }}" placeholder="Opcional"></textarea>
                </x-field>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-stone-200 px-5 py-4">
                <p x-show="saveHint" x-text="saveHint" class="mr-auto text-[12px] text-stone-500"></p>
                <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="button" class="btn btn-secondary" :disabled="!complete || checking" @click="validate()">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="checking" x-cloak />
                    <x-lucide-shield-check class="size-4" x-show="!checking" />
                    Validar disponibilidad
                </button>
                <button type="submit" class="btn btn-primary" :disabled="!canSave">
                    <x-lucide-save class="size-4" />
                    {{ $scheduling ? 'Guardar cambios' : 'Programar' }}
                </button>
            </div>
        </x-card>

        <div class="space-y-4">
            <x-card>
                <x-card-header title="Resumen" />
                <dl class="divide-y divide-stone-100 text-[13px]">
                    <template x-for="[label, text] in summary" :key="label">
                        <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                            <dt class="text-stone-500" x-text="label"></dt>
                            <dd class="text-right font-medium text-stone-800">
                                <span x-show="text" x-text="text"></span>
                                <span x-show="!text" class="text-stone-300">—</span>
                            </dd>
                        </div>
                    </template>
                </dl>
            </x-card>

            <x-card>
                <header class="border-b border-stone-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-stone-900">Disponibilidad</h2>
                    <p class="mt-0.5 text-[12px] text-stone-500"
                        x-text="validation
                            ? 'Generaría ' + validation.occurrences + (validation.occurrences === 1 ? ' recorrido' : ' recorridos')
                            : (outdated ? 'Cambiaste datos: vuelve a validar' : 'Pulsa «Validar disponibilidad»')"></p>
                </header>
                <ul class="space-y-2 p-5">
                    @foreach ($checks as $key => $label)
                        <li class="flex items-center gap-2 text-[12px]">
                            <span class="grid size-4 shrink-0 place-items-center rounded-full"
                                :class="!check('{{ $key }}') ? 'bg-stone-100 text-stone-400' : (check('{{ $key }}').ok ? 'bg-brand-100 text-brand-700' : 'bg-amber-100 text-amber-700')">
                                <x-lucide-check class="size-3" x-show="check('{{ $key }}')?.ok" x-cloak />
                                <x-lucide-x class="size-3" x-show="check('{{ $key }}') && !check('{{ $key }}').ok" x-cloak />
                            </span>
                            <span :class="check('{{ $key }}') ? 'text-stone-700' : 'text-stone-400'">{{ $label }}</span>
                        </li>
                    @endforeach
                </ul>

                {{-- Un feriado no lleva recorrido; el mismo día de la semana siguiente sí. --}}
                <div x-show="validation?.holidays?.length" x-cloak
                    class="mx-5 mb-3 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-[12px] text-blue-800">
                    <p class="flex items-start gap-2 font-medium">
                        <x-lucide-calendar-x class="mt-px size-3.5 shrink-0" />
                        <span x-text="validation?.holidays?.length === 1 ? 'Este feriado no lleva recorrido:' : 'Estos feriados no llevan recorrido:'"></span>
                    </p>
                    <ul class="mt-1.5 list-disc space-y-1 pl-5 text-[11px] text-blue-700">
                        <template x-for="holiday in validation?.holidays ?? []" :key="holiday.date">
                            <li x-text="holidayLabel(holiday)"></li>
                        </template>
                    </ul>
                </div>

                <p x-show="validation?.valid" x-cloak
                    class="mx-5 mb-5 flex items-start gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2.5 text-[12px] text-brand-700">
                    <x-lucide-circle-check class="mt-px size-3.5 shrink-0" />
                    Todo disponible: ya puedes guardar.
                </p>

                <div x-show="validation && !validation.valid" x-cloak
                    class="mx-5 mb-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-800">
                    <p class="flex items-start gap-2 font-medium">
                        <x-lucide-triangle-alert class="mt-px size-3.5 shrink-0" />
                        Corrige lo marcado y vuelve a validar.
                    </p>
                    <ul class="mt-1.5 list-disc space-y-1 pl-5 text-[11px] text-amber-700">
                        <template x-for="message in messages" :key="message">
                            <li x-text="message"></li>
                        </template>
                    </ul>
                </div>
            </x-card>
        </div>
    </form>
</x-layouts.app>
