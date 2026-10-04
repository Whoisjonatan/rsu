{{-- Bienvenida tras iniciar sesión: información general y accesos rápidos. --}}
@php
    $user = auth()->user();
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');

    $shortcuts = [
        ['/programacion/nueva', 'Nueva programación', 'Asigna zona, vehículo, personal y turno.', 'calendar-plus'],
        ['/programacion/calendario', 'Calendario semanal', 'Revisa los recorridos de la semana.', 'calendar-days'],
        ['/vehiculos/nuevo', 'Registrar vehículo', 'Da de alta una unidad de la flota.', 'truck'],
        ['/personal/nuevo', 'Registrar personal', 'Conductores, ayudantes y supervisores.', 'user-plus'],
        ['/asistencias', 'Reporte de asistencias', 'Presentes, tardanzas y faltas del periodo.', 'clipboard-check'],
        ['/zonas', 'Zonas y perímetros', 'Sectores de recolección en el mapa.', 'map-pinned'],
        ['/turnos', 'Turnos', 'Horarios de cada turno de recolección.', 'clock'],
        // El terminal de marcación es una pantalla pública: se abre aparte.
        ['/marcacion', 'Terminal de marcación', 'Fichaje del personal con DNI y clave.', 'fingerprint', true],
    ];

    // El orden en que se alimenta el sistema, para quien entra por primera vez.
    $workflow = [
        ['Configura los catálogos', 'Colores, marcas, modelos, tipos de vehículo y funciones del personal.'],
        ['Registra flota y personal', 'Vehículos con su capacidad, y personal con su contrato vigente.'],
        ['Define zonas y rutas', 'Traza el perímetro de cada zona y sus rutas de recolección.'],
        ['Programa los recorridos', 'El sistema valida contratos, vacaciones y cruces de turno.'],
    ];
@endphp

<x-layouts.app title="Inicio" :subtitle="ucfirst(now()->translatedFormat('l, j \d\e F \d\e Y'))">
    <section class="relative overflow-hidden rounded-xl bg-brand-600 px-6 py-6 sm:px-8">
        {{-- Círculos decorativos en el tono de la escala. --}}
        <div class="absolute -top-16 -right-10 size-56 rounded-full bg-brand-500/40"></div>
        <div class="absolute right-40 -bottom-20 size-40 rounded-full bg-brand-500/25"></div>

        <div class="relative flex items-center justify-between gap-6">
            <div class="min-w-0">
                <p class="text-[12px] font-medium text-brand-200">
                    {{ $user->usertype?->name ?? 'Usuario' }} · Área de RSU · USAT
                </p>
                <h2 class="mt-1 text-2xl font-bold text-cream-100">{{ $greeting }}, {{ $user->name }}</h2>
                <p class="mt-2 max-w-xl text-[13px] leading-relaxed text-brand-100">
                    Bienvenido al sistema de Gestión de Residuos Sólidos. Desde aquí se administra la flota, el
                    personal, las zonas de recolección y la programación diaria de los recorridos.
                </p>
                <a href="{{ route('dashboard') }}"
                    class="mt-4 inline-flex items-center gap-1.5 rounded-md bg-cream-100 px-3.5 py-2 text-[12px] font-semibold text-brand-700 shadow-sm transition hover:bg-cream-50">
                    Ir al dashboard
                    <x-lucide-arrow-right class="size-3.5" />
                </a>
            </div>

            <span class="hidden shrink-0 place-items-center rounded-xl bg-cream-100 px-4 py-3 shadow-sm sm:grid">
                <x-garbage-truck class="h-20 w-40" />
            </span>
        </div>
    </section>

    <h3 class="mt-6 mb-3 text-[11px] font-semibold tracking-wide text-stone-500 uppercase">El sistema en cifras</h3>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Vehículos registrados" :value="$vehicles['total']" tone="brand" icon="truck" />
        <x-stat-card label="Personal registrado" :value="$people['total']" tone="brand" icon="users" />
        <x-stat-card label="Zonas de recolección" :value="$zonesCount" tone="brand" icon="map" />
        <x-stat-card label="Recorridos de hoy" :value="$routes['total']" tone="brand" icon="route" />
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-3">
        <x-card class="xl:col-span-2">
            <x-card-header title="Accesos rápidos" description="Las tareas más frecuentes, a un clic." />
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                @foreach ($shortcuts as $shortcut)
                    @php
                        [$to, $label, $description, $icon] = $shortcut;
                    @endphp
                    <a href="{{ url($to) }}" @if ($shortcut[4] ?? false) target="_blank" rel="noreferrer" @endif
                        class="group flex items-start gap-3 rounded-lg border border-stone-200 p-3.5 transition hover:border-brand-300 hover:bg-brand-50/60">
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-lg bg-cream-100 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-cream-100">
                            <x-dynamic-component :component="'lucide-'.$icon" class="size-4.5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-[13px] font-semibold text-stone-900">{{ $label }}</span>
                            <span class="mt-0.5 block text-[12px] text-stone-500">{{ $description }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </x-card>

        <x-card>
            <x-card-header title="Flujo de trabajo" description="Cómo se alimenta el sistema." />
            <ol class="space-y-4 p-5">
                @foreach ($workflow as [$step, $description])
                    <li class="flex gap-3">
                        <span
                            class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-600 text-[11px] font-bold text-cream-100">
                            {{ $loop->iteration }}
                        </span>
                        <div>
                            <p class="text-[13px] font-semibold text-stone-900">{{ $step }}</p>
                            <p class="mt-0.5 text-[12px] text-stone-500">{{ $description }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-card>
    </div>
</x-layouts.app>
