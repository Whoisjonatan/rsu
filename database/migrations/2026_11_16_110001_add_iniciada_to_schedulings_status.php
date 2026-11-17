<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Iniciada": el conductor (o W21) arrancó el recorrido de la programación y
 * aún no lo cierra. Sigue en pie como una activa (ocupa vehículo y personal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->enum('status', ['activa', 'inactiva', 'finalizada', 'reprogramada', 'iniciada'])->default('activa')->change();
        });

        // Las que hoy tienen su recorrido en curso.
        DB::table('schedulings')
            ->whereIn('status', ['activa', 'reprogramada'])
            ->whereIn('id', DB::table('vehicleroutes')->where('status', 'iniciado')->select('scheduling_id'))
            ->update(['status' => 'iniciada']);
    }

    public function down(): void
    {
        DB::table('schedulings')->where('status', 'iniciada')->update(['status' => 'activa']);

        Schema::table('schedulings', function (Blueprint $table) {
            $table->enum('status', ['activa', 'inactiva', 'finalizada', 'reprogramada'])->default('activa')->change();
        });
    }
};
