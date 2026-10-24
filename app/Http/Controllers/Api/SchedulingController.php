<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchedulingRequest;
use App\Http\Resources\SchedulingResource;
use App\Models\Scheduling;
use App\Models\User;
use App\Models\UserType;
use App\Models\Zone;
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

    public function store(SchedulingRequest $request): JsonResponse
    {
        $scheduling = (new Scheduling)->saveAndSync($request->validated());

        return (new SchedulingResource($scheduling->load(self::RELATIONS)->loadCount('vehicleRoutes')))
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
        $validator = SchedulingRules::validator($data, $request->integer('id') ?: null);
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
                'ok' => ! str_contains($all, 'ya está programado'),
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
        ]);
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
            $payload = array_merge($scheduling->only([
                'zone_id', 'route_id', 'vehicle_id', 'schedule_id',
                'driver_id', 'status',
            ]), [
                'helper_ids' => $scheduling->helperIds(),
                'weekdays' => array_map('intval', $scheduling->weekdays ?? []),
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
