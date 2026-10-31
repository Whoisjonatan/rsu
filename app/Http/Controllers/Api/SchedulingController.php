<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchedulingRequest;
use App\Http\Resources\SchedulingResource;
use App\Models\GeneralScheduling;
use App\Models\Holiday;
use App\Models\Scheduling;
use App\Models\User;
use App\Models\UserType;
use App\Models\Zone;
use App\Services\Scheduling\BulkScheduling;
use App\Services\Scheduling\SchedulingRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Programacion maestra (W17, W18).
 *
 * Guardar una programacion hace dos cosas: escribe la regla en "schedulings" y
 * genera los recorridos de cada dia en "vehicleroutes", que es lo que leen el
 * calendario (W20) y el inicio de recorrido (W21).
 */
class SchedulingController extends Controller
{
    private const RELATIONS = Scheduling::RELATIONS;

    public function index(Request $request): AnonymousResourceCollection
    {
        Scheduling::closeFinished();

        $schedulings = Scheduling::query()
            ->with(self::RELATIONS)
            ->withCount('vehicleRoutes')
            ->filter($request->all())
            ->orderByDesc('start_date')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        // La columna ESTADO de W17 es el estado del recorrido de ese día.
        if ($request->filled('date')) {
            Scheduling::attachDayStatus($schedulings->getCollection(), $request->date('date')->format('Y-m-d'));
        }

        return SchedulingResource::collection($schedulings);
    }

    /**
     * Registra la programación general y devuelve los días creados, uno por
     * programación. Siempre 201: aunque se sumen a una general existente
     * (ver SchedulingRules::mergeTarget), los días son nuevos.
     */
    public function store(SchedulingRequest $request): JsonResponse
    {
        $days = GeneralScheduling::register($request->validated(), $request->skipDates(), $request->mergeTarget());
        $general = $days->first()->general;

        return SchedulingResource::collection(Scheduling::with(self::RELATIONS)->withCount('vehicleRoutes')->findMany($days->pluck('id')))
            ->additional(['general' => [
                'id' => $general->id,
                'code' => $general->code,
                'start_date' => $general->start_date->toDateString(),
                'end_date' => $general->end_date->toDateString(),
                'merged' => $request->mergeTarget() !== null,
                'kept_dates' => $request->skipDates(),
            ]])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Scheduling $scheduling): SchedulingResource
    {
        return new SchedulingResource(
            $scheduling->load(self::RELATIONS)->loadCount('vehicleRoutes'),
        );
    }

    public function update(SchedulingRequest $request, Scheduling $scheduling): SchedulingResource
    {
        $scheduling->saveAndSync($request->validated());

        return new SchedulingResource($scheduling->load(self::RELATIONS)->loadCount('vehicleRoutes'));
    }

