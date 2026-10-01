<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\Vehicle;
use App\Models\VehicleColor;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

/**
 * Maestros de vehiculos (W03) y la flota de ejemplo del listado (W04).
 */
class VehicleCatalogSeeder extends Seeder
{
    /** @var array<string, list<string>> marca => modelos */
    private const BRANDS = [
        'Toyota' => ['Dyna 300', 'Hiace', 'Hilux'],
        'Hino' => ['300 Series', '500 Series'],
        'Volvo' => ['FL 240', 'FM 370'],
        'Mitsubishi' => ['Canter', 'Fuso FI'],
        'Isuzu' => ['NPR 75', 'FVR'],
    ];

    /** @var array<string, string> tipo => descripcion */
    private const TYPES = [
        'Compactador' => 'Camión compactador de residuos sólidos',
        'Volquete' => 'Camión volquete para escombros y residuos voluminosos',
        'Baranda' => 'Camión baranda para recolección selectiva',
        'Furgón' => 'Furgón cerrado para residuos reciclables',
        'Motofurgón' => 'Vehículo menor para calles estrechas',
    ];

    /** @var array<string, array{int, int, int}> color => RGB */
    private const COLORS = [
        'Blanco' => [255, 255, 255],
        'Verde RSU' => [22, 130, 74],
        'Amarillo' => [245, 197, 24],
        'Naranja' => [234, 121, 38],
        'Azul' => [37, 99, 235],
        'Gris' => [107, 114, 128],
    ];

    /**
     * Carga y compactacion en Tn, combustible en L. La compactacion solo
     * aplica a los compactadores; el resto la deja en null.
     *
     * @var list<array{string, string, string, string, string, int, int, float, float, ?float, string}>
     */
    private const FLEET = [
        ['VEH-001', 'B2K-841', 'Toyota', 'Dyna 300', 'Compactador', 2020, 3, 8.0, 100.0, 6.0, 'operativo'],
        ['VEH-002', 'C1T-509', 'Hino', '300 Series', 'Compactador', 2019, 3, 10.0, 100.0, 7.5, 'operativo'],
        ['VEH-003', 'A9P-233', 'Volvo', 'FL 240', 'Volquete', 2021, 2, 12.0, 200.0, null, 'operativo'],
        ['VEH-004', 'D4M-118', 'Mitsubishi', 'Canter', 'Baranda', 2017, 3, 6.0, 100.0, null, 'mantenimiento'],
        ['VEH-005', 'E7Q-660', 'Toyota', 'Hiace', 'Compactador', 2022, 4, 7.0, 70.0, 5.0, 'operativo'],
        ['VEH-006', 'F3R-902', 'Isuzu', 'NPR 75', 'Furgón', 2018, 3, 5.5, 100.0, null, 'operativo'],
        ['VEH-007', 'G5N-471', 'Hino', '500 Series', 'Compactador', 2023, 3, 14.0, 200.0, 10.0, 'operativo'],
        ['VEH-008', 'H8L-325', 'Volvo', 'FM 370', 'Volquete', 2016, 2, 15.0, 300.0, null, 'mantenimiento'],
        ['VEH-009', 'J2D-780', 'Mitsubishi', 'Fuso FI', 'Baranda', 2021, 3, 9.0, 200.0, null, 'operativo'],
        ['VEH-010', 'K6V-194', 'Isuzu', 'FVR', 'Compactador', 2020, 3, 11.0, 200.0, 8.0, 'operativo'],
        ['VEH-011', 'L4X-057', 'Toyota', 'Hilux', 'Motofurgón', 2024, 2, 1.5, 80.0, null, 'operativo'],
    ];

    public function run(): void
    {
        foreach (self::BRANDS as $brandName => $models) {
            $brand = Brand::updateOrCreate(['name' => $brandName]);

            foreach ($models as $modelName) {
                BrandModel::updateOrCreate(
                    ['brand_id' => $brand->id, 'name' => $modelName],
                );
            }
        }

        foreach (self::TYPES as $name => $description) {
            VehicleType::updateOrCreate(['name' => $name], ['description' => $description]);
        }

        foreach (self::COLORS as $name => [$red, $green, $blue]) {
            VehicleColor::updateOrCreate(
                ['name' => $name],
                ['red' => $red, 'green' => $green, 'blue' => $blue],
            );
        }

        $white = VehicleColor::where('name', 'Blanco')->firstOrFail();
        $green = VehicleColor::where('name', 'Verde RSU')->firstOrFail();

        foreach (self::FLEET as $index => [$code, $plate, $brandName, $modelName, $typeName, $year, $occupants, $load, $fuel, $compaction, $status]) {
            $brand = Brand::where('name', $brandName)->firstOrFail();
            $model = BrandModel::where('brand_id', $brand->id)->where('name', $modelName)->firstOrFail();
            $type = VehicleType::where('name', $typeName)->firstOrFail();

            Vehicle::updateOrCreate(['code' => $code], [
                'name' => $brandName.' '.$modelName,
                'plate' => $plate,
                'year' => $year,
                'occupant_capacity' => $occupants,
                'load_capacity' => $load,
                'fuel_capacity' => $fuel,
                'compaction_capacity' => $compaction,
                'engine_number' => sprintf('MTR-%05d', 1000 + $index),
                'status' => $status,
                'brand_id' => $brand->id,
                'model_id' => $model->id,
                'type_id' => $type->id,
                'color_id' => $index % 2 === 0 ? $green->id : $white->id,
            ]);
        }
    }
}
