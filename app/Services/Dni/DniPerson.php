<?php

namespace App\Services\Dni;

/**
 * Lo que devuelve una consulta de DNI. Deliberadamente pobre: los proveedores
 * de RENIEC solo entregan el nombre. Correo, telefono y licencia los escribe
 * siempre el usuario.
 */
final class DniPerson
{
    public function __construct(
        public readonly string $dni,
        public readonly string $name,
        public readonly string $lastname,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'dni' => $this->dni,
            'name' => $this->name,
            'lastname' => $this->lastname,
        ];
    }
}
