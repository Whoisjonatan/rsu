<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Traza GPS recorrida por el vehiculo durante un recorrido.
     */
    public function up(): void
    {
        Schema::create('routepaths', function (Blueprint $table) {
            $table->id();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->foreignId('vehicleroute_id')->constrained('vehicleroutes')->cascadeOnDelete();
            $table->timestamps();

            $table->index('vehicleroute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routepaths');
    }
};
