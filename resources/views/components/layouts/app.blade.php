@props([
    'title',
    'subtitle' => null,
])

@php
    /*
     * Navegación lateral: la bienvenida y los módulos, agrupados. El primer
     * grupo va sin título.
     */
    $navGroups = [
        [null, [
            ['/inicio', 'Inicio', 'house'],
            ['/dashboard', 'Dashboard', 'layout-dashboard'],
        ]],
        ['Gestión vehicular', [
            ['/vehiculos', 'Vehículos', 'truck'],
            ['/vehiculos/catalogos', 'Catálogos', 'wrench'],
        ]],
        ['Gestión de personal', [
            ['/personal', 'Personal', 'users'],
            ['/asistencias', 'Asistencias', 'clipboard-check'],
            ['/personal/funciones', 'Funciones del personal', 'id-card'],
        ]],
        ['Gestión de zonas', [
            ['/zonas', 'Zonas', 'map'],
            // Rutas (/rutas) queda oculto: por ahora no se programa por ruta.
        ]],
        ['Programación', [
            ['/programacion', 'Programación', 'calendar-days'],
            ['/turnos', 'Turnos', 'clock'],
            ['/grupos-personal', 'Grupos de personal', 'users-round'],
            ['/feriados', 'Feriados', 'calendar-x'],
        ]],
        ['Gestión de cambios', [
            ['/cambios', 'Cambios', 'git-compare-arrows'],
            ['/motivos', 'Motivos', 'message-square-text'],
        ]],
        ['Gestión de usuarios', [
            ['/usuarios', 'Lista de usuarios', 'user-cog'],
            ['/usuarios/tipos', 'Tipos de usuario', 'shield-user'],
        ]],
    ];

    /*
     * Cada opción se ve solo con su permiso (App\Support\Permissions::menu());
     * una sección sin opciones desaparece. Quien solo ve el mapa de zonas
     * entra directo al mapa.
     */
    $authUser = auth()->user();
    $menuPermissions = \App\Support\Permissions::menu();
    $navGroups = collect($navGroups)
        ->map(function ($group) use ($authUser, $menuPermissions) {
            $group[1] = collect($group[1])
                ->filter(fn ($item) => ! isset($menuPermissions[$item[0]]) || $authUser?->hasAnyPermission($menuPermissions[$item[0]]))
                ->map(function ($item) use ($authUser) {
                    if ($item[0] === '/zonas' && ! $authUser?->hasPermission('zones.view')) {
                        $item[0] = '/zonas/mapa';
                    }

                    return $item;
                })
                ->values()
                ->all();

            return $group;
        })
        ->filter(fn ($group) => $group[1] !== [])
        ->values()
        ->all();

    /*
     * La opción activa es la de ruta más específica: en /vehiculos/catalogos
     * se marca "Catálogos" y no también "Vehículos", que coincidiría por
     * prefijo.
     */
    $path = '/'.ltrim(request()->path(), '/');
    $activePath = collect($navGroups)
        ->flatMap(fn ($group) => array_column($group[1], 0))
        ->filter(fn ($to) => $to === '/' ? $path === '/' : $path === $to || str_starts_with($path, $to.'/'))
        ->sortByDesc(fn ($to) => strlen($to))
        ->first();

    $user = auth()->user();

    /*
     * Secciones plegadas del menú (cookie nav_closed, ver bootstrap/app.php).
     * Se pintan ya plegadas desde el servidor para que no parpadeen; la de la
     * página actual siempre se abre.
     */
    $navKey = fn (?string $title) => $title ? \Illuminate\Support\Str::slug($title) : null;
    $activeGroup = collect($navGroups)
        ->first(fn ($group) => in_array($activePath, array_column($group[1], 0), true));
    $navClosed = collect(explode(',', (string) request()->cookie('nav_closed', '')))
        ->filter()
        ->reject(fn ($key) => $activeGroup && $key === $navKey($activeGroup[0]))
        ->values()
        ->all();
@endphp

<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · RSU Reciclaje</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>

