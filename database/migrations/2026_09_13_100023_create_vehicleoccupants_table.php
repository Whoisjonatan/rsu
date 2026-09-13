<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal asignado a un recorrido, con su funcion: conductor o ayudante.
     */
    public function up(): void
    {
        Schema::create('vehicleoccupants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicleroute_id')->constrained('vehicleroutes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('usertype_id')->constrained('usertypes')->restrictOnDelete();
            $table->enum('status', ['asignado', 'reemplazado', 'ausente'])->default('asignado');
            $table->foreignId('replaced_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vehicleroute_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicleoccupants');
    }
};
