<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('latitude_start', 10, 7)->nullable();
            $table->decimal('longitude_start', 10, 7)->nullable();
            $table->decimal('latitude_end', 10, 7)->nullable();
            $table->decimal('longitude_end', 10, 7)->nullable();
            $table->string('description', 255)->nullable();
            $table->enum('status', ['activa', 'inactiva'])->default('activa');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
