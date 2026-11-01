{{--
    Gestión de usuarios › Lista de usuarios › Permisos de un usuario (ver App\Support\Permissions).

    A la izquierda, las secciones con su avance; a la derecha, los módulos de la
    sección elegida con un interruptor por acción. Todas las casillas siguen en
    el formulario aunque su sección no se vea, así que se guarda todo junto.
--}}
@php
    $all = \App\Support\Permissions::all();
    $total = count($all);

    /** Ícono de cada sección. */
    $sectionIcons = [
        'Dashboard' => 'layout-dashboard',
        'Gestión vehicular' => 'truck',
        'Gestión de personal' => 'users',
        'Gestión de zonas' => 'map',
        'Programación' => 'calendar-days',
        'Gestión de cambios' => 'git-compare-arrows',
        'Gestión de usuarios' => 'user-cog',
    ];

    /** Ícono de cada acción, para leer la lista de un vistazo. */
    $actionIcons = [
        'view' => 'eye', 'create' => 'plus', 'edit' => 'pencil', 'delete' => 'trash-2',
        'images' => 'image', 'map' => 'map', 'perimeter' => 'pen-tool', 'bulk' => 'layers',
        'calendar' => 'calendar-days', 'details' => 'info', 'by_day' => 'list', 'finish' => 'flag',
        'schedule' => 'calendar-plus', 'load' => 'download', 'mark' => 'fingerprint', 'permissions' => 'shield-check',
    ];

    // Las secciones con sus módulos y claves, listas para Alpine.
    $sections = collect($tree)->map(fn ($modules, $name) => [
        'name' => $name,
        'slug' => \Illuminate\Support\Str::slug($name),
        'keys' => collect($modules)->flatMap(fn ($module, $key) => array_map(fn ($a) => "{$key}.{$a}", array_keys($module[1])))->values()->all(),
    ])->values();
@endphp

