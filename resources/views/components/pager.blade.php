{{--
    Paginación en el navegador, con el mismo aspecto que pagination/rsu: para
    listas que ya vienen completas en la página y se reparten sin recargar.
    `page` es la variable de Alpine con la página actual y `total` una
    expresión con cuántos elementos hay. Si caben en una página no aparece.
--}}
@props(['page', 'total', 'perPage'])

@php
    $pages = "Math.max(1, Math.ceil(({$total}) / {$perPage}))";
    $button = 'rounded-md border border-stone-300 px-3 py-1.5 text-[12px] font-medium text-stone-600 transition';
@endphp

<nav x-show="({{ $total }}) > {{ $perPage }}" x-cloak aria-label="Paginación"
    {{ $attributes->merge(['class' => 'flex items-center justify-between gap-4 border-t border-stone-200 px-4 py-3']) }}>
    <p class="text-[12px] text-stone-500">
        Mostrando <span class="font-medium text-stone-700" x-text="({{ $page }} - 1) * {{ $perPage }} + 1"></span>–<span
            class="font-medium text-stone-700" x-text="Math.min({{ $page }} * {{ $perPage }}, {{ $total }})"></span>
        de <span class="font-medium text-stone-700" x-text="{{ $total }}"></span>
    </p>

    <div class="flex items-center gap-1">
        <button type="button" class="{{ $button }}" :disabled="{{ $page }} <= 1" @click="{{ $page }}--"
            :class="{{ $page }} <= 1 ? 'cursor-not-allowed opacity-40' : 'cursor-pointer hover:bg-stone-50'">Anterior</button>
        <span class="px-2 text-[12px] text-stone-500 tabular-nums" x-text="{{ $page }} + ' / ' + {{ $pages }}"></span>
        <button type="button" class="{{ $button }}" :disabled="{{ $page }} >= {{ $pages }}" @click="{{ $page }}++"
            :class="{{ $page }} >= {{ $pages }} ? 'cursor-not-allowed opacity-40' : 'cursor-pointer hover:bg-stone-50'">Siguiente</button>
    </div>
</nav>
