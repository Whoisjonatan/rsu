<?php

use App\Http\Controllers\Web\AttendanceController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BrandController;
use App\Http\Controllers\Web\BrandModelController;
use App\Http\Controllers\Web\ContractController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PersonController;
use App\Http\Controllers\Web\RouteController;
use App\Http\Controllers\Web\ScheduleController;
use App\Http\Controllers\Web\SchedulingController;
use App\Http\Controllers\Web\UserTypeController;
use App\Http\Controllers\Web\VacationController;
use App\Http\Controllers\Web\VehicleCatalogController;
use App\Http\Controllers\Web\VehicleColorController;
use App\Http\Controllers\Web\VehicleController;
use App\Http\Controllers\Web\VehicleImageController;
use App\Http\Controllers\Web\VehicleTypeController;
use App\Http\Controllers\Web\ZoneController;
use Illuminate\Support\Facades\Route;

/*
 * Pantallas del panel web (Blade). Las direcciones son las mismas que tenía
 * el front en React, así que los enlaces y marcadores viejos siguen sirviendo.
 * El API para la app móvil vive aparte, en routes/api.php.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store']);
});

// Terminal de marcación (W11): público a propósito. Es un punto de fichaje
// compartido donde el trabajador se identifica con su DNI y su clave.
Route::get('marcacion', [AttendanceController::class, 'terminal'])->name('attendances.terminal');
Route::post('marcacion', [AttendanceController::class, 'mark'])
    ->middleware('throttle:30,1')
    ->name('attendances.mark');

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

    // El panel (W02) se retiró: la raíz lleva a Inicio.
    Route::redirect('/', '/inicio');
    Route::get('inicio', [HomeController::class, 'welcome'])->name('welcome');

    // Catálogos de vehículos (W03): cuatro pestañas en una sola pantalla.
    Route::get('vehiculos/catalogos', [VehicleCatalogController::class, 'index'])->name('vehicle-catalogs.index');
    Route::prefix('vehiculos/catalogos')->group(function () {
        Route::post('marcas', [BrandController::class, 'store'])->name('brands.store');
        Route::put('marcas/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('marcas/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

        Route::post('modelos', [BrandModelController::class, 'store'])->name('brandmodels.store');
        Route::put('modelos/{brandmodel}', [BrandModelController::class, 'update'])->name('brandmodels.update');
        Route::delete('modelos/{brandmodel}', [BrandModelController::class, 'destroy'])->name('brandmodels.destroy');

        Route::post('tipos', [VehicleTypeController::class, 'store'])->name('vehicletypes.store');
        Route::put('tipos/{vehicletype}', [VehicleTypeController::class, 'update'])->name('vehicletypes.update');
        Route::delete('tipos/{vehicletype}', [VehicleTypeController::class, 'destroy'])->name('vehicletypes.destroy');

        Route::post('colores', [VehicleColorController::class, 'store'])->name('vehiclecolors.store');
        Route::put('colores/{vehiclecolor}', [VehicleColorController::class, 'update'])->name('vehiclecolors.update');
        Route::delete('colores/{vehiclecolor}', [VehicleColorController::class, 'destroy'])->name('vehiclecolors.destroy');
    });

    // Vehículos (W04, W05) y sus imágenes.
    Route::get('vehiculos', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::get('vehiculos/nuevo', [VehicleController::class, 'create'])->name('vehicles.create');
    Route::post('vehiculos', [VehicleController::class, 'store'])->name('vehicles.store');
    Route::get('vehiculos/{vehicle}', [VehicleController::class, 'edit'])->whereNumber('vehicle')->name('vehicles.edit');
    Route::put('vehiculos/{vehicle}', [VehicleController::class, 'update'])->whereNumber('vehicle')->name('vehicles.update');
    Route::delete('vehiculos/{vehicle}', [VehicleController::class, 'destroy'])->whereNumber('vehicle')->name('vehicles.destroy');
    Route::post('vehiculos/{vehicle}/imagenes', [VehicleImageController::class, 'store'])->name('vehicles.images.store');
    Route::put('vehiculos/{vehicle}/imagenes/{image}/perfil', [VehicleImageController::class, 'setProfile'])->name('vehicles.images.profile');
    Route::delete('vehiculos/{vehicle}/imagenes/{image}', [VehicleImageController::class, 'destroy'])->name('vehicles.images.destroy');

    // Funciones del personal (W06).
    Route::get('personal/funciones', [UserTypeController::class, 'index'])->name('usertypes.index');
    Route::post('personal/funciones', [UserTypeController::class, 'store'])->name('usertypes.store');
    Route::put('personal/funciones/{usertype}', [UserTypeController::class, 'update'])->name('usertypes.update');
    Route::delete('personal/funciones/{usertype}', [UserTypeController::class, 'destroy'])->name('usertypes.destroy');

    // Personal (W07, W08), su foto, contratos (W09) y vacaciones (W10).
    Route::get('personal', [PersonController::class, 'index'])->name('people.index');
    Route::get('personal/nuevo', [PersonController::class, 'create'])->name('people.create');
    Route::post('personal', [PersonController::class, 'store'])->name('people.store');
    Route::get('personal/{person}', [PersonController::class, 'edit'])->whereNumber('person')->name('people.edit');
    Route::put('personal/{person}', [PersonController::class, 'update'])->whereNumber('person')->name('people.update');
    Route::delete('personal/{person}', [PersonController::class, 'destroy'])->whereNumber('person')->name('people.destroy');
    Route::post('personal/{person}/foto', [PersonController::class, 'uploadPhoto'])->name('people.photo.store');
    Route::delete('personal/{person}/foto', [PersonController::class, 'deletePhoto'])->name('people.photo.destroy');

    Route::post('personal/{person}/contratos', [ContractController::class, 'store'])->name('contracts.store');
    Route::put('personal/{person}/contratos/{contract}', [ContractController::class, 'update'])->name('contracts.update');
    Route::delete('personal/{person}/contratos/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');

    Route::post('personal/{person}/vacaciones', [VacationController::class, 'store'])->name('vacations.store');
    Route::put('personal/{person}/vacaciones/{vacation}', [VacationController::class, 'update'])->name('vacations.update');
    Route::patch('personal/{person}/vacaciones/{vacation}/aprobar', [VacationController::class, 'approve'])->name('vacations.approve');
    Route::patch('personal/{person}/vacaciones/{vacation}/rechazar', [VacationController::class, 'reject'])->name('vacations.reject');
    Route::delete('personal/{person}/vacaciones/{vacation}', [VacationController::class, 'destroy'])->name('vacations.destroy');

    // Reporte de asistencias (W12).
    Route::get('asistencias', [AttendanceController::class, 'report'])->name('attendances.report');

    // Zonas (W13), su perímetro (W14) y rutas (W15). Los sectores se crean desde
    // el modal de zona contra el API (POST /api/sectors), sin salir del formulario.
    Route::get('zonas', [ZoneController::class, 'index'])->name('zones.index');
    Route::post('zonas', [ZoneController::class, 'store'])->name('zones.store');
    Route::put('zonas/{zone}', [ZoneController::class, 'update'])->name('zones.update');
    Route::delete('zonas/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
    Route::get('zonas/{zone}/perimetro', [ZoneController::class, 'perimeter'])->name('zones.perimeter');
    Route::put('zonas/{zone}/perimetro', [ZoneController::class, 'savePerimeter'])->name('zones.perimeter.update');

    Route::get('rutas', [RouteController::class, 'index'])->name('routes.index');
    Route::post('rutas', [RouteController::class, 'store'])->name('routes.store');
    Route::put('rutas/{route}', [RouteController::class, 'update'])->name('routes.update');
    Route::delete('rutas/{route}', [RouteController::class, 'destroy'])->name('routes.destroy');

    // Turnos (W16).
    Route::get('turnos', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::post('turnos', [ScheduleController::class, 'store'])->name('schedules.store');
    Route::put('turnos/{schedule}', [ScheduleController::class, 'update'])->name('schedules.update');
    Route::delete('turnos/{schedule}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');

    // Programación (W17, W18), edición masiva (W19) y calendario semanal (W20).
    // El inicio de recorrido (W21) es un modal del calendario que trabaja
    // contra el API: valida, reemplaza personal e inicia en vivo.
    Route::get('programacion', [SchedulingController::class, 'index'])->name('schedulings.index');
    Route::get('programacion/calendario', [SchedulingController::class, 'calendar'])->name('schedulings.calendar');
    Route::get('programacion/masiva', [SchedulingController::class, 'bulk'])->name('schedulings.bulk');
    Route::get('programacion/nueva', [SchedulingController::class, 'create'])->name('schedulings.create');
    Route::post('programacion', [SchedulingController::class, 'store'])->name('schedulings.store');
    Route::get('programacion/{scheduling}', [SchedulingController::class, 'edit'])->whereNumber('scheduling')->name('schedulings.edit');
    Route::put('programacion/{scheduling}', [SchedulingController::class, 'update'])->whereNumber('scheduling')->name('schedulings.update');
    Route::delete('programacion/{scheduling}', [SchedulingController::class, 'destroy'])->whereNumber('scheduling')->name('schedulings.destroy');

    // Maestros se separó en dos módulos; las direcciones viejas siguen funcionando.
    Route::redirect('maestros', '/vehiculos/catalogos');
    Route::redirect('maestros/funciones', '/personal/funciones');
});
