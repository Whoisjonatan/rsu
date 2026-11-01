<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleRouteResource;
use App\Models\Attendance;
use App\Models\User;
use App\Models\VehicleOccupant;
use App\Models\VehicleRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Recorridos por dia: el calendario semanal (W20) y el inicio de recorrido (W21).
 *
 * Estos recorridos no se crean a mano: los genera la programacion maestra al
 * guardarse (ver Scheduling::syncVehicleRoutes). Aqui solo se consultan, se
 * inician, se bloquean y se les cambia el personal.
 */
class VehicleRouteController extends Controller
{
    private const RELATIONS = ['vehicle', 'schedule', 'route', 'scheduling.zone', 'occupants.user.vacations', 'occupants.usertype', 'occupants.replacedUser'];

    /** El calendario de W20: todos los recorridos de un rango de fechas. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date' => ['nullable', 'date'],
        ]);

        $routes = VehicleRoute::query()
            ->with(self::RELATIONS)
            ->when($request->filled('date'), fn ($q) => $q->whereDate('date_route', $request->date('date')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date_route', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date_route', '<=', $request->date('to')))
            ->when($request->filled('schedule_id'), fn ($q) => $q->where('schedule_id', $request->integer('schedule_id')))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('zone_id'), fn ($q) => $q->whereHas(
                'scheduling',
                fn ($s) => $s->where('zone_id', $request->integer('zone_id')),
            ))
            ->orderBy('date_route')
            ->orderBy('schedule_id')
            ->get();

        return VehicleRouteResource::collection($routes);
    }

    /**
     * Detalle con la checklist de W21: el front necesita saber no solo si se
     * puede iniciar, sino qué falta y a quién hay que reemplazar.
     */
    public function show(VehicleRoute $vehicleroute): JsonResponse
    {
        $vehicleroute->load(self::RELATIONS);

        return response()->json([
            'data' => new VehicleRouteResource($vehicleroute),
            'readiness' => $vehicleroute->readiness(),
        ]);
    }

    /** Inicia un recorrido, si pasa las validaciones del informe. */
    public function start(VehicleRoute $vehicleroute): JsonResponse
    {
        $readiness = $vehicleroute->readiness();

        if (! $readiness['ready']) {
            return response()->json([
                'message' => 'El recorrido todavía no puede iniciar.',
                'readiness' => $readiness,
            ], 422);
        }

        $vehicleroute->update([
            'status' => 'iniciado',
            'time_route' => now()->format('H:i:s'),
        ]);

        return response()->json([
            'data' => new VehicleRouteResource($vehicleroute->load(self::RELATIONS)),
            'readiness' => $vehicleroute->readiness(),
        ]);
    }

