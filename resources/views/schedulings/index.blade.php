{{-- W17 Listado de programaciones. --}}
@php
    /*
     * La columna ESTADO no muestra el estado de la programación (activa/inactiva)
     * sino el del recorrido del día elegido en el filtro de fecha. Hay dos
     * valores que no existen en la tabla y los deriva el modelo.
     */
    $dayStatus = [
        'programado' => ['Programado', 'slate'],
        'iniciado' => ['Iniciado', 'green'],
        'bloqueado' => ['Bloqueado', 'red'],
        'finalizado' => ['Finalizado', 'blue'],
        'sin_recorrido' => ['Sin recorrido', 'amber'],
        'no_aplica' => ['No aplica', 'slate'],
    ];

    /** Las cuatro que el informe exige y el sistema aplica al guardar. */
    $validations = [
        'Personal contratado' => 'Solo se puede programar personal con contrato vigente en todas las fechas.',
        'Sin vacaciones' => 'No se puede programar personal con vacaciones dentro del rango de fechas.',
        'Sin duplicidad' => 'No se admiten dos programaciones que coincidan en turno, vehículo, conductor o ayudante.',
        'Precarga por zona' => 'Al elegir la zona se precargan el vehículo, el personal y el turno habituales.',
    ];

    $plural = fn (int $count, string $one, string $many) => $count.' '.($count === 1 ? $one : $many);
    $date = $filters['date'];
    $dateLabel = $date ? \Carbon\Carbon::parse($date)->format('d/m/Y') : null;

    // Recién creada una programación que empieza mañana, el filtro de fecha la
    // oculta y "no hay resultados" desconcierta: conviene decir que existen.
    $emptyMessage = $date && $summary['total'] > 0
        ? ($summary['total'] === 1
            ? "La única programación registrada no está vigente el {$dateLabel}"
            : "Ninguna de las {$summary['total']} programaciones está vigente el {$dateLabel}")
        : 'No hay programaciones que coincidan con los filtros';

    // Vacaciones registradas DESPUÉS de programar: las validaciones de W18
    // corren al guardar, así que sin este aviso la programación seguiría en pie.
    $alerts = $schedulings->getCollection()->flatMap(fn ($row) => collect($row->vacation_conflicts ?? [])->map(fn ($conflict) => [
        'code' => $row->code,
        'text' => "{$conflict['full_name']} (".mb_strtolower($conflict['role']).') tiene vacaciones programadas desde el '
            .\Carbon\Carbon::parse($conflict['start_date'])->format('d/m/Y'),
    ]));
@endphp

