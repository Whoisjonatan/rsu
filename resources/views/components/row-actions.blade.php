{{--
    Editar / eliminar de cada fila, dentro de un x-data="crud(...)". `row` son
    los datos que rellenan el formulario; `label`, el nombre que se lee en la
    confirmación de borrado.
--}}
@props(['row', 'label', 'deletable' => true])

<div class="flex justify-end gap-1">
    {{ $slot }}
    <button type="button" class="icon-btn" aria-label="Editar {{ $label }}" title="Editar" @click="edit(@js($row))">
        <x-lucide-pencil class="size-3.5" />
    </button>
    @if ($deletable)
        <button type="button" class="icon-btn icon-btn-danger" aria-label="Eliminar {{ $label }}" title="Eliminar"
            @click="askDelete(@js(['id' => $row['id'], 'label' => $label]))">
            <x-lucide-trash-2 class="size-3.5" />
        </button>
    @endif
</div>
