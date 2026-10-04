{{-- Fila de "sin registros" de las tablas. --}}
@props(['colspan', 'message' => 'No hay registros'])

<tr class="hover:bg-transparent">
    <td colspan="{{ $colspan }}" class="px-4 py-16 text-center">
        <x-lucide-inbox class="mx-auto size-6 text-stone-300" />
        <p class="mt-2 text-[13px] text-stone-500">{{ $message }}</p>
    </td>
</tr>
