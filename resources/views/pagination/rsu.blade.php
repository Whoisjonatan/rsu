{{-- Pie de los listados paginados: "Mostrando 1–15 de 42" y Anterior / Siguiente. --}}
@if ($paginator->total() > 0)
    <nav class="flex items-center justify-between gap-4 border-t border-stone-200 px-4 py-3" aria-label="Paginación">
        <p class="text-[12px] text-stone-500">
            Mostrando <span class="font-medium text-stone-700">{{ $paginator->firstItem() ?? 0 }}</span>–<span
                class="font-medium text-stone-700">{{ $paginator->lastItem() ?? 0 }}</span>
            de <span class="font-medium text-stone-700">{{ $paginator->total() }}</span>
        </p>

        @php
            $button = 'rounded-md border border-stone-300 px-3 py-1.5 text-[12px] font-medium text-stone-600 transition';
        @endphp

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $button }} cursor-not-allowed opacity-40">Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $button }} hover:bg-stone-50">Anterior</a>
            @endif

            <span class="px-2 text-[12px] text-stone-500 tabular-nums">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $button }} hover:bg-stone-50">Siguiente</a>
            @else
                <span class="{{ $button }} cursor-not-allowed opacity-40">Siguiente</span>
            @endif
        </div>
    </nav>
@endif
