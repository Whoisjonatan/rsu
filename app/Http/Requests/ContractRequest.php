<?php

namespace App\Http\Requests;

use App\Models\Contract;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato del personal (W09), con las reglas de contratación del informe.
 * Lo usan el API (people/{person}/contracts) y la ficha Blade.
 */
class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30', Rule::unique('contracts', 'code')->ignore($this->route('contract'))],
            'type' => ['required', Rule::in(['nombrado', 'permanente', 'eventual'])],
            'regime' => ['nullable', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'salary' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:99999999.99'],
            'probation_months' => ['nullable', 'integer', 'min:1', 'max:'.Contract::MAX_PROBATION_MONTHS],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'salary.required' => 'Ingresa el salario.',
            'salary.numeric' => 'El salario debe ser un monto.',
            'salary.decimal' => 'El salario admite hasta 2 decimales.',
            'salary.min' => 'El salario debe ser mayor a cero.',
            'probation_months.integer' => 'El período de prueba se indica en meses enteros.',
            'probation_months.min' => 'El período de prueba debe ser de al menos 1 mes.',
            'probation_months.max' => 'El período de prueba no puede pasar de '.Contract::MAX_PROBATION_MONTHS.' meses.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Nombrado y permanente son indefinidos: no llevan fecha de término.
        if (in_array($this->input('type'), Contract::PERMANENT_TYPES, true)) {
            $this->merge(['end_date' => null]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->businessErrors() as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /**
     * Lo validado, listo para guardar. El correlativo CON-AAAA-NNN lo pone el
     * sistema si no viene uno.
     *
     * @return array<string, mixed>
     */
    public function contractData(): array
    {
        $data = $this->validated();

        if (! $this->route('contract')) {
            $data['code'] ??= Contract::nextCode(Carbon::parse($data['start_date'])->year);
        }

        return $data;
    }

    /**
     * Reglas que el informe fija para la contratación.
     *
     * @return array<string, string>
     */
    private function businessErrors(): array
    {
        /** @var User $person */
        $person = $this->route('person');
        $ignoreId = $this->route('contract')?->id;
        $errors = [];

        $start = Carbon::parse($this->input('start_date'));
        $end = $this->input('end_date') ? Carbon::parse($this->input('end_date')) : null;

        if ($this->input('type') === 'eventual') {
            if (! $end) {
                $errors['end_date'] = 'Un contrato eventual necesita fecha de término.';
            } elseif ($start->diffInMonths($end->copy()->addDay()) > Contract::TEMPORARY_MONTHS) {
                // "Los contratos eventuales pueden ser solo cada 2 meses".
                $errors['end_date'] = 'Un contrato eventual solo puede pactarse por períodos de 2 meses.';
            }
        }

        // El período de prueba no puede durar más que el propio contrato.
        $probation = (int) $this->input('probation_months');

        if ($end && $probation > 0 && $start->copy()->addMonthsNoOverflow($probation)->subDay()->gt($end)) {
            $errors['probation_months'] = 'El período de prueba no puede ser más largo que el contrato.';
        }

        // Dos contratos no pueden solaparse para la misma persona.
        $overlapping = $person->contracts()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('start_date', '<=', $end?->toDateString() ?? '9999-12-31')
            ->where(function ($q) use ($start) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $start->toDateString());
            })
            ->exists();

        if ($overlapping) {
            $errors['start_date'] = 'Ya existe un contrato que se cruza con estas fechas.';
        }

        return $errors;
    }
}
