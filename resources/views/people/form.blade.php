{{-- W08 Ficha de personal, con las pestañas de W09 (contratos) y W10 (vacaciones). --}}
@php
    $tabs = ['datos' => 'Datos personales', 'contratos' => 'Contratos', 'vacaciones' => 'Vacaciones'];
@endphp

<x-layouts.app :title="$person ? $person->full_name : 'Registrar personal'"
    :subtitle="$person ? 'Personal › Ficha · DNI '.$person->dni : 'Personal › Registrar una persona'">
    <x-slot:actions>
        <a href="{{ ListUrl::get('people.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    @if ($person)
        <nav class="mb-5 flex gap-1 border-b border-stone-200" aria-label="Secciones de la ficha">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('people.edit', [$person, 'tab' => $key]) }}"
                    @if ($key === $tab) aria-current="page" @endif @class([
                        '-mb-px border-b-2 px-4 py-2 text-[13px] font-medium transition',
                        'border-brand-600 text-brand-700' => $key === $tab,
                        'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-700' => $key !== $tab,
                    ])>{{ $label }}</a>
            @endforeach
        </nav>
    @endif

    @if ($tab === 'contratos')
        @include('people.partials.contracts')
    @elseif ($tab === 'vacaciones')
        @include('people.partials.vacations')
    @else
        @include('people.partials.details')
    @endif
</x-layouts.app>
