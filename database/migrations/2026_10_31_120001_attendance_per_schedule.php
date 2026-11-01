<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una marcación por turno, no por día: quien trabaja mañana y tarde marca
     * entrada y salida en cada uno (hasta tres turnos al día).
     *
     * El índice nuevo se crea antes de quitar el viejo porque MySQL usa el
     * índice que empieza por user_id para su clave foránea.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['user_id', 'date', 'schedule_id']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['user_id', 'date']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'date', 'schedule_id']);
        });
    }
};
