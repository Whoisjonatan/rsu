{{-- Dashboard: monitoreo diario de las programaciones (ver App\Services\Scheduling\DailyDashboard). --}}
@php
    $date = \Carbon\Carbon::parse($filters['date']);
    $isToday = $date->isToday();
    $isFuture = $date->isFuture() && ! $isToday;

    $tiles = [
        ['Total de programaciones', $totals['total'], 'calendar-check-2', 'bg-brand-600'],
        ['Programaciones completas', $totals['completas'], 'circle-check', 'bg-emerald-600'],
        ['Programaciones incompletas', $totals['incompletas'], 'triangle-alert', 'bg-red-600'],
        ['Personal faltante', $totals['faltantes'], 'user-x', 'bg-amber-500'],
    ];

    // Cabecera de cada card y su etiqueta, según el estado del día.
    $states = [
        'completa' => ['Completa', 'bg-emerald-600', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'circle-check'],
        'incompleta' => ['Incompleta', 'bg-red-600', 'bg-red-50 text-red-700 ring-red-600/20', 'triangle-alert'],
        'pendiente' => ['Pendiente', 'bg-stone-500', 'bg-stone-100 text-stone-600 ring-stone-500/20', 'clock'],
    ];
@endphp

<x-layouts.app title="Dashboard" subtitle="Monitoreo diario de las programaciones y la asistencia del personal">
    <x-slot:actions>
        <a href="{{ route('schedulings.index') }}" class="btn btn-primary">
            <x-lucide-list-checks class="size-4" />
            Ir a Programación
        </a>
    </x-slot:actions>

    <div class="space-y-5"
        x-data="dailyDashboard({ options: @js($options), reasons: @js($reasons), updateUrl: @js(route('schedulings.update', '__ID__')) })">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($tiles as [$label, $value, $icon, $bg])
                <div class="{{ $bg }} relative overflow-hidden rounded-xl px-5 py-4 text-white shadow-sm">
                    <x-dynamic-component :component="'lucide-'.$icon" class="absolute -right-2 -bottom-3 size-20 text-white/15" />
                    <p class="relative text-3xl font-bold tabular-nums">{{ $value }}</p>
                    <p class="relative mt-1 text-[13px] font-medium text-white/90">{{ $label }}</p>
                </div>
            @endforeach
        </div>

        <x-card>
            <form method="GET" class="flex flex-wrap items-end gap-3 p-4">
                <label>
                    <span class="label">Fecha</span>
                    <input type="date" name="date" value="{{ $filters['date'] }}" class="field w-auto min-w-40">
                </label>
                <label>
                    <span class="label">Turno</span>
                    <select name="schedule_id" class="field w-auto min-w-44">
                        <option value="">Todos los turnos</option>
                        @foreach ($schedules as $schedule)
                            <option value="{{ $schedule->id }}" @selected($filters['schedule_id'] == $schedule->id)>{{ $schedule->label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="label">Zona</span>
                    <select name="zone_id" class="field w-auto min-w-44">
                        <option value="">Todas las zonas</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}" @selected($filters['zone_id'] == $zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">
                    <x-lucide-search class="size-4" />
                    Buscar
                </button>
                @unless ($isToday && blank($filters['zone_id']) && blank($filters['schedule_id']))
                    <a href="{{ route('dashboard') }}" class="btn btn-secondary">Hoy</a>
                @endunless
                <p class="ml-auto self-center text-[12px] text-stone-500">
                    {{ ucfirst($date->translatedFormat('l, j \d\e F \d\e Y')) }}
                    @if ($isFuture)
                        · aún no hay marcaciones
                    @endif
                </p>
            </form>
        </x-card>

        @if ($cards === [])
            <x-card class="px-6 py-12 text-center">
                <x-lucide-calendar class="mx-auto size-8 text-stone-300" />
                <p class="mt-3 text-[13px] font-medium text-stone-700">No hay programaciones para este día</p>
                <p class="mt-1 text-[12px] text-stone-500">Cambia la fecha o los filtros, o registra una programación.</p>
            </x-card>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($cards as $card)
                    @php [$stateLabel, $headerBg, $badge, $stateIcon] = $states[$card['status']]; @endphp
                    <x-card class="overflow-hidden">
                        <header class="{{ $headerBg }} flex items-center gap-2 px-4 py-2.5 text-white">
                            <x-lucide-map-pin class="size-4 shrink-0" />
                            <span class="truncate text-[13px] font-semibold">Zona {{ $card['zone'] }}</span>
                            <span class="ml-auto font-mono text-[11px] text-white/80">{{ $card['code'] }}</span>
                        </header>

                        <div class="space-y-4 p-4">
                            <dl class="grid grid-cols-3 gap-2 text-center text-[12px]">
                                <div>
                                    <x-lucide-clock class="mx-auto size-4 text-sky-600" />
                                    <dt class="mt-1 text-stone-500">Turno</dt>
                                    <dd class="font-medium text-stone-800" title="{{ $card['schedule_label'] }}">{{ $card['schedule'] }}</dd>
                                </div>
                                <div>
                                    <x-lucide-users-round class="mx-auto size-4 text-emerald-600" />
                                    <dt class="mt-1 text-stone-500">Grupo</dt>
                                    <dd class="truncate font-medium text-stone-800">{{ $card['group'] }}</dd>
                                </div>
                                <div>
                                    <x-lucide-truck class="mx-auto size-4 text-amber-500" />
                                    <dt class="mt-1 text-stone-500">Vehículo</dt>
                                    <dd class="font-mono font-medium text-stone-800" title="{{ $card['plate'] }}">{{ $card['vehicle'] }}</dd>
                                    @if ($card['vehicle_name'])
                                        <dd class="truncate text-[11px] text-stone-500" title="{{ $card['vehicle_name'] }}">{{ $card['vehicle_name'] }}</dd>
                                    @endif
                                </div>
                            </dl>

                            <div class="flex items-center justify-center gap-2">
                                <span class="{{ $badge }} inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[12px] font-semibold ring-1 ring-inset">
                                    <x-dynamic-component :component="'lucide-'.$stateIcon" class="size-3.5" />
                                    {{ $stateLabel }}
                                </span>
                                <span class="text-[11px] text-stone-400">Recorrido: {{ mb_strtolower($card['route']) }}</span>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-lg bg-emerald-50 px-3 py-2 text-center text-emerald-700">
                                    <p class="flex items-center justify-center gap-1.5 text-lg font-bold tabular-nums">
                                        <x-lucide-user-check class="size-4" />
                                        {{ $card['present'] }}
                                    </p>
                                    <p class="text-[11px] font-medium">Presentes</p>
                                </div>
                                <div class="rounded-lg bg-red-50 px-3 py-2 text-center text-red-700">
                                    <p class="flex items-center justify-center gap-1.5 text-lg font-bold tabular-nums">
                                        <x-lucide-user-x class="size-4" />
                                        {{ $card['missing'] }}
                                    </p>
                                    <p class="text-[11px] font-medium">Faltantes</p>
                                </div>
                            </div>

                            <button type="button" class="btn btn-secondary w-full justify-center" @click="openDetail(@js($card))">
                                <x-lucide-eye class="size-4" />
                                Ver detalles
                            </button>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif

        <x-modal show="detail" close="closeDetail()" size="lg" title-expr="'Programación ' + (detail?.code ?? '')"
            description-expr="detail ? 'Zona ' + detail.zone + ' · ' + detail.schedule + ' · ' + detail.group : ''">
            <x-modal.body class="max-h-[65vh] space-y-5 overflow-y-auto">
                <dl class="grid gap-3 rounded-lg bg-stone-50 px-4 py-3 text-[12px] sm:grid-cols-4">
                    <div>
                        <dt class="text-stone-500">Fecha</dt>
                        <dd class="font-medium text-stone-800">{{ $date->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Programación general</dt>
                        <dd class="font-mono font-medium text-stone-800" x-text="detail?.general ?? '—'"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Vehículo</dt>
                        <dd class="font-mono font-medium text-stone-800" x-text="[detail?.vehicle, detail?.plate].filter(Boolean).join(' · ')"></dd>
                        <dd x-show="detail?.vehicle_name" class="text-[11px] text-stone-500" x-text="detail?.vehicle_name"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Recorrido</dt>
                        <dd class="font-medium text-stone-800">
                            <span x-text="detail?.route"></span>
                            <span x-show="detail?.block_reason" class="block text-[11px] text-red-600" x-text="detail?.block_reason"></span>
                        </dd>
                    </div>
                </dl>

                <section>
                    <h3 class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-stone-900">
                        <x-lucide-users class="size-4 text-stone-500" />
                        Asistencia del personal
                        <span class="font-normal text-stone-400" x-text="detail ? '(' + detail.present + ' de ' + detail.crew.length + ' presentes)' : ''"></span>
                    </h3>
                    <div class="overflow-x-auto rounded-lg border border-stone-200">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rol</th>
                                    <th>Nombre</th>
                                    <th>DNI</th>
                                    <th>Marcación</th>
                                    <th>Hora</th>
                                    <th class="text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="member in detail?.crew ?? []" :key="member.user_id">
                                    <tr>
                                        <td class="text-stone-500">
                                            <span x-text="member.role"></span>
                                            <span x-show="member.replaced" class="block text-[11px] text-amber-600">Reemplazo</span>
                                        </td>
                                        <td class="font-medium text-stone-900" x-text="member.full_name"></td>
                                        <td class="font-mono" x-text="member.dni ?? '—'"></td>
                                        <td>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                                                :class="badgeClass(member.mark[1])" x-text="member.mark[0]"></span>
                                        </td>
                                        <td class="tabular-nums" x-text="member.check_in ?? '—'"></td>
                                        <td class="text-right">
                                            <button type="button" x-show="canReplace(member)" class="btn btn-secondary btn-sm"
                                                :class="replacing?.user_id === member.user_id && 'border-brand-300 bg-brand-50 text-brand-700'"
                                                @click="startReplace(member)">
                                                <x-lucide-user-round-cog class="size-3.5" />
                                                Cambiar
                                            </button>
                                            <span x-show="!canReplace(member)" class="text-stone-300">—</span>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="!detail?.crew?.length">
                                    <td colspan="6" class="py-6 text-center text-[12px] text-stone-500">Sin personal asignado.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p x-show="detail?.status === 'incompleta' && detail?.missing && !detail?.editable" class="mt-2 text-[12px] text-stone-500"
                        x-text="'No se puede cambiar al personal faltante: ' + (detail?.lock_reason ?? '').toLowerCase()"></p>
                    <p x-show="detail?.status === 'incompleta' && detail?.missing && detail?.editable && !replacing" class="mt-2 text-[12px] text-stone-500">
                        Usa «Cambiar» para poner en lugar de quien falta a personal disponible en este turno.
                    </p>
                </section>

                {{-- Reemplazo de quien falta por personal disponible. --}}
                <template x-if="replacing">
                    <section class="space-y-3 border-t border-stone-200 pt-5">
                        <div>
                            <h3 class="flex items-center gap-2 text-[13px] font-semibold text-stone-900">
                                <x-lucide-user-round-cog class="size-4 text-stone-500" />
                                <span x-text="'Reemplazar a ' + replacing.full_name"></span>
                                <x-badge class="capitalize"><span x-text="replacing.role"></span></x-badge>
                            </h3>
                            <p class="mt-0.5 text-[12px] text-stone-500">
                                Se ofrece a quien tiene contrato, no está de vacaciones y no va en otra programación de este turno. Primero, los que ya marcaron.
                            </p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-field label="Nuevo personal" for="replace_new" required class="sm:col-span-2">
                                <select id="replace_new" x-model="replacing.new_id" class="field">
                                    <option value="" x-text="replaceOptions.length ? 'Selecciona ' + (replacing.role === 'conductor' ? 'un conductor disponible' : 'un ayudante disponible') : 'No hay personal disponible en este turno'"></option>
                                    <template x-for="person in replaceOptions" :key="person.id">
                                        <option :value="person.id" x-text="person.full_name + markLabel(person)"></option>
                                    </template>
                                </select>
                            </x-field>
                            <x-field label="Motivo" for="replace_reason" required>
                                <select id="replace_reason" x-model="replacing.reason" class="field">
                                    <option value="">Selecciona un motivo</option>
                                    @foreach ($reasons['personal'] as $reason)
                                        <option value="{{ $reason['name'] }}">{{ $reason['name'] }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <x-field label="Descripción" for="replace_description">
                                <input id="replace_description" type="text" maxlength="500" x-model="replacing.description" class="field"
                                    :placeholder="needsDescription('personal', replacing.reason) ? 'Obligatoria con este motivo' : 'Opcional'">
                            </x-field>
                        </div>
                        <p x-show="error" x-text="error" class="rounded-md bg-red-50 px-3 py-2 text-[12px] font-medium text-red-700" role="alert"></p>
                        <div class="flex items-center justify-end gap-2">
                            <p class="mr-auto text-[11px] text-stone-400">El cambio queda en el historial y la programación pasa a Reprogramada.</p>
                            <button type="button" class="btn btn-secondary btn-sm" @click="replacing = null; error = null">Cancelar</button>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="submitReplace()">
                                <x-lucide-loader-circle class="size-3.5 animate-spin" x-show="busy" x-cloak />
                                <x-lucide-user-check class="size-3.5" x-show="!busy" />
                                Reemplazar
                            </button>
                        </div>
                    </section>
                </template>
            </x-modal.body>
            <x-modal.footer>
                <button type="button" class="btn btn-secondary" @click="closeDetail()">Cerrar</button>
                <a href="{{ route('schedulings.calendar', ['semana' => $filters['date']]) }}" class="btn btn-secondary">
                    <x-lucide-calendar-days class="size-4" />
                    Ver en el calendario
                </a>
                <button type="button" x-show="detail?.editable" class="btn btn-primary" @click="openEditor(detail)">
                    <x-lucide-pencil class="size-4" />
                    Modificar programación
                </button>
            </x-modal.footer>
        </x-modal>

        @include('dashboard.partials.editor')
    </div>
</x-layouts.app>
