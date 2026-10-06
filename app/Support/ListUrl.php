<?php

namespace App\Support;

/**
 * Recuerda la dirección completa de un listado (búsqueda, filtros y página)
 * para que "Volver", "Cancelar" y "Guardar cambios" de la ficha regresen al
 * mismo resultado y no a la tabla completa.
 */
final class ListUrl
{
    /** Lo llama el listado al mostrarse. */
    public static function remember(string $route): void
    {
        session()->put(self::key($route), request()->fullUrl());
    }

    /** El último listado visto, o el listado sin filtros si no hay ninguno. */
    public static function get(string $route): string
    {
        return session(self::key($route), route($route));
    }

    private static function key(string $route): string
    {
        return 'list_url.'.$route;
    }
}
