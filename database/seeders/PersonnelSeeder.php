<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserType;
use App\Models\Vacation;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Personal del wireframe W07, con sus contratos y vacaciones, elegidos para
 * que cada regla del informe tenga al menos un caso que la ejercite.
 */
class PersonnelSeeder extends Seeder
{
    /**
     * dni, apellidos, nombres, función, licencia, categoría, zona,
     * tipo de contrato, inicio, fin.
     *
     * @var list<array{string, string, string, string, ?string, ?string, ?string, string, string, ?string}>
     */
    private const PEOPLE = [
        ['46812339', 'Puma Ríos', 'Juan Carlos', 'conductor', 'Q46812339', 'A-IIIb', 'José Leonardo Ortiz', 'permanente', '2021-03-01', null],
        ['41220987', 'Salas Mendoza', 'Rosa', 'conductor', 'Q41220987', 'A-IIIa', 'La Victoria', 'nombrado', '2019-07-15', null],
        ['70551204', 'Vera Cabrera', 'Luis', 'ayudante', null, null, 'Chiclayo Centro', 'eventual', '2026-07-01', '2026-08-31'],
        ['44903118', 'Díaz Lozano', 'Ana', 'ayudante', null, null, 'Pimentel', 'permanente', '2020-01-10', null],
        ['72118440', 'Chávez Neyra', 'Pedro', 'ayudante', null, null, 'Monsefú', 'eventual', '2026-05-01', '2026-06-30'],
        ['43887102', 'Quispe Ramos', 'Marta', 'supervisor', null, null, 'Chiclayo Centro', 'nombrado', '2018-02-02', null],
        ['48220314', 'Flores Santamaría', 'Carlos', 'conductor', 'Q48220314', 'A-IIIb', 'Monsefú', 'nombrado', '2020-09-01', null],
        ['75330128', 'Bances Chapoñán', 'Julio', 'ayudante', null, null, 'José Leonardo Ortiz', 'permanente', '2022-04-18', null],
        ['42990771', 'Inoñán Sandoval', 'Teresa', 'ayudante', null, null, 'La Victoria', 'nombrado', '2017-11-06', null],
        ['71004582', 'Llontop Vidaurre', 'Miguel', 'conductor', 'Q71004582', 'A-IIIa', 'Pimentel', 'permanente', '2023-01-09', null],
    ];

    public function run(): void
    {
        $types = UserType::pluck('id', 'slug');
        $zones = Zone::pluck('id', 'name');

        foreach (self::PEOPLE as $index => [
            $dni, $lastname, $name, $typeSlug, $license, $category,
            $zoneName, $contractType, $start, $end,
        ]) {
            $person = User::updateOrCreate(['dni' => $dni], [
                'name' => $name,
                'lastname' => $lastname,
                'email' => $this->email($name, $lastname),
                'password' => Hash::make('password'),
                'phone' => '9'.str_pad((string) (78441220 + $index), 8, '0', STR_PAD_LEFT),
                'address' => 'Av. Grau '.(1200 + $index * 15).', '.$zoneName,
                'birthdate' => sprintf('19%02d-%02d-%02d', 80 + $index, ($index % 12) + 1, ($index % 27) + 1),
                'license' => $license,
                'license_category' => $category,
                'license_expiration' => $license ? '2028-12-31' : null,
                'status' => 'activo',
                'usertype_id' => $types[$typeSlug] ?? null,
                'zone_id' => $zones[$zoneName] ?? null,
            ]);

            $person->contracts()->updateOrCreate(
                ['start_date' => $start],
                [
                    'code' => sprintf('CON-%s-%03d', substr($start, 0, 4), $index + 1),
                    'type' => $contractType,
                    'regime' => $typeSlug === 'supervisor' ? 'Empleado municipal' : 'Obrero municipal',
                    'end_date' => $end,
                ],
            );
        }

        // Ana Díaz aparece de vacaciones en W07 y su ficha en W10 muestra
        // 15 días programados del año en curso y dos períodos gozados del anterior.
        // El período vigente se ancla a hoy (el wireframe se dibujó para el 24 de
        // agosto) para que el estado "Vacaciones" sea visible en el listado.
        $ana = User::where('dni', '44903118')->first();

        if ($ana) {
            $current = now()->subDays(4);
            $lastYear = (int) now()->year - 1;

            $periods = [
                [(int) now()->year, $current->toDateString(), $current->copy()->addDays(14)->toDateString(), 15, 'programadas'],
                [$lastYear, $lastYear.'-12-15', $lastYear.'-12-29', 15, 'gozadas'],
                [$lastYear, $lastYear.'-07-01', $lastYear.'-07-15', 15, 'gozadas'],
            ];

            foreach ($periods as [$year, $from, $to, $days, $status]) {
                Vacation::updateOrCreate(
                    ['user_id' => $ana->id, 'start_date' => $from],
                    ['year' => $year, 'end_date' => $to, 'days' => $days, 'status' => $status],
                );
            }
        }
    }

    private function email(string $name, string $lastname): string
    {
        $first = strtok(mb_strtolower($this->strip($name)), ' ');
        $sur = strtok(mb_strtolower($this->strip($lastname)), ' ');

        return $first.'.'.$sur.'@rsu.usat.edu.pe';
    }

    private function strip(string $value): string
    {
        return strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
    }
}
