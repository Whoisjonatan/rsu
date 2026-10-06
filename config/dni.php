<?php

return [

    'driver' => env('DNI_LOOKUP_DRIVER', 'null'),

    'token' => env('DNI_LOOKUP_TOKEN'),

    /** Consultas API pide, ademas del token en la ruta, una llave por cabecera. */
    'api_key' => env('DNI_LOOKUP_API_KEY'),

    /**
     * Segundos antes de rendirse: el alta nunca debe quedarse colgada. El
     * minimo de 1 es a proposito: con 0, Http::timeout() entiende "sin limite".
     */
    'timeout' => max(1, (int) env('DNI_LOOKUP_TIMEOUT', 3)),

    /** El nombre de una persona no cambia: cachear ahorra cuota del proveedor. */
    'cache_days' => (int) env('DNI_LOOKUP_CACHE_DAYS', 30),

];