    public function destroy(Scheduling $scheduling): JsonResponse
    {
        if ($blocker = $scheduling->deletionBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $scheduling->delete();

        return response()->json(null, 204);
    }

    /** Finalizar a mano, antes de su fecha de término (ver Scheduling::finish). */
    public function finish(Scheduling $scheduling): JsonResponse|SchedulingResource
    {
        if ($blocker = $scheduling->finishBlocker()) {
            return response()->json(['message' => $blocker], 409);
        }

        $scheduling->finish();

        return new SchedulingResource($scheduling->load(self::RELATIONS)->loadCount('vehicleRoutes'));
    }

    /**
     * Valida sin guardar: es la checklist viva del panel derecho de W18, que
     * se actualiza mientras el usuario elige y no al pulsar "Programar".
     *
     * Reutiliza el mismo SchedulingRequest, asi que no hay dos juegos de
     * reglas que puedan separarse con el tiempo.
     */
    public function validateDraft(Request $request): JsonResponse
    {
        // A proposito NO se usa SchedulingRequest: un FormRequest aborta con
        // 422 al primer fallo y W18 necesita las cuatro filas a la vez, tres
        // en verde y una en ambar. Aqui siempre se responde 200 con el estado
        // de cada una.
        $data = $request->all();
        $ignoreId = $request->integer('id') ?: null;

        // El formulario no toca los feriados trabajados: al editar se valida con los que ya tiene.
        if ($ignoreId && ! array_key_exists('worked_holidays', $data)) {
            $data['worked_holidays'] = Scheduling::find($ignoreId)?->worked_holidays ?? [];
        }

        // Un alta se valida como lo que se guardará: sin los días que el grupo
        // ya tiene programados, y sumando a la general que se cruza, si la hay.
        $target = $ignoreId ? null : SchedulingRules::mergeTarget($data);
        $data['skip_dates'] = $ignoreId ? [] : SchedulingRules::programmedDates($data);

        $validator = SchedulingRules::validator($data, $ignoreId);
        $validator->fails();

        $errors = $validator->errors();
        $all = implode(' | ', $errors->all());

        // Cada fila de la checklist se enciende por el texto del error que la
        // provoca: las reglas de negocio devuelven varios mensajes sobre el
        // mismo campo (driver_id puede fallar por contrato Y por vacaciones).
        $checks = [
            'contrato' => [
                'label' => 'Personal con contrato vigente',
                'ok' => ! str_contains($all, 'contrato vigente'),
            ],
            'vehiculo' => [
                'label' => 'Vehículo disponible',
                'ok' => ! $errors->has('vehicle_id'),
            ],
            'duplicidad' => [
                'label' => 'Sin duplicidad de turno',
                'ok' => ! str_contains($all, 'ya está programado') && ! str_contains($all, 'pertenece al grupo'),
            ],
            'vacaciones' => [
                'label' => 'Personal sin vacaciones',
                'ok' => ! str_contains($all, 'de vacaciones'),
            ],
        ];

        return response()->json([
            'valid' => $errors->isEmpty(),
            'checks' => $checks,
            'errors' => $errors->toArray(),
            // "Generará N recorridos": ayuda a entender el rango antes de guardar.
            'occurrences' => count(SchedulingRules::occurrenceDates($data)),
            // Los feriados que caen en un día marcado: explican por qué salen menos recorridos.
            'holidays' => $this->holidaysSkipped($data),
            // W18 avisa de que los días se sumarán a esta general en vez de crear otra.
            'merge' => $target ? (fn (array $merged) => [
                'code' => $target->code,
                'start_date' => $merged['start_date'],
                'end_date' => $merged['end_date'],
                'weekdays' => $merged['weekdays'],
            ])(SchedulingRules::mergeInto($target, $data)) : null,
            // Días que el grupo ya tiene programados en ese turno: se mantienen.
            'kept' => $data['skip_dates'] !== [] ? SchedulingRules::dateList($data['skip_dates']) : null,
        ]);
    }

    /**
     * Feriados activos del rango que caen en uno de los días marcados. Esos
     * días no llevan recorrido (salvo los trabajados); el mismo día de la
     * semana siguiente sí.
     *
     * @return list<array{date: string, description: string}>
     */
    private function holidaysSkipped(array $data): array
    {
        $weekdays = SchedulingRules::normalizeWeekdays($data['weekdays'] ?? null);
        $worked = SchedulingRules::normalizeDates($data['worked_holidays'] ?? null);

        if ($weekdays === [] || blank($data['start_date'] ?? null) || blank($data['end_date'] ?? null)) {
            return [];
        }

        return Holiday::active()
            ->whereDate('date', '>=', $data['start_date'])
            ->whereDate('date', '<=', $data['end_date'])
            ->orderBy('date')
            ->get()
            ->filter(fn (Holiday $holiday) => in_array($holiday->date->dayOfWeekIso, $weekdays, true)
                && ! in_array($holiday->date->toDateString(), $worked, true))
            ->map(fn (Holiday $holiday) => [
                'date' => $holiday->date->toDateString(),
                'description' => $holiday->description,
            ])
            ->values()
            ->all();
    }

    /**
     * Programación masiva (W19), "Validar disponibilidad": el resultado de la
     * validación general, grupo por grupo, sin guardar nada.
     */
    public function bulkValidate(Request $request): JsonResponse
    {
        $bulk = new BulkScheduling($request->validate(BulkScheduling::rules(), BulkScheduling::messages()));

        return response()->json($bulk->report());
    }

    /**
     * Programación masiva (W19), guardar. Se vuelve a validar todo: si queda
     * una sola inconsistencia no se guarda nada y se devuelve el resultado
     * para corregirla.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $input = $request->validate(BulkScheduling::rules(), BulkScheduling::messages());
        $bulk = new BulkScheduling($input);

        if (! $bulk->isValid()) {
            return response()->json([
                'message' => 'Hay inconsistencias: corrígelas y vuelve a validar.',
                ...$bulk->report(),
            ], 422);
        }

        $totals = $bulk->save();
        $message = sprintf(
            'Programación masiva registrada: %s (%s, %s) con %s y %s.',
            $this->plural($totals['created'] + $totals['merged'], 'programación general', 'programaciones generales'),
            $this->plural($totals['created'], 'nueva', 'nuevas'),
            $this->plural($totals['merged'], 'ampliada', 'ampliadas'),
            $this->plural($totals['days'], 'programación individual', 'programaciones individuales'),
            $this->plural($totals['routes'], 'recorrido', 'recorridos'),
        );

        // La pantalla vuelve al listado y el aviso sale allí.
        if ($request->hasSession()) {
            $request->session()->flash('success', $message);
        }

        return response()->json([
            'message' => $message,
            ...$totals,
            'redirect' => route('schedulings.index', ['tab' => 'general', 'date_from' => $input['start_date'], 'date_to' => $input['end_date']]),
        ], 201);
    }

    /**
     * Edicion masiva (W19): aplica los mismos cambios a varias programaciones.
     *
     * La pantalla lo dice con todas las letras: "el sistema rechazará las filas
     * que incumplan una validación y aplicará el resto". Por eso cada fila se
     * valida y se guarda por separado, y se devuelve el detalle de las que no
     * pasaron en vez de abortar el lote entero.
     *
     * Con `dry_run` no escribe nada: solo dice qué pasaria. Es lo que alimenta
     * el aviso previo de W19 ("2 programaciones quedarán duplicadas en turno y
     * vehículo"), que tiene que salir ANTES de pulsar "Aplicar cambios".
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:schedulings,id'],
            'dry_run' => ['sometimes', 'boolean'],
            // Solo los campos que W19 ofrece cambiar.
            'changes' => ['required', 'array', 'min:1'],
            'changes.schedule_id' => ['sometimes', 'exists:schedules,id'],
            'changes.vehicle_id' => ['sometimes', Rule::exists('vehicles', 'id')->where('status', 'operativo')],
            'changes.driver_id' => ['sometimes', 'exists:users,id'],
            // Reemplaza a todos los ayudantes; vacío los quita.
            'changes.helper_ids' => ['sometimes', 'array'],
            'changes.helper_ids.*' => ['integer', 'exists:users,id'],
            'changes.end_date' => ['sometimes', 'date'],
            'changes.status' => ['sometimes', Rule::in(['activa', 'inactiva'])],
        ], [], ['changes.vehicle_id' => 'vehículo']);

        $simulacion = $request->boolean('dry_run');
        $aplicadas = [];
        $rechazadas = [];

        foreach (Scheduling::with('helpers')->findMany($data['ids']) as $scheduling) {
            if ($scheduling->isFinished()) {
                $rechazadas[] = [
                    'id' => $scheduling->id,
                    'code' => $scheduling->code,
                    'errors' => ['La programación ya finalizó: no se puede editar.'],
                ];

                continue;
            }

            $payload = array_merge($scheduling->only([
                'zone_id', 'route_id', 'vehicle_id', 'schedule_id',
                'driver_id', 'status',
            ]), [
                'helper_ids' => $scheduling->helperIds(),
                'weekdays' => array_map('intval', $scheduling->weekdays ?? []),
                // Sin esto, un día que cae en feriado trabajado se quedaría sin fecha.
                'worked_holidays' => $scheduling->worked_holidays ?? [],
                'start_date' => $scheduling->start_date->toDateString(),
                'end_date' => $scheduling->end_date->toDateString(),
            ], $data['changes']);

            // Se ignora a sí misma para que no choque con su propio horario.
            $validator = SchedulingRules::validator($payload, $scheduling->id);

            if ($validator->fails()) {
                $rechazadas[] = [
                    'id' => $scheduling->id,
                    'code' => $scheduling->code,
                    'errors' => $validator->errors()->all(),
                ];

                continue;
            }

            if (! $simulacion) {
                $scheduling->saveAndSync($payload);
            }

            $aplicadas[] = ['id' => $scheduling->id, 'code' => $scheduling->code];
        }

        $hechas = count($aplicadas);
        $fallidas = count($rechazadas);

        if ($simulacion) {
            return response()->json([
                'dry_run' => true,
                'message' => sprintf(
                    '%s se %s y %s se %s.',
                    $this->plural($hechas, 'programación', 'programaciones'),
                    $hechas === 1 ? 'actualizaría' : 'actualizarían',
                    $this->plural($fallidas, 'programación', 'programaciones'),
                    $fallidas === 1 ? 'rechazaría' : 'rechazarían',
                ),
                'aplicadas' => $aplicadas,
                'rechazadas' => $rechazadas,
            ]);
        }

        return response()->json([
            'message' => sprintf(
                '%s %s y %s %s.',
                $this->plural($hechas, 'programación', 'programaciones'),
                $hechas === 1 ? 'actualizada' : 'actualizadas',
                $this->plural($fallidas, 'programación', 'programaciones'),
                $fallidas === 1 ? 'rechazada' : 'rechazadas',
            ),
            'aplicadas' => $aplicadas,
            'rechazadas' => $rechazadas,
        ], $rechazadas === [] ? 200 : 207);
    }

    /** "1 programación" / "3 programaciones"; los "(es)" quedan feos en pantalla. */
    private function plural(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    /** Contadores de la barra superior de W17. */
    public function summary(): JsonResponse
    {
        return response()->json(Scheduling::summary());
    }

    /**
     * Tercera validacion del informe: "debe precargar datos en base a la zona".
     *
     * El personal sale de su zona asignada (users.zone_id). El vehiculo y el
     * turno no tienen zona propia en el esquema, asi que se toman de la ultima
     * programacion de esa zona: lo habitual es lo ultimo que se uso.
     */
    public function defaultsForZone(Zone $zone): JsonResponse
    {
        $last = Scheduling::with(['helpers', 'vehicle'])
            ->where('zone_id', $zone->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        $staff = User::where('zone_id', $zone->id)
            ->where('status', 'activo')
            ->with('usertype')
            ->get();

        $bySlug = fn (string $slug) => $staff
            ->filter(fn (User $person) => $person->usertype?->slug === $slug)
            ->pluck('id')
            ->all();

        // Sin vehículo conocido se propone un solo ayudante.
        $seats = $last?->vehicle?->helperSeats() ?? 1;
        $helperIds = array_slice($bySlug(UserType::HELPER) ?: ($last?->helperIds() ?? []), 0, $seats);

        return response()->json([
            'vehicle_id' => $last?->vehicle_id,
            'schedule_id' => $last?->schedule_id,
            'route_id' => $last?->route_id,
            // La zona manda sobre la última programación para el personal:
            // es el dato explícito de la ficha, no una inferencia.
            'driver_id' => $bySlug(UserType::DRIVER)[0] ?? $last?->driver_id,
            'helper_ids' => $helperIds,
            'weekdays' => $last ? array_map('intval', $last->weekdays ?? []) : [],
            'source' => $last ? 'ultima-programacion' : 'zona',
        ]);
    }
}
