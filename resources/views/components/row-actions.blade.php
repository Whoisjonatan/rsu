{{--
    Editar / eliminar de cada fila, dentro de un x-data="crud(...)". `row` son
    los datos que rellenan el formulario; `label`, el nombre que se lee en la
    confirmación de borrado. `editable` y `deletable` los apagan (por ejemplo,
    sin el permiso de editar o de eliminar del módulo).
--}}
@props(['row', 'label', 'editable' => true, 'deletable' => true])

<div class="flex justify-end gap-1">
    {{ $slot }}
    @if ($editable)
        <button type="button" class="icon-btn" aria-label="Editar {{ $label }}" title="Editar" @click="edit(@js($row))">
            <x-lucide-pencil class="size-3.5" />
        </button>
    @endif
    @if ($deletable)
        <button type="button" class="icon-btn icon-btn-danger" aria-label="Eliminar {{ $label }}" title="Eliminar"
            @click="askDelete(@js(['id' => $row['id'], 'label' => $label]))">
            <x-lucide-trash-2 class="size-3.5" />
        </button>
    @endif
</div>
