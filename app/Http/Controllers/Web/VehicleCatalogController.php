<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\VehicleColor;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * W03 Catálogos de vehículos (los "maestros" del informe): colores, marcas,
 * modelos y tipos, una pestaña cada uno. La pestaña viaja en ?tab= para que
 * se pueda enlazar y para volver a ella tras guardar.
 */
class VehicleCatalogController extends Controller
{
    private const TABS = ['colores', 'marcas', 'modelos', 'tipos'];

    /** Prefijo del permiso de cada pestaña (App\Support\Permissions). */
    public const PERMISSIONS = ['colores' => 'colors', 'marcas' => 'brands', 'modelos' => 'models', 'tipos' => 'types'];

    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        // Solo las pestañas que el usuario puede ver (permisos colors/brands/models/types.view).
        $allowed = array_values(array_filter(self::TABS, fn (string $tab) => $request->user()->hasPermission(self::PERMISSIONS[$tab].'.view')));
        $tab = in_array($request->query('tab'), $allowed, true) ? $request->query('tab') : $allowed[0];
        $search = trim((string) $request->query('q'));

        // La colación de MySQL ya ignora tildes y mayúsculas: "camion"
        // encuentra "Camión" sin normalizar nada aquí.
        $like = fn (Builder $q, array $columns) => $q->when($search !== '', fn (Builder $q) => $q
            ->where(function (Builder $inner) use ($columns, $search) {
                foreach ($columns as $column) {
                    $inner->orWhere($column, 'like', "%{$search}%");
                }
            }));

        $rows = match ($tab) {
            'colores' => $like(VehicleColor::withCount('vehicles'), ['name', 'description'])
                // El HEX no es columna: se busca por sus canales si escriben "#RRGGBB".
                ->when(preg_match('/^#?([0-9a-f]{6})$/i', $search, $m), function (Builder $q) use ($m) {
                    $q->orWhere(fn (Builder $c) => $c
                        ->where('red', hexdec(substr($m[1], 0, 2)))
                        ->where('green', hexdec(substr($m[1], 2, 2)))
                        ->where('blue', hexdec(substr($m[1], 4, 2))));
                }),
            'marcas' => $like(Brand::withCount('models', 'vehicles'), ['name', 'description']),
            'modelos' => $like(BrandModel::with('brand')->withCount('vehicles'), ['name', 'code', 'description'])
                ->when($search !== '', fn (Builder $q) => $q
                    ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$search}%"))),
            'tipos' => $like(VehicleType::withCount('vehicles'), ['name', 'description']),
        };

        return view('vehicles.catalogs', [
            'tab' => $tab,
            'allowedTabs' => $allowed,
            'perm' => self::PERMISSIONS[$tab],
            'search' => $search,
            'rows' => $rows->orderBy('name')->paginate(self::PER_PAGE)->withQueryString(),
            'counts' => [
                'colores' => VehicleColor::count(),
                'marcas' => Brand::count(),
                'modelos' => BrandModel::count(),
                'tipos' => VehicleType::count(),
            ],
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
