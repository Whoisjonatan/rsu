<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Schedule;
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

    /**
     * Sin área: sale del perímetro, que se dibuja en W14.
     *
     * @var list<array{string, string}> zona, distrito
     */
    private const ZONES = [
        ['José Leonardo Ortiz', 'José Leonardo Ortiz'],
        ['La Victoria', 'La Victoria'],
        ['Chiclayo Centro', 'Chiclayo'],
        ['Pimentel', 'Pimentel'],
        ['Monsefú', 'Monsefú'],
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

        foreach (self::ZONES as [$zoneName, $districtName]) {
            $district = District::where('name', $districtName)->first();

            if (! $district) {
                continue;
            }

            Zone::updateOrCreate(['name' => $zoneName], [
                'district_id' => $district->id,
                'status' => 'activa',
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
