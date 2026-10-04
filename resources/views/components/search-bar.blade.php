{{--
    Buscador y filtros de los listados: un formulario GET que conserva los
    demás parámetros de la URL (pestaña, filtros) y vuelve a la página 1.

    Busca mientras se escribe, como en el front anterior: tras una pausa
    breve envía el formulario. Al recargar, el foco vuelve al buscador con el
    cursor al final, para poder seguir escribiendo sin hacer clic.

    Los <select> del slot se aplican al cambiar si llevan data-autosubmit.
--}}
@props([
    'name' => 'q',
    'value' => '',
    'placeholder' => 'Buscar…',
    'keep' => [],
])

<form method="GET" x-data
    @change="if ($event.target.hasAttribute('data-autosubmit')) $el.requestSubmit()"
    {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 border-b border-stone-200 p-3']) }}>
    @foreach ($keep as $key => $kept)
        @if (filled($kept))
            <input type="hidden" name="{{ $key }}" value="{{ $kept }}">
        @endif
    @endforeach

    <div class="relative min-w-56 flex-1">
        <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
        <input type="search" name="{{ $name }}" value="{{ $value }}" placeholder="{{ $placeholder }}"
            aria-label="{{ rtrim($placeholder, '…') }}" class="field pl-9" autocomplete="off"
            @input.debounce.500ms="$el.form.requestSubmit()"
            @if (filled($value)) x-init="$el.focus(); $el.setSelectionRange($el.value.length, $el.value.length)" @endif>
    </div>

    {{ $slot }}
</form>
