{{-- Página de error (404, 419, 500...) con el estilo del sistema. --}}
@props(['code', 'title', 'message'])

<x-layouts.guest :title="$title">
    <div class="flex min-h-screen items-center justify-center bg-cream-100 px-4 py-10">
        <div class="w-full max-w-md text-center">
            <x-garbage-truck class="mx-auto h-24 w-48" />
            <p class="mt-4 font-mono text-[12px] font-semibold tracking-widest text-brand-500">ERROR {{ $code }}</p>
            <h1 class="mt-1 text-lg font-bold text-stone-900">{{ $title }}</h1>
            <p class="mt-2 text-[13px] text-stone-500">{{ $message }}</p>
            <div class="mt-6 flex justify-center gap-2">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="btn btn-secondary">
                    <x-lucide-arrow-left class="size-4" />
                    Volver
                </a>
                <a href="{{ url('/') }}" class="btn btn-primary">Ir al inicio</a>
            </div>
        </div>
    </div>
</x-layouts.guest>
