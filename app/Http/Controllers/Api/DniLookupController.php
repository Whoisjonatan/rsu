<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dni\DniLookup;
use App\Services\Dni\DniLookupFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Consulta de DNI para prellenar la ficha de personal (W08).
 *
 * Es un enriquecimiento opcional: si el driver no esta configurado el front
 * ni siquiera muestra el boton, y el alta manual funciona igual.
 */
class DniLookupController extends Controller
{
    /** Marca que se guarda cuando el proveedor respondio "no existe". */
    private const SIN_RESULTADO = 'sin-resultado';

    public function __construct(private readonly DniLookup $lookup) {}

    /** El front pregunta esto una vez para decidir si muestra el boton. */
    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->lookup->isEnabled(),
            'provider' => $this->lookup->isEnabled() ? $this->lookup->provider() : null,
        ]);
    }

    public function show(string $dni): JsonResponse
    {
        if (! preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['message' => 'El DNI debe tener 8 dígitos.'], 422);
        }

        if (! $this->lookup->isEnabled()) {
            return response()->json([
                'message' => 'La consulta por DNI no está configurada.',
            ], 503);
        }

        // Si ya está registrado, no hace falta gastar una consulta.
        $registered = User::where('dni', $dni)->first();

        if ($registered) {
            return response()->json([
                'source' => 'registrado',
                'message' => 'Ese DNI ya está registrado en el sistema.',
                'person' => [
                    'id' => $registered->id,
                    'dni' => $registered->dni,
                    'name' => $registered->name,
                    'lastname' => $registered->lastname,
                ],
            ], 409);
        }

        // El nombre de una persona no cambia: cachear ahorra cuota. La clave
        // lleva el driver para que, si algun dia se cambia de proveedor, no se
        // sirvan respuestas del anterior.
        $key = 'dni:'.config('dni.driver').":{$dni}";
        $cached = Cache::get($key);

        try {
            if ($cached === null) {
                // Un DNI fuera del padron tambien gasta consulta, asi que el
                // "no encontrado" se cachea como marca: Cache::remember no lo
                // haria, porque para el un null es "no hay nada guardado".
                $found = $this->lookup->find($dni)?->toArray();

                Cache::put(
                    $key,
                    $cached = $found ?? self::SIN_RESULTADO,
                    // Menos dias para el negativo: puede venir de un padron
                    // desactualizado del proveedor, no de un DNI inexistente.
                    $found ? now()->addDays((int) config('dni.cache_days')) : now()->addDay(),
                );
            }
        } catch (DniLookupFailed $e) {
            // Un fallo del proveedor nunca se cachea: se reintenta a la proxima.
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $person = is_array($cached) ? $cached : null;

        if (! $person) {
            return response()->json([
                'message' => 'No se encontró ese DNI. Escribe los datos a mano.',
            ], 404);
        }

        return response()->json([
            'source' => $this->lookup->provider(),
            'person' => $person,
        ]);
    }
}
