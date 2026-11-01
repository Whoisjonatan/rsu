{{-- Gestión de usuarios › Lista de usuarios: los usuarios del sistema (Administrador y Supervisor). --}}
<x-layouts.app title="Lista de usuarios"
    :subtitle="$summary['total'].' usuarios del sistema · '.$summary['activos'].' activos · son los que entran al panel'">
    <x-slot:actions>
        @can('users.create')
            <a href="{{ route('users.create') }}" class="btn btn-primary">
                <x-lucide-user-plus class="size-4" />
                Registrar usuario
            </a>
        @endcan
    </x-slot:actions>

    <div x-data="{
        toDelete: null,
        get deleteAction() { return this.toDelete ? @js(route('users.destroy', '__ID__')).replace('__ID__', this.toDelete.id) : '' },
    }">
        <x-card>
            <x-search-bar :value="$filters['q'] ?? ''" placeholder="Buscar por nombre, DNI o correo…">
                <select name="usertype_id" class="field w-auto min-w-44" aria-label="Filtrar por tipo de usuario" data-autosubmit>
                    <option value="">Tipo de usuario: todos</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected(($filters['usertype_id'] ?? '') == $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="field w-auto min-w-36" aria-label="Filtrar por estado" data-autosubmit>
                    <option value="">Estado: todos</option>
                    <option value="activo" @selected(($filters['status'] ?? '') === 'activo')>Activo</option>
                    <option value="inactivo" @selected(($filters['status'] ?? '') === 'inactivo')>Inactivo</option>
                </select>
            </x-search-bar>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>DNI</th>
                            <th>Apellidos y nombres</th>
                            <th>Correo electrónico</th>
                            <th>Teléfono</th>
                            <th>Tipo de usuario</th>
                            <th>Estado</th>
                            <th>Fecha de creación</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr>
                                <td><span class="font-mono font-medium text-stone-900">{{ $user->dni }}</span></td>
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        @if ($user->photo_url)
                                            <img src="{{ $user->photo_url }}" alt="" class="size-7 shrink-0 rounded-full object-cover">
                                        @else
                                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-stone-100 text-[10px] font-semibold text-stone-500">
                                                {{ mb_strtoupper(mb_substr($user->lastname ?? '?', 0, 1)) }}
                                            </span>
                                        @endif
                                        <span class="font-medium text-stone-800">{{ $user->full_name }}</span>
                                        @if ($user->is(auth()->user()))
                                            <x-badge tone="blue">Tú</x-badge>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <a href="mailto:{{ $user->email }}" class="text-stone-600 hover:text-brand-700 hover:underline">{{ $user->email }}</a>
                                </td>
                                <td>{{ $user->phone ?: '—' }}</td>
                                <td>{{ $user->usertype?->name ?? '—' }}</td>
                                <td>
                                    <x-badge :tone="$user->status === 'activo' ? 'green' : 'slate'">{{ ucfirst($user->status) }}</x-badge>
                                </td>
                                <td><x-datetime :value="$user->created_at" /></td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        @can('users.permissions')
                                            <a href="{{ route('users.permissions.edit', $user) }}" class="icon-btn"
                                                title="{{ $user->isAdmin() ? 'Permisos (el Administrador los tiene todos)' : 'Permisos' }}"
                                                aria-label="Permisos de {{ $user->full_name }}">
                                                <x-lucide-shield-check class="size-3.5" />
                                            </a>
                                        @endcan
                                        @can('users.edit')
                                            <a href="{{ route('users.edit', $user) }}" class="icon-btn" title="Editar"
                                                aria-label="Editar {{ $user->full_name }}">
                                                <x-lucide-pencil class="size-3.5" />
                                            </a>
                                        @endcan
                                        @cannot('users.delete')
                                        @elseif ($user->is(auth()->user()))
                                            {{-- El propio usuario no se elimina: el botón se ve, pero deshabilitado. --}}
                                            <span class="grid size-7 cursor-not-allowed place-items-center text-stone-300"
                                                title="No puedes eliminar tu propio usuario">
                                                <x-lucide-trash-2 class="size-3.5" />
                                            </span>
                                        @else
                                            <button type="button" class="icon-btn icon-btn-danger" title="Eliminar"
                                                aria-label="Eliminar {{ $user->full_name }}"
                                                @click="toDelete = @js(['id' => $user->id, 'label' => "{$user->full_name} · {$user->usertype?->name}"])">
                                                <x-lucide-trash-2 class="size-3.5" />
                                            </button>
                                        @endcannot
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="8" message="No hay usuarios que coincidan con los filtros" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $users->links() }}
        </x-card>

        <x-confirm-delete title="Eliminar usuario">
            El usuario ya no podrá entrar al sistema. No puedes eliminarte a ti mismo ni al único administrador activo.
        </x-confirm-delete>
    </div>
</x-layouts.app>
