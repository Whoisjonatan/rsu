<?php

namespace Database\Seeders;

use App\Models\Schedule;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Funciones del personal (W06), turnos (W16) y el usuario administrador. Las
 * zonas van en ZoneSeeder; el personal se registra desde el sistema.
 */
class OperationSeeder extends Seeder
{
    /** @var list<array{string, string, string, bool}> nombre, slug, descripcion, predefinida */
    private const USER_TYPES = [
        ['Administrador', 'administrador', 'Acceso total al sistema', true],
        ['Supervisor', 'supervisor', 'Valida e inicia los recorridos del día', true],
        ['Conductor', 'conductor', 'Conduce el vehículo asignado al recorrido', true],
        ['Ayudante', 'ayudante', 'Acompaña el recorrido y realiza la recolección', true],
    ];

    /** @var list<array{string, string, string}> nombre, inicio, fin */
    private const SCHEDULES = [
        ['Mañana', '06:00:00', '12:00:00'],
        ['Tarde', '12:00:00', '18:00:00'],
        ['Noche', '20:28:00', '23:59:00'],
    ];

    public function run(): void
    {
        foreach (self::USER_TYPES as [$name, $slug, $description, $isSystem]) {
            UserType::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'is_system' => $isSystem,
            ]);
        }

        foreach (self::SCHEDULES as [$name, $start, $end]) {
            Schedule::updateOrCreate(['name' => $name], [
                'time_start' => $start,
                'time_end' => $end,
            ]);
        }

        $admin = UserType::where('slug', 'administrador')->firstOrFail();

        User::updateOrCreate(['email' => 'admin@rsu.com'], [
            'name' => 'Administrador',
            'lastname' => 'RSU',
            'dni' => '10000001',
            'password' => Hash::make('password'),
            'status' => 'activo',
            'usertype_id' => $admin->id,
        ]);
    }
}
