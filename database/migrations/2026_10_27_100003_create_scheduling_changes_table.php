<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de cambios de una programación: turno, vehículo o personal,
     * con su motivo. Los valores se guardan también como texto para que el
     * historial se siga leyendo aunque luego cambie el catálogo.
     */
    public function up(): void
    {
        Schema::create('scheduling_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduling_id')->constrained('schedulings')->cascadeOnDelete();
            $table->enum('type', ['turno', 'vehiculo', 'conductor', 'ayudante']);
            $table->unsignedBigInteger('old_id')->nullable();
            $table->unsignedBigInteger('new_id');
            $table->string('old_value', 150)->nullable();
            $table->string('new_value', 150);
            $table->string('reason', 100);
            $table->string('description', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_changes');
    }
};
