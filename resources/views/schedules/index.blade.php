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

    /** Márgenes de marcación (Attendance), para la leyenda y el aviso del formulario. */
    $opensBefore = App\Models\Attendance::OPENS_BEFORE;
    $graceAfter = App\Models\Attendance::GRACE_AFTER;
    $closesAfter = App\Models\Attendance::CLOSES_AFTER;
    $exitClosesAfter = App\Models\Attendance::EXIT_CLOSES_AFTER;
    $today = now()->toImmutable();
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
                @can('schedules.create')
                <button type="button" class="btn btn-primary btn-sm" @click="create()">
                    <x-lucide-plus class="size-3.5" />
                    Nuevo turno
                </button>
                @endcan
            </x-card-header>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Turno</th>
                            <th>Horario</th>
                            <th>Duración</th>
                            <th>Marcación de asistencia</th>
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
                                <td>
                                    @php $window = $schedule->markingWindow($today); @endphp
                                    <span class="font-mono text-stone-700 tabular-nums">
                                        {{ $window['opens']->format('H:i') }} – {{ $window['closes']->format('H:i') }}
                                    </span>
                                    <span class="block text-[11px] text-stone-500">
                                        Puntual hasta las {{ $window['grace']->format('H:i') }}
                                    </span>
                                </td>
                                <td><span class="text-stone-500">{{ $schedule->description ?: '—' }}</span></td>
                                <td class="text-right tabular-nums">{{ $schedule->schedulings_count }}</td>
                                <td>
                                    <x-row-actions :editable="auth()->user()->can('schedules.edit')" :deletable="auth()->user()->can('schedules.delete')" :label="$schedule->name" :row="[
                                        'id' => $schedule->id,
                                        'name' => $schedule->name,
                                        'time_start' => $hhmm($schedule->time_start),
                                        'time_end' => $hhmm($schedule->time_end),
                                        'description' => $schedule->description,
                                    ]" />
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="7" message="Todavía no hay turnos definidos" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex items-start gap-2.5 border-t border-stone-200 bg-stone-50 px-4 py-3 text-[12px] text-stone-600">
                <x-lucide-info class="mt-0.5 size-4 shrink-0 text-stone-400" />
                <div class="space-y-1">
                    <p class="font-medium text-stone-700">¿Cómo se define el horario de marcación?</p>
                    <p>
                        Cada turno tiene su <strong>entrada</strong> y su <strong>salida</strong>: quien trabaja mañana y tarde
                        marca las dos en cada turno.
                    </p>
                    <p>
                        La <strong>entrada</strong> se calcula con la <strong>hora de inicio</strong>: abre {{ $opensBefore }} min antes,
                        se registra como <strong>presente</strong> hasta {{ $graceAfter }} min después, como <strong>tarde</strong>
                        pasado ese margen, y cierra {{ $closesAfter / 60 }} horas después del inicio. Quien está programado ese día
                        solo puede marcar entrada en su turno.
                    </p>
                    <p>
                        La <strong>salida</strong> se marca desde la entrada hasta {{ $exitClosesAfter / 60 }} horas después de la
                        <strong>hora de fin</strong>; antes del fin queda como <strong>salida anticipada</strong>.
                    </p>
                </div>
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
            <p x-show="form.time_start" x-data="{
                at(minutes) {
                    const [h, m] = form.time_start.split(':').map(Number);
                    const total = (h * 60 + m + minutes + 1440) % 1440;
                    return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
                },
            }" class="flex items-start gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-2.5 text-[12px] text-stone-600">
                <x-lucide-info class="mt-0.5 size-3.5 shrink-0 text-stone-400" />
                <span>
                    Con esta hora de inicio, el personal podrá marcar su entrada de
                    <strong class="font-mono" x-text="at(-{{ $opensBefore }})"></strong> a
                    <strong class="font-mono" x-text="at({{ $closesAfter }})"></strong>
                    (puntual hasta las <strong class="font-mono" x-text="at({{ $graceAfter }})"></strong>).
                </span>
            </p>
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
