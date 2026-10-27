<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feriados en los que esta programación sí lleva recorrido. Por defecto un
     * feriado activo no se programa; la programación masiva deja desmarcar
     * alguno para trabajarlo igual.
     */
    public function up(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->json('worked_holidays')->nullable()->after('weekdays');
        });
    }

    public function down(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->dropColumn('worked_holidays');
        });
    }
};
