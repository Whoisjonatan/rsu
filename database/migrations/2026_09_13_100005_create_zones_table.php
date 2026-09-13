<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('area', 10, 3)->nullable()->comment('Area en km2');
            $table->string('description', 255)->nullable();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
