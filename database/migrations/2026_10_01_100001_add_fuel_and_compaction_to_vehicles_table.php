<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La capacidad de carga pasa a medirse en toneladas (antes m3) y la ficha
     * suma el tanque de combustible y la capacidad de compactacion. Esta
     * ultima solo aplica a los compactadores, por eso admite null.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('load_capacity', 8, 2)->default(0)->comment('Capacidad de carga en Tn')->change();
            $table->decimal('fuel_capacity', 8, 2)->nullable()->after('load_capacity')->comment('Capacidad de combustible en L');
            $table->decimal('compaction_capacity', 8, 2)->nullable()->after('fuel_capacity')->comment('Capacidad de compactacion en Tn');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['fuel_capacity', 'compaction_capacity']);
            $table->decimal('load_capacity', 8, 2)->default(0)->comment('Capacidad de carga en m3')->change();
        });
    }
};
