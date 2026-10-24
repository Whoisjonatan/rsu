<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grupos de personal: plantillas de programación. Guardan turno, zona,
     * vehículo, días y tripulación (conductor y ayudantes) para que al
     * programar solo falte el rango de fechas.
     */
    public function up(): void
    {
        Schema::create('staffgroups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('schedule_id')->constrained('schedules')->restrictOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->json('weekdays')->comment('Dias ISO-8601: 1 lunes a 7 domingo');
            $table->enum('status', ['activo', 'inactivo'])->default('activo');
            $table->timestamps();
        });

        Schema::create('staffgrouphelpers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staffgroup_id')->constrained('staffgroups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();

            $table->unique(['staffgroup_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staffgrouphelpers');
        Schema::dropIfExists('staffgroups');
    }
};