    /**
     * "Las programaciones cuyos turnos sí puedan iniciar el recorrido deben
     * cambiar de estado a iniciado" — en plural. W21 trabaja sobre un día y un
     * turno completos, así que se arrancan de golpe los que estén listos y se
     * devuelve el motivo de los que no.
     */
    public function startBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'schedule_id' => ['required', 'exists:schedules,id'],
        ]);

        $routes = VehicleRoute::query()
            ->with(self::RELATIONS)
            ->whereDate('date_route', $data['date'])
            ->where('schedule_id', $data['schedule_id'])
            ->where('status', 'programado')
            ->get();

        $iniciados = [];
        $pendientes = [];

        DB::transaction(function () use ($routes, &$iniciados, &$pendientes) {
            foreach ($routes as $route) {
                $readiness = $route->readiness();

                if (! $readiness['ready']) {
                    $pendientes[] = [
                        'id' => $route->id,
                        'vehicle' => $route->vehicle?->code,
                        'blockers' => $readiness['blockers'],
                    ];

                    continue;
                }

                $route->update(['status' => 'iniciado', 'time_route' => now()->format('H:i:s')]);
                $iniciados[] = ['id' => $route->id, 'vehicle' => $route->vehicle?->code];
            }
        });

        $arrancados = count($iniciados);
        $faltan = count($pendientes);

        return response()->json([
            'message' => sprintf(
                '%d %s %s y %d %s.',
                $arrancados,
                $arrancados === 1 ? 'recorrido' : 'recorridos',
                $arrancados === 1 ? 'iniciado' : 'iniciados',
                $faltan,
                $faltan === 1 ? 'pendiente' : 'pendientes',
            ),
            'iniciados' => $iniciados,
            'pendientes' => $pendientes,
        ]);
    }

    /**
     * Personal de reserva para cubrir a quien no marcó asistencia.
     * Se pide la función para no ofrecer un ayudante donde falta un conductor.
     */
    public function reserves(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $data = $request->validate([
            'usertype_id' => ['required', 'exists:usertypes,id'],
        ]);

        $reserves = $vehicleroute->availableReserves((int) $data['usertype_id']);
        $date = $vehicleroute->date_route->toDateString();

        // W21 los lista como "Salas Mendoza, Rosa · presente 06:04 a. m.": sin
        // la hora de marcación no se puede elegir con criterio entre dos.
        $marks = Attendance::query()
            ->whereIn('user_id', $reserves->pluck('id'))
            ->whereDate('date', $date)
            ->where('schedule_id', $vehicleroute->schedule_id)
            ->whereIn('status', ['presente', 'tarde'])
            ->get()
            ->keyBy('user_id');

        return response()->json([
            'data' => $reserves->map(fn (User $person) => [
                'id' => $person->id,
                'full_name' => $person->full_name,
                'dni' => $person->dni,
                'zone_id' => $person->zone_id,
                'attendance_status' => $marks->get($person->id)?->status,
                'attendance_time' => $marks->get($person->id)?->check_in,
            ])->values(),
        ]);
    }

    /**
     * Cambia a un ocupante por personal de reserva (W21).
     *
     * El saliente no se borra: queda en "reemplazado" con replaced_user_id
     * apuntando a quien entró, para que después se sepa qué pasó ese día.
     */
    public function replaceOccupant(Request $request, VehicleRoute $vehicleroute, VehicleOccupant $occupant): JsonResponse
    {
        if ($occupant->vehicleroute_id !== $vehicleroute->id) {
            return response()->json(['message' => 'Ese ocupante no pertenece a este recorrido.'], 404);
        }

        if (! $vehicleroute->canStart()) {
            return response()->json([
                'message' => 'Solo se puede cambiar el personal de un recorrido que aún no ha iniciado.',
            ], 409);
        }

        $data = $request->validate([
            'user_id' => [
                'required',
                'different:'.$occupant->user_id,
                Rule::exists('users', 'id'),
            ],
        ]);

        $replacement = User::findOrFail($data['user_id']);
        $date = $vehicleroute->date_route->toDateString();

        // El reemplazo tiene que cumplir lo mismo que se le exige al titular.
        if (! $replacement->attendedOn($date, $vehicleroute->schedule_id)) {
            return response()->json([
                'message' => $replacement->full_name.' tampoco marcó entrada en este turno.',
            ], 422);
        }

        if ($replacement->datesWithoutContract([$date]) !== []) {
            return response()->json([
                'message' => $replacement->full_name.' no tiene contrato vigente.',
            ], 422);
        }

        if ($replacement->datesOnVacation([$date]) !== []) {
            return response()->json([
                'message' => $replacement->full_name.' está de vacaciones.',
            ], 422);
        }

        DB::transaction(function () use ($occupant, $replacement, $vehicleroute) {
            $occupant->update([
                'status' => 'reemplazado',
                'replaced_user_id' => $replacement->id,
            ]);

            $vehicleroute->occupants()->updateOrCreate(
                ['user_id' => $replacement->id],
                [
                    'usertype_id' => $occupant->usertype_id,
                    'status' => 'asignado',
                    'replaced_user_id' => null,
                ],
            );
        });

        $vehicleroute->load(self::RELATIONS);

        return response()->json([
            'data' => new VehicleRouteResource($vehicleroute),
            'readiness' => $vehicleroute->readiness(),
        ]);
    }

    /** Bloquear o desbloquear un recorrido que no va a salir. */
    public function block(Request $request, VehicleRoute $vehicleroute): JsonResponse
    {
        $data = $request->validate([
            'block_reason' => ['required', 'string', 'max:255'],
        ]);

        if (! $vehicleroute->canStart()) {
            return response()->json([
                'message' => 'Solo se puede bloquear un recorrido que aún no ha iniciado.',
            ], 409);
        }

        $vehicleroute->update(['status' => 'bloqueado', 'block_reason' => $data['block_reason']]);

        return response()->json(['data' => new VehicleRouteResource($vehicleroute->load(self::RELATIONS))]);
    }

    /** Cierra un recorrido ya iniciado. */
    public function finish(VehicleRoute $vehicleroute): JsonResponse
    {
        if ($vehicleroute->status !== 'iniciado') {
            return response()->json([
                'message' => 'Solo se puede finalizar un recorrido iniciado.',
            ], 409);
        }

        $vehicleroute->update(['status' => 'finalizado']);

        return response()->json(['data' => new VehicleRouteResource($vehicleroute->load(self::RELATIONS))]);
    }

    /** Contadores del día para la cabecera de W20 y W21, y para el panel (W02). */
    public function summary(Request $request): JsonResponse
    {
        $date = $request->filled('date')
            ? $request->date('date')->format('Y-m-d')
            : now()->toDateString();

        return response()->json(VehicleRoute::daySummary($date));
    }
}
