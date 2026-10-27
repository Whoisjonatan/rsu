<?php

namespace App\Http\Requests;

use App\Models\Scheduling;
use App\Services\Scheduling\SchedulingRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edicion de una programacion (W18).
 *
 * Las reglas viven en SchedulingRules porque la checklist en vivo de W18 y la
 * edicion masiva de W19 tienen que aplicar exactamente las mismas.
 */
class SchedulingRequest extends FormRequest
{
    private ?Scheduling $mergeTarget = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = SchedulingRules::rules();

        // Una programación nueva sale siempre de un grupo de personal activo:
        // de él vienen la zona, el turno y el vehículo. Las anteriores a los
        // grupos se siguen pudiendo editar sin uno.
        if (! $this->route('scheduling')) {
            $rules['staffgroup_id'] = ['required', Rule::exists('staffgroups', 'id')->where('status', 'activo')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...SchedulingRules::messages(),
            'staffgroup_id.required' => 'Elige un grupo de personal.',
            'staffgroup_id.exists' => 'El grupo de personal no existe o no está activo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('weekdays')) {
            $this->merge(['weekdays' => SchedulingRules::normalizeWeekdays($this->input('weekdays'))]);
        }

        // Los clientes que aún mandan un solo helper_id siguen funcionando.
        $helpers = $this->has('helper_ids') ? $this->input('helper_ids') : [$this->input('helper_id')];
        $this->merge(['helper_ids' => SchedulingRules::normalizeHelpers($helpers)]);

        // El formulario no toca los feriados trabajados: al editar se valida con los que ya tiene.
        if ($this->route('scheduling') && ! $this->has('worked_holidays')) {
            $this->merge(['worked_holidays' => $this->route('scheduling')->worked_holidays ?? []]);
        }

        // Un alta que se cruza con una programación del mismo grupo y turno la
        // amplía: se valida (y se guarda) ya con el rango y los días sumados.
        if (! $this->route('scheduling')) {
            $this->mergeTarget = SchedulingRules::mergeTarget($this->all());
        }

        if ($this->mergeTarget) {
            $this->merge(SchedulingRules::mergeInto($this->mergeTarget, $this->all()));
        }
    }

    /** La programación que este alta amplía en vez de crear otra, si la hay. */
    public function mergeTarget(): ?Scheduling
    {
        return $this->mergeTarget;
    }

    public function withValidator(Validator $validator): void
    {
        // Lo que terminó es historia, como las vacaciones gozadas.
        if ($this->route('scheduling')?->isFinished()) {
            $validator->after(fn (Validator $validator) => $validator->errors()
                ->add('status', 'La programación ya finalizó: no se puede editar.'));

            return;
        }

        // Al editar (o ampliar otra), la programacion no debe chocar consigo misma.
        SchedulingRules::attachBusinessRules($validator, $this->route('scheduling')?->id ?? $this->mergeTarget?->id);
    }

    /**
     * Cuantos recorridos generaria. Lo usa la checklist de W18 para decir
     * cuantos dias cubre lo que se esta programando.
     *
     * @return list<string>
     */
    public function occurrenceDates(): array
    {
        return SchedulingRules::occurrenceDates($this->validated());
    }
}
