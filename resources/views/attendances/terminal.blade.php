{{--
    W11 Marcación de asistencia. Terminal compartido, sin navegación lateral y
    sin sesión: el trabajador se identifica con su DNI y su clave.
--}}
@php
    $result = session('marking');
    $blocked = ($result['status'] ?? null) === 'out_of_hours' ? $result['windows'] : null;
    $error = $errors->first('dni') ?: $errors->first('password');
@endphp

<x-layouts.guest title="Marcación de asistencia">
    <div class="flex min-h-screen items-center justify-center bg-cream-100 px-4 py-10">
        <div class="w-full max-w-lg">
            <div class="mb-6 text-center" x-data="{
                now: new Date(),
                init() { setInterval(() => this.now = new Date(), 1000) },
                get date() {
                    const text = new Intl.DateTimeFormat('es-PE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(this.now)
                    return text.charAt(0).toUpperCase() + text.slice(1)
                },
                get time() { return this.now.toLocaleTimeString('es-PE', { hour12: false }) },
            }">
                <x-garbage-truck class="mx-auto h-24 w-48" />
                <h1 class="mt-3 text-xl font-bold text-stone-900">Marcación de asistencia</h1>
                <p class="mt-1 text-[13px] text-stone-500">
                    <span x-text="date"></span> ·
                    <span class="font-mono font-medium text-stone-700 tabular-nums" x-text="time"></span>
                </p>
            </div>

            <form method="POST" action="{{ route('attendances.mark') }}"
                class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm"
                x-data="{ dni: @js(old('dni', '')), password: '', sending: false }" @submit="sending = true">
                @csrf

                <div class="space-y-4">
                    <div>
                        <label for="dni" class="label">DNI <span class="ml-0.5 text-brand-600">*</span></label>
                        <input id="dni" name="dni" inputmode="numeric" maxlength="8" autofocus required autocomplete="off"
                            x-model="dni" @input="dni = dni.replace(/\D/g, '')"
                            @class(['field text-center font-mono text-lg tracking-widest', 'field-error' => $error])>
                        @if ($error)
                            <p class="mt-1.5 flex items-center gap-1 text-[11px] font-medium text-red-600">
                                <x-lucide-circle-alert class="size-3.5 shrink-0" />
                                {{ $error }}
                            </p>
                        @endif
                    </div>

                    <div>
                        <label for="password" class="label">Clave <span class="ml-0.5 text-brand-600">*</span></label>
                        <input id="password" name="password" type="password" required autocomplete="off" x-model="password"
                            class="field text-center text-lg tracking-widest">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary mt-6 h-12 w-full text-sm"
                    :disabled="dni.length !== 8 || password === '' || sending">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                    Registrar asistencia
                </button>

                @if ($result && $result['status'] !== 'out_of_hours')
                    @php($already = $result['status'] === 'already_marked')
                    {{-- El acuse se borra solo, para que el siguiente trabajador no lo vea. --}}
                    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 12000)" x-show="show" x-transition.opacity
                        @class([
                            'mt-5 flex items-start gap-3 rounded-lg border px-4 py-3',
                            'border-sky-200 bg-sky-50' => $already,
                            'border-brand-200 bg-brand-50' => ! $already,
                        ])>
                        <x-lucide-circle-check @class(['mt-0.5 size-4 shrink-0', 'text-sky-600' => $already, 'text-brand-600' => ! $already]) />
                        <div class="min-w-0">
                            <p @class(['text-[13px] font-semibold', 'text-sky-900' => $already, 'text-brand-900' => ! $already])>
                                {{ $result['message'] }}
                            </p>
                            <p class="mt-0.5 text-[12px] text-stone-600">
                                Turno {{ $result['attendance']['schedule'] }} ·
                                {{ $result['attendance']['status'] === 'tarde' ? 'registrada como tardanza' : 'a tiempo' }}
                                @if ($result['attendance']['zone'])
                                    · zona {{ $result['attendance']['zone'] }}
                                @endif
                            </p>
                        </div>
                    </div>
                @endif

                @if ($blocked)
                    <div class="mt-5 rounded-lg border-2 border-amber-300 bg-amber-50 px-4 py-3">
                        <p class="flex items-center gap-2 text-[13px] font-bold text-amber-900">
                            <x-lucide-triangle-alert class="size-4 shrink-0" />
                            Marcación fuera de horario
                        </p>
                        <p class="mt-1.5 text-[12px] text-amber-800">
                            Comuníquese con el supervisor de zona. Los turnos admiten marcación en estos horarios:
                        </p>
                        <ul class="mt-2 space-y-1">
                            @foreach ($blocked as $window)
                                <li class="flex items-center justify-between text-[12px] text-amber-900">
                                    <span class="font-medium">{{ $window['name'] }}</span>
                                    <span class="font-mono tabular-nums">{{ $window['opens'] }} – {{ $window['closes'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </form>

            @unless ($blocked)
                <div class="mt-5 rounded-xl border border-stone-200 bg-white px-5 py-4">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-stone-500 uppercase">
                        <x-lucide-clock class="size-3.5" />
                        Horarios de marcación
                    </p>
                    <ul class="mt-2.5 space-y-1.5">
                        @foreach ($windows as $window)
                            <li class="flex items-center justify-between text-[12px] text-stone-600">
                                <span class="font-medium text-stone-800">{{ $window['name'] }}</span>
                                <span class="font-mono tabular-nums">{{ $window['opens'] }} – {{ $window['closes'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endunless

            <p class="mt-4 text-center text-[11px] text-stone-400">La marcación habilita el inicio del recorrido del día.</p>
        </div>
    </div>
</x-layouts.guest>
