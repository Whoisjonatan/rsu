<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W09 pide el salario y, de forma opcional, el período de prueba. El
     * salario queda nulo en la base para los contratos que ya existían; el
     * formulario lo exige en adelante.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('salary', 10, 2)->nullable()->after('end_date');
            $table->unsignedTinyInteger('probation_months')->nullable()->after('salary')
                ->comment('Período de prueba en meses; nulo si no tiene');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['salary', 'probation_months']);
        });
    }
};
