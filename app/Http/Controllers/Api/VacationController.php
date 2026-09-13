<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vacation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vacaciones del personal (W10). Anidado bajo /people/{person}/vacations.
 */
class VacationController extends Controller
{
    public function index(Request $request, User $person): JsonResponse
    {
        $year = $request->integer('year', (int) now()->year);

        return response()->json([
            'data' => $person->vacations()->orderByDesc('start_date')->get(),
            'summary' => [
                'year' => $year,
                'max_days' => Vacation::MAX_DAYS_PER_YEAR,
                'scheduled_days' => Vacation::usedDays($person->id, $year),
                'pending_days' => Vacation::pendingDays($person->id, $year),
                // Si no tiene contrato que dé derecho, W10 no deja registrar.
                'eligible' => $this->isEligible($person),
            ],
        ]);
    }

    public function store(Request $request, User $person): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertRules($data, $person);

        return response()->json($person->vacations()->create($data), 201);
    }

    public function update(Request $request, User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        $data = $this->validated($request);
        $this->assertRules($data, $person, $vacation->id);

        $vacation->update($data);

        return response()->json($vacation->fresh());
    }

    public function destroy(User $person, Vacation $vacation): JsonResponse
    {
        abort_if($vacation->user_id !== $person->id, 404);

        $vacation->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.Vacation::MAX_DAYS_PER_YEAR],
            'status' => ['nullable', Rule::in(['programadas', 'gozadas', 'canceladas'])],
        ]);

        // Si no mandan los días, se calculan del rango (ambos extremos incluidos).
        $data['days'] ??= Carbon::parse($data['start_date'])
            ->diffInDays(Carbon::parse($data['end_date'])) + 1;

        $data['status'] ??= 'programadas';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertRules(array $data, User $person, ?int $ignoreId = null): void
    {
        // "Solo personal nombrado y a contrato permanente".
        if (! $this->isEligible($person)) {
            throw ValidationException::withMessages([
                'year' => 'Solo el personal nombrado o con contrato permanente puede registrar vacaciones.',
            ]);
        }

        // "Máximo 30 días por año".
        $pending = Vacation::pendingDays($person->id, (int) $data['year'], $ignoreId);

        if ($data['days'] > $pending) {
            throw ValidationException::withMessages([
                'days' => $pending === 0
                    ? 'No quedan días pendientes en ese período.'
                    : "Solo quedan {$pending} días pendientes en ese período.",
            ]);
        }

        // Dos períodos de vacaciones no pueden solaparse.
        $overlapping = $person->vacations()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('status', '!=', 'canceladas')
            ->where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date'])
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages([
                'start_date' => 'Ya hay vacaciones registradas que se cruzan con estas fechas.',
            ]);
        }
    }

    private function isEligible(User $person): bool
    {
        return $person->contracts()
            ->activeOn(now()->toDateString())
            ->whereIn('type', \App\Models\Contract::PERMANENT_TYPES)
            ->exists();
    }
}
