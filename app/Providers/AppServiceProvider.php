<?php

namespace App\Providers;

use App\Services\Dni\DecolectaLookup;
use App\Services\Dni\DniLookup;
use App\Services\Dni\FakeLookup;
use App\Services\Dni\NullLookup;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Driver de consulta de DNI. Añadir un proveedor nuevo es escribir su
        // clase e incluirla en este match.
        $this->app->singleton(DniLookup::class, function () {
            return match (config('dni.driver')) {
                'decolecta' => new DecolectaLookup(
                    token: config('dni.token'),
                    timeout: (int) config('dni.timeout'),
                ),
                'fake' => new FakeLookup(),
                default => new NullLookup(),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
