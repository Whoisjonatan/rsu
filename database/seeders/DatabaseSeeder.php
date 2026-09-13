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
            VehicleCatalogSeeder::class,
            PersonnelSeeder::class,
            AttendanceSeeder::class,
        ]);
    }
}
