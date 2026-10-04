{{-- W12 Reporte de asistencias. --}}
@php
    $byDate = $tab === 'fecha';
    $summary = $report['summary'] ?? null;
    $rows = $report['data'] ?? [];
    $exportUrl = request()->fullUrlWithQuery(['export' => 'csv']);

    // Por qué un día puede salir vacío, para no dejar la tabla muda.
    $emptyReason = function () use ($byDate, $filters) {
        if (! $byDate) {
            return $filters['user_id'] ? 'No hay marcaciones en ese rango' : 'Elige una persona para consultar';
        }

        $date = \Carbon\Carbon::parse($filters['date'])->startOfDay();

        return match (true) {
            $date->isFuture() => 'Es una fecha futura: todavía no hay marcaciones',
            $date->isSunday() => 'Los domingos no se trabaja',
            default => 'No hay personal con marcación esperada ese día',
        };
    };
@endphp

<x-layouts.app title="Asistencias" subtitle="Consulta por fecha o por persona en un rango de fechas">
    <x-slot:actions>
        <a href="{{ route('attendances.terminal') }}" target="_blank" rel="noreferrer" class="btn btn-secondary">
            <x-lucide-external-link class="size-4" />
            Abrir terminal
        </a>
    </x-slot:actions>

    <nav class="mb-5 flex gap-1 border-b border-stone-200" aria-label="Modo de consulta">
        @foreach (['persona' => 'Por persona', 'fecha' => 'Por fecha'] as $key => $label)
            <a href="{{ route('attendances.report', ['tab' => $key]) }}" @class([
                '-mb-px border-b-2 px-4 py-2 text-[13px] font-medium transition',
                'border-brand-600 text-brand-700' => $key === $tab,
                'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-700' => $key !== $tab,
            ])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="space-y-5">
        <x-card>
            {{-- Cambiar cualquier filtro vuelve a consultar. --}}
            <form method="GET" class="flex flex-wrap items-end gap-3 p-4" x-data @change="$el.requestSubmit()">
                <input type="hidden" name="tab" value="{{ $tab }}">

                @if ($byDate)
                    <label>
                        <span class="label">Fecha</span>
                        <input type="date" name="date" value="{{ $filters['date'] }}" class="field w-auto">
                    </label>
                @else
                    <label class="min-w-56 flex-1">
                        <span class="label">Persona</span>
                        <select name="user_id" class="field">
                            <option value="">Selecciona una persona</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}" @selected($filters['user_id'] == $person->id)>
                                    {{ $person->full_name }} · {{ $person->dni }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="label">Desde</span>
                        <input type="date" name="from" value="{{ $filters['from'] }}" class="field w-auto">
                    </label>
                    <label>
                        <span class="label">Hasta</span>
                        <input type="date" name="to" value="{{ $filters['to'] }}" min="{{ $filters['from'] }}" class="field w-auto">
                    </label>
                @endif

                <label>
                    <span class="label">Estado</span>
                    <select name="status" class="field w-auto min-w-36">
                        <option value="">Todos</option>
                        @foreach (Status::options('attendance') as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                @if (count($rows) > 0)
                    <a href="{{ $exportUrl }}" class="btn btn-secondary">
                        <x-lucide-download class="size-4" />
                        Exportar
                    </a>
                @else
                    <button type="button" class="btn btn-secondary" disabled>
                        <x-lucide-download class="size-4" />
                        Exportar
                    </button>
                @endif
            </form>

            @if ($filterErrors->any())
                <p class="border-t border-stone-200 px-4 py-2.5 text-[12px] font-medium text-red-600">{{ $filterErrors->first() }}</p>
            @endif
        </x-card>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Días hábiles" :value="$summary['dias_habiles'] ?? '—'" tone="slate" icon="calendar-check" />
            <x-stat-card label="Presente" :value="$summary['presente'] ?? '—'" tone="brand" />
            <x-stat-card label="Tardanza" :value="$summary['tarde'] ?? '—'" tone="amber" icon="clock" />
            <x-stat-card label="Ausente" :value="$summary['falta'] ?? '—'" tone="red" icon="triangle-alert" />
        </div>

        <x-card>
            @if ($byDate)
                <x-card-header :title="'Personal del '.\Carbon\Carbon::parse($filters['date'])->format('d/m/Y')"
                    description="Solo aparece el personal con contrato vigente ese día; los de vacaciones se omiten." />
            @else
                <x-card-header :title="$report ? 'Marcaciones de '.$report['person']['full_name'] : 'Marcaciones'"
                    description="Los días hábiles ya transcurridos sin marcación se muestran como ausentes." />
            @endif

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ $byDate ? 'Apellidos y nombres' : 'Fecha' }}</th>
                            <th>Turno</th>
                            <th>Hora de marcación</th>
                            <th>Zona asignada</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td>
                                    @if ($byDate)
                                        <p class="font-medium text-stone-900">{{ $row['person']['full_name'] }}</p>
                                        <p class="font-mono text-[11px] text-stone-500">{{ $row['person']['dni'] }}</p>
                                    @else
                                        <span class="font-medium text-stone-900">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</span>
                                    @endif
                                </td>
                                <td>{{ $row['schedule'] ?? '—' }}</td>
                                <td>
                                    @if ($row['check_in'])
                                        <span class="font-mono tabular-nums">{{ $row['check_in'] }}</span>
                                    @else
                                        <span class="text-stone-400">—</span>
                                    @endif
                                </td>
                                <td>{{ $row['zone'] ?? '—' }}</td>
                                <td>
                                    <x-badge :tone="Status::tone('attendance', $row['status'])">
                                        {{ Status::label('attendance', $row['status']) }}
                                    </x-badge>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="5" :message="$emptyReason()" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-layouts.app>
