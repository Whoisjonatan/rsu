{{-- Fecha y hora de una celda ("04/10/2026 17:22"), o un guion si no hay. --}}
@props(['value'])

@if ($value)
    <span {{ $attributes->merge(['class' => 'whitespace-nowrap text-stone-500 tabular-nums']) }}
        title="{{ $value->translatedFormat('l, j \d\e F \d\e Y, H:i:s') }}">{{ $value->format('d/m/Y H:i') }}</span>
@else
    <span class="text-stone-400">—</span>
@endif
