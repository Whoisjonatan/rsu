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
    | Por eso esto es un enriquecimiento OPCIONAL: con driver "null" el sistema
    | funciona igual y el boton ni siquiera aparece en la ficha de personal.
    |
    | Drivers: null | fake | decolecta
    |
    */

    'driver' => env('DNI_LOOKUP_DRIVER', 'null'),

    'token' => env('DNI_LOOKUP_TOKEN'),

    /** Segundos antes de rendirse: el alta nunca debe quedarse colgada. */
    'timeout' => (int) env('DNI_LOOKUP_TIMEOUT', 3),

    /** El nombre de una persona no cambia: cachear ahorra cuota del proveedor. */
    'cache_days' => (int) env('DNI_LOOKUP_CACHE_DAYS', 30),

];
