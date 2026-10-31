<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UbigeoSeeder::class,
            OperationSeeder::class,
            ZoneSeeder::class,
            VehicleCatalogSeeder::class,
        ]);
        // El personal, sus contratos y la programación se registran desde el sistema.
    }
}
