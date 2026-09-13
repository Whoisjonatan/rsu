<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recorrido de un dia concreto, generado desde una programacion maestra.
     */
    public function up(): void
    {
        Schema::create('vehicleroutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduling_id')->nullable()->constrained('schedulings')->cascadeOnDelete();
            $table->date('date_route');
            $table->time('time_route')->nullable()->comment('Hora real de inicio del recorrido');
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->foreignId('schedule_id')->constrained('schedules')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->enum('status', ['programado', 'iniciado', 'bloqueado', 'finalizado'])->default('programado');
            $table->string('block_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['date_route', 'schedule_id']);
            $table->unique(['scheduling_id', 'date_route']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicleroutes');
    }
};
