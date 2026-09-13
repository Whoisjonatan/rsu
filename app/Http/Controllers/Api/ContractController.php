<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contratos del personal (W09). Anidado bajo /people/{person}/contracts.
 */
class ContractController extends Controller
{
    public function index(User $person): JsonResponse
    {
        return response()->json(
            $person->contracts()->orderByDesc('start_date')->get()
        );
    }

    public function store(Request $request, User $person): JsonResponse
    {
        $data = $this->validated($request);

        $this->assertRules($data, $person);

        $data['code'] ??= Contract::nextCode(Carbon::parse($data['start_date'])->year);

        return response()->json($person->contracts()->create($data), 201);
    }

    public function update(Request $request, User $person, Contract $contract): JsonResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $data = $this->validated($request, $contract->id);
        $this->assertRules($data, $person, $contract->id);

        $contract->update($data);

        return response()->json($contract->fresh());
    }

    public function destroy(User $person, Contract $contract): JsonResponse
    {
        abort_if($contract->user_id !== $person->id, 404);

        $contract->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:30', Rule::unique('contracts', 'code')->ignore($id)],
            'type' => ['required', Rule::in(['nombrado', 'permanente', 'eventual'])],
            'regime' => ['nullable', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Reglas que el informe fija para la contratación.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRules(array $data, User $person, ?int $ignoreId = null): void
    {
        $errors = [];
        $start = Carbon::parse($data['start_date']);
        $end = $data['end_date'] ? Carbon::parse($data['end_date']) : null;

        if ($data['type'] === 'eventual') {
            if (! $end) {
                $errors['end_date'] = 'Un contrato eventual necesita fecha de término.';
            } else {
                // "Los contratos eventuales pueden ser solo cada 2 meses".
                $months = $start->diffInMonths($end->copy()->addDay());

                if ($months > Contract::TEMPORARY_MONTHS) {
                    $errors['end_date'] = 'Un contrato eventual solo puede pactarse por períodos de 2 meses.';
                }
            }
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

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
