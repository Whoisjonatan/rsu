{{-- W16 Turnos. --}}
@php
    $hhmm = fn ($time) => substr((string) $time, 0, 5);

    /** Duración del turno, para la columna de apoyo. */
    $duration = function ($start, $end) {
        [$sh, $sm] = array_map('intval', explode(':', (string) $start));
        [$eh, $em] = array_map('intval', explode(':', (string) $end));
        $minutes = $eh * 60 + $em - ($sh * 60 + $sm);

        if ($minutes <= 0) {
            return '—';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours} h" : "{$hours} h {$rest} min";
    };
@endphp

<x-layouts.app title="Turnos" subtitle="Cada programación se asigna a un turno; el recorrido se inicia por día y turno">
    <div x-data="crud({
        name: 'schedule',
        storeUrl: @js(route('schedules.store')),
        updateUrl: @js(route('schedules.update', '__ID__')),
        destroyUrl: @js(route('schedules.destroy', '__ID__')),
        defaults: { name: '', time_start: '06:00', time_end: '12:00', description: '' },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <x-card-header title="Turnos definidos">
                <button type="button" class="btn btn-primary btn-sm" @click="create()">
                    <x-lucide-plus class="size-3.5" />
                    Nuevo turno
                </button>
            </x-card-header>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Turno</th>
                            <th>Horario</th>
                            <th>Duración</th>
                            <th>Descripción</th>
                            <th class="text-right">Programaciones</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schedules as $schedule)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-50 text-brand-600">
                                            <x-lucide-clock class="size-3.5" />
                                        </span>
                                        <span class="font-medium text-stone-900">{{ $schedule->name }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="font-mono text-stone-700 tabular-nums">
                                        {{ $hhmm($schedule->time_start) }} – {{ $hhmm($schedule->time_end) }}
                                    </span>
                                </td>
                                <td>{{ $duration($schedule->time_start, $schedule->time_end) }}</td>
                                <td><span class="text-stone-500">{{ $schedule->description ?: '—' }}</span></td>
                                <td class="text-right tabular-nums">{{ $schedule->schedulings_count }}</td>
                                <td>
                                    <x-row-actions :label="$schedule->name" :row="[
                                        'id' => $schedule->id,
                                        'name' => $schedule->name,
                                        'time_start' => $hhmm($schedule->time_start),
                                        'time_end' => $hhmm($schedule->time_end),
                                        'description' => $schedule->description,
                                    ]" />
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" message="Todavía no hay turnos definidos" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-crud-form name="schedule" title-new="Nuevo turno" title-edit="Editar turno">
            <x-field label="Nombre" name="name" required live>
                <input id="name" name="name" x-model="form.name" placeholder="Mañana" maxlength="80" required class="field"
                    :class="error('name') && 'field-error'">
            </x-field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Hora de inicio" name="time_start" required live>
                    <input id="time_start" name="time_start" type="time" x-model="form.time_start" required class="field"
                        :class="error('time_start') && 'field-error'">
                </x-field>
                <x-field label="Hora de fin" name="time_end" required live>
                    <input id="time_end" name="time_end" type="time" x-model="form.time_end" required class="field"
                        :class="error('time_end') && 'field-error'">
                </x-field>
            </div>
            <x-field label="Descripción" name="description" live>
                <input id="description" name="description" x-model="form.description" maxlength="255" class="field"
                    :class="error('description') && 'field-error'">
            </x-field>
        </x-crud-form>

        <x-confirm-delete title="Eliminar turno">
            No se puede eliminar si hay programaciones o recorridos que lo usan.
        </x-confirm-delete>
    </div>
</x-layouts.app>
