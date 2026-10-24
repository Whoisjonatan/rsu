{{-- Grupos de personal: plantillas para programar indicando solo el rango de fechas. --}}
<x-layouts.app title="Grupos de personal"
    subtitle="Plantillas con turno, zona, vehículo, días y tripulación: al programar solo falta el rango de fechas">
    <x-slot:actions>
        <a href="{{ route('staffgroups.create') }}" class="btn btn-primary">
            <x-lucide-plus class="size-4" />
            Nuevo grupo
        </a>
    </x-slot:actions>

    <div x-data="{
        toDelete: null,
        get deleteAction() { return this.toDelete ? @js(route('staffgroups.destroy', '__ID__')).replace('__ID__', this.toDelete.id) : '' },
    }">
        <x-card>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nombre del grupo</th>
                            <th>Turno</th>
                            <th>Zona</th>
                            <th>Vehículo</th>
                            <th>Conductor</th>
                            <th>Ayudantes</th>
                            <th>Días</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groups as $group)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-50 text-brand-600">
                                            <x-lucide-users-round class="size-3.5" />
                                        </span>
                                        <span class="font-medium text-stone-900">{{ $group->name }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">{{ $group->schedule?->label ?? '—' }}</td>
                                <td>{{ $group->zone?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($group->vehicle)
                                        <span class="block font-mono text-stone-900">{{ $group->vehicle->code }}</span>
                                        <span class="block text-[11px] text-stone-500">
                                            {{ $group->vehicle->plate }} · {{ $group->vehicle->type?->name }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $group->driver?->full_name ?? '—' }}</td>
                                <td>
                                    @forelse ($group->helpers as $helper)
                                        <span class="block whitespace-nowrap">{{ $helper->full_name }}</span>
                                    @empty
                                        <span class="text-stone-400">Sin ayudantes</span>
                                    @endforelse
                                </td>
                                <td class="whitespace-nowrap">{{ $group->weekdaysLabel() }}</td>
                                <td>
                                    <x-badge :tone="Status::tone('staffgroup', $group->status)">{{ Status::label('staffgroup', $group->status) }}</x-badge>
                                </td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        @if ($group->status === 'activo')
                                            <a href="{{ route('schedulings.create', ['grupo' => $group->id]) }}" class="icon-btn"
                                                title="Programar con este grupo" aria-label="Programar con {{ $group->name }}">
                                                <x-lucide-calendar-plus class="size-3.5" />
                                            </a>
                                        @endif
                                        <a href="{{ route('staffgroups.edit', $group) }}" class="icon-btn" title="Editar"
                                            aria-label="Editar {{ $group->name }}">
                                            <x-lucide-pencil class="size-3.5" />
                                        </a>
                                        <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                            aria-label="Eliminar {{ $group->name }}"
                                            @click="toDelete = @js(['id' => $group->id, 'label' => $group->name])">
                                            <x-lucide-trash-2 class="size-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="9" message="Todavía no hay grupos de personal" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $groups->links() }}

            <div class="flex items-start gap-2.5 border-t border-stone-200 bg-stone-50 px-4 py-3 text-[12px] text-stone-600">
                <x-lucide-info class="mt-0.5 size-4 shrink-0 text-stone-400" />
                <p>
                    Con <x-lucide-calendar-plus class="inline size-3 align-[-1px]" /> se abre una programación nueva con
                    los datos del grupo ya cargados: solo indica la fecha de inicio y la de término. También puedes
                    elegir el grupo desde <a href="{{ route('schedulings.create') }}" class="font-medium text-brand-500 hover:text-brand-600">Nueva programación</a>.
                </p>
            </div>
        </x-card>

        <x-confirm-delete title="Eliminar grupo">
            Las programaciones que ya se crearon con este grupo no se eliminan.
        </x-confirm-delete>
    </div>
</x-layouts.app>
