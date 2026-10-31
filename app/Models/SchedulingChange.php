<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de turno, vehículo o personal hecho a una programación ya
 * registrada. Lo aplica Scheduling::reschedule().
 */
class SchedulingChange extends Model
{
    /** Tipos tal como se guardan, con su etiqueta. El personal se separa por función. */
    public const TYPES = [
        'turno' => 'Turno',
        'vehiculo' => 'Vehículo',
        'conductor' => 'Personal (conductor)',
        'ayudante' => 'Personal (ayudante)',
    ];

    // Los motivos se registran en Gestión de cambios (ChangeReason); aquí se guarda su texto.

    protected $table = 'scheduling_changes';

    protected $fillable = [
        'type', 'old_id', 'new_id', 'old_value', 'new_value', 'reason', 'description', 'user_id',
    ];

    /** La sección de la pantalla (y de ChangeReason) a la que pertenece el tipo. */
    public static function section(string $type): string
    {
        return in_array($type, ['conductor', 'ayudante'], true) ? 'personal' : $type;
    }

    public function scheduling(): BelongsTo
    {
        return $this->belongsTo(Scheduling::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
