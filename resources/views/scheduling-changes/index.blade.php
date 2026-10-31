{{-- Gestión de cambios › Cambios: historial de los cambios hechos a las programaciones (ver Rescheduling). --}}
@php
    $tones = ['turno' => 'blue', 'vehiculo' => 'amber', 'conductor' => 'green', 'ayudante' => 'green'];
    $icons = ['turno' => 'clock', 'vehiculo' => 'truck', 'conductor' => 'user', 'ayudante' => 'user'];
    $filtered = filled($filters['date_from']) || filled($filters['date_to']) || filled($filters['type']);
@endphp

<x-layouts.app title="Cambios" subtitle="Gestión de cambios › Historial de los cambios de turno, vehículo y personal hechos a las programaciones">
    <x-slot:actions>
        {{-- El modal vive abajo (partials.bulk); el botón está fuera de su x-data y lo abre con un evento. --}}
        <button type="button" class="btn btn-primary" x-data @click="$dispatch('open-bulk-change')">
            <x-lucide-layers class="size-4" />
            Nuevo cambio masivo
        </button>
    </x-slot:actions>

    <div x-data="{
        // El cambio que enseña el modal Ver (partials.detail).
        detail: null,
        toDelete: null,
        get deleteAction() {
            return this.toDelete ? @js(route('scheduling-changes.destroy', '__ID__')).replace('__ID__', this.toDelete.id) : '';
        },
    }">
        <x-card>
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-stone-200 p-3" x-data
                @change="$el.requestSubmit()">
                <select name="type" class="field w-auto min-w-44" aria-label="Filtrar por tipo de cambio">
                    <option value="">Tipo de cambio: todos</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="ml-auto flex flex-wrap items-center gap-2 text-[12px] text-stone-500">
                    Cambios del
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="field w-auto min-w-36"
                        aria-label="Fecha de inicio">
                    al
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="field w-auto min-w-36"
                        aria-label="Fecha de fin">
                    @if ($filtered)
                        <a href="{{ route('scheduling-changes.index') }}" class="btn btn-secondary">Limpiar</a>
                    @endif
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Fecha del cambio</th>
                            <th>Programación</th>
                            <th>Tipo de cambio</th>
                            <th>Valor anterior</th>
                            <th>Valor nuevo</th>
                            <th>Motivo</th>
                            <th>Registrado por</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($changes as $change)
                            @php $scheduling = $change->scheduling; @endphp
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span class="text-stone-800 tabular-nums">{{ $change->created_at->format('d/m/Y') }}</span>
                                    <span class="block text-[11px] text-stone-500 tabular-nums">{{ $change->created_at->format('H:i') }}</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="font-mono font-medium text-stone-900">{{ $scheduling?->code ?? '—' }}</span>
                                    @if ($scheduling)
                                        <span class="block text-[11px] text-stone-500">
                                            {{ $scheduling->dateLabel() }} · {{ $scheduling->zone?->name ?? '—' }} · {{ $scheduling->schedule?->name ?? '—' }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <x-badge :tone="$tones[$change->type] ?? 'slate'" class="gap-1">
                                        <x-dynamic-component :component="'lucide-'.($icons[$change->type] ?? 'circle')" class="size-3" />
                                        {{ $change->typeLabel() }}
                                    </x-badge>
                                </td>
                                <td class="text-stone-500">{{ $change->old_value ?? '—' }}</td>
                                <td>
                                    <span class="flex items-center gap-1.5 font-medium text-stone-900">
                                        <x-lucide-arrow-right class="size-3.5 shrink-0 text-stone-400" />
                                        {{ $change->new_value }}
                                    </span>
                                </td>
                                <td class="max-w-64 whitespace-normal">
                                    <span class="text-stone-800">{{ $change->reason }}</span>
                                    @if ($change->description)
                                        <span class="block text-[11px] text-stone-500">{{ $change->description }}</span>
                                    @endif
                                </td>
                                <td class="text-stone-600">{{ $change->user?->full_name ?? '—' }}</td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        <button type="button" class="icon-btn icon-btn-success" title="Ver detalle"
                                            aria-label="Ver detalle del cambio de {{ $change->typeLabel() }} de {{ $scheduling?->code }}"
                                            @click="detail = @js($details[$change->id])">
                                            <x-lucide-eye class="size-3.5" />
                                        </button>
                                        <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                            aria-label="Eliminar cambio de {{ $change->typeLabel() }} de {{ $scheduling?->code }}"
                                            @click="toDelete = @js(['id' => $change->id, 'label' => $change->typeLabel().' · '.$scheduling?->code.' · '.$change->created_at->format('d/m/Y H:i')])">
                                            <x-lucide-trash-2 class="size-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="8" :message="$filtered ? 'No hay cambios con estos filtros' : 'Todavía no se ha hecho ningún cambio a las programaciones'" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $changes->links() }}
        </x-card>

        <x-confirm-delete title="Eliminar cambio">
            Se quita del historial, pero no se deshace: la programación conserva el turno, vehículo o personal que tiene ahora.
        </x-confirm-delete>

        @include('scheduling-changes.partials.detail')
    </div>

    @include('scheduling-changes.partials.bulk')
</x-layouts.app>
