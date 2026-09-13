<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Programacion maestra: la regla que genera un recorrido por cada dia
     * marcado de la semana entre start_date y end_date (tabla vehicleroutes).
     */
    public function up(): void
    {
        Schema::create('schedulings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('schedule_id')->constrained('schedules')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('helper_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('weekdays')->comment('Dias ISO-8601: 1 lunes a 7 domingo');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['activa', 'inactiva'])->default('activa');
            $table->timestamps();

            $table->index(['zone_id', 'schedule_id']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedulings');
    }
};
