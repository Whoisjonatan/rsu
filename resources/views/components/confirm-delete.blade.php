{{--
    Confirmación de borrado del componente crud: abre con askDelete(fila) y
    envía un DELETE a deleteAction. Si el servidor no deja borrar (hay
    registros que dependen), vuelve con el motivo en el aviso flotante.
--}}
@props(['title', 'message' => null])

<x-modal show="toDelete" close="toDelete = null" size="sm" :title="$title" description-expr="toDelete?.label">
    <form method="POST" :action="deleteAction">
        @csrf
        @method('DELETE')
        <x-modal.body>
            <p class="text-[13px] text-stone-600">{{ $message ?? $slot }}</p>
        </x-modal.body>
        <x-modal.footer>
            <button type="button" class="btn btn-secondary" @click="toDelete = null">Cancelar</button>
            <button type="submit" class="btn bg-red-600 text-white hover:bg-red-700 active:bg-red-800">Eliminar</button>
        </x-modal.footer>
    </form>
</x-modal>