{{-- "Vigentes" se cuenta siempre contra hoy, no contra la fecha del filtro: por eso dice "hoy". --}}
<x-layouts.app title="Programación"
    :subtitle="$plural($summary['total'], 'programación', 'programaciones').' · '.$plural($summary['activas'], 'activa', 'activas').' · '.$plural($summary['vigentes'], 'vigente', 'vigentes').' hoy'">
    <x-slot:actions>
        <div class="flex gap-2">
            <a href="{{ route('schedulings.calendar') }}" class="btn btn-secondary">
                <x-lucide-calendar-days class="size-4" />
                Calendario
            </a>
            <a href="{{ route('schedulings.bulk') }}" class="btn btn-secondary">
                <x-lucide-layers class="size-4" />
                Edición masiva
            </a>
            <a href="{{ route('schedulings.create') }}" class="btn btn-primary">
                <x-lucide-plus class="size-4" />
                Programar
            </a>
        </div>
    </x-slot:actions>

    <div x-data="{
        toDelete: null,
        get deleteAction() { return this.toDelete ? @js(route('schedulings.destroy', '__ID__')).replace('__ID__', this.toDelete.id) : '' },
    }">
        <x-card>
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-stone-200 p-3" x-data
                @change="$el.requestSubmit()">
                <select name="zone_id" class="field w-auto min-w-44" aria-label="Filtrar por zona">
                    <option value="">Zona: todas</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected($filters['zone_id'] == $zone->id)>{{ $zone->name }}</option>
                    @endforeach
                </select>
                <select name="schedule_id" class="field w-auto min-w-40" aria-label="Filtrar por turno">
                    <option value="">Turno: todos</option>
                    @foreach ($schedules as $schedule)
                        <option value="{{ $schedule->id }}" @selected($filters['schedule_id'] == $schedule->id)>{{ $schedule->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="field w-auto min-w-40" aria-label="Filtrar por estado">
                    <option value="">Estado: todos</option>
                    @foreach (Status::options('active') as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <label class="ml-auto flex items-center gap-2 text-[12px] text-stone-500">
                    Vigentes el
                    <input type="date" name="date" value="{{ $date }}" class="field w-auto min-w-36"
                        aria-label="Ver las programaciones vigentes en esta fecha">
                </label>
            </form>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Zona</th>
                            <th>Ruta</th>
                            <th>Vehículo</th>
                            <th>Conductor</th>
                            <th>Ayudante</th>
                            <th>Turno</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schedulings as $row)
                            <tr>
                                <td><span class="font-mono font-medium text-stone-900">{{ $row->code }}</span></td>
                                <td>{{ $row->zone?->name ?? '—' }}</td>
                                <td>
                                    @if ($row->route)
                                        {{ $row->route->name }}
                                    @else
                                        <em class="text-stone-400">Sin ruta</em>
                                    @endif
                                </td>
                                <td><span class="font-mono">{{ $row->vehicle?->plate ?? '—' }}</span></td>
                                <td>{{ $row->driver?->full_name ?? '—' }}</td>
                                <td>
                                    @if ($row->helper)
                                        {{ $row->helper->full_name }}
                                    @else
                                        <em class="text-stone-400">Sin ayudante</em>
                                    @endif
                                </td>
                                <td>
                                    <span title="{{ $row->schedule?->label }}">
                                        {{ $row->schedule?->name ?? '—' }}
                                        <span class="mt-0.5 block text-[11px] text-stone-400">{{ $row->weekdaysLabel() }}</span>
                                    </span>
                                </td>
                                <td>
                                    @if (isset($row->day_status))
                                        <span title="{{ $row->day_block_reason }}">
                                            <x-badge :tone="$dayStatus[$row->day_status][1]">{{ $dayStatus[$row->day_status][0] }}</x-badge>
                                        </span>
                                    @else
                                        <x-badge :tone="Status::tone('active', $row->status)">{{ Status::label('active', $row->status) }}</x-badge>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        <a href="{{ route('schedulings.edit', $row) }}" class="icon-btn" title="Editar"
                                            aria-label="Editar {{ $row->code }}">
                                            <x-lucide-pencil class="size-3.5" />
                                        </a>
                                        <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                            aria-label="Eliminar {{ $row->code }}"
                                            @click="toDelete = @js(['id' => $row->id, 'label' => $row->code])">
                                            <x-lucide-trash-2 class="size-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="9" :message="$emptyMessage" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $schedulings->links() }}
        </x-card>

        @if ($alerts->isNotEmpty())
            <div class="mt-4 space-y-2">
                @foreach ($alerts as $alert)
                    <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
                        <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0" />
                        <span>
                            <strong class="font-mono font-semibold">{{ $alert['code'] }}</strong> requiere atención: {{ $alert['text'] }}.
                        </span>
                    </p>
                @endforeach
            </div>
        @endif

        <x-card class="mt-4">
            <div class="border-b border-stone-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-stone-900">Validaciones que aplica el sistema al programar</h2>
            </div>
            <div class="grid gap-3 p-5 sm:grid-cols-2">
                @foreach ($validations as $title => $detail)
                    <div class="rounded-lg bg-stone-50 px-4 py-3">
                        <p class="text-[13px] font-semibold text-stone-800">{{ $title }}</p>
                        <p class="mt-1 text-[12px] leading-relaxed text-stone-500">{{ $detail }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-confirm-delete title="Eliminar programación">
            Se eliminarán también los recorridos que todavía no hayan iniciado. Los recorridos ya iniciados impiden el
            borrado.
        </x-confirm-delete>
    </div>
</x-layouts.app>
