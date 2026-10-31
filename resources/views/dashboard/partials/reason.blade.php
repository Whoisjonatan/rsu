{{-- Motivo de un cambio del editor: sale solo si esa sección cambió. $section es turno, vehiculo o personal. --}}
<div x-show="changed.{{ $section }}" x-cloak class="grid gap-3 rounded-lg border border-dashed border-stone-300 p-3 sm:grid-cols-2">
    <x-field label="Motivo" for="reason_{{ $section }}" required>
        <select id="reason_{{ $section }}" x-model="editor.reasons.{{ $section }}.reason" class="field">
            <option value="">Selecciona un motivo</option>
            @foreach ($reasons[$section] as $reason)
                <option value="{{ $reason['name'] }}">{{ $reason['name'] }}</option>
            @endforeach
        </select>
    </x-field>
    <x-field label="Descripción" for="description_{{ $section }}">
        <input id="description_{{ $section }}" type="text" maxlength="500" x-model="editor.reasons.{{ $section }}.description" class="field"
            :placeholder="needsDescription('{{ $section }}', editor.reasons.{{ $section }}.reason) ? 'Obligatoria con este motivo' : 'Opcional'">
    </x-field>
</div>
