<?php

use App\Http\Controllers\Web\AttendanceController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BrandController;
use App\Http\Controllers\Web\BrandModelController;
use App\Http\Controllers\Web\ChangeReasonController;
use App\Http\Controllers\Web\ContractController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HolidayController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PersonController;
use App\Http\Controllers\Web\RouteController;
use App\Http\Controllers\Web\ScheduleController;
use App\Http\Controllers\Web\SchedulingChangeController;
use App\Http\Controllers\Web\SchedulingController;
use App\Http\Controllers\Web\StaffGroupController;
use App\Http\Controllers\Web\SystemUserController;
use App\Http\Controllers\Web\TrackingController;
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

Route::middleware(['auth', 'permission'])->group(function () {
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

    // El panel (W02) se retiró: la raíz lleva a Inicio.
    Route::redirect('/', '/inicio');
    Route::get('inicio', [HomeController::class, 'welcome'])->name('welcome');
    // Monitoreo diario de las programaciones: completas, incompletas y personal faltante.
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

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
    Route::patch('personal/{person}/contratos/{contract}/desactivar', [ContractController::class, 'deactivate'])->name('contracts.deactivate');
    Route::patch('personal/{person}/contratos/{contract}/reactivar', [ContractController::class, 'reactivate'])->name('contracts.reactivate');

    Route::post('personal/{person}/vacaciones', [VacationController::class, 'store'])->name('vacations.store');
    Route::put('personal/{person}/vacaciones/{vacation}', [VacationController::class, 'update'])->name('vacations.update');
    Route::patch('personal/{person}/vacaciones/{vacation}/aprobar', [VacationController::class, 'approve'])->name('vacations.approve');
    Route::patch('personal/{person}/vacaciones/{vacation}/rechazar', [VacationController::class, 'reject'])->name('vacations.reject');
    Route::delete('personal/{person}/vacaciones/{vacation}', [VacationController::class, 'destroy'])->name('vacations.destroy');

    // Reporte de asistencias (W12).
    Route::get('asistencias', [AttendanceController::class, 'report'])->name('attendances.report');

    // Zonas (W13), su perímetro (W14, del que sale el área) y rutas (W15).
    Route::get('zonas', [ZoneController::class, 'index'])->name('zones.index');
    Route::get('zonas/mapa', [ZoneController::class, 'map'])->name('zones.map');
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

    // Grupos de personal: plantillas para programar solo con el rango de fechas.
    Route::get('grupos-personal', [StaffGroupController::class, 'index'])->name('staffgroups.index');
    Route::get('grupos-personal/nuevo', [StaffGroupController::class, 'create'])->name('staffgroups.create');
    Route::post('grupos-personal', [StaffGroupController::class, 'store'])->name('staffgroups.store');
    Route::get('grupos-personal/{staffgroup}', [StaffGroupController::class, 'edit'])->whereNumber('staffgroup')->name('staffgroups.edit');
    Route::put('grupos-personal/{staffgroup}', [StaffGroupController::class, 'update'])->whereNumber('staffgroup')->name('staffgroups.update');
    Route::delete('grupos-personal/{staffgroup}', [StaffGroupController::class, 'destroy'])->whereNumber('staffgroup')->name('staffgroups.destroy');

    // Feriados: un feriado activo no lleva recorridos.
    Route::get('feriados', [HolidayController::class, 'index'])->name('holidays.index');
    Route::post('feriados', [HolidayController::class, 'store'])->name('holidays.store');
    Route::get('feriados/peru', [HolidayController::class, 'previewPeru'])->name('holidays.peru.preview');
    Route::post('feriados/peru', [HolidayController::class, 'loadPeru'])->name('holidays.peru');
    Route::put('feriados/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
    Route::delete('feriados/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

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
    Route::patch('programacion/{scheduling}/finalizar', [SchedulingController::class, 'finish'])->whereNumber('scheduling')->name('schedulings.finish');
    // La programación general entera (pestaña General de W17): todos sus días a la vez.
    Route::delete('programacion/general/{general}', [SchedulingController::class, 'destroyGeneral'])->whereNumber('general')->name('schedulings.general.destroy');
    Route::patch('programacion/general/{general}/finalizar', [SchedulingController::class, 'finishGeneral'])->whereNumber('general')->name('schedulings.general.finish');

    // Recorridos en vivo: el trayecto GPS que manda la app del conductor, en el mapa.
    Route::get('recorridos-en-vivo', [TrackingController::class, 'index'])->name('tracking.index');

    // Gestión de cambios: el historial de cambios y los motivos que se eligen al reprogramar.
    Route::get('cambios', [SchedulingChangeController::class, 'index'])->name('scheduling-changes.index');
    Route::delete('cambios/{change}', [SchedulingChangeController::class, 'destroy'])->whereNumber('change')->name('scheduling-changes.destroy');
    // Cambio masivo: primero el resumen, luego se aplica lo confirmado.
    Route::post('cambios/masivo/resumen', [SchedulingChangeController::class, 'bulkPreview'])->name('scheduling-changes.bulk.preview');
    Route::post('cambios/masivo', [SchedulingChangeController::class, 'bulkStore'])->name('scheduling-changes.bulk.store');
    Route::get('motivos', [ChangeReasonController::class, 'index'])->name('change-reasons.index');
    Route::post('motivos', [ChangeReasonController::class, 'store'])->name('change-reasons.store');
    Route::put('motivos/{reason}', [ChangeReasonController::class, 'update'])->whereNumber('reason')->name('change-reasons.update');
    Route::delete('motivos/{reason}', [ChangeReasonController::class, 'destroy'])->whereNumber('reason')->name('change-reasons.destroy');

    // Gestión de usuarios: los usuarios del sistema (Administrador y Supervisor). Solo para administradores.
    // Tipos de usuario (roles del sistema): el mismo catálogo que Funciones, con kind "sistema".
    Route::get('usuarios/tipos', [UserTypeController::class, 'index'])->defaults('kind', 'sistema')->name('system-usertypes.index');
    Route::post('usuarios/tipos', [UserTypeController::class, 'store'])->defaults('kind', 'sistema')->name('system-usertypes.store');
    Route::put('usuarios/tipos/{usertype}', [UserTypeController::class, 'update'])->defaults('kind', 'sistema')->name('system-usertypes.update');
    Route::delete('usuarios/tipos/{usertype}', [UserTypeController::class, 'destroy'])->defaults('kind', 'sistema')->name('system-usertypes.destroy');
    Route::get('usuarios', [SystemUserController::class, 'index'])->name('users.index');
    Route::get('usuarios/nuevo', [SystemUserController::class, 'create'])->name('users.create');
    Route::post('usuarios', [SystemUserController::class, 'store'])->name('users.store');
    Route::get('usuarios/{user}', [SystemUserController::class, 'edit'])->whereNumber('user')->name('users.edit');
    Route::put('usuarios/{user}', [SystemUserController::class, 'update'])->whereNumber('user')->name('users.update');
    Route::delete('usuarios/{user}', [SystemUserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
    Route::get('usuarios/{user}/permisos', [SystemUserController::class, 'permissions'])->whereNumber('user')->name('users.permissions.edit');
    Route::put('usuarios/{user}/permisos', [SystemUserController::class, 'updatePermissions'])->whereNumber('user')->name('users.permissions.update');
    Route::post('usuarios/{user}/foto', [SystemUserController::class, 'uploadPhoto'])->whereNumber('user')->name('users.photo.store');
    Route::delete('usuarios/{user}/foto', [SystemUserController::class, 'deletePhoto'])->whereNumber('user')->name('users.photo.destroy');

    // Maestros se separó en dos módulos; las direcciones viejas siguen funcionando.
    Route::redirect('maestros', '/vehiculos/catalogos');
    Route::redirect('maestros/funciones', '/personal/funciones');
});
