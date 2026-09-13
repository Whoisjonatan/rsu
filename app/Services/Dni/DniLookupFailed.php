<?php

namespace App\Services\Dni;

use RuntimeException;

/** El proveedor no respondio o respondio mal. Nunca debe bloquear el alta. */
class DniLookupFailed extends RuntimeException {}
