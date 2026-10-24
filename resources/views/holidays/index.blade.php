{{-- Feriados. Un feriado activo no lleva recorridos; uno inactivo no se considera en las validaciones. --}}
@php
    $year = $summary['year'];
    $next = $summary['next'];
    $hasFilters = filled($filters['from']) || filled($filters['to']) || filled($filters['status']);
@endphp

<x-layouts.app title="Feriados" subtitle="En un feriado activo no se generan recorridos ni se cuentan ausencias">
    <div class="space-y-5" x-data="crud({
        name: 'holiday',
        storeUrl: @js(route('holidays.store')),
        updateUrl: @js(route('holidays.update', '__ID__')),
        destroyUrl: @js(route('holidays.destroy', '__ID__')),
        defaults: { date: '', description: '', status: 'activo' },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Feriados del año" :value="$summary['year_total']" tone="brand" icon="calendar-days"
                :hint="'Activos en '.$year" />
            <x-stat-card label="Feriados del mes" :value="$summary['month_total']" tone="slate" icon="calendar-range"
                :hint="ucfirst(now()->translatedFormat('F'))" />
            <x-stat-card label="Próximos feriados" :value="$summary['upcoming']" tone="amber" icon="calendar-clock"
                :hint="$next ? 'Siguiente: '.$next->date->format('d/m').' · '.$next->description : 'No hay feriados por venir'" />
            <x-stat-card label="Año actual" :value="$year" tone="slate" icon="calendar" />
        </div>

        {{--
            Cargar los feriados del Perú pide confirmación y enseña antes lo que
            se va a registrar. La lista se consulta al abrir el modal (sale del
            calendario en línea), así no frena la carga del listado.
        --}}
        <div x-data="{
            confirmPeru: false,
            peru: null,
            loading: false,
            failed: false,
            async openPeru() {
                this.confirmPeru = true;
                this.loading = true;
                this.failed = false;
                try {
                    const response = await fetch(@js(route('holidays.peru.preview')), { headers: { Accept: 'application/json' } });
                    if (! response.ok) throw new Error(response.status);
                    this.peru = await response.json();
                } catch {
                    this.failed = true;
                } finally {
                    this.loading = false;
                }
            },
            get pending() {
                return this.peru ? this.peru.holidays.filter((holiday) => ! holiday.registered).length : 0;
            },
            dayMonth(date) {
                return date.slice(8, 10) + '/' + date.slice(5, 7);
            },
        }">
            <x-card>
                <x-card-header title="Feriados registrados"
                    description="Los feriados inactivos quedan registrados pero no afectan la programación de rutas.">
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="openPeru()">
                            <x-lucide-download class="size-3.5" />
                            Cargar feriados Perú
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" @click="create()">
                            <x-lucide-plus class="size-3.5" />
                            Nuevo feriado
                        </button>
                    </div>
                </x-card-header>

                <form method="GET" action="{{ route('holidays.index') }}"
                    class="grid items-end gap-3 border-b border-stone-200 p-4 sm:grid-cols-3 lg:grid-cols-[1fr_1fr_1fr_auto]">
                    <label>
                        <span class="label">Fecha inicio</span>
                        <input type="date" name="from" value="{{ $filters['from'] }}" class="field">
                    </label>
                    <label>
                        <span class="label">Fecha fin</span>
                        <input type="date" name="to" value="{{ $filters['to'] }}" class="field">
                    </label>
                    <label>
                        <span class="label">Estado</span>
                        <select name="status" class="field">
                            <option value="">Todos</option>
                            @foreach (Status::options('holiday') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex gap-2 sm:col-span-3 lg:col-span-1">
                        <button type="submit" class="btn btn-primary flex-1 lg:flex-none">
                            <x-lucide-funnel class="size-4" />
                            Filtrar
                        </button>
                        <a href="{{ route('holidays.index') }}" class="btn btn-secondary flex-1 lg:flex-none">
                            <x-lucide-eraser class="size-4" />
                            Limpiar
                        </a>
                    </div>
                </form>

                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Día</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($holidays as $holiday)
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <span class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-50 text-brand-600">
                                                <x-lucide-calendar-x class="size-3.5" />
                                            </span>
                                            <span class="font-mono font-medium text-stone-900 tabular-nums">{{ $holiday->date->format('d/m/Y') }}</span>
                                        </div>
                                    </td>
                                    <td class="max-w-80">
                                        <span class="block truncate" title="{{ $holiday->description }}">{{ $holiday->description }}</span>
                                    </td>
                                    <td>
                                        <x-badge :tone="Status::tone('holiday', $holiday->status)">{{ Status::label('holiday', $holiday->status) }}</x-badge>
                                    </td>
                                    <td>{{ ucfirst($holiday->date->translatedFormat('l')) }}</td>
                                    <td>
                                        <x-row-actions :label="$holiday->date->format('d/m/Y').' · '.$holiday->description" :row="[
                                            'id' => $holiday->id,
                                            'date' => $holiday->date->toDateString(),
                                            'description' => $holiday->description,
                                            'status' => $holiday->status,
                                        ]" />
                                    </td>
                                </tr>
                            @empty
                                <x-empty-row colspan="5" :message="$hasFilters
                                    ? 'No hay feriados que coincidan con los filtros'
                                    : 'Todavía no hay feriados registrados'" />
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $holidays->links() }}
            </x-card>

            <x-modal show="confirmPeru" size="md" :title="'Cargar feriados del Perú '.$year"
                description="Feriados nacionales del año en curso. Los que ya están registrados se omiten.">
                <form method="POST" action="{{ route('holidays.peru') }}" x-data="{ sending: false }" @submit="sending = true">
                    @csrf
                    <x-modal.body class="space-y-3">
                        <p x-show="loading" class="flex items-center justify-center gap-2 py-10 text-[13px] text-stone-500">
                            <x-lucide-loader-circle class="size-4 animate-spin" />
                            Consultando el calendario de feriados…
                        </p>

                        <p x-show="failed" x-cloak class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2.5 text-[12px] text-red-700">
                            <x-lucide-circle-alert class="mt-0.5 size-3.5 shrink-0" />
                            No se pudo obtener la lista de feriados. Cierra y vuelve a intentarlo.
                        </p>

                        <template x-if="! loading && peru">
                            <div class="space-y-3">
                                <p x-show="peru.source === 'local'"
                                    class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-800">
                                    <x-lucide-wifi-off class="mt-0.5 size-3.5 shrink-0" />
                                    No se pudo consultar el calendario en línea: se muestran los feriados de ley calculados
                                    por el sistema.
                                </p>

                                <ul class="max-h-80 divide-y divide-stone-100 overflow-y-auto rounded-md border border-stone-200">
                                    <template x-for="holiday in peru.holidays" :key="holiday.date">
                                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-[13px]">
                                            <span class="flex min-w-0 items-center gap-3">
                                                <span class="font-mono text-stone-500 tabular-nums" x-text="dayMonth(holiday.date)"></span>
                                                <span class="truncate text-stone-800" x-text="holiday.description"></span>
                                            </span>
                                            <span class="flex shrink-0 gap-1">
                                                <x-badge tone="slate" x-show="holiday.status === 'inactivo'"
                                                    title="No es feriado nacional: entra inactivo y puedes activarlo después">Inactivo</x-badge>
                                                <x-badge tone="slate" x-show="holiday.registered">Ya registrado</x-badge>
                                            </span>
                                        </li>
                                    </template>
                                </ul>

                                <p class="text-[12px] text-stone-600" x-show="pending > 0">
                                    Se registrarán <strong x-text="pending + (pending === 1 ? ' feriado' : ' feriados')"></strong>. Las
                                    programaciones que caigan en los activos dejarán de generar sus recorridos pendientes.
                                    ¿Deseas continuar?
                                </p>
                                <p class="text-[12px] text-stone-600" x-show="pending === 0">
                                    Todos los feriados de {{ $year }} ya están registrados: no hay nada que cargar.
                                </p>
                            </div>
                        </template>
                    </x-modal.body>
                    <x-modal.footer>
                        <button type="button" class="btn btn-secondary" @click="confirmPeru = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="sending || loading || ! peru || pending === 0">
                            <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                            Sí, cargar feriados
                        </button>
                    </x-modal.footer>
                </form>
            </x-modal>
        </div>

        <x-crud-form name="holiday" title-new="Nuevo feriado" title-edit="Editar feriado">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Fecha" name="date" required live>
                    <input id="date" name="date" type="date" x-model="form.date" required class="field"
                        :class="error('date') && 'field-error'">
                </x-field>
                <x-field label="Estado" name="status" required live>
                    <select id="status" name="status" x-model="form.status" required class="field"
                        :class="error('status') && 'field-error'">
                        @foreach (Status::options('holiday') as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <x-field label="Descripción" name="description" required live>
                <input id="description" name="description" x-model="form.description" placeholder="Fiestas Patrias"
                    maxlength="150" required class="field" :class="error('description') && 'field-error'">
            </x-field>
            <p class="flex items-start gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-2.5 text-[12px] text-stone-600">
                <x-lucide-info class="mt-0.5 size-3.5 shrink-0 text-stone-400" />
                <span x-show="form.status === 'activo'">
                    Las programaciones que caen en este día dejarán de tener recorrido; los recorridos ya iniciados no se tocan.
                </span>
                <span x-show="form.status !== 'activo'" x-cloak>
                    Un feriado inactivo no se considera en las validaciones: ese día se programa como cualquier otro.
                </span>
            </p>
        </x-crud-form>

        <x-confirm-delete title="Eliminar feriado">
            Si el feriado estaba activo, las programaciones que cubren ese día vuelven a generar su recorrido.
        </x-confirm-delete>
    </div>
</x-layouts.app>
