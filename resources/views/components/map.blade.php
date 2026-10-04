{{--
    Contenedor del mapa. Va dentro de un x-data="mapPicker({...})", que es el
    que crea el mapa de Leaflet sobre el x-ref="map".
--}}
@props(['height' => '380px'])

<div class="relative overflow-hidden rounded-lg border border-stone-200">
    <div x-ref="map" style="height: {{ $height }}" class="z-0 bg-stone-100"></div>

    <p x-show="tilesFailed" x-cloak
        class="pointer-events-none absolute inset-x-0 bottom-0 z-[400] bg-amber-50/95 px-3 py-2 text-center text-[11px] text-amber-800">
        Sin conexión no se carga el mapa de fondo. Los puntos marcados se guardan igual.
    </p>
</div>
