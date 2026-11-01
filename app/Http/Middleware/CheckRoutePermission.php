<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige el permiso de la ruta (App\Support\Permissions::routes()). Va detrás
 * de la autenticación, en el panel y en el API.
 *
 * Una ruta que no está en el mapa ni en Permissions::OPEN se rechaza: así una
 * pantalla nueva no queda abierta a todos por olvidar su permiso.
 */
class CheckRoutePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = $request->route()?->getName();

        // Las redirecciones (/ → /inicio, direcciones viejas) llevan a otra ruta, que pide lo suyo.
        $isRedirect = str_contains((string) $request->route()?->getActionName(), 'RedirectController');

        if (! $user || $isRedirect || in_array($name, Permissions::OPEN, true)) {
            return $next($request);
        }

        $required = Permissions::routes()[$name] ?? null;

        if ($required === null) {
            report(new \LogicException("La ruta [{$name}] no tiene permiso asignado en App\\Support\\Permissions."));
        }

        if ($required === null || ! $user->hasAnyPermission((array) $required)) {
            abort(403, 'No tienes permiso para esta acción. Pídeselo a un administrador.');
        }

        return $next($request);
    }
}
