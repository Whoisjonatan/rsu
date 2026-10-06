{{--
    Aviso de confirmación tras crear, modificar o eliminar. Sale de
    session('success') o de session('error'), que es lo que dejan los
    controladores al redirigir. El título se deduce del mensaje, para que los
    controladores sigan dejando una sola frase.
--}}
@php
    $isError = session()->has('error');
    $message = $isError ? session('error') : session('success');
    $title = match (true) {
        $isError => 'No se pudo completar',
        (bool) preg_match('/eliminad/i', (string) $message) => 'Eliminado correctamente',
        (bool) preg_match('/actualizad|guardad/i', (string) $message) => 'Modificado correctamente',
        default => 'Registrado correctamente',
    };
    $duration = $isError ? 8000 : 5000;
@endphp

@if ($message)
    <div x-data="{ show: false }" x-init="$nextTick(() => show = true); setTimeout(() => show = false, {{ $duration }})"
        x-show="show" x-cloak
        x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-3 opacity-0"
        x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="-translate-y-3 opacity-0"
        role="{{ $isError ? 'alert' : 'status' }}"
        class="fixed inset-x-4 top-4 z-[60] mx-auto max-w-md overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-stone-200">
        <div class="flex items-start gap-3 px-4 py-3.5">
            <span @class([
                'grid size-9 shrink-0 place-items-center rounded-full',
                'bg-red-100 text-red-600' => $isError,
                'bg-brand-100 text-brand-700' => ! $isError,
            ])>
                @if ($isError)
                    <x-lucide-circle-alert class="size-5" />
                @else
                    <x-lucide-circle-check class="size-5" />
                @endif
            </span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-[14px] font-semibold text-stone-900">{{ $title }}</p>
                <p class="mt-0.5 text-[13px] text-stone-600">{{ $message }}</p>
            </div>
            <button type="button" class="-m-1 cursor-pointer rounded p-1 text-stone-400 hover:text-stone-700"
                aria-label="Cerrar" @click="show = false">
                <x-lucide-x class="size-4" />
            </button>
        </div>
        {{-- Barra que se vacía mientras el aviso sigue en pantalla. --}}
        <div @class(['h-1 origin-left', 'bg-red-500' => $isError, 'bg-brand-500' => ! $isError])
            style="animation: flash-countdown {{ $duration }}ms linear forwards"></div>
    </div>

    <style>
        @keyframes flash-countdown {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }
    </style>
@endif
