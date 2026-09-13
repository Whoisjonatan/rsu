<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W10 muestra varios períodos de vacaciones por año (2025 aparece dos veces,
     * 15 días cada uno) con su propio estado. La tabla original guardaba un solo
     * rango con scheduled_days/pending_days por fila, que no representa eso:
     * ahora cada fila es un período y los totales del año se calculan sumando.
     */
    public function up(): void
    {
        Schema::table('vacations', function (Blueprint $table) {
            $table->dropColumn(['scheduled_days', 'pending_days']);
        });

        Schema::table('vacations', function (Blueprint $table) {
            $table->unsignedTinyInteger('days')->default(0)->after('year');
            $table->enum('status', ['programadas', 'gozadas', 'canceladas'])
                ->default('programadas')
                ->after('end_date');
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vacations', function (Blueprint $table) {
            $table->dropColumn(['days', 'status']);
            $table->unsignedTinyInteger('scheduled_days')->default(0)->after('year');
            $table->unsignedTinyInteger('pending_days')->default(30)->after('scheduled_days');
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });
    }
};
