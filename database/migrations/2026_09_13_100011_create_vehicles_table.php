<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();
            $table->string('code', 30)->unique();
            $table->string('plate', 10)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('occupant_capacity')->default(0);
            $table->decimal('load_capacity', 8, 2)->default(0)->comment('Capacidad de carga en m3');
            $table->string('engine_number', 60)->nullable();
            $table->string('description', 255)->nullable();
            $table->enum('status', ['operativo', 'mantenimiento', 'baja'])->default('operativo');
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('model_id')->constrained('brandmodels')->restrictOnDelete();
            $table->foreignId('type_id')->constrained('vehicletypes')->restrictOnDelete();
            $table->foreignId('color_id')->nullable()->constrained('vehiclecolors')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
