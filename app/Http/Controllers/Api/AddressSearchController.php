<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buscador de direcciones del mapa de W14, para ubicarse antes de dibujar.
 *
 * Usa Nominatim, el geocodificador de OpenStreetMap (el mismo proveedor del
 * mapa de fondo): sin API key. Va por el servidor y no desde el navegador
 * porque su política de uso pide identificar la aplicación y no repetir
 * consultas, así que cada búsqueda se cachea un día.
 */
class AddressSearchController extends Controller
{
    private const URL = 'https://nominatim.openstreetmap.org/search';

    /** Lambayeque: los resultados de aquí salen primero, sin descartar el resto del país. */
    private const VIEWBOX = '-80.70,-5.40,-79.10,-7.20';

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q'));

        if (mb_strlen($query) < 3) {
            return response()->json(['message' => 'Escribe al menos 3 letras.'], 422);
        }

        try {
            $results = Cache::remember('address-search:'.md5(mb_strtolower($query)), now()->addDay(), function () use ($query) {
                return Http::withHeaders(['User-Agent' => config('app.name').' ('.config('app.url').')'])
                    ->acceptJson()
                    ->timeout(8)
                    ->get(self::URL, [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'countrycodes' => 'pe',
                        'viewbox' => self::VIEWBOX,
                        'limit' => 5,
                        'accept-language' => 'es',
                    ])
                    ->throw()
                    ->collect()
                    ->map(fn (array $place) => [
                        'label' => $place['display_name'],
                        'latitude' => (float) $place['lat'],
                        'longitude' => (float) $place['lon'],
                        // [sur, norte, oeste, este]: para encuadrar una calle o un barrio entero.
                        'bounds' => array_map('floatval', $place['boundingbox'] ?? []),
                    ])
                    ->all();
            });
        } catch (Throwable $e) {
            Log::warning('Búsqueda de dirección fallida', ['q' => $query, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'El buscador de direcciones no responde. Ubícate moviendo el mapa.'], 503);
        }

        return response()->json($results);
    }
}
