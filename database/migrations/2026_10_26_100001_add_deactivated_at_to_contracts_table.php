<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desactivar un contrato lo corta desde ese día sin tocar sus fechas
     * pactadas, así la ficha sigue diciendo hasta cuándo iba y se puede
     * reactivar.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('deactivated_at')->nullable()->after('end_date')
                ->comment('Desde este día el contrato deja de cubrir; nulo si está activo');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
