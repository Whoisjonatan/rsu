{{--
    Los dos catálogos de `usertypes` (ver UserTypeController):
    W06 Funciones del personal y Gestión de usuarios › Tipos de usuario.
--}}
@php
    $system = $kind === \App\Models\UserType::KIND_SYSTEM;
    $routes = $system ? 'system-usertypes' : 'usertypes';
    // Permisos: roles.* (Tipos de usuario) o usertypes.* (Funciones del personal).
    $perm = $system ? 'roles' : 'usertypes';
    $text = $system
        ? [
            'title' => 'Tipos de usuario',
            'subtitle' => 'Roles de quienes entran al panel. Administrador y Supervisor son predefinidos: se pueden renombrar, pero no eliminar',
            'card' => 'Tipos de usuario',
            'cardDescription' => 'Se eligen al registrar un usuario en Lista de usuarios. Solo el Administrador gestiona usuarios.',
            'new' => 'Nuevo tipo de usuario',
            'column' => 'Tipo de usuario',
            'count' => 'Usuarios',
            'edit' => 'Editar tipo de usuario',
            'delete' => 'Eliminar tipo de usuario',
            'deleteText' => 'No se puede eliminar si hay usuarios con este tipo.',
            'predefined' => 'Predefinido',
            'lockedTitle' => 'Los tipos predefinidos no se pueden eliminar',
        ]
        : [
            'title' => 'Funciones del personal',
            'subtitle' => 'Conductor y Ayudante son predefinidas: se pueden renombrar, pero no eliminar',
            'card' => 'Funciones',
            'cardDescription' => 'Definen qué puede hacer cada persona dentro de un recorrido.',
            'new' => 'Nueva función',
            'column' => 'Función',
            'count' => 'Personal',
            'edit' => 'Editar función',
            'delete' => 'Eliminar función',
            'deleteText' => 'No se puede eliminar si hay personal con esta función.',
            'predefined' => 'Predefinida',
            'lockedTitle' => 'Las funciones predefinidas no se pueden eliminar',
        ];
@endphp

<x-layouts.app :title="$text['title']" :subtitle="$text['subtitle']">
    <div x-data="crud({
        name: 'usertype',
        storeUrl: @js(route($routes.'.store')),
        updateUrl: @js(route($routes.'.update', '__ID__')),
        destroyUrl: @js(route($routes.'.destroy', '__ID__')),
        defaults: { name: '', description: '' },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <x-card-header :title="$text['card']" :description="$text['cardDescription']">
                @can($perm.'.create')
                <button type="button" class="btn btn-primary btn-sm" @click="create()">
                    <x-lucide-plus class="size-3.5" />
                    {{ $text['new'] }}
                </button>
                @endcan
            </x-card-header>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ $text['column'] }}</th>
                            <th>Descripción</th>
                            <th class="text-right">{{ $text['count'] }}</th>
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
                                                {{ $text['predefined'] }}
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
                                        :editable="auth()->user()->can($perm.'.edit')"
                                        :deletable="! $type->is_system && auth()->user()->can($perm.'.delete')">
                                        @if ($type->is_system && auth()->user()->can($perm.'.delete'))
                                            <span class="order-last grid size-7 place-items-center text-stone-300"
                                                title="{{ $text['lockedTitle'] }}">
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

        <x-crud-form name="usertype" :title-new="$text['new']" :title-edit="$text['edit']">
            <x-field label="Nombre" name="name" required live>
                <input id="name" name="name" x-model="form.name" maxlength="80" required class="field"
                    :class="error('name') && 'field-error'">
            </x-field>
            <x-field label="Descripción" name="description" live>
                <input id="description" name="description" x-model="form.description" maxlength="255" class="field"
                    :class="error('description') && 'field-error'">
            </x-field>
        </x-crud-form>

        <x-confirm-delete :title="$text['delete']">
            {{ $text['deleteText'] }}
        </x-confirm-delete>
    </div>
</x-layouts.app>
