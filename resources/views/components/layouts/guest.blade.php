@props(['title'])

{{-- Pantallas sin barra lateral: el login (W01) y el terminal de marcación (W11). --}}
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · RSU Reciclaje</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body {{ $attributes }}>
    {{ $slot }}

    @stack('scripts')
</body>

</html>
