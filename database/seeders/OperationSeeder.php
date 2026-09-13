<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserType;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Funciones del personal (W06), turnos (W16), zonas (W13) y usuarios de acceso.
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
        ['Noche', '18:00:00', '23:59:00'],
    ];

    /** @var list<array{string, string, float}> zona, distrito, area km2 */
    private const ZONES = [
        ['José Leonardo Ortiz', 'José Leonardo Ortiz', 28.22],
        ['La Victoria', 'La Victoria', 29.36],
        ['Chiclayo Centro', 'Chiclayo', 50.35],
        ['Pimentel', 'Pimentel', 66.53],
        ['Monsefú', 'Monsefú', 44.94],
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

        foreach (self::ZONES as [$zoneName, $districtName, $area]) {
            $district = District::where('name', $districtName)->first();

            if (! $district) {
                continue;
            }

            $sector = Sector::updateOrCreate(
                ['name' => 'Sector '.$zoneName, 'district_id' => $district->id],
                ['area' => $area],
            );

            Zone::updateOrCreate(['name' => $zoneName], [
                'area' => $area,
                'sector_id' => $sector->id,
                'district_id' => $district->id,
            ]);
        }

        $admin = UserType::where('slug', 'administrador')->firstOrFail();

        User::updateOrCreate(['email' => 'admin@rsu.usat.edu.pe'], [
            'name' => 'Administrador',
            'lastname' => 'RSU',
            'dni' => '10000001',
            'password' => Hash::make('password'),
            'status' => 'activo',
            'usertype_id' => $admin->id,
        ]);
    }
}
