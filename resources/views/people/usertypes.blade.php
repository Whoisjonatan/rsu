{{-- W06 Funciones del personal. --}}
<x-layouts.app title="Funciones del personal"
    subtitle="Conductor y Ayudante son predefinidas: se pueden renombrar, pero no eliminar">
    <div x-data="crud({
        name: 'usertype',
        storeUrl: @js(route('usertypes.store')),
        updateUrl: @js(route('usertypes.update', '__ID__')),
        destroyUrl: @js(route('usertypes.destroy', '__ID__')),
        defaults: { name: '', description: '' },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <x-card-header title="Funciones" description="Definen qué puede hacer cada persona dentro de un recorrido.">
                <button type="button" class="btn btn-primary btn-sm" @click="create()">
                    <x-lucide-plus class="size-3.5" />
                    Nueva función
                </button>
            </x-card-header>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Función</th>
                            <th>Descripción</th>
                            <th class="text-right">Personal</th>
                            <th>Fecha de creación</th>
                            <th>Fecha de modificación</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($types as $type)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-stone-900">{{ $type->name }}</span>
                                        @if ($type->is_system)
                                            <x-badge tone="slate">
                                                <x-lucide-lock class="mr-1 size-2.5" />
                                                Predefinida
                                            </x-badge>
                                        @endif
                                    </div>
                                </td>
                                <td><span class="text-stone-500">{{ $type->description ?: '—' }}</span></td>
                                <td class="text-right tabular-nums">{{ $type->users_count }}</td>
                                <td><x-datetime :value="$type->created_at" /></td>
                                <td><x-datetime :value="$type->updated_at" /></td>
                                <td>
                                    <x-row-actions :row="$type->only('id', 'name', 'description')" :label="$type->name"
                                        :deletable="! $type->is_system">
                                        @if ($type->is_system)
                                            <span class="order-last grid size-7 place-items-center text-stone-300"
                                                title="Las funciones predefinidas no se pueden eliminar">
                                                <x-lucide-trash-2 class="size-3.5" />
                                            </span>
                                        @endif
                                    </x-row-actions>
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $types->links() }}
        </x-card>

        <x-crud-form name="usertype" title-new="Nueva función" title-edit="Editar función">
            <x-field label="Nombre" name="name" required live>
                <input id="name" name="name" x-model="form.name" maxlength="80" required class="field"
                    :class="error('name') && 'field-error'">
            </x-field>
            <x-field label="Descripción" name="description" live>
                <input id="description" name="description" x-model="form.description" maxlength="255" class="field"
                    :class="error('description') && 'field-error'">
            </x-field>
        </x-crud-form>

        <x-confirm-delete title="Eliminar función">
            No se puede eliminar si hay personal con esta función.
        </x-confirm-delete>
    </div>
</x-layouts.app>
