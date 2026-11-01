<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuario de prueba para revisar los permisos (Lista de usuarios › Permisos).
 *
 * Es un Supervisor con unos pocos permisos, para ver cómo el menú y los
 * botones cambian según lo asignado. No es parte de la carga base: se crea
 * a mano con
 *
 *     php artisan db:seed --class=TestUserSeeder
 *
 * Usuario: prueba@rsu.com (o DNI 10000002) · Clave: prueba123
 */
class TestUserSeeder extends Seeder
{
    /** Lo justo para ver la diferencia con el Administrador. */
    private const PERMISSIONS = [
        'dashboard.view',
        'vehicles.view',
        'vehicles.images',
        'attendances.view',
        'zones.map',
        'schedulings.view',
        'schedulings.details',
        'schedulings.calendar',
    ];

    public function run(): void
    {
        $user = User::updateOrCreate(['email' => 'prueba@rsu.com'], [
            'name' => 'Usuario',
            'lastname' => 'Prueba',
            'dni' => '10000002',
            'password' => Hash::make('prueba123'),
            'status' => 'activo',
            'usertype_id' => UserType::where('slug', UserType::SUPERVISOR)->firstOrFail()->id,
        ]);

        $user->forceFill(['permissions' => self::PERMISSIONS])->save();
    }
}
