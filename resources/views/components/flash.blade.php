{{--
    Aviso flotante tras guardar o eliminar. Sale de session('success') o de
    session('error'), que es lo que dejan los controladores al redirigir.
--}}
@php
    $isError = session()->has('error');
    $message = $isError ? session('error') : session('success');
@endphp

@if ($message)
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, {{ $isError ? 8000 : 4000 }})" x-show="show"
        x-transition.opacity.duration.200ms role="status" @class([
            'fixed right-4 bottom-4 z-[60] flex max-w-sm items-start gap-2.5 rounded-lg px-4 py-3 text-[13px] shadow-lg ring-1',
            'bg-red-50 text-red-800 ring-red-200' => $isError,
            'bg-white text-stone-700 ring-stone-200' => ! $isError,
        ])>
        @if ($isError)
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-red-600" />
        @else
            <x-lucide-circle-check class="mt-0.5 size-4 shrink-0 text-brand-600" />
        @endif
        <p class="flex-1">{{ $message }}</p>
        <button type="button" class="-m-1 cursor-pointer rounded p-1 opacity-60 hover:opacity-100" aria-label="Cerrar"
            @click="show = false">
            <x-lucide-x class="size-3.5" />
        </button>
    </div>
@endif
