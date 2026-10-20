<?php

use App\Http\Controllers\Api\AddressSearchController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BrandModelController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DniLookupController;
use App\Http\Controllers\Api\GeographyController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SchedulingController;
use App\Http\Controllers\Api\UserTypeController;
use App\Http\Controllers\Api\VacationController;
use App\Http\Controllers\Api\VehicleColorController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleImageController;
use App\Http\Controllers\Api\VehicleRouteController;
use App\Http\Controllers\Api\VehicleTypeController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Con prefijo de nombre "api.": las rutas de apiResource se llaman igual que
// las de las vistas Blade (vehicles.store...) y, sin el prefijo, las pisarían.
Route::name('api.')->group(function () {
    Route::get('/ping', function () {
        try {
            DB::connection()->getPdo();
            $database = DB::connection()->getDatabaseName();
        } catch (Throwable $e) {
            $database = null;
        }

        return [
            'app' => config('app.name'),
            'database' => $database,
            'time' => now()->toDateTimeString(),
        ];
    });

    Route::post('/login', [AuthController::class, 'login']);

    // Terminal de marcacion (W11): publico, el trabajador se identifica con DNI y clave.
    Route::post('/attendances/mark', [AttendanceController::class, 'mark']);
    Route::get('/attendances/windows', [AttendanceController::class, 'windows']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Maestros de vehiculos (W03)
        Route::apiResource('brands', BrandController::class)->except('show');
        Route::post('brands/{brand}/logo', [BrandController::class, 'uploadLogo']);
        Route::delete('brands/{brand}/logo', [BrandController::class, 'deleteLogo']);
        Route::apiResource('brandmodels', BrandModelController::class)->except('show');
        Route::apiResource('vehicletypes', VehicleTypeController::class)->except('show');
        Route::apiResource('vehiclecolors', VehicleColorController::class)->except('show');

        // Vehiculos (W04, W05)
        Route::get('vehicles/summary', [VehicleController::class, 'summary']);
        Route::apiResource('vehicles', VehicleController::class);
        Route::post('vehicles/{vehicle}/images', [VehicleImageController::class, 'store']);
        Route::put('vehicles/{vehicle}/images/{image}/profile', [VehicleImageController::class, 'setProfile']);
        Route::delete('vehicles/{vehicle}/images/{image}', [VehicleImageController::class, 'destroy']);

        // Funciones del personal (W06)
        Route::apiResource('usertypes', UserTypeController::class)->except('show');

        // Personal (W07, W08)
        Route::get('people/summary', [PersonController::class, 'summary']);
        Route::apiResource('people', PersonController::class)->parameters(['people' => 'person']);
        Route::post('people/{person}/photo', [PersonController::class, 'uploadPhoto']);
        Route::delete('people/{person}/photo', [PersonController::class, 'deletePhoto']);

        // Consulta de DNI para prellenar la ficha (opcional). Se limita el ritmo
        // para no agotar la cuota del proveedor por un dedo pegado al teclado.
        Route::get('dni-lookup', [DniLookupController::class, 'status']);
        Route::get('dni-lookup/{dni}', [DniLookupController::class, 'show'])
            ->middleware('throttle:20,1');

        // Contratos (W09) y vacaciones (W10)
        Route::apiResource('people.contracts', ContractController::class)
            ->parameters(['people' => 'person'])
            ->except('show');
        Route::apiResource('people.vacations', VacationController::class)
            ->parameters(['people' => 'person'])
            ->except('show');
        Route::patch('people/{person}/vacations/{vacation}/approve', [VacationController::class, 'approve']);
        Route::patch('people/{person}/vacations/{vacation}/reject', [VacationController::class, 'reject']);

        // Zonas (W13), su perimetro (W14) y sus rutas (W15)
        Route::apiResource('zones', ZoneController::class);
        Route::put('zones/{zone}/coords', [ZoneController::class, 'saveCoords']);
        Route::post('zones/{zone}/overlaps', [ZoneController::class, 'overlaps']);
        Route::apiResource('routes', RouteController::class);
        Route::get('departments', [GeographyController::class, 'departments']);
        Route::get('provinces', [GeographyController::class, 'provinces']);
        Route::get('districts', [GeographyController::class, 'districts']);
        // Buscador de direcciones del mapa (Nominatim), con freno para no abusar del servicio.
        Route::get('address-search', AddressSearchController::class)->middleware('throttle:30,1');

        // Reporte de asistencias (W12)
        Route::get('attendances/by-person', [AttendanceController::class, 'byPerson']);
        Route::get('attendances/by-date', [AttendanceController::class, 'byDate']);

        // Turnos (W16)
        Route::apiResource('schedules', ScheduleController::class)->except('show');

        // Programacion (W17, W18). Guardar una programacion genera ademas los
        // recorridos de cada dia, que son los que leen W20 y W21.
        Route::get('schedulings/summary', [SchedulingController::class, 'summary']);
        Route::get('schedulings/defaults/{zone}', [SchedulingController::class, 'defaultsForZone']);
        // La checklist viva del panel derecho de W18: valida sin guardar.
        Route::post('schedulings/validate', [SchedulingController::class, 'validateDraft']);
        // Edicion masiva (W19): mismos cambios a varias programaciones.
        Route::post('schedulings/bulk-update', [SchedulingController::class, 'bulkUpdate']);
        Route::apiResource('schedulings', SchedulingController::class);

        // Recorridos por dia: calendario (W20) e inicio de recorrido (W21).
        // No hay store ni destroy: los recorridos los genera la programacion.
        Route::get('vehicleroutes/summary', [VehicleRouteController::class, 'summary']);
        Route::post('vehicleroutes/start-batch', [VehicleRouteController::class, 'startBatch']);
        Route::get('vehicleroutes', [VehicleRouteController::class, 'index']);
        Route::get('vehicleroutes/{vehicleroute}', [VehicleRouteController::class, 'show']);
        Route::post('vehicleroutes/{vehicleroute}/start', [VehicleRouteController::class, 'start']);
        Route::post('vehicleroutes/{vehicleroute}/block', [VehicleRouteController::class, 'block']);
        Route::post('vehicleroutes/{vehicleroute}/finish', [VehicleRouteController::class, 'finish']);
        Route::get('vehicleroutes/{vehicleroute}/reserves', [VehicleRouteController::class, 'reserves']);
        Route::put('vehicleroutes/{vehicleroute}/occupants/{occupant}', [VehicleRouteController::class, 'replaceOccupant']);
    });
});
