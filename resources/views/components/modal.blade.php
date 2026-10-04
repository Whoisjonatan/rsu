{{--
    Ventana modal manejada por Alpine. `show` es la expresión que la abre
    (p. ej. "open") y `close` la que la cierra; las dos se evalúan en el
    x-data de quien la usa. El título puede ser fijo (`title`) o calculado
    (`title-expr`, una expresión de Alpine).

    El contenido trae su propio cuerpo y pie (x-modal.body, x-modal.footer),
    así un <form> puede envolver los dos y el botón de enviar queda dentro
    del formulario.
--}}
@props([
    'show',
    'close' => null,
    'title' => '',
    'titleExpr' => null,
    'description' => null,
    'descriptionExpr' => null,
    'size' => 'md',
])

@php
    $close ??= "{$show} = false";
    $sizes = ['sm' => 'max-w-sm', 'md' => 'max-w-lg', 'lg' => 'max-w-3xl', 'xl' => 'max-w-5xl'];
@endphp

<template x-teleport="body">
    <div x-show="{{ $show }}" x-cloak x-transition.opacity.duration.150ms
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center"
        @keydown.escape.window="if ({{ $show }}) { {{ $close }} }">
        <div class="fixed inset-0 bg-stone-900/40 backdrop-blur-[1px]" @click="{{ $close }}" aria-hidden="true"></div>

        <div role="dialog" aria-modal="true" x-trap.noscroll="{{ $show }}"
            {{ $attributes->merge(['class' => 'relative w-full rounded-xl bg-white shadow-xl ring-1 ring-stone-900/5 '.$sizes[$size]]) }}>
            <header class="flex items-start justify-between gap-4 border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-stone-900"
                        @if ($titleExpr) x-text="{{ $titleExpr }}" @endif>{{ $title }}</h2>
                    @if ($description || $descriptionExpr)
                        <p class="mt-0.5 text-[12px] text-stone-500"
                            @if ($descriptionExpr) x-text="{{ $descriptionExpr }}" @endif>{{ $description }}</p>
                    @endif
                </div>
                <button type="button" @click="{{ $close }}" aria-label="Cerrar"
                    class="-m-1 cursor-pointer rounded p-1 text-stone-400 transition hover:bg-stone-100 hover:text-stone-700">
                    <x-lucide-x class="size-4" />
                </button>
            </header>

            {{ $slot }}
        </div>
    </div>
</template>
