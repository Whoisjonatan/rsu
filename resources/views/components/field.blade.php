{{--
    Etiqueta + control + mensaje de error.

    Dos formas de mostrar el error:
    - Por defecto, el que dejó el servidor en $errors al redirigir.
    - Con `live`, el del estado de Alpine (error('campo')). Lo usan los
      formularios dentro de modales, que se abren y cierran sin recargar y no
      deben arrastrar errores de otra fila.
--}}
@props([
    'label',
    'name' => null,
    'for' => null,
    'required' => false,
    'hint' => null,
    'live' => false,
])

@php
    $for ??= $name;
@endphp

<div {{ $attributes }}>
    <label @if ($for) for="{{ $for }}" @endif class="label">
        {{ $label }}
        @if ($required)
            <span class="ml-0.5 text-brand-600">*</span>
        @endif
    </label>

    {{ $slot }}

    @if ($live && $name)
        <p x-show="error(@js($name))" x-cloak class="mt-1.5 flex items-center gap-1 text-[11px] font-medium text-red-600">
            <x-lucide-circle-alert class="size-3.5 shrink-0" />
            <span x-text="error(@js($name))"></span>
        </p>
        @if ($hint)
            <p x-show="!error(@js($name))" class="mt-1.5 text-[11px] text-stone-500">{{ $hint }}</p>
        @endif
    @elseif ($name && $errors->has($name))
        <p class="mt-1.5 flex items-center gap-1 text-[11px] font-medium text-red-600">
            <x-lucide-circle-alert class="size-3.5 shrink-0" />
            {{ $errors->first($name) }}
        </p>
    @elseif ($hint)
        <p class="mt-1.5 text-[11px] text-stone-500">{{ $hint }}</p>
    @endif
</div>