<x-layouts.app :title="'Permisos de '.$user->full_name"
    :subtitle="'Gestión de usuarios › Lista de usuarios › Permisos · '.($user->usertype?->name ?? 'Sin tipo')">
    <x-slot:actions>
        <a href="{{ ListUrl::get('users.index') }}" class="btn btn-secondary">
            <x-lucide-arrow-left class="size-4" />
            Volver
        </a>
    </x-slot:actions>

    <form method="POST" action="{{ route('users.permissions.update', $user) }}" class="pb-20"
        x-data="{
            granted: @js(array_values($granted)),
            initial: @js(array_values($granted)),
            locked: @js((bool) $locked),
            section: @js($sections->first()['slug']),
            search: '',
            sending: false,
            total: {{ $total }},
            has(key) { return this.granted.includes(key) },
            count(keys) { return keys.filter((key) => this.granted.includes(key)).length },
            all(keys) { return keys.length > 0 && keys.every((key) => this.granted.includes(key)) },
            toggle(key) {
                if (this.locked) return
                this.granted = this.has(key) ? this.granted.filter((k) => k !== key) : [...this.granted, key]
            },
            setAll(keys, on) {
                if (this.locked) return
                const rest = this.granted.filter((key) => !keys.includes(key))
                this.granted = on ? [...rest, ...keys] : rest
            },
            reset() { this.granted = [...this.initial] },
            get changes() {
                return this.granted.filter((k) => !this.initial.includes(k)).length
                    + this.initial.filter((k) => !this.granted.includes(k)).length
            },
            get percent() { return Math.round(this.granted.length / this.total * 100) },
            // Con búsqueda se ven todas las secciones, filtradas por el texto.
            matches(text) {
                const q = this.search.trim().toLowerCase()
                return q === '' || text.toLowerCase().includes(q)
            },
            showSection(slug) { return this.search.trim() !== '' || this.section === slug },
        }" @submit="sending = true">
        @csrf
        @method('PUT')

        {{-- Cabecera: el usuario y cuánto tiene asignado. --}}
        <x-card class="overflow-hidden">
            <div class="flex flex-wrap items-center gap-4 p-5">
                @if ($user->photo_url)
                    <img src="{{ $user->photo_url }}" alt="" class="size-12 rounded-full object-cover ring-2 ring-white shadow-sm">
                @else
                    <span class="grid size-12 place-items-center rounded-full bg-brand-50 text-[15px] font-semibold text-brand-700 ring-2 ring-white shadow-sm">
                        {{ mb_strtoupper(mb_substr($user->lastname ?? '?', 0, 1).mb_substr($user->name ?? '', 0, 1)) }}
                    </span>
                @endif
                <div class="min-w-0">
                    <p class="truncate text-[15px] font-semibold text-stone-900">{{ $user->full_name }}</p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[12px] text-stone-500">
                        <x-badge tone="blue">{{ $user->usertype?->name }}</x-badge>
                        <span>{{ $user->email }}</span>
                    </p>
                </div>

                <div class="ml-auto w-full max-w-xs sm:w-64">
                    <div class="flex items-baseline justify-between text-[12px]">
                        <span class="font-medium text-stone-600">Permisos asignados</span>
                        <span class="text-stone-500"><span class="font-semibold text-stone-900 tabular-nums" x-text="granted.length"></span> / {{ $total }}</span>
                    </div>
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-stone-100">
                        <div class="h-full rounded-full bg-brand-600 transition-all duration-300" :style="`width: ${percent}%`"></div>
                    </div>
                </div>
            </div>

            @if ($locked)
                <div class="flex items-start gap-2.5 border-t border-sky-100 bg-sky-50 px-5 py-3 text-[13px] text-sky-900">
                    <x-lucide-shield-check class="mt-0.5 size-4 shrink-0 text-sky-600" />
                    <p>{{ $locked }}</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2 border-t border-stone-100 bg-stone-50/60 px-5 py-3">
                    <label class="relative min-w-56 flex-1">
                        <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                        <input type="search" x-model="search" placeholder="Buscar un módulo o una acción…" class="field pl-9">
                    </label>
                    <button type="button" class="btn btn-secondary btn-sm" @click="setAll(@js($all), true)">
                        <x-lucide-check-check class="size-3.5" />
                        Marcar todo
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="setAll(@js($all), false)">
                        <x-lucide-x class="size-3.5" />
                        Quitar todo
                    </button>
                </div>
            @endif
        </x-card>

        <div class="mt-5 grid items-start gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
            {{-- Secciones. --}}
            <nav class="space-y-1 lg:sticky lg:top-20" aria-label="Secciones de permisos" x-show="search.trim() === ''">
                @foreach ($sections as $section)
                    @php $icon = $sectionIcons[$section['name']] ?? 'circle-dot'; @endphp
                    <button type="button" @click="section = @js($section['slug'])"
                        class="flex w-full cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-left transition"
                        :class="section === @js($section['slug'])
                            ? 'border-brand-200 bg-white shadow-sm ring-1 ring-brand-100'
                            : 'border-transparent hover:bg-white/70'">
                        <span class="grid size-8 shrink-0 place-items-center rounded-md transition"
                            :class="section === @js($section['slug']) ? 'bg-brand-600 text-white' : 'bg-white text-stone-500 ring-1 ring-stone-200'">
                            <x-dynamic-component :component="'lucide-'.$icon" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-medium text-stone-800">{{ $section['name'] }}</span>
                            <span class="mt-1 block h-1 overflow-hidden rounded-full bg-stone-200/70">
                                <span class="block h-full rounded-full bg-brand-500 transition-all duration-300"
                                    :style="`width: ${count(@js($section['keys'])) / {{ count($section['keys']) }} * 100}%`"></span>
                            </span>
                        </span>
                        <span class="shrink-0 text-[11px] font-medium text-stone-400 tabular-nums"
                            x-text="count(@js($section['keys'])) + '/{{ count($section['keys']) }}'"></span>
                    </button>
                @endforeach
            </nav>

            {{-- Módulos de la sección elegida (o de todas, al buscar). --}}
            <div class="space-y-4" :class="search.trim() !== '' && 'lg:col-span-2'">
                @foreach ($sections as $section)
                    @php $modules = $tree[$section['name']]; @endphp
                    <section x-show="showSection(@js($section['slug']))" class="space-y-3">
                        <div class="flex items-center gap-3">
                            <h2 class="text-[15px] font-semibold text-stone-900">{{ $section['name'] }}</h2>
                            @unless ($locked)
                                <button type="button" class="ml-auto text-[12px] font-medium text-brand-700 hover:underline"
                                    x-show="search.trim() === ''"
                                    @click="setAll(@js($section['keys']), !all(@js($section['keys'])))"
                                    x-text="all(@js($section['keys'])) ? 'Quitar toda la sección' : 'Marcar toda la sección'"></button>
                            @endunless
                        </div>

                        @foreach ($modules as $moduleKey => [$moduleLabel, $actions])
                            @php
                                $moduleKeys = array_map(fn ($action) => "{$moduleKey}.{$action}", array_keys($actions));
                                $searchText = $section['name'].' '.$moduleLabel.' '.implode(' ', $actions);
                            @endphp
                            {{-- En atributos de componentes Blade no compila @js: va Js::from. --}}
                            <x-card x-show="matches({{ \Illuminate\Support\Js::from($searchText) }})" class="overflow-hidden">
                                <div class="flex items-center gap-3 border-b border-stone-100 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="text-[13px] font-semibold text-stone-900">{{ $moduleLabel }}</p>
                                        <p class="text-[11px] text-stone-500">
                                            <span x-text="count(@js($moduleKeys))"></span> de {{ count($moduleKeys) }} acciones
                                        </p>
                                    </div>
                                    {{-- Interruptor de todo el módulo. --}}
                                    <button type="button" role="switch" :aria-checked="all(@js($moduleKeys)).toString()"
                                        aria-label="Todo el módulo {{ $moduleLabel }}" :disabled="locked"
                                        @click="setAll(@js($moduleKeys), !all(@js($moduleKeys)))"
                                        class="ml-auto flex cursor-pointer items-center gap-2 text-[12px] font-medium text-stone-600 disabled:cursor-not-allowed">
                                        Todo
                                        <span class="relative inline-flex h-5 w-9 shrink-0 rounded-full transition"
                                            :class="all(@js($moduleKeys)) ? 'bg-brand-600' : (count(@js($moduleKeys)) ? 'bg-brand-300' : 'bg-stone-300')">
                                            <span class="absolute top-0.5 left-0.5 size-4 rounded-full bg-white shadow transition"
                                                :class="all(@js($moduleKeys)) && 'translate-x-4'"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($actions as $action => $actionLabel)
                                        @php $key = "{$moduleKey}.{$action}"; @endphp
                                        <label class="group flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 transition select-none"
                                            :class="[
                                                has(@js($key)) ? 'border-brand-200 bg-brand-50/70' : 'border-stone-200 bg-white hover:border-stone-300',
                                                locked && 'cursor-default',
                                            ]">
                                            <input type="checkbox" name="permissions[]" value="{{ $key }}" x-model="granted"
                                                class="sr-only" :disabled="locked">
                                            <span class="grid size-7 shrink-0 place-items-center rounded-md transition"
                                                :class="has(@js($key)) ? 'bg-brand-600 text-white' : 'bg-stone-100 text-stone-500 group-hover:bg-stone-200'">
                                                <x-dynamic-component :component="'lucide-'.($actionIcons[$action] ?? 'circle-dot')" class="size-3.5" />
                                            </span>
                                            <span class="min-w-0 flex-1 text-[13px]"
                                                :class="has(@js($key)) ? 'font-medium text-brand-900' : 'text-stone-700'">{{ $actionLabel }}</span>
                                            <x-lucide-check class="size-4 shrink-0 text-brand-600 transition"
                                                ::class="has({{ \Illuminate\Support\Js::from($key) }}) ? 'opacity-100' : 'opacity-0'" />
                                        </label>
                                    @endforeach
                                </div>
                            </x-card>
                        @endforeach
                    </section>
                @endforeach

                <p x-show="search.trim() !== '' && !@js($sections->flatMap(fn ($s) => collect($tree[$s['name']])->map(fn ($m) => $s['name'].' '.$m[0].' '.implode(' ', $m[1])))->values()).some((t) => matches(t))"
                    x-cloak class="rounded-lg border border-dashed border-stone-300 px-4 py-10 text-center text-[13px] text-stone-500">
                    Ningún módulo ni acción coincide con «<span x-text="search"></span>».
                </p>
            </div>
        </div>

        {{-- Barra fija para guardar: avisa si hay cambios sin guardar. --}}
        @unless ($locked)
            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-stone-200 bg-white/95 backdrop-blur lg:pl-60">
                <div class="flex items-center gap-3 px-6 py-3">
                    <p class="text-[12px]" :class="changes ? 'font-medium text-amber-700' : 'text-stone-500'">
                        <span x-show="changes" x-cloak class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full bg-amber-500"></span>
                            <span x-text="changes === 1 ? '1 cambio sin guardar' : changes + ' cambios sin guardar'"></span>
                        </span>
                        <span x-show="!changes">Sin cambios. Rigen desde la siguiente pantalla que abra el usuario.</span>
                    </p>
                    <div class="ml-auto flex gap-2">
                        <button type="button" class="btn btn-secondary" x-show="changes" x-cloak @click="reset()">
                            <x-lucide-rotate-ccw class="size-4" />
                            Deshacer
                        </button>
                        <a href="{{ ListUrl::get('users.index') }}" class="btn btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary" :disabled="sending || !changes">
                            <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                            <x-lucide-save class="size-4" x-show="!sending" />
                            Guardar permisos
                        </button>
                    </div>
                </div>
            </div>
        @endunless
    </form>
</x-layouts.app>
