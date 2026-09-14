<?php

namespace App\Providers;

use App\Services\Dni\ConsultasApiLookup;
use App\Services\Dni\DniLookup;
use App\Services\Dni\NullLookup;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Driver de consulta de DNI. El proveedor es Consultas API; cualquier
        // otro valor (o ninguno) apaga la consulta y la ficha se llena a mano.
        // Añadir un proveedor nuevo es escribir su clase e incluirla aquí.
        $this->app->singleton(DniLookup::class, function () {
            return match (config('dni.driver')) {
                'consultasapi' => new ConsultasApiLookup(
                    token: config('dni.token'),
                    apiKey: config('dni.api_key'),
                    timeout: (int) config('dni.timeout'),
                ),
                default => new NullLookup(),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
