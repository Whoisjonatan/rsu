<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W12 lista la asistencia con su TURNO: la marcacion se hace dentro de la
     * ventana horaria de un turno, y hay que guardar cual fue.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('schedule_id')->nullable()->after('date')
                ->constrained('schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_id');
        });
    }
};
