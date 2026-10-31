<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Motivo de un cambio en una programación (Gestión de cambios › Motivos).
 *
 * Cada motivo se ofrece en una sección de la reprogramación (turno, vehículo
 * o personal) o en todas ("general"). Con `requires_description` hay que
 * describir el cambio al elegirlo, como pasa con "Otro".
 *
 * El historial guarda el texto del motivo, no su id, así que editarlo o
 * eliminarlo no cambia los cambios ya registrados.
 */
class ChangeReason extends Model
{
    /** A qué cambio se ofrece, con su etiqueta. */
    public const TYPES = [
        'turno' => 'Turno',
        'vehiculo' => 'Vehículo',
        'personal' => 'Personal',
        'general' => 'Todos los cambios',
    ];

    /** Las secciones de la reprogramación, sin "general". */
    public const SECTIONS = ['turno', 'vehiculo', 'personal'];

    protected $fillable = ['name', 'type', 'requires_description', 'description'];

    protected function casts(): array
    {
        return [
            'requires_description' => 'boolean',
        ];
    }

    /** Los que se ofrecen en una sección: los suyos y los generales. */
    public function scopeForSection(Builder $query, string $section): Builder
    {
        return $query->whereIn('type', [$section, 'general']);
    }

    /**
     * Lo que piden los combos de motivo: por sección, primero los propios y
     * al final los generales, cada uno con si pide descripción.
     *
     * @return array<string, list<array{name: string, requires_description: bool}>>
     */
    public static function options(): array
    {
        $all = self::query()->orderBy('name')->get(['name', 'type', 'requires_description']);

        return collect(self::SECTIONS)->mapWithKeys(fn (string $section) => [
            $section => $all->where('type', $section)
                ->concat($all->where('type', 'general'))
                ->map(fn (self $reason) => $reason->only('name', 'requires_description'))
                ->values()
                ->all(),
        ])->all();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Cuántos cambios del historial lo nombran. */
    public function usageCount(): int
    {
        return SchedulingChange::where('reason', $this->name)->count();
    }

    /**
     * Por qué no se puede eliminar, o null si se puede. Una sección de la
     * reprogramación no puede quedarse sin motivos: no se podría guardar
     * ningún cambio de ese tipo.
     */
    public function deletionBlocker(): ?string
    {
        $sections = $this->type === 'general' ? self::SECTIONS : [$this->type];

        foreach ($sections as $section) {
            if (! self::forSection($section)->whereKeyNot($this->id)->exists()) {
                return sprintf(
                    'No se puede eliminar: es el único motivo para los cambios de %s. Registra otro antes.',
                    mb_strtolower(self::TYPES[$section]),
                );
            }
        }

        return null;
    }
}
