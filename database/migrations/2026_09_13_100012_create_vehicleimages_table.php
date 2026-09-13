<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicleimages', function (Blueprint $table) {
            $table->id();
            $table->string('image', 255);
            $table->boolean('profile')->default(false)->comment('Imagen de perfil del vehiculo');
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicleimages');
    }
};
