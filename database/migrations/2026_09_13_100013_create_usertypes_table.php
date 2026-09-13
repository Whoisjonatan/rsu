<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usertypes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('slug', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false)->comment('Funcion predefinida: no se puede eliminar');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usertypes');
    }
};
