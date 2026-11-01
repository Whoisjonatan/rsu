{{-- W07 Listado de personal. --}}
@php
    /** Columna VIGENCIA de W07: "Desde 01/03/2021" o "01/07 - 31/08/2026". */
    $validity = function ($contract) {
        if (! $contract) {
            return '—';
        }

        return $contract->end_date
            ? $contract->start_date->format('d/m').' - '.$contract->end_date->format('d/m/Y')
            : 'Desde '.$contract->start_date->format('d/m/Y');
    };
@endphp

<x-layouts.app title="Personal"
    :subtitle="$summary['total'].' personas registradas · '.$summary['con_contrato'].' con contrato vigente · '.$summary['de_vacaciones'].' de vacaciones'">
    <x-slot:actions>
        @can('people.create')
        <a href="{{ route('people.create') }}" class="btn btn-primary">
            <x-lucide-plus class="size-4" />
            Registrar personal
        </a>
        @endcan
    </x-slot:actions>

    <div x-data="{
        toDelete: null,
        get deleteAction() { return this.toDelete ? @js(route('people.destroy', '__ID__')).replace('__ID__', this.toDelete.id) : '' },
    }">
        <x-card>
            <x-search-bar :value="$filters['q'] ?? ''" placeholder="Buscar por nombre o DNI…">
                <select name="usertype_id" class="field w-auto min-w-40" aria-label="Filtrar por función" data-autosubmit>
                    <option value="">Función: todas</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected(($filters['usertype_id'] ?? '') == $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
                <select name="contract_type" class="field w-auto min-w-40" aria-label="Filtrar por contrato" data-autosubmit>
                    <option value="">Contrato: todos</option>
                    @foreach (Status::options('contract') as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['contract_type'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="state" class="field w-auto min-w-40" aria-label="Filtrar por estado" data-autosubmit>
                    <option value="">Estado: todos</option>
                    @foreach (Status::options('person') as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['state'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-search-bar>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>DNI</th>
                            <th>Apellidos y nombres</th>
                            <th>Correo electrónico</th>
                            <th>Función</th>
                            <th>Contrato</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                            <th>Fecha de creación</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($people as $person)
                            @php
                                $contract = $person->currentContract();
                                $state = $person->operationalStatus();
                            @endphp
                            <tr>
                                <td><span class="font-mono font-medium text-stone-900">{{ $person->dni }}</span></td>
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        @if ($person->photo_url)
                                            <img src="{{ $person->photo_url }}" alt="" class="size-7 shrink-0 rounded-full object-cover">
                                        @else
                                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-stone-100 text-[10px] font-semibold text-stone-500">
                                                {{ mb_strtoupper(mb_substr($person->lastname ?? '?', 0, 1)) }}
                                            </span>
                                        @endif
                                        <span class="font-medium text-stone-800">{{ $person->full_name }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if ($person->email)
                                        <a href="mailto:{{ $person->email }}" class="text-stone-600 hover:text-brand-700 hover:underline">{{ $person->email }}</a>
                                    @else
                                        <span class="text-stone-400">—</span>
                                    @endif
                                </td>
                                <td>{{ $person->usertype?->name ?? '—' }}</td>
                                <td>
                                    @if ($contract)
                                        {{ Status::label('contract', $contract->type) }}
                                    @else
                                        <em class="text-stone-400">Sin contrato</em>
                                    @endif
                                </td>
                                <td>{{ $validity($contract) }}</td>
                                <td>
                                    <x-badge :tone="Status::tone('person', $state)">{{ Status::label('person', $state) }}</x-badge>
                                </td>
                                <td><x-datetime :value="$person->created_at" /></td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        @can('contracts.view')
                                        <a href="{{ route('people.edit', [$person, 'tab' => 'contratos']) }}" class="icon-btn"
                                            title="Contratos" aria-label="Contratos de {{ $person->full_name }}">
                                            <x-lucide-file-text class="size-3.5" />
                                        </a>
                                        @endcan
                                        @can('vacations.view')
                                        <a href="{{ route('people.edit', [$person, 'tab' => 'vacaciones']) }}" class="icon-btn"
                                            title="Vacaciones" aria-label="Vacaciones de {{ $person->full_name }}">
                                            <x-lucide-tree-palm class="size-3.5" />
                                        </a>
                                        @endcan
                                        @can('people.edit')
                                        <a href="{{ route('people.edit', $person) }}" class="icon-btn" title="Editar"
                                            aria-label="Editar {{ $person->full_name }}">
                                            <x-lucide-pencil class="size-3.5" />
                                        </a>
                                        @endcan
                                        @can('people.delete')
                                        <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                            aria-label="Eliminar {{ $person->full_name }}"
                                            @click="toDelete = @js(['id' => $person->id, 'label' => "{$person->full_name} · DNI {$person->dni}"])">
                                            <x-lucide-trash-2 class="size-3.5" />
                                        </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="9" message="No hay personal que coincida con los filtros" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $people->links() }}
        </x-card>

        <x-confirm-delete title="Eliminar personal">
            Se eliminarán también sus contratos, vacaciones y asistencias. Una persona con recorridos asignados no se
            puede eliminar.
        </x-confirm-delete>
    </div>
</x-layouts.app>
