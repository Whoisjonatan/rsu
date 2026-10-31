{{-- W17 Listado de programaciones: pestaña General (lo registrado) y Detallada (un día por fila). --}}
@php
    /*
     * En la Detallada, debajo del estado de la programación va el de su
     * recorrido. Hay dos valores que no existen en la tabla y los deriva el
     * modelo (ver Scheduling::attachRouteStatus).
     */
    $routeStatus = [
        'programado' => 'Recorrido programado',
        'iniciado' => 'Recorrido iniciado',
        'bloqueado' => 'Recorrido bloqueado',
        'finalizado' => 'Recorrido finalizado',
        'sin_recorrido' => 'Sin recorrido',
        'feriado' => 'Feriado: sin recorrido',
    ];

    /** Las cuatro que el informe exige y el sistema aplica al guardar. */
    $validations = [
        'Personal contratado' => 'Solo se puede programar personal con contrato vigente en todas las fechas.',
        'Sin vacaciones' => 'No se puede programar personal con vacaciones dentro del rango de fechas.',
        'Sin duplicidad' => 'No se admiten dos programaciones que coincidan en turno, vehículo, conductor o ayudante.',
        'Precarga por grupo' => 'Al elegir el grupo de personal se cargan la zona, el turno, el vehículo, los días y la tripulación. Antes de guardar hay que validar la disponibilidad.',
    ];

    $isGeneral = $tab === 'general';
    $plural = fn (int $count, string $one, string $many) => $count.' '.($count === 1 ? $one : $many);
    $date = $filters['date'];
    $dateFrom = $filters['date_from'];
    $dateTo = $filters['date_to'];
    $fmt = fn (string $value) => \Carbon\Carbon::parse($value)->format('d/m/Y');
    $rangeLabel = match (true) {
        filled($date) => "el {$fmt($date)}",
        filled($dateFrom) && filled($dateTo) => "entre el {$fmt($dateFrom)} y el {$fmt($dateTo)}",
        filled($dateFrom) => "desde el {$fmt($dateFrom)}",
        filled($dateTo) => "hasta el {$fmt($dateTo)}",
        default => null,
    };

    // Recién creada una programación que empieza mañana, el filtro de fecha la
    // oculta y "no hay resultados" desconcierta: conviene decir que existen.
    $total = $isGeneral ? $summary['generales'] : $summary['total'];
    $emptyMessage = $rangeLabel && $total > 0
        ? ($isGeneral
            ? ($total === 1 ? "La única programación general registrada no cubre {$rangeLabel}" : "Ninguna de las {$total} programaciones generales cubre {$rangeLabel}")
            : ($total === 1 ? "La única programación individual registrada no es {$rangeLabel}" : "Ninguna de las {$total} programaciones individuales es {$rangeLabel}"))
        : 'No hay programaciones que coincidan con los filtros';

    // Vacaciones registradas DESPUÉS de programar: las validaciones de W18
    // corren al guardar, así que sin este aviso la programación seguiría en pie.
    $alerts = $isGeneral ? collect() : $rows->getCollection()->flatMap(fn ($row) => collect($row->vacation_conflicts ?? [])->map(fn ($conflict) => [
        'code' => $row->code,
        'text' => "{$conflict['full_name']} (".mb_strtolower($conflict['role']).') tiene vacaciones programadas desde el '
            .\Carbon\Carbon::parse($conflict['start_date'])->format('d/m/Y'),
    ]));

    // Las pestañas conservan los filtros.
    $keep = array_filter(\Illuminate\Support\Arr::only($filters, ['zone_id', 'schedule_id', 'status']), 'filled')
        + ['date_from' => $dateFrom ?? '', 'date_to' => $dateTo ?? ''];

    $dayCountLabels = [
        'reprogramada' => ['reprogramado', 'reprogramados'],
        'finalizada' => ['finalizado', 'finalizados'],
        'inactiva' => ['inactivo', 'inactivos'],
    ];
@endphp

