{{-- W20 Calendario semanal, con el modal de inicio de recorrido (W21). --}}
@php
    /*
     * Estados que pinta la tarjeta. "vacaciones" no existe en la tabla: se
     * deriva de si algún ocupante entró de vacaciones después de programarse,
     * y manda sobre "programado" porque ese recorrido no va a salir así.
     */
    $cardStatus = [
        'iniciado' => ['Iniciado', 'green'],
        'programado' => ['Programado', 'slate'],
        'vacaciones' => ['Vacaciones', 'amber'],
        'bloqueado' => ['Bloqueado', 'red'],
        'finalizado' => ['Finalizado', 'blue'],
    ];

    $first = $days->first();
    $last = $days->last();
    $sameMonth = $first->month === $last->month;
    // "Semana del 24 al 30 de agosto de 2026", sin repetir el mes si coincide.
    $rangeLabel = $sameMonth
        ? "Semana del {$first->day} al {$last->day} de {$last->translatedFormat('F')} de {$last->year}"
        : "Semana del {$first->day} de {$first->translatedFormat('F')} al {$last->day} de {$last->translatedFormat('F')} de {$last->year}";

    $selectedZone = $zones->firstWhere('id', (int) ($filters['zone_id'] ?? 0));
    $week = fn ($date) => route('schedulings.calendar', [...$filters, 'semana' => $date->toDateString()]);
    $today = now()->toDateString();
@endphp

<x-layouts.app title="Calendario semanal" :subtitle="$rangeLabel.($selectedZone ? ' · zona '.$selectedZone->name : '')">
    <x-slot:actions>
        <a href="{{ route('schedulings.create') }}" class="btn btn-primary">
            <x-lucide-plus class="size-4" />
            Programar
        </a>
    </x-slot:actions>

    <div x-data="startRoute()">
        <x-card>
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-stone-200 p-3" x-data
                @change="$el.requestSubmit()">
                <input type="hidden" name="semana" value="{{ $first->toDateString() }}">
                <a href="{{ $week($first->subWeek()) }}" class="btn btn-secondary btn-sm">
                    <x-lucide-chevron-left class="size-3.5" />
                    Anterior
                </a>
                <a href="{{ route('schedulings.calendar', $filters) }}" class="btn btn-secondary btn-sm">Hoy</a>
                <a href="{{ $week($first->addWeek()) }}" class="btn btn-secondary btn-sm">
                    Siguiente
                    <x-lucide-chevron-right class="size-3.5" />
                </a>

                <select name="zone_id" class="field ml-auto w-auto min-w-44" aria-label="Filtrar por zona">
                    <option value="">Zona: todas</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected(($filters['zone_id'] ?? '') == $zone->id)>{{ $zone->name }}</option>
                    @endforeach
                </select>
                <select name="schedule_id" class="field w-auto min-w-40" aria-label="Filtrar por turno">
                    <option value="">Turno: todos</option>
                    @foreach ($schedules as $schedule)
                        <option value="{{ $schedule->id }}" @selected(($filters['schedule_id'] ?? '') == $schedule->id)>{{ $schedule->name }}</option>
                    @endforeach
                </select>
            </form>

            {{-- En móvil el grid de 7 se hace ilegible; se apila en una columna por día. --}}
            <div class="grid gap-2 p-3 sm:grid-cols-2 lg:grid-cols-7">
                @foreach ($days as $day)
                    @php
                        $dayRoutes = $routes->get($day->toDateString(), collect());
                    @endphp
                    <div class="min-w-0">
                        <div @class([
                            'mb-2 rounded-md px-2 py-1.5 text-center text-[12px] font-semibold',
                            'bg-brand-600 text-white' => $day->toDateString() === $today,
                            'bg-stone-100 text-stone-600' => $day->toDateString() !== $today,
                        ])>
                            {{ \App\Models\Scheduling::WEEKDAY_LABELS[$day->dayOfWeekIso] }} {{ $day->day }}
                        </div>

                        <div class="space-y-2">
                            @forelse ($dayRoutes as $route)
                                @php
                                    // Un recorrido ya iniciado no se re-etiqueta: lo que pasó, pasó,
                                    // aunque hoy alguien esté de vacaciones.
                                    $state = $route->status === 'programado' && $route->staffOnVacation() !== []
                                        ? 'vacaciones'
                                        : $route->status;
                                @endphp
                                <button type="button" @click="open({{ $route->id }})"
                                    class="w-full cursor-pointer rounded-lg border border-stone-200 bg-white px-2.5 py-2 text-left transition hover:border-stone-300 hover:shadow-sm">
                                    <p class="truncate text-[12px] font-semibold text-stone-800">{{ $route->schedule?->name }}</p>
                                    <p class="truncate font-mono text-[11px] text-stone-500">{{ $route->vehicle?->plate }}</p>
                                    @if (! $selectedZone && $route->scheduling?->zone)
                                        <p class="truncate text-[10px] text-stone-400">{{ $route->scheduling->zone->name }}</p>
                                    @endif
                                    <span class="mt-1.5 inline-block">
                                        <x-badge :tone="$cardStatus[$state][1]">{{ $cardStatus[$state][0] }}</x-badge>
                                    </span>
                                </button>
                            @empty
                                <p class="rounded-lg border border-dashed border-stone-200 px-2 py-6 text-center text-[11px] text-stone-400">
                                    Sin programación
                                </p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-stone-200 px-4 py-3">
                @foreach ($cardStatus as [$label, $tone])
                    <x-badge :tone="$tone">{{ $label }}</x-badge>
                @endforeach
                <span class="text-[11px] text-stone-400">· toca una tarjeta para iniciarla</span>
            </div>
        </x-card>

        @include('schedulings.partials.start-route')
    </div>
</x-layouts.app>
