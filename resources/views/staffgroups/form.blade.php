{{-- Grupo de personal: alta y edición. Estado y llamadas al API en resources/js/components/staff-group-form.js. --}}
@php
    $form = [
        'name' => old('name', $group?->name ?? ''),
        'schedule_id' => (string) old('schedule_id', $group?->schedule_id ?? ''),
        'zone_id' => (string) old('zone_id', $group?->zone_id ?? ''),
        'vehicle_id' => (string) old('vehicle_id', $group?->vehicle_id ?? ''),
        'weekdays' => array_map('intval', old('weekdays', $group?->weekdays ?? [1, 2, 3, 4, 5, 6])),
        'driver_id' => (string) old('driver_id', $group?->driver_id ?? ''),
        'helper_ids' => array_map('strval', old('helper_ids', $group?->helperIds() ?? [])),
        'status' => old('status', $group?->status ?? 'activo'),
    ];

    $fieldClass = fn (string $field) => $errors->has($field) ? 'field field-error' : 'field';
@endphp

<x-layouts.app :title="$group ? 'Grupo '.$group->name : 'Nuevo grupo de personal'"
    subtitle="Grupos de personal › La plantilla guarda todo lo de la programación salvo las fechas">
    <x-slot:actions>
        <a href="{{ route('staffgroups.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <form method="POST" class="grid gap-4 lg:grid-cols-[1fr_20rem]"
        action="{{ $group ? route('staffgroups.update', $group) : route('staffgroups.store') }}"
        x-data="staffGroupForm({
            id: @js($group?->id),
            form: @js($form),
            vehicles: @js($vehicles),
            people: @js($people),
            scheduleLabels: @js($schedules->pluck('label', 'id')),
        })"
        @submit="$el.querySelector('[type=submit]').disabled = true">
        @csrf
        @if ($group)
            @method('PUT')
        @endif

        <div class="space-y-4">
            <x-card>
                <x-card-header title="Datos del grupo"
                    description="El turno y los días deciden quién está disponible: elígelos antes que el personal." />

                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-field label="Nombre del grupo" name="name" required class="sm:col-span-2">
                        <input id="name" name="name" x-model="form.name" maxlength="100" required placeholder="Cuadrilla Norte · Mañana"
                            class="{{ $fieldClass('name') }}">
                    </x-field>

                    <x-field label="Turno" name="schedule_id" required>
                        <select id="schedule_id" name="schedule_id" x-model="form.schedule_id" required class="{{ $fieldClass('schedule_id') }}">
                            <option value="">Selecciona un turno</option>
                            @foreach ($schedules as $schedule)
                                <option value="{{ $schedule->id }}">{{ $schedule->label }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Zona" name="zone_id" required>
                        <select id="zone_id" name="zone_id" x-model="form.zone_id" required class="{{ $fieldClass('zone_id') }}">
                            <option value="">Selecciona una zona</option>
                            @foreach ($zones as $zone)
                                <option value="{{ $zone->id }}">{{ $zone->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <div class="sm:col-span-2">
                        <x-field label="Vehículo" name="vehicle_id" required>
                            <select id="vehicle_id" name="vehicle_id" x-model="form.vehicle_id" required class="{{ $fieldClass('vehicle_id') }}">
                                <option value="">Selecciona un vehículo</option>
                                <template x-for="vehicle in vehicles" :key="vehicle.id">
                                    <option :value="vehicle.id" :selected="String(vehicle.id) === String(form.vehicle_id)"
                                        x-text="vehicleOption(vehicle)"></option>
                                </template>
                            </select>
                        </x-field>

                        {{-- Ficha del vehículo: cuántas personas caben define los asientos de ayudante. --}}
                        <div x-show="vehicle" x-cloak class="mt-2 rounded-lg border px-4 py-3"
                            :class="vehicleStatus()?.available === false ? 'border-red-200 bg-red-50/50' : 'border-stone-200 bg-stone-50'">
                            {{-- Cuatro columnas que se reparten el ancho: capacidad, código, placa y tipo. --}}
                            <dl class="grid grid-cols-2 items-center gap-x-6 gap-y-3 sm:grid-cols-[2fr_1fr_1fr_1fr]">
                                <div class="col-span-2 flex items-center gap-2.5 sm:col-span-1">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-100 text-brand-700">
                                        <x-lucide-truck class="size-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <dt class="text-[11px] text-stone-400">Capacidad</dt>
                                        <dd class="text-[13px] font-semibold text-stone-900">
                                            <span x-text="vehicle?.capacity + (vehicle?.capacity === 1 ? ' persona' : ' personas')"></span>
                                            <span class="font-normal text-stone-500" x-text="'· ' + crewLabel"></span>
                                        </dd>
                                        <dd class="truncate text-[11px] text-stone-500" x-text="vehicle?.brand"></dd>
                                    </div>
                                </div>
                                <div>
                                    <dt class="text-[11px] text-stone-400">Código</dt>
                                    <dd class="font-mono text-[13px] font-medium text-stone-800" x-text="vehicle?.code"></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] text-stone-400">Placa</dt>
                                    <dd class="font-mono text-[13px] font-medium text-stone-800" x-text="vehicle?.plate"></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] text-stone-400">Tipo</dt>
                                    <dd class="text-[13px] font-medium text-stone-800" x-text="vehicle?.type ?? '—'"></dd>
                                </div>
                            </dl>
                            <template x-for="problem in vehicleStatus()?.problems ?? []" :key="problem">
                                <p class="mt-2 flex items-start gap-1.5 text-[12px] text-red-700">
                                    <x-lucide-circle-alert class="mt-0.5 size-3.5 shrink-0" />
                                    <span x-text="problem"></span>
                                </p>
                            </template>
                        </div>
                    </div>

                    {{-- Días y estado comparten fila en dos mitades, como turno y zona; los días se estiran a su mitad. --}}
                    <div>
                        <span class="label">Días de trabajo <span class="ml-0.5 text-brand-600">*</span></span>
                        <div class="grid grid-cols-7 gap-1.5">
                            @foreach (\App\Models\Scheduling::WEEKDAY_LABELS as $day => $label)
                                <button type="button" :aria-pressed="form.weekdays.includes({{ $day }})"
                                    @click="toggleWeekday({{ $day }})"
                                    :class="form.weekdays.includes({{ $day }})
                                        ? 'border-brand-600 bg-brand-600 text-white'
                                        : 'border-stone-300 bg-white text-stone-400 hover:border-stone-400 hover:text-stone-600'"
                                    class="w-full cursor-pointer rounded-md border px-1 py-2 text-[12px] font-semibold transition">
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

                    <x-field label="Estado" name="status" required
                        hint="Un grupo inactivo no ocupa a nadie ni se ofrece al programar.">
                        <select id="status" name="status" x-model="form.status" class="{{ $fieldClass('status') }}">
                            @foreach (Status::options('staffgroup') as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
            </x-card>

            <x-card>
                <x-card-header title="Tripulación"
                    description="Busca por DNI, nombre o apellido. Se indica si cada persona está libre en ese turno y esos días." />

                <div class="space-y-5 p-5">
                    <div>
                        <span class="label">Conductor <span class="ml-0.5 text-brand-600">*</span></span>
                        @include('staffgroups.partials.picker', ['seat' => "'driver'", 'label' => 'conductor'])
                        <input type="hidden" name="driver_id" :value="form.driver_id">
                        @error('driver_id')
                            @foreach ($errors->get('driver_id') as $message)
                                <p class="mt-1.5 text-[11px] font-medium text-red-600">{{ $message }}</p>
                            @endforeach
                        @enderror
                    </div>

                    <p x-show="!vehicle" class="rounded-lg border border-dashed border-stone-300 px-4 py-3 text-[12px] text-stone-500">
                        Elige un vehículo para saber cuántos ayudantes caben.
                    </p>
                    <p x-show="vehicle && seats === 0" x-cloak class="rounded-lg border border-dashed border-stone-300 px-4 py-3 text-[12px] text-stone-500">
                        Este vehículo solo lleva al conductor.
                    </p>

                    <template x-for="(helper, index) in form.helper_ids" :key="index">
                        <div>
                            <span class="label" x-text="'Ayudante ' + (index + 1)"></span>
                            @include('staffgroups.partials.picker', ['seat' => "'h' + index", 'label' => 'ayudante'])
                        </div>
                    </template>
                    <template x-for="id in form.helper_ids.filter(Boolean)" :key="id">
                        <input type="hidden" name="helper_ids[]" :value="id">
                    </template>
                    @if ($errors->has('helper_ids') || $errors->has('helper_ids.*'))
                        @foreach (array_merge($errors->get('helper_ids'), ...array_values($errors->get('helper_ids.*'))) as $message)
                            <p class="text-[11px] font-medium text-red-600">{{ $message }}</p>
                        @endforeach
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-stone-200 px-5 py-4">
                    <a href="{{ route('staffgroups.index') }}" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <x-lucide-save class="size-4" />
                        {{ $group ? 'Guardar cambios' : 'Registrar grupo' }}
                    </button>
                </div>
            </x-card>
        </div>

        <div class="space-y-4">
            <x-card>
                <x-card-header title="Resumen" />
                <dl class="divide-y divide-stone-100 text-[13px]">
                    <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                        <dt class="text-stone-500">Turno</dt>
                        <dd class="text-right font-medium text-stone-800" x-text="scheduleLabels[form.schedule_id] ?? '—'"></dd>
                    </div>
                    <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                        <dt class="text-stone-500">Días</dt>
                        <dd class="text-right font-medium text-stone-800" x-text="weekdaysLabel || '—'"></dd>
                    </div>
                    <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                        <dt class="text-stone-500">Vehículo</dt>
                        <dd class="text-right font-medium text-stone-800" x-text="vehicle ? vehicle.code + ' · ' + crewLabel : '—'"></dd>
                    </div>
                    <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                        <dt class="text-stone-500">Conductor</dt>
                        <dd class="text-right font-medium text-stone-800" x-text="person(form.driver_id)?.full_name ?? '—'"></dd>
                    </div>
                    <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                        <dt class="text-stone-500">Ayudantes</dt>
                        <dd class="text-right font-medium text-stone-800">
                            <template x-for="id in form.helper_ids.filter(Boolean)" :key="id">
                                <span class="block" x-text="person(id)?.full_name"></span>
                            </template>
                            <span x-show="!form.helper_ids.some(Boolean)">—</span>
                        </dd>
                    </div>
                </dl>
            </x-card>

            <x-card>
                <header class="border-b border-stone-200 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-stone-900">Disponibilidad</h2>
                    <p class="mt-0.5 text-[12px] text-stone-500"
                        x-text="loading ? 'Consultando…' : (form.schedule_id && form.weekdays.length ? 'En ese turno, los ' + weekdaysLabel : 'Elige turno y días para revisar cruces')"></p>
                </header>
                <ul class="space-y-2 p-5">
                    <template x-for="[label, ok] in checks" :key="label">
                        <li class="flex items-center gap-2 text-[12px]">
                            <span class="grid size-4 shrink-0 place-items-center rounded-full"
                                :class="ok === null ? 'bg-stone-100 text-stone-400' : (ok ? 'bg-brand-100 text-brand-700' : 'bg-red-100 text-red-700')">
                                <x-lucide-check class="size-3" x-show="ok === true" />
                                <x-lucide-x class="size-3" x-show="ok === false" />
                            </span>
                            <span :class="ok === null ? 'text-stone-400' : 'text-stone-700'" x-text="label"></span>
                        </li>
                    </template>
                </ul>
                <p class="border-t border-stone-200 px-5 py-3 text-[11px] leading-relaxed text-stone-500">
                    Las vacaciones futuras y los contratos por vencer solo se avisan: al programar con fechas, el sistema
                    vuelve a validar contrato, vacaciones y duplicidad en cada día.
                </p>
            </x-card>
        </div>
    </form>
</x-layouts.app>
