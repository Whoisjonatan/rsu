<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Correlativos que no se repiten aunque se borren registros.
 *
 * El último número entregado vive en la tabla "sequences" y se lee con
 * bloqueo, así dos altas a la vez no reciben el mismo. Si la tabla se quedó
 * atrás (un seeder que puso códigos a mano), manda el mayor ya usado.
 */
class Sequence
{
    /**
     * @param  callable(): int  $highestUsed  El mayor número que ya tiene un registro.
     */
    public static function next(string $name, callable $highestUsed): int
    {
        return DB::transaction(function () use ($name, $highestUsed) {
            $stored = DB::table('sequences')->where('name', $name)->lockForUpdate()->value('value');
            $value = max((int) $stored, $highestUsed()) + 1;

            DB::table('sequences')->updateOrInsert(
                ['name' => $name],
                ['value' => $value, 'updated_at' => now()] + ($stored === null ? ['created_at' => now()] : []),
            );

            return $value;
        });
    }
}
