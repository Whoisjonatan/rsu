<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use Illuminate\Database\Seeder;

/**
 * Carga inicial de departments, provinces y districts exigida por el informe.
 *
 * Se cargan los 25 departamentos del Peru con su codigo INEI y, en detalle,
 * Lambayeque completo (3 provincias y 38 distritos), que es la region donde
 * opera el area de RSU. El resto de provincias y distritos se puede anadir
 * despues desde el catalogo INEI sin tocar este esquema.
 */
class UbigeoSeeder extends Seeder
{
    /** @var array<string, string> codigo INEI => nombre */
    private const DEPARTMENTS = [
        '01' => 'Amazonas',
        '02' => 'Áncash',
        '03' => 'Apurímac',
        '04' => 'Arequipa',
        '05' => 'Ayacucho',
        '06' => 'Cajamarca',
        '07' => 'Callao',
        '08' => 'Cusco',
        '09' => 'Huancavelica',
        '10' => 'Huánuco',
        '11' => 'Ica',
        '12' => 'Junín',
        '13' => 'La Libertad',
        '14' => 'Lambayeque',
        '15' => 'Lima',
        '16' => 'Loreto',
        '17' => 'Madre de Dios',
        '18' => 'Moquegua',
        '19' => 'Pasco',
        '20' => 'Piura',
        '21' => 'Puno',
        '22' => 'San Martín',
        '23' => 'Tacna',
        '24' => 'Tumbes',
        '25' => 'Ucayali',
    ];

    /** @var array<string, array{name: string, districts: array<string, string>}> */
    private const LAMBAYEQUE = [
        '1401' => [
            'name' => 'Chiclayo',
            'districts' => [
                '140101' => 'Chiclayo',
                '140102' => 'Chongoyape',
                '140103' => 'Eten',
                '140104' => 'Eten Puerto',
                '140105' => 'José Leonardo Ortiz',
                '140106' => 'La Victoria',
                '140107' => 'Lagunas',
                '140108' => 'Monsefú',
                '140109' => 'Nueva Arica',
                '140110' => 'Oyotún',
                '140111' => 'Picsi',
                '140112' => 'Pimentel',
                '140113' => 'Reque',
                '140114' => 'Santa Rosa',
                '140115' => 'Zaña',
                '140116' => 'Cayaltí',
                '140117' => 'Pátapo',
                '140118' => 'Pomalca',
                '140119' => 'Pucalá',
                '140120' => 'Tumán',
            ],
        ],
        '1402' => [
            'name' => 'Ferreñafe',
            'districts' => [
                '140201' => 'Ferreñafe',
                '140202' => 'Cañaris',
                '140203' => 'Incahuasi',
                '140204' => 'Manuel Antonio Mesones Muro',
                '140205' => 'Pítipo',
                '140206' => 'Pueblo Nuevo',
            ],
        ],
        '1403' => [
            'name' => 'Lambayeque',
            'districts' => [
                '140301' => 'Lambayeque',
                '140302' => 'Chóchope',
                '140303' => 'Íllimo',
                '140304' => 'Jayanca',
                '140305' => 'Mochumí',
                '140306' => 'Mórrope',
                '140307' => 'Motupe',
                '140308' => 'Olmos',
                '140309' => 'Pácora',
                '140310' => 'Salas',
                '140311' => 'San José',
                '140312' => 'Túcume',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $code => $name) {
            Department::updateOrCreate(['code' => $code], ['name' => $name]);
        }

        $lambayeque = Department::where('code', '14')->firstOrFail();

        foreach (self::LAMBAYEQUE as $provinceCode => $province) {
            $row = Province::updateOrCreate(
                ['code' => $provinceCode],
                ['name' => $province['name'], 'department_id' => $lambayeque->id],
            );

            foreach ($province['districts'] as $districtCode => $districtName) {
                District::updateOrCreate(
                    ['code' => $districtCode],
                    [
                        'name' => $districtName,
                        'department_id' => $lambayeque->id,
                        'province_id' => $row->id,
                    ],
                );
            }
        }
    }
}
