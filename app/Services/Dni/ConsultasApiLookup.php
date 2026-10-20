<?php

namespace App\Services\Dni;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConsultasApiLookup implements DniLookup
{
    private const BASE = 'http://www.consultasapi.devmiguelrevilla.com/api/test';

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {}

    public function provider(): string
    {
        return 'Consultas API (RENIEC)';
    }

    public function isEnabled(): bool
    {
        return filled($this->token) && filled($this->apiKey);
    }

    public function find(string $dni): ?DniPerson
    {
        if (! $this->isEnabled()) {
            throw new DniLookupFailed('Falta configurar DNI_LOOKUP_TOKEN y DNI_LOOKUP_API_KEY.');
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $this->apiKey])
                ->acceptJson()
                ->timeout($this->timeout)
                ->get(self::BASE."/{$dni}/{$this->token}");
        } catch (Throwable $e) {
            Log::warning('Consulta de DNI fallida', ['dni' => $dni, 'error' => $this->redact($e->getMessage())]);

            throw new DniLookupFailed(
                'El servicio de consulta no responde. Escribe los datos a mano.'
            );
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new DniLookupFailed(
                'Las credenciales del servicio de consulta no son válidas. Escribe los datos a mano.'
            );
        }

        if ($response->status() === 429) {
            throw new DniLookupFailed(
                'Se agotó la cuota de consultas por hoy. Escribe los datos a mano.'
            );
        }

        if (! $response->successful()) {
            Log::warning('Consulta de DNI con respuesta inesperada', [
                'dni' => $dni,
                'status' => $response->status(),
            ]);

            throw new DniLookupFailed(
                'El servicio de consulta devolvió un error. Escribe los datos a mano.'
            );
        }

        $data = $response->json();

        $name = $this->titleCase((string) ($data['name'] ?? ''));
        $lastname = $this->titleCase((string) ($data['last_name'] ?? ''));

        // DNI fuera del padrón: responde 200 con los nombres en blanco.
        if ($name === '' || $lastname === '') {
            return null;
        }

        return new DniPerson(dni: $dni, name: $name, lastname: $lastname);
    }

    /**
     * Los errores de conexión traen la URL completa, y el token va en la ruta:
     * se tapa antes de escribirlo en el log, junto con la llave por si acaso.
     */
    private function redact(string $message): string
    {
        return str_replace(array_filter([$this->token, $this->apiKey]), '[oculto]', $message);
    }

    /** RENIEC devuelve todo en mayúsculas; la ficha se ve mejor capitalizada. */
    private function titleCase(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
