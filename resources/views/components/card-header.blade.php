@props(['title', 'description' => null])

<header {{ $attributes->merge(['class' => 'flex items-start justify-between gap-4 border-b border-stone-200 px-5 py-3.5']) }}>
    <div>
        <h2 class="text-sm font-semibold text-stone-900">{{ $title }}</h2>
        @if ($description)
            <p class="mt-0.5 text-[12px] text-stone-500">{{ $description }}</p>
        @endif
    </div>
    {{ $slot }}
</header>
