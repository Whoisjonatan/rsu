{{-- Indicador numérico del panel principal y de las cabeceras de cada módulo. --}}
@props(['label', 'value', 'icon' => null, 'tone' => 'slate'])

@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'red' => 'bg-red-50 text-red-600',
        'slate' => 'bg-stone-100 text-stone-500',
    ];
@endphp

<x-card {{ $attributes->merge(['class' => 'px-5 py-4']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-[11px] font-semibold tracking-wide text-stone-500 uppercase">{{ $label }}</p>
            <p class="mt-2 text-3xl font-bold tracking-tight text-stone-900 tabular-nums">{{ $value }}</p>
        </div>
        @if ($icon)
            <span class="{{ $tones[$tone] }} grid size-9 shrink-0 place-items-center rounded-lg">
                <x-dynamic-component :component="'lucide-'.$icon" class="size-4.5" />
            </span>
        @endif
    </div>
</x-card>
