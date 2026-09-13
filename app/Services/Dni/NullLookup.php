<?php

namespace App\Services\Dni;

/** Driver por defecto: la consulta no esta configurada. */
class NullLookup implements DniLookup
{
    public function provider(): string
    {
        return 'ninguno';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function find(string $dni): ?DniPerson
    {
        throw new DniLookupFailed('La consulta por DNI no está configurada.');
    }
}
