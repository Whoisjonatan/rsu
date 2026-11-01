<?php

namespace App\Support;

/**
 * Permisos de los usuarios del sistema (Gestión de usuarios › Lista de
 * usuarios › Permisos).
 *
 * Cada permiso es "módulo.acción" (vehicles.create). Se asignan por usuario
 * y se guardan en users.permissions. El Administrador los tiene todos
 * siempre (User::hasPermission).
 *
 * TREE es lo que pinta la pantalla de permisos; ROUTES dice qué permiso pide
 * cada ruta (lo aplica el middleware "permission"). Una ruta con varios
 * permisos se abre con cualquiera de ellos. Las de OPEN no piden ninguno.
 * Una ruta del panel o del API autenticado que no esté en ninguno de los dos
 * se rechaza, para que una pantalla nueva no quede abierta por olvido.
 */
final class Permissions
{
    private const CRUD = ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Eliminar'];

    /**
     * Grupo => [módulo => [etiqueta, [acción => etiqueta]]].
     *
     * @return array<string, array<string, array{0: string, 1: array<string, string>}>>
     */
    public static function tree(): array
    {
        return [
            'Dashboard' => [
                'dashboard' => ['Dashboard', ['view' => 'Ver']],
            ],
            'Gestión vehicular' => [
                'colors' => ['Catálogos › Colores', self::CRUD],
                'brands' => ['Catálogos › Marcas', self::CRUD],
                'models' => ['Catálogos › Modelos', self::CRUD],
                'types' => ['Catálogos › Tipos', self::CRUD],
                'vehicles' => ['Vehículos', [...self::CRUD, 'images' => 'Ver imágenes']],
            ],
            'Gestión de personal' => [
                'people' => ['Personal', self::CRUD],
                'contracts' => ['Personal › Contratos', self::CRUD],
                'vacations' => ['Personal › Vacaciones', self::CRUD],
                'attendances' => ['Asistencias', ['view' => 'Ver', 'mark' => 'Marcación', 'edit' => 'Editar', 'delete' => 'Eliminar']],
                'usertypes' => ['Funciones del personal', self::CRUD],
            ],
            'Gestión de zonas' => [
                'zones' => ['Zonas', [
                    'map' => 'Ver mapa',
                    'view' => 'Ver listado de zonas',
                    'create' => 'Crear',
                    'perimeter' => 'Dibujar perímetro',
                    'edit' => 'Editar',
                    'delete' => 'Eliminar',
                ]],
            ],
            'Programación' => [
                'schedulings' => ['Programación', [
                    'view' => 'Ver',
                    'create' => 'Programar',
                    'bulk' => 'Programación masiva',
                    'calendar' => 'Calendario',
                    'edit' => 'Editar',
                    'details' => 'Ver detalles',
                    'by_day' => 'Ver programaciones por día',
                    'finish' => 'Finalizar',
                    'delete' => 'Eliminar',
                ]],
                'schedules' => ['Turnos', self::CRUD],
                'staffgroups' => ['Grupos de personal', ['schedule' => 'Programar con este grupo', ...self::CRUD]],
                'holidays' => ['Feriados', [
                    'view' => 'Ver y filtrar',
                    'create' => 'Crear',
                    'load' => 'Cargar feriados',
                    'edit' => 'Editar',
                    'delete' => 'Eliminar',
                ]],
            ],
            'Gestión de cambios' => [
                'changes' => ['Cambios', ['view' => 'Ver', 'bulk' => 'Nuevo cambio masivo', 'details' => 'Ver detalle', 'delete' => 'Eliminar']],
                'reasons' => ['Motivos', self::CRUD],
            ],
            'Gestión de usuarios' => [
                'roles' => ['Tipos de usuario', self::CRUD],
                'users' => ['Lista de usuarios', [
                    'view' => 'Ver',
                    'create' => 'Crear',
                    'edit' => 'Editar',
                    'permissions' => 'Gestionar permisos',
                    'delete' => 'Eliminar',
                ]],
            ],
        ];
    }

