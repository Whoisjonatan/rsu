<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un tipo de la tabla `usertypes`. Hay dos grupos (columna `kind`):
 *
 * - **sistema**: tipos de usuario que entran al panel (Administrador,
 *   Supervisor y los roles que se creen en Gestión de usuarios › Tipos de
 *   usuario). Sus usuarios se gestionan en Lista de usuarios.
 * - **personal**: funciones del personal operativo (Conductor, Ayudante y las
 *   que se creen en Personal › Funciones), con contratos, vacaciones y
 *   marcación.
 */
class UserType extends Model
{
    protected $table = 'usertypes';

    /** Funciones que el informe exige preservar. */
    public const DRIVER = 'conductor';

    public const HELPER = 'ayudante';

    /** El administrador es el único que gestiona usuarios y tipos de usuario. */
    public const ADMIN = 'administrador';

    public const SUPERVISOR = 'supervisor';

    public const KIND_SYSTEM = 'sistema';

    public const KIND_STAFF = 'personal';

    /** Ciudadanos registrados desde la app: ni personal ni usuarios del panel. */
    public const KIND_CITIZEN = 'ciudadano';

    public const CITIZEN = 'ciudadano';

    protected $fillable = ['name', 'slug', 'kind', 'description', 'is_system'];

    protected $attributes = ['kind' => self::KIND_STAFF];

    /** Tipos de usuario del sistema: los que entran al panel. */
    public function scopeSystemUsers(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_SYSTEM);
    }

    /** Funciones del personal operativo. */
    public function scopeStaffFunctions(Builder $query): Builder
    {
        return $query->whereNotIn('kind', [self::KIND_SYSTEM, self::KIND_CITIZEN]);
    }

    public function isSystemUser(): bool
    {
        return $this->kind === self::KIND_SYSTEM;
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'usertype_id');
    }

    /** Por qué no se puede eliminar, o null si se puede. */
    public function deletionBlocker(): ?string
    {
        $system = $this->isSystemUser();

        if ($this->is_system) {
            return $system
                ? "El tipo de usuario \"{$this->name}\" es predefinido y no se puede eliminar."
                : "La función \"{$this->name}\" es predefinida y no se puede eliminar.";
        }

        if ($this->users()->exists()) {
            return $system
                ? 'No se puede eliminar: hay usuarios con este tipo.'
                : 'No se puede eliminar: hay personal con esta función.';
        }

        return null;
    }
}
