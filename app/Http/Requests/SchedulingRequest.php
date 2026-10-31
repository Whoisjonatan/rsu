<?php

namespace App\Http\Requests;

use App\Models\GeneralScheduling;
use App\Services\Scheduling\SchedulingRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edicion de una programacion (W18).
 *
 * El alta registra una programación general y la desglosa en una por día
 * (GeneralScheduling::register); la edición por API cambia un solo día.
 *
 * Las reglas viven en SchedulingRules porque la checklist en vivo de W18 y la
 * edicion masiva de W19 tienen que aplicar exactamente las mismas.
 */
class SchedulingRequest extends FormRequest
{
    private ?GeneralScheduling $mergeTarget = null;

    /** @var list<string> */
    private array $skipDates = [];

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

        // En un alta, los días que el grupo ya tiene programados en ese turno
        // no se validan ni se vuelven a crear, y si hay una general que se
        // cruza, los días nuevos se suman a ella.
        if (! $this->route('scheduling')) {
            $this->skipDates = SchedulingRules::programmedDates($this->all());
            $this->mergeTarget = SchedulingRules::mergeTarget($this->all());
            $this->merge(['skip_dates' => $this->skipDates]);
        }
    }

    /** La programación general a la que este alta suma sus días, si la hay. */
    public function mergeTarget(): ?GeneralScheduling
    {
        return $this->mergeTarget;
    }

    /**
     * Días del rango que el grupo ya tenía programados en ese turno.
     *
     * @return list<string>
     */
    public function skipDates(): array
    {
        return $this->skipDates;
    }

    public function withValidator(Validator $validator): void
    {
        // Lo que terminó es historia, como las vacaciones gozadas.
        if ($this->route('scheduling')?->isFinished()) {
            $validator->after(fn (Validator $validator) => $validator->errors()
                ->add('status', 'La programación ya finalizó: no se puede editar.'));

            return;
        }

        // Al editar, la programacion no debe chocar consigo misma.
        SchedulingRules::attachBusinessRules($validator, $this->route('scheduling')?->id);
    }

    /**
     * Los días que se crearían. Lo usa la checklist de W18 para decir
     * cuantos dias cubre lo que se esta programando.
     *
     * @return list<string>
     */
    public function occurrenceDates(): array
    {
        return SchedulingRules::occurrenceDates([...$this->validated(), 'skip_dates' => $this->skipDates]);
    }
}
