{{-- Gestión de cambios › Motivos. Los usa la reprogramación (Rescheduling) al cambiar turno, vehículo o personal. --}}
@php
    use App\Models\ChangeReason;

    $tabs = ['' => 'Todos', ...ChangeReason::TYPES];
    $tones = ['turno' => 'blue', 'vehiculo' => 'amber', 'personal' => 'green', 'general' => 'slate'];
    $icons = ['turno' => 'clock', 'vehiculo' => 'truck', 'personal' => 'users', 'general' => 'layers'];
@endphp

<x-layouts.app title="Motivos" subtitle="Gestión de cambios › Motivos que se eligen al cambiar el turno, el vehículo o el personal de una programación">
    <nav class="mb-5 flex flex-wrap gap-1 border-b border-stone-200" aria-label="Filtrar por tipo de cambio">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('change-reasons.index', array_filter(['tipo' => $key])) }}" @class([
                '-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-[13px] font-medium whitespace-nowrap transition',
                'border-brand-600 text-brand-700' => (string) $type === (string) $key,
                'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-700' => (string) $type !== (string) $key,
            ])>
                {{ $label }}
                <span class="rounded-full bg-stone-100 px-1.5 text-[11px] text-stone-500 tabular-nums">
                    {{ $key === '' ? $counts->sum() : $counts->get($key, 0) }}
                </span>
            </a>
        @endforeach
    </nav>

    <div x-data="crud({
        name: 'reason',
        storeUrl: @js(route('change-reasons.store')),
        updateUrl: @js(route('change-reasons.update', '__ID__')),
        destroyUrl: @js(route('change-reasons.destroy', '__ID__')),
        defaults: { name: '', type: @js($type ?? 'turno'), requires_description: false, description: '' },
        old: @js(session()->getOldInput()),
        errors: @js($errors->getMessages()),
    })">
        <x-card>
            <x-card-header title="Motivos registrados"
                description="Se ofrecen en la reprogramación y en el Dashboard según el tipo de cambio; los de «Todos los cambios», en los tres.">
                <button type="button" class="btn btn-primary btn-sm" @click="create()">
                    <x-lucide-plus class="size-3.5" />
                    Nuevo motivo
                </button>
            </x-card-header>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Motivo</th>
                            <th>Tipo de cambio</th>
                            <th>Pide descripción</th>
                            <th>Detalle</th>
                            <th class="text-right">Usos</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reasons as $reason)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-50 text-brand-600">
                                            <x-dynamic-component :component="'lucide-'.$icons[$reason->type]" class="size-3.5" />
                                        </span>
                                        <span class="font-medium text-stone-900">{{ $reason->name }}</span>
                                    </div>
                                </td>
                                <td><x-badge :tone="$tones[$reason->type]">{{ $reason->typeLabel() }}</x-badge></td>
                                <td>
                                    @if ($reason->requires_description)
                                        <span class="inline-flex items-center gap-1 text-[12px] text-stone-700">
                                            <x-lucide-check class="size-3.5 text-brand-600" />
                                            Obligatoria
                                        </span>
                                    @else
                                        <span class="text-[12px] text-stone-400">Opcional</span>
                                    @endif
                                </td>
                                <td class="max-w-72 whitespace-normal"><span class="text-stone-500">{{ $reason->description ?: '—' }}</span></td>
                                <td class="text-right tabular-nums">{{ $usage->get($reason->name, 0) }}</td>
                                <td>
                                    <x-row-actions :label="$reason->name" :row="[
                                        'id' => $reason->id,
                                        'name' => $reason->name,
                                        'type' => $reason->type,
                                        'requires_description' => $reason->requires_description,
                                        'description' => $reason->description,
                                    ]" />
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" :message="$type ? 'No hay motivos para este tipo de cambio' : 'Todavía no hay motivos registrados'" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex items-start gap-2.5 border-t border-stone-200 bg-stone-50 px-4 py-3 text-[12px] text-stone-600">
                <x-lucide-info class="mt-0.5 size-4 shrink-0 text-stone-400" />
                <p>
                    El historial de cada programación guarda el texto del motivo: editar o eliminar uno no cambia los cambios ya registrados.
                    «Usos» cuenta cuántos cambios lo nombran.
                </p>
            </div>
        </x-card>

        <x-crud-form name="reason" title-new="Nuevo motivo" title-edit="Editar motivo">
            <x-field label="Motivo" name="name" required live>
                <input id="name" name="name" x-model="form.name" placeholder="Avería" maxlength="100" required class="field"
                    :class="error('name') && 'field-error'">
            </x-field>
            <x-field label="Tipo de cambio" name="type" required live hint="En qué cambios se ofrece este motivo.">
                <select id="type" name="type" x-model="form.type" required class="field" :class="error('type') && 'field-error'">
                    @foreach (ChangeReason::TYPES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>
            <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-stone-200 px-3 py-2.5 text-[13px] transition hover:bg-stone-50">
                <input type="hidden" name="requires_description" value="0">
                <input type="checkbox" name="requires_description" value="1" class="mt-0.5 size-4 shrink-0 accent-brand-600"
                    :checked="form.requires_description === true || form.requires_description === '1'"
                    @change="form.requires_description = $event.target.checked">
                <span>
                    <span class="font-medium text-stone-800">Pide descripción</span>
                    <span class="block text-[12px] text-stone-500">Al elegirlo hay que describir el cambio, como con «Otro».</span>
                </span>
            </label>
            <x-field label="Detalle" name="description" live hint="Opcional: cuándo usar este motivo.">
                <input id="description" name="description" x-model="form.description" maxlength="255" class="field"
                    :class="error('description') && 'field-error'">
            </x-field>
        </x-crud-form>

        <x-confirm-delete title="Eliminar motivo">
            Dejará de ofrecerse al reprogramar. Los cambios ya registrados conservan su motivo.
        </x-confirm-delete>
    </div>
</x-layouts.app>
