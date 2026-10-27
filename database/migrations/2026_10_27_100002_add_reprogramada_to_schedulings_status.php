<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una programación a la que se le cambió el turno, el vehículo o el
     * personal queda "reprogramada". Sigue en pie como una activa.
     */
    public function up(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->enum('status', ['activa', 'inactiva', 'finalizada', 'reprogramada'])->default('activa')->change();
        });
    }

    public function down(): void
    {
        DB::table('schedulings')->where('status', 'reprogramada')->update(['status' => 'activa']);

        Schema::table('schedulings', function (Blueprint $table) {
            $table->enum('status', ['activa', 'inactiva', 'finalizada'])->default('activa')->change();
        });
    }
};