    /** Todas las claves "módulo.acción". @return list<string> */
    public static function all(): array
    {
        $keys = [];

        foreach (self::tree() as $modules) {
            foreach ($modules as $module => [, $actions]) {
                foreach (array_keys($actions) as $action) {
                    $keys[] = "{$module}.{$action}";
                }
            }
        }

        return $keys;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    /**
     * Rutas que cualquier usuario autenticado puede usar.
     *
     * @var list<string>
     */
    public const OPEN = [
        'logout', 'welcome',
        'api.me', 'api.logout',
        // Combos de geografía: los usa cualquier formulario con dirección.
        'api.departments', 'api.provinces', 'api.districts',
    ];

    /**
     * Ruta => permiso (o lista: basta con uno).
     *
     * @return array<string, string|list<string>>
     */
    public static function routes(): array
    {
        // Varias rutas con el mismo permiso (o la misma lista de permisos).
        $crud = fn (string|array $perm, array $names) => array_fill_keys($names, $perm);

        return [
            'dashboard' => 'dashboard.view',

            // Catálogos de vehículos: la pantalla se abre con ver cualquiera de sus pestañas.
            'vehicle-catalogs.index' => ['colors.view', 'brands.view', 'models.view', 'types.view'],
            ...$crud('brands.create', ['brands.store', 'api.brands.store']),
            ...$crud('brands.edit', ['brands.update', 'api.brands.update', 'api.brands.logo.store', 'api.brands.logo.destroy']),
            ...$crud('brands.delete', ['brands.destroy', 'api.brands.destroy']),
            'api.brands.index' => 'brands.view',
            ...$crud('models.create', ['brandmodels.store', 'api.brandmodels.store']),
            ...$crud('models.edit', ['brandmodels.update', 'api.brandmodels.update']),
            ...$crud('models.delete', ['brandmodels.destroy', 'api.brandmodels.destroy']),
            'api.brandmodels.index' => ['models.view', 'vehicles.create', 'vehicles.edit'],
            ...$crud('types.create', ['vehicletypes.store', 'api.vehicletypes.store']),
            ...$crud('types.edit', ['vehicletypes.update', 'api.vehicletypes.update']),
            ...$crud('types.delete', ['vehicletypes.destroy', 'api.vehicletypes.destroy']),
            'api.vehicletypes.index' => 'types.view',
            ...$crud('colors.create', ['vehiclecolors.store', 'api.vehiclecolors.store']),
            ...$crud('colors.edit', ['vehiclecolors.update', 'api.vehiclecolors.update']),
            ...$crud('colors.delete', ['vehiclecolors.destroy', 'api.vehiclecolors.destroy']),
            'api.vehiclecolors.index' => 'colors.view',

            // Vehículos. Las fotos se suben y se ordenan desde la ficha.
            ...$crud('vehicles.view', ['vehicles.index', 'api.vehicles.index', 'api.vehicles.show', 'api.vehicles.summary']),
            ...$crud('vehicles.create', ['vehicles.create', 'vehicles.store', 'api.vehicles.store']),
            ...$crud('vehicles.edit', ['vehicles.edit', 'vehicles.update', 'api.vehicles.update']),
            ...$crud('vehicles.delete', ['vehicles.destroy', 'api.vehicles.destroy']),
            ...$crud(['vehicles.create', 'vehicles.edit'], [
                'vehicles.images.store', 'vehicles.images.profile', 'vehicles.images.destroy',
                'api.vehicles.images.store', 'api.vehicles.images.profile', 'api.vehicles.images.destroy',
            ]),

            // Funciones del personal.
            ...$crud('usertypes.view', ['usertypes.index', 'api.usertypes.index']),
            ...$crud('usertypes.create', ['usertypes.store', 'api.usertypes.store']),
            ...$crud('usertypes.edit', ['usertypes.update', 'api.usertypes.update']),
            ...$crud('usertypes.delete', ['usertypes.destroy', 'api.usertypes.destroy']),

            // Personal, su foto, contratos y vacaciones.
            ...$crud('people.view', ['people.index', 'api.people.index', 'api.people.show', 'api.people.summary']),
            ...$crud('people.create', ['people.create', 'people.store', 'api.people.store']),
            // La ficha se abre para editar los datos o para ver sus contratos o vacaciones.
            'people.edit' => ['people.edit', 'contracts.view', 'vacations.view'],
            ...$crud('people.edit', ['people.update', 'people.photo.store', 'people.photo.destroy', 'api.people.update', 'api.people.photo.store', 'api.people.photo.destroy']),
            ...$crud('people.delete', ['people.destroy', 'api.people.destroy']),
            'api.people.contracts.index' => 'contracts.view',
            ...$crud('contracts.create', ['contracts.store', 'api.people.contracts.store']),
            ...$crud('contracts.edit', ['contracts.update', 'contracts.deactivate', 'contracts.reactivate', 'api.people.contracts.update']),
            ...$crud('contracts.delete', ['contracts.destroy', 'api.people.contracts.destroy']),
            'api.people.vacations.index' => 'vacations.view',
            ...$crud('vacations.create', ['vacations.store', 'api.people.vacations.store']),
            ...$crud('vacations.edit', ['vacations.update', 'vacations.approve', 'vacations.reject', 'api.people.vacations.update', 'api.people.vacations.approve', 'api.people.vacations.reject']),
            ...$crud('vacations.delete', ['vacations.destroy', 'api.people.vacations.destroy']),
            // Consulta de DNI: la usan las fichas de personal y de usuarios.
            ...$crud(['people.create', 'people.edit', 'users.create', 'users.edit'], ['api.dni-lookup.status', 'api.dni-lookup.show']),

            // Asistencias.
            ...$crud('attendances.view', ['attendances.report', 'api.attendances.by-person', 'api.attendances.by-date']),

            // Zonas, su perímetro y rutas (las rutas viven dentro de las zonas).
            'zones.index' => 'zones.view',
            'zones.map' => 'zones.map',
            ...$crud(['zones.view', 'zones.map'], ['api.zones.index', 'api.zones.show']),
            ...$crud('zones.create', ['zones.store', 'api.zones.store']),
            ...$crud('zones.edit', ['zones.update', 'api.zones.update']),
            ...$crud('zones.delete', ['zones.destroy', 'api.zones.destroy']),
            ...$crud('zones.perimeter', ['zones.perimeter', 'zones.perimeter.update', 'api.zones.coords', 'api.zones.overlaps']),
            ...$crud(['zones.create', 'zones.edit', 'zones.perimeter'], ['api.address-search']),
            ...$crud('zones.view', ['routes.index', 'api.routes.index', 'api.routes.show']),
            ...$crud('zones.edit', ['routes.store', 'routes.update', 'routes.destroy', 'api.routes.store', 'api.routes.update', 'api.routes.destroy']),

            // Turnos.
            ...$crud('schedules.view', ['schedules.index', 'api.schedules.index']),
            ...$crud('schedules.create', ['schedules.store', 'api.schedules.store']),
            ...$crud('schedules.edit', ['schedules.update', 'api.schedules.update']),
            ...$crud('schedules.delete', ['schedules.destroy', 'api.schedules.destroy']),

            // Grupos de personal.
            'staffgroups.index' => 'staffgroups.view',
            ...$crud('staffgroups.create', ['staffgroups.create', 'staffgroups.store']),
            ...$crud('staffgroups.edit', ['staffgroups.edit', 'staffgroups.update']),
            'staffgroups.destroy' => 'staffgroups.delete',
            'api.staff-groups.availability' => ['staffgroups.create', 'staffgroups.edit'],

            // Feriados.
            'holidays.index' => 'holidays.view',
            'holidays.store' => 'holidays.create',
            ...$crud('holidays.load', ['holidays.peru.preview', 'holidays.peru']),
            'holidays.update' => 'holidays.edit',
            'holidays.destroy' => 'holidays.delete',

            // Programación. "Programar con este grupo" abre el alta con el grupo elegido.
            ...$crud('schedulings.view', ['schedulings.index', 'api.schedulings.index', 'api.schedulings.show', 'api.schedulings.summary']),
            ...$crud(['schedulings.create', 'staffgroups.schedule'], ['schedulings.create', 'schedulings.store', 'api.schedulings.store', 'api.schedulings.defaults']),
            'api.schedulings.validate' => ['schedulings.create', 'staffgroups.schedule', 'schedulings.edit'],
            ...$crud('schedulings.bulk', ['schedulings.bulk', 'api.schedulings.bulk.validate', 'api.schedulings.bulk']),
            // Calendario e inicio de recorrido (W20, W21).
            ...$crud('schedulings.calendar', [
                'schedulings.calendar',
                'api.vehicleroutes.index', 'api.vehicleroutes.show', 'api.vehicleroutes.summary',
                'api.vehicleroutes.start', 'api.vehicleroutes.start-batch', 'api.vehicleroutes.block',
                'api.vehicleroutes.finish', 'api.vehicleroutes.reserves', 'api.vehicleroutes.occupants',
            ]),
            // Editar (reprogramar) también lo usan los modales del Dashboard.
            ...$crud('schedulings.edit', ['schedulings.edit', 'schedulings.update', 'api.schedulings.update', 'api.schedulings.bulk-update']),
            ...$crud('schedulings.finish', ['schedulings.finish', 'schedulings.general.finish', 'api.schedulings.finish']),
            ...$crud('schedulings.delete', ['schedulings.destroy', 'schedulings.general.destroy', 'api.schedulings.destroy']),

            // Gestión de cambios.
            'scheduling-changes.index' => 'changes.view',
            ...$crud('changes.bulk', ['scheduling-changes.bulk.preview', 'scheduling-changes.bulk.store']),
            'scheduling-changes.destroy' => 'changes.delete',
            'change-reasons.index' => 'reasons.view',
            'change-reasons.store' => 'reasons.create',
            'change-reasons.update' => 'reasons.edit',
            'change-reasons.destroy' => 'reasons.delete',

            // Gestión de usuarios.
            'system-usertypes.index' => 'roles.view',
            'system-usertypes.store' => 'roles.create',
            'system-usertypes.update' => 'roles.edit',
            'system-usertypes.destroy' => 'roles.delete',
            'users.index' => 'users.view',
            ...$crud('users.create', ['users.create', 'users.store']),
            ...$crud('users.edit', ['users.edit', 'users.update', 'users.photo.store', 'users.photo.destroy']),
            ...$crud('users.permissions', ['users.permissions.edit', 'users.permissions.update']),
            'users.destroy' => 'users.delete',
        ];
    }

    /**
     * Qué permiso abre cada opción del menú lateral (basta con uno).
     *
     * @return array<string, list<string>>
     */
    public static function menu(): array
    {
        return [
            '/dashboard' => ['dashboard.view'],
            '/vehiculos' => ['vehicles.view'],
            '/vehiculos/catalogos' => ['colors.view', 'brands.view', 'models.view', 'types.view'],
            '/personal' => ['people.view'],
            '/asistencias' => ['attendances.view'],
            '/personal/funciones' => ['usertypes.view'],
            '/zonas' => ['zones.view', 'zones.map'],
            '/programacion' => ['schedulings.view'],
            '/turnos' => ['schedules.view'],
            '/grupos-personal' => ['staffgroups.view'],
            '/feriados' => ['holidays.view'],
            '/cambios' => ['changes.view'],
            '/motivos' => ['reasons.view'],
            '/usuarios' => ['users.view'],
            '/usuarios/tipos' => ['roles.view'],
        ];
    }
}
