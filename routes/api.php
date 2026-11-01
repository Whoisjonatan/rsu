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
use App\Http\Controllers\Api\StaffGroupController;
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

    Route::middleware(['auth:sanctum', 'permission'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Maestros de vehiculos (W03)
        Route::apiResource('brands', BrandController::class)->except('show');
        Route::post('brands/{brand}/logo', [BrandController::class, 'uploadLogo'])->name('brands.logo.store');
        Route::delete('brands/{brand}/logo', [BrandController::class, 'deleteLogo'])->name('brands.logo.destroy');
        Route::apiResource('brandmodels', BrandModelController::class)->except('show');
        Route::apiResource('vehicletypes', VehicleTypeController::class)->except('show');
        Route::apiResource('vehiclecolors', VehicleColorController::class)->except('show');

        // Vehiculos (W04, W05)
        Route::get('vehicles/summary', [VehicleController::class, 'summary'])->name('vehicles.summary');
        Route::apiResource('vehicles', VehicleController::class);
        Route::post('vehicles/{vehicle}/images', [VehicleImageController::class, 'store'])->name('vehicles.images.store');
        Route::put('vehicles/{vehicle}/images/{image}/profile', [VehicleImageController::class, 'setProfile'])->name('vehicles.images.profile');
        Route::delete('vehicles/{vehicle}/images/{image}', [VehicleImageController::class, 'destroy'])->name('vehicles.images.destroy');

        // Funciones del personal (W06)
        Route::apiResource('usertypes', UserTypeController::class)->except('show');

        // Personal (W07, W08)
        Route::get('people/summary', [PersonController::class, 'summary'])->name('people.summary');
        Route::apiResource('people', PersonController::class)->parameters(['people' => 'person']);
        Route::post('people/{person}/photo', [PersonController::class, 'uploadPhoto'])->name('people.photo.store');
        Route::delete('people/{person}/photo', [PersonController::class, 'deletePhoto'])->name('people.photo.destroy');

        // Consulta de DNI para prellenar la ficha (opcional). Se limita el ritmo
        // para no agotar la cuota del proveedor por un dedo pegado al teclado.
        Route::get('dni-lookup', [DniLookupController::class, 'status'])->name('dni-lookup.status');
        Route::get('dni-lookup/{dni}', [DniLookupController::class, 'show'])
            ->middleware('throttle:20,1')
            ->name('dni-lookup.show');

        // Contratos (W09) y vacaciones (W10)
        Route::apiResource('people.contracts', ContractController::class)
            ->parameters(['people' => 'person'])
            ->except('show');
        Route::apiResource('people.vacations', VacationController::class)
            ->parameters(['people' => 'person'])
            ->except('show');
        Route::patch('people/{person}/vacations/{vacation}/approve', [VacationController::class, 'approve'])->name('people.vacations.approve');
        Route::patch('people/{person}/vacations/{vacation}/reject', [VacationController::class, 'reject'])->name('people.vacations.reject');

        // Zonas (W13), su perimetro (W14) y sus rutas (W15)
        Route::apiResource('zones', ZoneController::class);
        Route::put('zones/{zone}/coords', [ZoneController::class, 'saveCoords'])->name('zones.coords');
        Route::post('zones/{zone}/overlaps', [ZoneController::class, 'overlaps'])->name('zones.overlaps');
        Route::apiResource('routes', RouteController::class);
        Route::get('departments', [GeographyController::class, 'departments'])->name('departments');
        Route::get('provinces', [GeographyController::class, 'provinces'])->name('provinces');
        Route::get('districts', [GeographyController::class, 'districts'])->name('districts');
        // Buscador de direcciones del mapa (Nominatim), con freno para no abusar del servicio.
        Route::get('address-search', AddressSearchController::class)->middleware('throttle:30,1')->name('address-search');

        // Reporte de asistencias (W12)
        Route::get('attendances/by-person', [AttendanceController::class, 'byPerson'])->name('attendances.by-person');
        Route::get('attendances/by-date', [AttendanceController::class, 'byDate'])->name('attendances.by-date');

        // Turnos (W16)
        Route::apiResource('schedules', ScheduleController::class)->except('show');

        // Programacion (W17, W18). Guardar una programacion genera ademas los
        // recorridos de cada dia, que son los que leen W20 y W21.
        Route::get('schedulings/summary', [SchedulingController::class, 'summary'])->name('schedulings.summary');
        Route::get('schedulings/defaults/{zone}', [SchedulingController::class, 'defaultsForZone'])->name('schedulings.defaults');
        // La checklist viva del panel derecho de W18: valida sin guardar.
        Route::post('schedulings/validate', [SchedulingController::class, 'validateDraft'])->name('schedulings.validate');
        // Programación masiva (W19): varios grupos en un rango de fechas, de una vez.
        Route::post('schedulings/bulk/validate', [SchedulingController::class, 'bulkValidate'])->name('schedulings.bulk.validate');
        Route::post('schedulings/bulk', [SchedulingController::class, 'bulkStore'])->name('schedulings.bulk');
        // Edicion masiva: mismos cambios a varias programaciones (sin pantalla).
        Route::post('schedulings/bulk-update', [SchedulingController::class, 'bulkUpdate'])->name('schedulings.bulk-update');
        Route::patch('schedulings/{scheduling}/finish', [SchedulingController::class, 'finish'])->name('schedulings.finish');
        Route::apiResource('schedulings', SchedulingController::class);

        // Grupos de personal: disponibilidad en vivo para el formulario.
        Route::post('staff-groups/availability', [StaffGroupController::class, 'availability'])->name('staff-groups.availability');

        // Recorridos por dia: calendario (W20) e inicio de recorrido (W21).
        // No hay store ni destroy: los recorridos los genera la programacion.
        Route::get('vehicleroutes/summary', [VehicleRouteController::class, 'summary'])->name('vehicleroutes.summary');
        Route::post('vehicleroutes/start-batch', [VehicleRouteController::class, 'startBatch'])->name('vehicleroutes.start-batch');
        Route::get('vehicleroutes', [VehicleRouteController::class, 'index'])->name('vehicleroutes.index');
        Route::get('vehicleroutes/{vehicleroute}', [VehicleRouteController::class, 'show'])->name('vehicleroutes.show');
        Route::post('vehicleroutes/{vehicleroute}/start', [VehicleRouteController::class, 'start'])->name('vehicleroutes.start');
        Route::post('vehicleroutes/{vehicleroute}/block', [VehicleRouteController::class, 'block'])->name('vehicleroutes.block');
        Route::post('vehicleroutes/{vehicleroute}/finish', [VehicleRouteController::class, 'finish'])->name('vehicleroutes.finish');
        Route::get('vehicleroutes/{vehicleroute}/reserves', [VehicleRouteController::class, 'reserves'])->name('vehicleroutes.reserves');
        Route::put('vehicleroutes/{vehicleroute}/occupants/{occupant}', [VehicleRouteController::class, 'replaceOccupant'])->name('vehicleroutes.occupants');
    });
});
