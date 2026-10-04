{{--
    Modal de alta/edición del componente crud. Pone los campos ocultos que
    necesita el patrón (_modal para reabrirse si falla la validación, _id para
    saber qué fila se editaba, _method para el PUT) y los botones del pie. Los
    campos del formulario van en el slot.
--}}
@props([
    'name',
    'titleNew',
    'titleEdit',
    'size' => 'md',
    'files' => false,
    'submitLabel' => 'Guardar',
])

<x-modal show="open" close="close()" :size="$size"
    title-expr="editingId ? {{ Js::from($titleEdit) }} : {{ Js::from($titleNew) }}">
    <form method="POST" :action="action" @if ($files) enctype="multipart/form-data" @endif
        x-data="{ sending: false }" @submit="sending = true">
        @csrf
        <input type="hidden" name="_method" :value="method">
        <input type="hidden" name="_modal" value="{{ $name }}">
        <input type="hidden" name="_id" :value="editingId ?? ''">

        <x-modal.body {{ $attributes->merge(['class' => 'space-y-4']) }}>
            {{ $slot }}
        </x-modal.body>

        <x-modal.footer>
            <button type="button" class="btn btn-secondary" @click="close()">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="sending">
                <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                {{ $submitLabel }}
            </button>
        </x-modal.footer>
    </form>
</x-modal>
