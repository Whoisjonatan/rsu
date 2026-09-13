<?php

namespace App\Services\Dni;

interface DniLookup
{
    /** Nombre visible del proveedor, para el estado del servicio. */
    public function provider(): string;

    /** Si falta el token o el driver es "null", la funcion no se ofrece. */
    public function isEnabled(): bool;

    /**
     * Devuelve null si el DNI no existe en el padron.
     *
     * @throws DniLookupFailed si el proveedor falla o no responde.
     */
    public function find(string $dni): ?DniPerson;
}
