<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Zonas de recolección (W13) con su perímetro (W14): los cuatro sectores de
 * José Leonardo Ortiz con los que trabaja el sistema.
 */
class ZoneSeeder extends Seeder
{
    /**
     * nombre, descripción, área en km², residuos promedio en kg, perímetro
     * (lat, lng) en el orden en que se dibujó.
     *
     * @var list<array{string, string, float, float, list<array{float, float}>}>
     */
    private const ZONES = [
        ['Oeste', 'Sector Oeste de JLO', 0.096, 100.0, [
            [-6.7489345, -79.8482037],
            [-6.7512785, -79.8485041],
            [-6.7517260, -79.8447490],
            [-6.7500000, -79.8445129],
        ]],
        ['Este', 'Sector Este de JLO', 0.046, 100.0, [
            [-6.7524185, -79.8443091],
            [-6.7505327, -79.8441160],
            [-6.7504688, -79.8444808],
            [-6.7490517, -79.8443735],
            [-6.7492329, -79.8430324],
            [-6.7525837, -79.8433435],
        ]],
        ['Norte', 'Sector Norte de JLO', 0.025, 100.0, [
            [-6.7487055, -79.8429251],
            [-6.7472138, -79.8427427],
            [-6.7476293, -79.8413801],
            [-6.7489399, -79.8415196],
        ]],
        ['Sur', 'Sector Sur de JLO', 0.017, 100.0, [
            [-6.7498029, -79.8416483],
            [-6.7508150, -79.8417449],
            [-6.7510335, -79.8405540],
            [-6.7500319, -79.8402321],
        ]],
    ];

    private const DISTRICT = 'José Leonardo Ortiz';

    public function run(): void
    {
        $district = District::where('name', self::DISTRICT)->firstOrFail();

        foreach (self::ZONES as [$name, $description, $area, $waste, $perimeter]) {
            $zone = Zone::updateOrCreate(['name' => $name], [
                'description' => $description,
                'area' => $area,
                'average_waste_kg' => $waste,
                'status' => 'activa',
                'district_id' => $district->id,
            ]);

            // El perímetro se reemplaza entero: es una sola figura.
            $zone->coords()->delete();

            foreach ($perimeter as $position => [$latitude, $longitude]) {
                $zone->coords()->create([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'position' => $position,
                ]);
            }
        }
    }
}