<body>
    <div class="flex min-h-screen bg-cream-100" x-data="{ menu: false }">
        {{-- En pantallas chicas la barra lateral se abre como cajón. --}}
        <div x-show="menu" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-stone-900/40 lg:hidden"
            @click="menu = false"></div>

        <aside :class="menu ? 'flex' : 'hidden lg:flex'"
            class="fixed inset-y-0 left-0 z-40 w-60 flex-col border-r border-brand-700 bg-brand-600">
            <div class="flex items-center gap-2.5 px-5 py-5">
                <span class="grid h-10 w-18 shrink-0 place-items-center rounded-lg bg-cream-100">
                    <x-garbage-truck class="h-8 w-16" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-[13px] font-semibold text-cream-100">RSU Reciclaje</p>
                    <p class="truncate text-[11px] text-brand-200">Área de RSU · USAT</p>
                </div>
            </div>

            <nav class="nav-scroll flex-1 space-y-3 overflow-y-auto px-3 py-2" x-data="{
                closed: @js($navClosed),
                isOpen(key) { return !this.closed.includes(key) },
                toggle(key) {
                    this.closed = this.isOpen(key) ? [...this.closed, key] : this.closed.filter((k) => k !== key)
                    document.cookie = 'nav_closed=' + encodeURIComponent(this.closed.join(',')) + '; path=/; max-age=31536000; samesite=lax'
                },
            }">
                @foreach ($navGroups as [$groupTitle, $items])
                    @php
                        $key = $navKey($groupTitle);
                        $startsOpen = ! in_array($key, $navClosed, true);
                    @endphp
                    <div>
                        @if ($groupTitle)
                            <button type="button" @click="toggle(@js($key))"
                                :aria-expanded="isOpen(@js($key)) ? 'true' : 'false'" aria-expanded="{{ $startsOpen ? 'true' : 'false' }}"
                                aria-controls="nav-{{ $key }}"
                                class="group flex w-full cursor-pointer items-center justify-between rounded-md px-3 py-1.5 text-[10px] font-semibold tracking-wider text-brand-300 uppercase transition hover:bg-white/5 hover:text-cream-100">
                                {{ $groupTitle }}
                                <x-lucide-chevron-down class="size-3.5 shrink-0 transition-transform duration-200 {{ $startsOpen ? '' : '-rotate-90' }}"
                                    ::class="{ '-rotate-90': !isOpen({{ \Illuminate\Support\Js::from($key) }}) }" />
                            </button>
                        @endif
                        {{-- Plegado con grid-rows 1fr/0fr: anima la altura sin medirla. --}}
                        <div @if ($groupTitle) id="nav-{{ $key }}" @endif
                            @class([
                                'grid transition-[grid-template-rows] duration-200 ease-out',
                                'grid-rows-[1fr]' => $startsOpen,
                                'grid-rows-[0fr]' => ! $startsOpen,
                            ])
                            @if ($groupTitle)
                                {{-- Sintaxis de objeto: así Alpine también quita la clase que pintó el servidor. --}}
                                :class="{ 'grid-rows-[1fr]': isOpen(@js($key)), 'grid-rows-[0fr]': !isOpen(@js($key)) }"
                                :inert="!isOpen(@js($key))" @if (! $startsOpen) inert @endif
                            @endif>
                        <div class="min-h-0 space-y-0.5 overflow-hidden">
                            @foreach ($items as [$to, $label, $icon])
                                <a href="{{ url($to) }}" @if ($to === $activePath) aria-current="page" @endif
                                    @class([
                                        'flex items-center gap-2.5 rounded-md px-3 py-2 text-[13px] font-medium transition',
                                        'bg-cream-100 text-brand-700 shadow-sm' => $to === $activePath,
                                        'text-brand-200 hover:bg-white/10 hover:text-cream-100' => $to !== $activePath,
                                    ])>
                                    <x-dynamic-component :component="'lucide-'.$icon" class="size-4 shrink-0" />
                                    {{ $label }}
                                </a>
                            @endforeach
                        </div>
                        </div>
                    </div>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('logout') }}" class="border-t border-white/10 p-3">
                @csrf
                <button type="submit"
                    class="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-[13px] font-medium text-brand-200 transition hover:bg-white/10 hover:text-cream-100">
                    <x-lucide-log-out class="size-4 shrink-0" />
                    Cerrar sesión
                </button>
            </form>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col lg:pl-60">
            {{-- Barra superior de cada pantalla: título, subtítulo, acciones y usuario. --}}
            <header class="sticky top-0 z-20 border-b border-cream-200 bg-cream-100/90 backdrop-blur">
                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-3.5">
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button" class="icon-btn -ml-2 lg:hidden" aria-label="Abrir menú"
                            @click="menu = true">
                            <x-lucide-menu class="size-4" />
                        </button>
                        <div class="min-w-0">
                            <h1 class="truncate text-base font-bold text-stone-900">{{ $title }}</h1>
                            @if ($subtitle)
                                <p class="mt-0.5 truncate text-[12px] text-stone-500">{{ $subtitle }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        {{ $actions ?? '' }}
                        <div class="hidden items-center gap-2 border-l border-stone-200 pl-3 sm:flex">
                            <div class="text-right">
                                <p class="text-[12px] font-medium text-stone-700">{{ $user->name }}</p>
                                <p class="text-[11px] text-stone-500">{{ $user->usertype?->name ?? 'Usuario' }}</p>
                            </div>
                            <span
                                class="grid size-8 place-items-center rounded-full bg-brand-100 text-[12px] font-semibold text-brand-700">
                                {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                            </span>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 px-6 py-5">
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-flash />

    @stack('scripts')
</body>

</html>
