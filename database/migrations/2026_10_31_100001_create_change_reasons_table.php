<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motivos de los cambios en las programaciones (Gestión de cambios).
     * Antes eran una lista fija en el código; aquí se cargan esos mismos para
     * que reprogramar siga funcionando igual desde el primer día.
     *
     * El historial (scheduling_changes.reason) guarda el texto del motivo, no
     * su id: editar o eliminar un motivo no reescribe lo que ya pasó.
     */
    public function up(): void
    {
        Schema::create('change_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->enum('type', ['turno', 'vehiculo', 'personal', 'general'])
                ->comment('A qué cambio se ofrece; general es para todos');
            $table->boolean('requires_description')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        $reasons = [
            'turno' => ['Reorganización de rutas', 'Mayor demanda en la zona', 'Solicitud del personal'],
            'vehiculo' => ['Mantenimiento programado', 'Avería', 'Siniestro', 'Reasignación de flota'],
            'personal' => ['Vacaciones', 'Descanso médico', 'Permiso', 'Falta', 'Reasignación'],
        ];

        $now = now();
        $rows = [];

        foreach ($reasons as $type => $names) {
            foreach ($names as $name) {
                $rows[] = ['name' => $name, 'type' => $type, 'requires_description' => false, 'description' => null, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        // "Otro" valía en las tres secciones y obligaba a describir el cambio.
        $rows[] = [
            'name' => 'Otro',
            'type' => 'general',
            'requires_description' => true,
            'description' => 'Cuando ningún otro motivo encaja; hay que describir el cambio.',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('change_reasons')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('change_reasons');
    }
};
