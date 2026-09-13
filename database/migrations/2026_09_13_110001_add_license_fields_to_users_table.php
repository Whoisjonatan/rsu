<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W08 pide categoria de licencia y avisa "licencia no vigente": el informe
     * exige validar la licencia, y solo se puede si se guarda su vencimiento.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('license_category', 10)->nullable()->after('license');
            $table->date('license_expiration')->nullable()->after('license_category');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['license_category', 'license_expiration']);
        });
    }
};
