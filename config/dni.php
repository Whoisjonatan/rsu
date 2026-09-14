<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consulta de DNI
    |--------------------------------------------------------------------------
    |
    | RENIEC no expone una API publica: su servicio oficial exige convenio
    | institucional. Lo que hay son intermediarios, y varios ya retiraron la
    | consulta abierta por proteccion de datos (Ley 29733).
    |
    | El proveedor contratado es Consultas API (el mismo de Crinver), driver
    | "consultasapi".
    |
    | Por eso esto es un enriquecimiento OPCIONAL: con driver "null" el sistema
    | funciona igual y el boton ni siquiera aparece en la ficha de personal.
    | Si el proveedor entra en mantenimiento basta con poner "null" aqui: el
    | alta sigue completa escribiendo los diez campos a mano, como siempre.
    |
    | Drivers: null | consultasapi
    |
    */

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
