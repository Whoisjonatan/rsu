<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brandmodels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brandmodels');
    }
};
