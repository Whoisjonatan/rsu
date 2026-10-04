{{-- W18 Nueva programación (y edición individual). --}}
@php
    $value = fn (string $field, $default = '') => (string) old($field, $scheduling?->{$field} ?? $default);
    $fieldClass = fn (string $field) => $errors->has($field) ? 'field field-error' : 'field';

    $form = [
        'zone_id' => $value('zone_id'),
        'route_id' => $value('route_id'),
        'vehicle_id' => $value('vehicle_id'),
        'schedule_id' => $value('schedule_id'),
        'driver_id' => $value('driver_id'),
        'helper_id' => $value('helper_id'),
        'weekdays' => array_map('intval', old('weekdays', $scheduling?->weekdays ?? [1, 2, 3, 4, 5])),
        'start_date' => old('start_date', $scheduling?->start_date?->toDateString() ?? ''),
        'end_date' => old('end_date', $scheduling?->end_date?->toDateString() ?? ''),
        'status' => $value('status', 'activa'),
    ];

    $catalogs = [
        'zones' => $zones,
        'routes' => $routes,
        'schedules' => $schedules->map->only('id', 'label'),
        'vehicles' => $vehicles->map->only('id', 'plate'),
        'drivers' => $drivers->map->only('id', 'full_name'),
        'helpers' => $helpers->map->only('id', 'full_name'),
    ];

    $checks = [
        'contrato' => 'Personal con contrato vigente',
        'vehiculo' => 'Vehículo disponible',
        'duplicidad' => 'Sin duplicidad de turno',
        'vacaciones' => 'Personal sin vacaciones',
    ];
@endphp

<x-layouts.app :title="$scheduling ? 'Programación '.$scheduling->code : 'Nueva programación'"
    subtitle="Programación › Crear o editar (edición individual)">
    <x-slot:actions>
        <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <form method="POST" class="grid gap-4 lg:grid-cols-[1fr_20rem]"
        action="{{ $scheduling ? route('schedulings.update', $scheduling) : route('schedulings.store') }}"
        x-data="schedulingForm({ id: @js($scheduling?->id), isNew: @js($scheduling === null), form: @js($form), catalogs: @js($catalogs) })"
        @submit="$el.querySelector('[type=submit]').disabled = true">
        @csrf
        @if ($scheduling)
            @method('PUT')
        @endif

        <x-card>
            <x-card-header title="Datos de la programación"
                description="Los días marcados y el rango de fechas deciden cuántos recorridos se generan." />

            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-field label="Zona" name="zone_id" required>
                    <select id="zone_id" name="zone_id" x-model="form.zone_id" class="{{ $fieldClass('zone_id') }}" required>
                        <option value="">Selecciona una zona</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}">{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Ruta" name="route_id" hint="Las rutas de la zona se crean en el módulo de zonas (W15).">
                    <select id="route_id" name="route_id" x-model="form.route_id" class="{{ $fieldClass('route_id') }}"
                        :disabled="!form.zone_id">
                        <option value="">Sin ruta asignada</option>
                        <template x-for="route in zoneRoutes" :key="route.id">
                            <option :value="route.id" :selected="String(route.id) === String(form.route_id)"
                                x-text="route.name + (route.status === 'inactiva' ? ' (inactiva)' : '')"></option>
                        </template>
                    </select>
                </x-field>

                <p x-show="precargada" x-cloak
                    class="flex items-start gap-2 rounded-lg bg-brand-50 px-4 py-3 text-[12px] text-brand-700 sm:col-span-2">
                    <x-lucide-info class="mt-px size-4 shrink-0" />
                    <span x-text="precargadaText"></span>
                </p>

                <x-field label="Vehículo" name="vehicle_id" required>
                    <select id="vehicle_id" name="vehicle_id" x-model="form.vehicle_id" class="{{ $fieldClass('vehicle_id') }}" required>
                        <option value="">Selecciona un vehículo</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->plate }} · {{ $vehicle->brand?->name }} {{ $vehicle->model?->name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Turno" name="schedule_id" required>
                    <select id="schedule_id" name="schedule_id" x-model="form.schedule_id" class="{{ $fieldClass('schedule_id') }}" required>
                        <option value="">Selecciona un turno</option>
                        @foreach ($schedules as $schedule)
                            <option value="{{ $schedule->id }}">{{ $schedule->label }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Conductor" name="driver_id" required>
                    <select id="driver_id" name="driver_id" x-model="form.driver_id" class="{{ $fieldClass('driver_id') }}" required>
                        <option value="">Selecciona un conductor</option>
                        @foreach ($drivers as $person)
                            <option value="{{ $person->id }}">{{ $person->full_name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Ayudante" name="helper_id">
                    <select id="helper_id" name="helper_id" x-model="form.helper_id" class="{{ $fieldClass('helper_id') }}">
                        <option value="">Sin ayudante</option>
                        @foreach ($helpers as $person)
                            <option value="{{ $person->id }}">{{ $person->full_name }}</option>
                        @endforeach
                    </select>
                </x-field>

                {{-- El aviso sale mientras se elige, no al guardar: es lo que distingue la checklist viva de un error tardío. --}}
                <p x-show="vacationWarning" x-cloak
                    class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[12px] text-amber-800 sm:col-span-2">
                    <x-lucide-triangle-alert class="mt-px size-4 shrink-0" />
                    <span x-text="vacationWarning"></span>
                </p>

                <div class="sm:col-span-2">
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

                <x-field label="Fecha de inicio" name="start_date" required>
                    <input id="start_date" name="start_date" type="date" x-model="form.start_date"
                        class="{{ $fieldClass('start_date') }}" required>
                </x-field>

                <x-field label="Fecha de término" name="end_date" required>
                    <input id="end_date" name="end_date" type="date" x-model="form.end_date" :min="form.start_date"
                        class="{{ $fieldClass('end_date') }}" required>
                </x-field>

                <x-field label="Estado" name="status" required>
                    <select id="status" name="status" x-model="form.status" class="{{ $fieldClass('status') }}">
                        @foreach (Status::options('active') as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-stone-200 px-5 py-4">
                <a href="{{ route('schedulings.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">
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
                    <h2 class="text-sm font-semibold text-stone-900">Validaciones</h2>
                    <p class="mt-0.5 text-[12px] text-stone-500"
                        x-text="validation ? 'Generaría ' + validation.occurrences + ' recorridos' : 'Completa el formulario'"></p>
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

                <p x-show="validation && !validation.valid" x-cloak
                    class="mx-5 mb-5 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-800">
                    <x-lucide-triangle-alert class="mt-px size-3.5 shrink-0" />
                    Corrige lo marcado para poder programar.
                </p>
            </x-card>
        </div>
    </form>
</x-layouts.app>
