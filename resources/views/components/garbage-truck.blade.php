{{--
    Camión recolector animado en SVG. La sensación de avance sale de varias
    animaciones CSS (ver .truck-* en resources/css/app.css): ruedas girando,
    carrocería que rebota, un vaivén de acelerar y frenar, carretera que corre
    hacia atrás y humo del escape.
--}}
<svg viewBox="0 0 160 80" role="img" aria-label="Camión recolector en movimiento"
    {{ $attributes->merge(['class' => 'truck-scene']) }}>
    {{-- Líneas de velocidad detrás del camión --}}
    <g class="stroke-stone-300" stroke-width="2" stroke-linecap="round">
        <line class="truck-speed" x1="2" y1="28" x2="12" y2="28" />
        <line class="truck-speed [animation-delay:-0.3s]" x1="0" y1="38" x2="8" y2="38" />
        <line class="truck-speed [animation-delay:-0.6s]" x1="3" y1="46" x2="11" y2="46" />
    </g>

    {{-- Carretera --}}
    <line x1="0" y1="70" x2="160" y2="70" class="stroke-stone-300" stroke-width="1.5" />
    <line x1="0" y1="75" x2="160" y2="75" class="truck-road stroke-stone-300" stroke-width="1.5"
        stroke-dasharray="10 8" stroke-linecap="round" />

    <g class="truck-drive">
        {{-- Humo del escape --}}
        <circle class="truck-puff fill-stone-300" cx="11" cy="56" r="3" />
        <circle class="truck-puff fill-stone-300 [animation-delay:-0.45s]" cx="11" cy="56" r="3" />

        <g class="truck-body">
            {{-- Tolva trasera y contenedor --}}
            <path d="M22 22 L14 28 V56 H22 Z" class="fill-brand-700" />
            <rect x="22" y="18" width="80" height="38" rx="5" class="fill-brand-600" />
            <g class="stroke-brand-500" stroke-width="1.5">
                <line x1="34" y1="22" x2="34" y2="52" />
                <line x1="90" y1="22" x2="90" y2="52" />
            </g>
            <svg x="51" y="26" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" class="text-white">
                <path d="M7 19H4.815a1.83 1.83 0 0 1-1.57-.881 1.785 1.785 0 0 1-.004-1.784L7.196 9.5" />
                <path d="M11 19h8.203a1.83 1.83 0 0 0 1.556-.89 1.784 1.784 0 0 0 0-1.775l-1.226-2.12" />
                <path d="m14 16-3 3 3 3" />
                <path d="M8.293 13.596 7.196 9.5 3.1 10.598" />
                <path d="m9.344 5.811 1.093-1.892A1.83 1.83 0 0 1 11.985 3a1.784 1.784 0 0 1 1.546.888l3.943 6.843" />
                <path d="m13.378 9.633 4.096 1.098 1.097-4.096" />
            </svg>

            {{-- Cabina --}}
            <path d="M104 30 H124 Q128 30 130 34 L136 44 Q137 46 137 48 V56 H104 Z" class="fill-brand-500" />
            <path d="M109 34 H122 Q124 34 125 36 L129.5 44 H109 Z" class="fill-sky-100" />
            <rect x="133" y="48" width="4" height="3" rx="1" class="fill-amber-300" />

            {{-- Chasis y parachoques --}}
            <rect x="13" y="55" width="124" height="4" rx="1" class="fill-stone-700" />
            <rect x="134" y="54" width="5" height="5" rx="1" class="fill-stone-500" />
        </g>

        {{-- Ruedas --}}
        @foreach ([38, 118] as $cx)
            <g class="truck-wheel">
                <circle cx="{{ $cx }}" cy="61" r="9" class="fill-stone-800" />
                <circle cx="{{ $cx }}" cy="61" r="4.5" class="fill-stone-300" />
                <g class="stroke-stone-500" stroke-width="1.5">
                    <line x1="{{ $cx - 4.5 }}" y1="61" x2="{{ $cx + 4.5 }}" y2="61" />
                    <line x1="{{ $cx }}" y1="56.5" x2="{{ $cx }}" y2="65.5" />
                </g>
            </g>
        @endforeach
    </g>
</svg>
