<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\Vacation;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud de vacaciones (W10), con las reglas del informe: solo personal
 * nombrado o permanente, máximo 30 días por año y sin cruces. El estado no
 * viene en la petición: toda solicitud entra pendiente y se aprueba o
 * rechaza aparte.
 */
class VacationRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.Vacation::MAX_DAYS_PER_YEAR],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
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
     * Lo validado, con los días calculados del rango si no vinieron (ambos
     * extremos incluidos).
     *
     * @return array<string, mixed>
     */
    public function vacationData(): array
    {
        $data = $this->validated();
        $data['days'] = $this->requestedDays();

        return $data;
    }

    private function requestedDays(): int
    {
        return (int) ($this->input('days') ?: Carbon::parse($this->input('start_date'))
            ->diffInDays(Carbon::parse($this->input('end_date'))) + 1);
    }

    /**
     * @return array<string, string>
     */
    private function businessErrors(): array
    {
        /** @var User $person */
        $person = $this->route('person');
        $ignoreId = $this->route('vacation')?->id;

        // "Solo personal nombrado y a contrato permanente".
        if (! $person->isVacationEligible()) {
            return ['year' => 'Solo el personal nombrado o con contrato permanente puede registrar vacaciones.'];
        }

        // "Máximo 30 días por año", contando también lo que espera aprobación.
        $available = Vacation::pendingDays($person->id, (int) $this->input('year'), $ignoreId);

        if ($this->requestedDays() > $available) {
            return ['days' => $available === 0
                ? 'No quedan días disponibles en ese período.'
                : "Solo quedan {$available} días disponibles en ese período."];
        }

        // No puede cruzarse con otra solicitud pendiente ni con vacaciones aprobadas.
        $overlapping = $person->vacations()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereIn('status', Vacation::COMMITTED_STATUSES)
            ->where('start_date', '<=', $this->input('end_date'))
            ->where('end_date', '>=', $this->input('start_date'))
            ->exists();

        if ($overlapping) {
            return ['start_date' => 'Ya hay vacaciones pendientes o aprobadas que se cruzan con estas fechas.'];
        }

        return [];
    }
}
