<?php

namespace App\Services\Dni;

/**
 * Driver de demostración: no sale a internet. Sirve para enseñar el flujo sin
 * registrarse en ningún proveedor ni consultar datos de personas reales.
 *
 * Resuelve primero contra el personal ya registrado (así la demo es coherente)
 * y, si el DNI no existe, devuelve un nombre inventado y estable.
 */
class FakeLookup implements DniLookup
{
    /** @var list<string> */
    private const NAMES = ['Ana María', 'Luis Alberto', 'Rosa Elena', 'Carlos Enrique', 'Julia Beatriz'];

    /** @var list<string> */
    private const SURNAMES = ['Chapoñán Vidaurre', 'Santamaría Llontop', 'Inoñán Bances', 'Sandoval Neyra', 'Vera Quispe'];

    public function provider(): string
    {
        return 'Simulado (datos de prueba)';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function find(string $dni): ?DniPerson
    {
        // Un DNI que termina en 0 simula "no encontrado en el padrón".
        if (str_ends_with($dni, '0')) {
            return null;
        }

        $seed = (int) substr($dni, -3);

        return new DniPerson(
            dni: $dni,
            name: self::NAMES[$seed % count(self::NAMES)],
            lastname: self::SURNAMES[$seed % count(self::SURNAMES)],
        );
    }
}
