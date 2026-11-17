{{--
    Etiqueta de estado. Los semáforos de cada dominio (vehículo, recorrido,
    persona...) están en App\Support\Status, para que todas las vistas pinten
    el mismo estado con el mismo color.
--}}
@props(['tone' => 'slate'])

@php
    $tones = [
        'green' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'slate' => 'bg-stone-100 text-stone-600 ring-stone-500/20',
        // En curso: verde sólido para que resalte entre las activas.
        'live' => 'bg-brand-600 text-white ring-brand-600',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset '.($tones[$tone] ?? $tones['slate'])]) }}>
    {{ $slot }}
</span>
