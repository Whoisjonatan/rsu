{{-- W02 Panel principal. --}}
<x-layouts.app title="Dashboard" :subtitle="ucfirst(now()->translatedFormat('l, j \d\e F'))">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Recorridos programados" :value="$summary['programado']" tone="slate" icon="route" />
        <x-stat-card label="Recorridos iniciados" :value="$summary['iniciado']" tone="brand" icon="clipboard-check" />
        {{-- Sobre el total asignado: "2" a secas no dice si es de 2 o de 20. --}}
        <x-stat-card label="Personal ausente"
            :value="$summary['personal_ausente'].' / '.$summary['personal_asignado']"
            :tone="$summary['personal_ausente'] > 0 ? 'red' : 'slate'" icon="triangle-alert" />
        <x-stat-card label="Vehículos operativos" :value="$vehicles['operativo'].' / '.$vehicles['total']"
            tone="brand" icon="truck" />
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <x-card>
            <x-card-header title="Recorridos del día" :description="$summary['total'] > 0
                ? $summary['total'].' '.($summary['total'] === 1 ? 'recorrido' : 'recorridos').' · '.$summary['iniciado'].' en marcha'
                : 'Los recorridos salen de la programación vigente.'">
                <a href="{{ route('schedulings.calendar') }}" class="link">Ver calendario →</a>
            </x-card-header>

            @if ($routes->isEmpty())
                <div class="px-5 py-16 text-center">
                    <x-lucide-calendar-days class="mx-auto size-6 text-stone-300" />
                    <p class="mt-2 text-[13px] text-stone-500">Hoy no hay recorridos programados.</p>
                    <a href="{{ route('schedulings.create') }}" class="link mt-2 inline-block">Crear una programación →</a>
                </div>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($routes as $route)
                        <li>
                            <a href="{{ route('schedulings.calendar') }}"
                                class="flex items-center gap-3 px-5 py-3 text-[13px] transition hover:bg-stone-50">
                                <span class="w-20 shrink-0 font-medium text-stone-800">{{ $route->schedule?->name }}</span>
                                <span class="w-24 shrink-0 font-mono text-stone-500">{{ $route->vehicle?->plate }}</span>
                                <span class="flex-1 truncate text-stone-600">{{ $route->scheduling?->zone?->name ?? '—' }}</span>
                                @if ($route->time_route)
                                    <span class="shrink-0 text-[11px] text-stone-400 tabular-nums">
                                        {{ substr($route->time_route, 0, 5) }}
                                    </span>
                                @endif
                                <x-badge :tone="Status::tone('route', $route->status)">
                                    {{ Status::label('route', $route->status) }}
                                </x-badge>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card>
            <x-card-header title="Estado del sistema" />
            <dl class="divide-y divide-stone-100 text-[13px]">
                @foreach ([
                    'Vehículos registrados' => $vehicles['total'],
                    'Operativos' => $vehicles['operativo'],
                    'En mantenimiento' => $vehicles['mantenimiento'],
                    'De baja' => $vehicles['baja'],
                    'Recorridos bloqueados hoy' => $summary['bloqueado'],
                    'Recorridos finalizados hoy' => $summary['finalizado'],
                ] as $label => $value)
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-stone-500">{{ $label }}</dt>
                        <dd class="font-semibold text-stone-900 tabular-nums">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="border-t border-stone-200 px-5 py-3.5">
                <a href="{{ route('vehicles.index') }}" class="link">Ver la flota completa →</a>
            </div>
        </x-card>
    </div>
</x-layouts.app>
