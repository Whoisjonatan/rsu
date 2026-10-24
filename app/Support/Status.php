<?php

namespace App\Support;

/**
 * Etiqueta y color de cada estado. Las vistas lo usan como
 * <x-badge :tone="Status::tone('vehicle', $v->status)">, así el mismo estado
 * sale siempre igual en cualquier pantalla.
 */
class Status
{
    /** @var array<string, array<string, array{0: string, 1: string}>> [etiqueta, color] */
    private const MAP = [
        // Semáforo del vehículo.
        'vehicle' => [
            'operativo' => ['Operativo', 'green'],
            'mantenimiento' => ['Mantenimiento', 'amber'],
            'baja' => ['De baja', 'slate'],
        ],
        'zone' => [
            'activa' => ['Activa', 'green'],
            'inactiva' => ['Inactiva', 'slate'],
        ],
        // Recorrido de un día (vehicleroutes).
        'route' => [
            'programado' => ['Programado', 'slate'],
            'iniciado' => ['Iniciado', 'green'],
            'bloqueado' => ['Bloqueado', 'red'],
            'finalizado' => ['Finalizado', 'blue'],
        ],
        // Estado derivado de W07 (contrato y vacaciones de hoy).
        'person' => [
            'activo' => ['Activo', 'green'],
            'vacaciones' => ['Vacaciones', 'blue'],
            'culminado' => ['Culminado', 'amber'],
            'sin_contrato' => ['Sin contrato', 'slate'],
            'inactivo' => ['Inactivo', 'slate'],
        ],
        'contract' => [
            'nombrado' => ['Nombrado', 'green'],
            'permanente' => ['Permanente', 'green'],
            'eventual' => ['Eventual', 'amber'],
        ],
        'vacation' => [
            'pendientes' => ['Pendiente', 'amber'],
            'programadas' => ['Programada', 'blue'],
            'gozadas' => ['Gozada', 'slate'],
            'rechazadas' => ['Rechazada', 'red'],
            'canceladas' => ['Cancelada', 'slate'],
        ],
        'staffgroup' => [
            'activo' => ['Activo', 'green'],
            'inactivo' => ['Inactivo', 'slate'],
        ],
        'holiday' => [
            'activo' => ['Activo', 'green'],
            'inactivo' => ['Inactivo', 'slate'],
        ],
        'attendance' => [
            'presente' => ['Presente', 'green'],
            'tarde' => ['Tardanza', 'amber'],
            'falta' => ['Ausente', 'red'],
        ],
        // Ruta (catálogo de W15) y programación maestra (W17).
        'active' => [
            'activa' => ['Activa', 'green'],
            'inactiva' => ['Inactiva', 'slate'],
        ],
    ];

    public static function label(string $domain, ?string $status): string
    {
        return self::MAP[$domain][$status][0] ?? ($status ? ucfirst(str_replace('_', ' ', $status)) : '—');
    }

    public static function tone(string $domain, ?string $status): string
    {
        return self::MAP[$domain][$status][1] ?? 'slate';
    }

    /**
     * Opciones para un <select>: [valor => etiqueta].
     *
     * @return array<string, string>
     */
    public static function options(string $domain): array
    {
        return array_map(fn (array $entry) => $entry[0], self::MAP[$domain] ?? []);
    }
}
