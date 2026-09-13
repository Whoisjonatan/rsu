<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W09 lista los contratos por "N.° CONTRATO" y su alta pide el regimen.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->unique()->after('id');
            $table->string('regime', 60)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'regime']);
        });
    }
};
