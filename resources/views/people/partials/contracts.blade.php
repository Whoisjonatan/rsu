{{-- W09 Contratos del personal. --}}
@php
    $regimes = ['Obrero municipal', 'Empleado municipal', 'Locación de servicios'];
@endphp

<div class="space-y-5" x-data="crud({
    name: 'contract',
    storeUrl: @js(route('contracts.store', $person)),
    updateUrl: @js(route('contracts.update', [$person, '__ID__'])),
    destroyUrl: @js(route('contracts.destroy', [$person, '__ID__'])),
    defaults: {
        type: 'eventual', regime: @js($regimes[0]), start_date: @js(now()->toDateString()), end_date: '',
        salary: '', probation_months: '',
    },
    old: @js(session()->getOldInput()),
    errors: @js($errors->getMessages()),
})">
    <x-card>
        <x-card-header title="Contratos" description="El contrato vigente condiciona si la persona puede ser programada.">
            <button type="button" class="btn btn-primary btn-sm" @click="create()">
                <x-lucide-plus class="size-3.5" />
                Registrar contrato
            </button>
        </x-card-header>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>N.° contrato</th>
                        <th>Tipo</th>
                        <th>Régimen</th>
                        <th>Fecha inicio</th>
                        <th>Fecha término</th>
                        <th>Duración</th>
                        <th class="text-right">Salario</th>
                        <th>Período de prueba</th>
                        <th>Estado</th>
                        <th class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($contracts as $contract)
                        <tr>
                            <td><span class="font-medium text-stone-900">{{ $contract->code ?? '—' }}</span></td>
                            <td>{{ Status::label('contract', $contract->type) }}</td>
                            <td>{{ $contract->regime ?? '—' }}</td>
                            <td>{{ $contract->start_date->format('d/m/Y') }}</td>
                            <td>{{ $contract->end_date?->format('d/m/Y') ?? 'Indefinido' }}</td>
                            <td>{{ $contract->duration_label }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">{{ $contract->salary_label }}</td>
                            <td>{{ $contract->probation_label }}</td>
                            <td>
                                <x-badge :tone="$contract->is_active ? 'green' : 'slate'">
                                    {{ $contract->is_active ? 'Vigente' : 'Culminado' }}
                                </x-badge>
                            </td>
                            <td>
                                <x-row-actions :label="'contrato '.$contract->code" :row="[
                                    'id' => $contract->id,
                                    'type' => $contract->type,
                                    'regime' => $contract->regime,
                                    'start_date' => $contract->start_date->toDateString(),
                                    'end_date' => $contract->end_date?->toDateString(),
                                    'salary' => $contract->salary,
                                    'probation_months' => $contract->probation_months,
                                ]" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="10" message="Esta persona todavía no tiene contratos registrados" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card>
        <x-card-header title="Reglas de contratación" />
        <dl class="divide-y divide-stone-100 p-5 pt-0 text-[13px]">
            @foreach ([
                'Nombrado' => 'Vínculo permanente: solo lleva fecha de inicio. Da derecho a vacaciones.',
                'Permanente' => 'Contrato indefinido: solo lleva fecha de inicio. Da derecho a vacaciones.',
                'Eventual' => 'Contrato temporal: lleva fecha de inicio y de término, por períodos de hasta 2 meses. No da derecho a vacaciones.',
                'Programación' => 'Solo el personal con contrato vigente puede asignarse como conductor o ayudante en las programaciones de rutas.',
            ] as $term => $detail)
                <div class="flex gap-4 py-3">
                    <dt class="w-32 shrink-0 font-semibold text-stone-900">{{ $term }}</dt>
                    <dd class="text-stone-600">{{ $detail }}</dd>
                </div>
            @endforeach
        </dl>
    </x-card>

    <x-crud-form name="contract" title-new="Nuevo contrato" title-edit="Editar contrato">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Tipo de contrato" name="type" required live>
                <select id="type" name="type" x-model="form.type" class="field" :class="error('type') && 'field-error'">
                    @foreach (Status::options('contract') as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Régimen" name="regime" live>
                <select id="regime" name="regime" x-model="form.regime" class="field" :class="error('regime') && 'field-error'">
                    @foreach ($regimes as $regime)
                        <option value="{{ $regime }}">{{ $regime }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Fecha de inicio" name="start_date" required live>
                <input id="start_date" name="start_date" type="date" x-model="form.start_date" required class="field"
                    :class="error('start_date') && 'field-error'">
            </x-field>

            {{--
                Nombrado y permanente son indefinidos: solo llevan fecha de inicio. El
                campo se oculta y queda deshabilitado, así que no viaja en el envío.
            --}}
            <x-field label="Fecha de término" name="end_date" required live hint="Máximo 2 meses"
                x-show="form.type === 'eventual'">
                <input id="end_date" name="end_date" type="date" x-model="form.end_date" class="field"
                    :disabled="form.type !== 'eventual'" :required="form.type === 'eventual'" :min="form.start_date"
                    :class="error('end_date') && 'field-error'">
            </x-field>
            <div x-show="form.type !== 'eventual'" class="self-end pb-2.5 text-[12px] text-stone-500">
                Contrato indefinido: no lleva fecha de término.
            </div>

            <x-field label="Salario mensual (S/)" name="salary" required live>
                <input id="salary" name="salary" type="number" x-model="form.salary" min="1" step="0.01"
                    inputmode="decimal" placeholder="0.00" required class="field tabular-nums"
                    :class="error('salary') && 'field-error'">
            </x-field>

            <x-field label="Período de prueba (meses)" name="probation_months" live hint="Opcional · déjalo vacío si no tiene">
                <input id="probation_months" name="probation_months" type="number" x-model="form.probation_months"
                    min="1" max="{{ App\Models\Contract::MAX_PROBATION_MONTHS }}" step="1" inputmode="numeric"
                    class="field tabular-nums" :class="error('probation_months') && 'field-error'">
            </x-field>
        </div>

        <p x-show="form.type === 'eventual'"
            class="flex items-start gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-2.5 text-[12px] text-stone-600">
            <x-lucide-info class="mt-0.5 size-3.5 shrink-0 text-stone-400" />
            Un contrato eventual solo puede pactarse por períodos de 2 meses.
        </p>
    </x-crud-form>

    <x-confirm-delete title="Eliminar contrato">
        Al quitar el contrato vigente la persona dejará de poder ser programada.
    </x-confirm-delete>
</div>
