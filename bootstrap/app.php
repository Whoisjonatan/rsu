<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Dos formas de entrar, un solo API:
        // - Las vistas Blade usan la sesión por cookie. Las pocas pantallas que
        //   consultan el API en vivo (DNI, checklist de W18, W19, W21) mandan esa
        //   misma cookie, y statefulApi() hace que Sanctum la acepte.
        // - La app móvil sigue entrando con token Bearer, como hasta ahora.
        $middleware->statefulApi();

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/inicio');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