{{-- "Vigentes" se cuenta siempre contra hoy, no contra la fecha del filtro: por eso dice "hoy". --}}
<x-layouts.app title="Programación"
    :subtitle="$plural($summary['generales'], 'programación general', 'programaciones generales').' · '.$plural($summary['total'], 'individual', 'individuales').' · '.$plural($summary['vigentes'], 'vigente', 'vigentes').' hoy'">
    <x-slot:actions>
        <div class="flex gap-2">
            <a href="{{ route('schedulings.calendar') }}" class="btn btn-secondary">
                <x-lucide-calendar-days class="size-4" />
                Calendario
            </a>
            <a href="{{ route('schedulings.bulk') }}" class="btn btn-secondary">
                <x-lucide-layers class="size-4" />
                Programación masiva
            </a>
            <a href="{{ route('schedulings.create') }}" class="btn btn-primary">
                <x-lucide-plus class="size-4" />
                Programar
            </a>
        </div>
    </x-slot:actions>

    <nav class="mb-5 flex gap-1 border-b border-stone-200" aria-label="Vista de la programación">
        @foreach (['general' => 'General', 'detallada' => 'Detallada (por día)'] as $key => $label)
            <a href="{{ route('schedulings.index', ['tab' => $key, ...$keep]) }}" @class([
                '-mb-px border-b-2 px-4 py-2 text-[13px] font-medium transition',
                'border-brand-600 text-brand-700' => $key === $tab,
                'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-700' => $key !== $tab,
            ])>{{ $label }}</a>
        @endforeach
    </nav>

    <div x-data="{
        toDelete: null,
        toFinish: null,
        toDetail: null,
        toGeneral: null,
        detailPage: 1,
        get deleteAction() {
            if (!this.toDelete) return '';
            return (this.toDelete.general ? @js(route('schedulings.general.destroy', '__ID__')) : @js(route('schedulings.destroy', '__ID__'))).replace('__ID__', this.toDelete.id);
        },
        get finishAction() {
            if (!this.toFinish) return '';
            return (this.toFinish.general ? @js(route('schedulings.general.finish', '__ID__')) : @js(route('schedulings.finish', '__ID__'))).replace('__ID__', this.toFinish.id);
        },
    }">
        @if (! $isGeneral && $general)
            <p class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-[13px] text-blue-800">
                <x-lucide-list-filter class="size-4 shrink-0" />
                <span>
                    Días de la programación <strong class="font-mono font-semibold">{{ $general->code }}</strong>
                    ({{ $general->start_date->format('d/m/Y') }} al {{ $general->end_date->format('d/m/Y') }} · {{ $general->weekdaysLabel() }}).
                </span>
                <a href="{{ route('schedulings.index', ['tab' => 'detallada']) }}" class="ml-auto font-medium underline">Ver todos los días</a>
            </p>
        @endif

        <x-card>
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-stone-200 p-3" x-data
                @change="$el.requestSubmit()">
                <input type="hidden" name="tab" value="{{ $tab }}">
                @if (! $isGeneral && $general)
                    <input type="hidden" name="general" value="{{ $general->id }}">
                @endif
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
                    {{-- Una general no tiene estado propio: sale de sus días y nunca está "reprogramada". --}}
                    @foreach (\Illuminate\Support\Arr::except(Status::options('scheduling'), $isGeneral ? ['reprogramada'] : []) as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="ml-auto flex flex-wrap items-center gap-2 text-[12px] text-stone-500">
                    {{ $isGeneral ? 'Vigentes del' : 'Días del' }}
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="field w-auto min-w-36"
                        aria-label="Desde esta fecha">
                    al
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="field w-auto min-w-36"
                        aria-label="Hasta esta fecha">
                    <a href="{{ route('schedulings.index', ['tab' => $tab, ...array_filter(\Illuminate\Support\Arr::only($filters, ['zone_id', 'schedule_id', 'status']))]) }}"
                        class="btn btn-secondary" title="Volver al rango de hoy a un año">Hoy</a>
                </div>
            </form>

            <div class="overflow-x-auto">
                @if ($isGeneral)
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Grupo / Zona</th>
                                <th>Vigencia</th>
                                <th>Turno</th>
                                <th>Vehículo</th>
                                <th>Programaciones</th>
                                <th>Estado</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php
                                    $counts = $row->dayCounts();
                                    $days = array_sum($counts);
                                    $status = $row->status();
                                @endphp
                                <tr>
                                    <td><span class="font-mono font-medium text-stone-900">{{ $row->code }}</span></td>
                                    <td>
                                        {{ $row->staffGroup?->name ?? 'Sin grupo' }}
                                        <span class="mt-0.5 block text-[11px] text-stone-400">{{ $row->zone?->name ?? '—' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        {{ $row->start_date->format('d/m/Y') }}
                                        <span class="mt-0.5 block text-[11px] text-stone-400">al {{ $row->end_date->format('d/m/Y') }}</span>
                                    </td>
                                    <td>
                                        <span title="{{ $row->schedule?->label }}">
                                            {{ $row->schedule?->name ?? '—' }}
                                            <span class="mt-0.5 block text-[11px] text-stone-400">{{ $row->weekdaysLabel() }}</span>
                                        </span>
                                    </td>
                                    <td><span class="font-mono">{{ $row->vehicle?->plate ?? '—' }}</span></td>
                                    <td class="whitespace-nowrap">
                                        <a href="{{ route('schedulings.index', ['tab' => 'detallada', 'general' => $row->id, 'date_from' => '', 'date_to' => '']) }}"
                                            class="font-medium text-brand-700 hover:underline">
                                            {{ $plural($days, 'día', 'días') }}
                                        </a>
                                        @foreach ($dayCountLabels as $key => [$one, $many])
                                            @if ($counts[$key] ?? 0)
                                                <span class="mt-0.5 block text-[11px] text-stone-400">{{ $plural($counts[$key], $one, $many) }}</span>
                                            @endif
                                        @endforeach
                                    </td>
                                    <td>
                                        <x-badge :tone="Status::tone('scheduling', $status)">{{ Status::label('scheduling', $status) }}</x-badge>
                                    </td>
                                    <td>
                                        <div class="flex justify-end gap-1">
                                            <a href="{{ route('schedulings.index', ['tab' => 'detallada', 'general' => $row->id, 'date_from' => '', 'date_to' => '']) }}"
                                                class="icon-btn" title="Ver programaciones por día" aria-label="Ver los días de {{ $row->code }}">
                                                <x-lucide-list class="size-3.5" />
                                            </a>
                                            @unless ($row->finishBlocker())
                                                <button type="button" class="icon-btn" title="Finalizar"
                                                    aria-label="Finalizar {{ $row->code }}"
                                                    @click="toFinish = @js(['id' => $row->id, 'label' => $row->code, 'general' => true, 'early' => $row->end_date->isAfter(today())])">
                                                    <x-lucide-flag class="size-3.5" />
                                                </button>
                                            @endunless
                                            <button type="button" class="icon-btn" title="Ver detalles"
                                                aria-label="Ver detalles de {{ $row->code }}"
                                                @click="toGeneral = @js($row->details()); detailPage = 1">
                                                <x-lucide-eye class="size-3.5" />
                                            </button>
                                            <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                                aria-label="Eliminar {{ $row->code }}"
                                                @click="toDelete = @js(['id' => $row->id, 'label' => $row->code.' · '.$plural($days, 'día', 'días'), 'general' => true])">
                                                <x-lucide-trash-2 class="size-3.5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-row colspan="8" :message="$emptyMessage" />
                            @endforelse
                        </tbody>
                    </table>
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Fecha</th>
                                <th>Zona</th>
                                <th>Vehículo</th>
                                <th>Conductor</th>
                                <th>Ayudantes</th>
                                <th>Turno</th>
                                <th>Estado</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td>
                                        <span class="font-mono font-medium text-stone-900">{{ $row->code }}</span>
                                        @if ($row->general)
                                            <span class="mt-0.5 block font-mono text-[11px] text-stone-400" title="Programación general">{{ $row->general->code }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">{{ $row->dateLabel() }}</td>
                                    <td>{{ $row->zone?->name ?? '—' }}</td>
                                    <td><span class="font-mono">{{ $row->vehicle?->plate ?? '—' }}</span></td>
                                    <td>{{ $row->driver?->full_name ?? '—' }}</td>
                                    <td>
                                        @forelse ($row->helpers as $helper)
                                            <span class="block whitespace-nowrap">{{ $helper->full_name }}</span>
                                        @empty
                                            <em class="text-stone-400">Sin ayudante</em>
                                        @endforelse
                                    </td>
                                    <td><span title="{{ $row->schedule?->label }}">{{ $row->schedule?->name ?? '—' }}</span></td>
                                    <td>
                                        <x-badge :tone="Status::tone('scheduling', $row->status)">{{ Status::label('scheduling', $row->status) }}</x-badge>
                                        <span class="mt-1 block whitespace-nowrap text-[11px] text-stone-400" title="{{ $row->day_block_reason }}">
                                            {{ $routeStatus[$row->day_status] ?? '' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="flex justify-end gap-1">
                                            {{-- Una finalizada es historia: ya no se edita. --}}
                                            @unless ($row->isFinished())
                                                <a href="{{ route('schedulings.edit', $row) }}" class="icon-btn" title="Modificar este día"
                                                    aria-label="Modificar {{ $row->code }}">
                                                    <x-lucide-pencil class="size-3.5" />
                                                </a>
                                            @endunless
                                            @unless ($row->finishBlocker())
                                                <button type="button" class="icon-btn" title="Finalizar"
                                                    aria-label="Finalizar {{ $row->code }}"
                                                    @click="toFinish = @js(['id' => $row->id, 'label' => $row->code, 'general' => false, 'early' => $row->end_date->isAfter(today())])">
                                                    <x-lucide-flag class="size-3.5" />
                                                </button>
                                            @endunless
                                            <button type="button" class="icon-btn" title="Ver detalles"
                                                aria-label="Ver detalles de {{ $row->code }}"
                                                @click="toDetail = @js($row->details()); detailPage = 1">
                                                <x-lucide-eye class="size-3.5" />
                                            </button>
                                            <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                                aria-label="Eliminar {{ $row->code }}"
                                                @click="toDelete = @js(['id' => $row->id, 'label' => $row->code.' · '.$row->dateLabel(), 'general' => false])">
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
                @endif
            </div>

            {{ $rows->links() }}
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

        <x-modal show="toFinish" close="toFinish = null" size="sm" title="Finalizar programación" description-expr="toFinish?.label">
            <form method="POST" :action="finishAction">
                @csrf
                @method('PATCH')
                <x-modal.body>
                    <p class="text-[13px] text-stone-600" x-show="toFinish?.general && toFinish?.early">
                        Termina hoy: sus días de hoy y anteriores pasan a finalizada, y los de mañana en adelante que no
                        hayan iniciado se eliminan. El vehículo y el personal quedan libres para otras programaciones.
                    </p>
                    <p class="text-[13px] text-stone-600" x-show="toFinish?.general && !toFinish?.early">
                        Todos sus días en pie pasan a finalizada. El vehículo y el personal quedan libres para otras programaciones.
                    </p>
                    <p class="text-[13px] text-stone-600" x-show="!toFinish?.general">
                        Este día pasa a finalizada. Los demás días de su programación general no cambian.
                    </p>
                    <p class="mt-2 text-[12px] text-stone-500">Una programación finalizada ya no se puede editar.</p>
                </x-modal.body>
                <x-modal.footer>
                    <button type="button" class="btn btn-secondary" @click="toFinish = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <x-lucide-flag class="size-4" />
                        Finalizar
                    </button>
                </x-modal.footer>
            </form>
        </x-modal>

        {{-- Detalle de una general: lo registrado y sus días. --}}
        <x-modal show="toGeneral" close="toGeneral = null" size="xl" title-expr="'Programación ' + (toGeneral?.code ?? '')"
            description-expr="[toGeneral?.group, toGeneral?.zone, toGeneral?.schedule].filter(Boolean).join(' · ')">
            <x-modal.body class="max-h-[70vh] space-y-5 overflow-y-auto">
                <dl class="grid gap-3 rounded-lg bg-stone-50 px-4 py-3 text-[12px] sm:grid-cols-4">
                    <div>
                        <dt class="text-stone-500">Estado</dt>
                        <dd class="mt-0.5">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                                :class="{
                                    green: 'bg-brand-50 text-brand-700 ring-brand-600/20',
                                    amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                    blue: 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                    slate: 'bg-stone-100 text-stone-600 ring-stone-500/20',
                                }[toGeneral?.status[1]] ?? ''"
                                x-text="toGeneral?.status[0]"></span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Vigencia</dt>
                        <dd class="font-medium text-stone-800" x-text="toGeneral?.dates"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Días</dt>
                        <dd class="font-medium text-stone-800" x-text="toGeneral?.weekdays"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Vehículo</dt>
                        <dd class="font-mono font-medium text-stone-800" x-text="toGeneral?.vehicle ?? '—'"></dd>
                    </div>
                </dl>

                <section>
                    <h3 class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-stone-900">
                        <x-lucide-calendar-days class="size-4 text-stone-500" />
                        Programaciones por día
                        <span class="font-normal text-stone-400" x-text="'(' + (toGeneral?.days?.length ?? 0) + ')'"></span>
                    </h3>
                    <div class="overflow-x-auto rounded-lg border border-stone-200">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Fecha</th>
                                    <th>Turno</th>
                                    <th>Vehículo</th>
                                    <th>Conductor</th>
                                    <th>Ayudantes</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="day in (toGeneral?.days ?? []).slice((detailPage - 1) * 10, detailPage * 10)" :key="day.id">
                                    <tr>
                                        <td class="font-mono font-medium text-stone-900" x-text="day.code"></td>
                                        <td class="whitespace-nowrap" x-text="day.date"></td>
                                        <td x-text="day.schedule"></td>
                                        <td class="font-mono" x-text="day.vehicle"></td>
                                        <td x-text="day.driver"></td>
                                        <td class="whitespace-normal" x-text="day.helpers"></td>
                                        <td>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                                                :class="{
                                                    green: 'bg-brand-50 text-brand-700 ring-brand-600/20',
                                                    amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                                    blue: 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                                    slate: 'bg-stone-100 text-stone-600 ring-stone-500/20',
                                                }[day.status[1]] ?? ''"
                                                x-text="day.status[0]"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <x-pager page="detailPage" total="toGeneral?.days?.length ?? 0" per-page="10" />
                    </div>
                </section>
            </x-modal.body>
            <x-modal.footer>
                <button type="button" class="btn btn-secondary" @click="toGeneral = null">Cerrar</button>
            </x-modal.footer>
        </x-modal>

        {{-- Detalle de un día. --}}
        <x-modal show="toDetail" close="toDetail = null" size="lg" title-expr="'Programación ' + (toDetail?.code ?? '')"
            description-expr="[toDetail?.zone, toDetail?.schedule].filter(Boolean).join(' · ')">
            {{-- Con alto máximo: con mucho historial el modal no se sale de la pantalla. --}}
            <x-modal.body class="max-h-[65vh] space-y-5 overflow-y-auto">
                <dl class="grid gap-3 rounded-lg bg-stone-50 px-4 py-3 text-[12px] sm:grid-cols-4">
                    <div>
                        <dt class="text-stone-500">Estado</dt>
                        <dd class="mt-0.5">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                                :class="{
                                    green: 'bg-brand-50 text-brand-700 ring-brand-600/20',
                                    amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                    blue: 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                    slate: 'bg-stone-100 text-stone-600 ring-stone-500/20',
                                }[toDetail?.status[1]] ?? ''"
                                x-text="toDetail?.status[0]"></span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Fecha</dt>
                        <dd class="font-medium text-stone-800" x-text="toDetail?.dates"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Programación general</dt>
                        <dd class="font-mono font-medium text-stone-800" x-text="toDetail?.general ?? '—'"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Vehículo</dt>
                        <dd class="font-mono font-medium text-stone-800" x-text="toDetail?.vehicle ?? '—'"></dd>
                    </div>
                </dl>

                <section>
                    <h3 class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-stone-900">
                        <x-lucide-users class="size-4 text-stone-500" />
                        Personal asignado
                    </h3>
                    <div class="overflow-hidden rounded-lg border border-stone-200">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rol</th>
                                    <th>Nombre</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="member in toDetail?.crew ?? []" :key="member.role">
                                    <tr>
                                        <td class="text-stone-500" x-text="member.role"></td>
                                        <td class="font-medium text-stone-900" x-text="member.name"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section>
                    <h3 class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-stone-900">
                        <x-lucide-history class="size-4 text-stone-500" />
                        Historial de cambios
                    </h3>
                    <div class="overflow-x-auto rounded-lg border border-stone-200">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Anterior</th>
                                    <th>Nuevo</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(change, index) in (toDetail?.changes ?? []).slice((detailPage - 1) * 5, detailPage * 5)"
                                    :key="detailPage + '-' + index">
                                    <tr>
                                        <td class="whitespace-nowrap" x-text="change.date"></td>
                                        <td x-text="change.type"></td>
                                        <td x-text="change.old"></td>
                                        <td class="font-medium text-stone-900" x-text="change.new"></td>
                                        <td class="whitespace-normal">
                                            <span x-text="change.reason"></span>
                                            <span x-show="change.description" class="mt-0.5 block text-[11px] text-stone-400" x-text="change.description"></span>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="!toDetail?.changes?.length">
                                    <td colspan="5" class="py-6 text-center text-[12px] text-stone-500">Sin cambios registrados.</td>
                                </tr>
                            </tbody>
                        </table>
                        <x-pager page="detailPage" total="toDetail?.changes?.length ?? 0" per-page="5" />
                    </div>
                </section>
            </x-modal.body>
            <x-modal.footer>
                <button type="button" class="btn btn-secondary" @click="toDetail = null">Cerrar</button>
            </x-modal.footer>
        </x-modal>

        <x-confirm-delete title="Eliminar programación">
            <span x-show="toDelete?.general">
                Se eliminan la programación general y todos sus días, con sus recorridos. Si algún día ya tiene un
                recorrido iniciado no se puede: finalízala.
            </span>
            <span x-show="!toDelete?.general">
                Se elimina solo este día y su recorrido, si todavía no inició. Los demás días de la programación
                general siguen igual.
            </span>
        </x-confirm-delete>
    </div>
</x-layouts.app>
