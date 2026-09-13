<?php

namespace App\Services\Dni;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decolecta (api.decolecta.com), el proveedor detras de apis.net.pe.
 *
 * GET /v1/reniec/dni?numero=46027897  con  Authorization: Bearer <token>
 *
 * Respuesta 200:
 *   { "first_name": "ROXANA KARINA", "first_last_name": "DELGADO",
 *     "second_last_name": "HUAMANI", "full_name": "...", "document_number": "..." }
 */
class DecolectaLookup implements DniLookup
{
    private const BASE = 'https://api.decolecta.com/v1/reniec/dni';

    public function __construct(
        private readonly ?string $token,
        private readonly int $timeout,
    ) {}

    public function provider(): string
    {
        return 'Decolecta (RENIEC)';
    }

    public function isEnabled(): bool
    {
        return filled($this->token);
    }

    public function find(string $dni): ?DniPerson
    {
        if (! $this->isEnabled()) {
            throw new DniLookupFailed('Falta configurar DNI_LOOKUP_TOKEN.');
        }

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout($this->timeout)
                ->get(self::BASE, ['numero' => $dni]);
        } catch (Throwable $e) {
            Log::warning('Consulta de DNI fallida', ['dni' => $dni, 'error' => $e->getMessage()]);

            throw new DniLookupFailed('No se pudo contactar al servicio de consulta.');
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new DniLookupFailed('El token del servicio de consulta no es válido.');
        }

        if ($response->status() === 429) {
            throw new DniLookupFailed('Se agotó la cuota de consultas por hoy.');
        }

        if (! $response->successful()) {
            Log::warning('Consulta de DNI con respuesta inesperada', [
                'dni' => $dni,
                'status' => $response->status(),
            ]);

            throw new DniLookupFailed('El servicio de consulta devolvió un error.');
        }

        $data = $response->json();
        $name = trim((string) ($data['first_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $lastname = trim(
            ($data['first_last_name'] ?? '').' '.($data['second_last_name'] ?? '')
        );

        return new DniPerson(
            dni: $dni,
            name: $this->titleCase($name),
            lastname: $this->titleCase($lastname),
        );
    }

    /** RENIEC devuelve todo en mayúsculas; la ficha se ve mejor capitalizada. */
    private function titleCase(string $value): string
    {
        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
