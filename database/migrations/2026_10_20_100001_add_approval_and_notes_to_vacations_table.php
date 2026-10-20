<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las vacaciones ahora se solicitan y luego se evalúan: entran como
     * "pendientes" y se aprueban (pasan a "programadas") o se rechazan. Los
     * períodos ya cargados eran programaciones firmes y se quedan como están.
     */
    public function up(): void
    {
        Schema::table('vacations', function (Blueprint $table) {
            $table->enum('status', ['pendientes', 'programadas', 'gozadas', 'rechazadas', 'canceladas'])
                ->default('pendientes')
                ->change();
            $table->string('notes', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        DB::table('vacations')->where('status', 'pendientes')->update(['status' => 'programadas']);
        DB::table('vacations')->where('status', 'rechazadas')->update(['status' => 'canceladas']);

        Schema::table('vacations', function (Blueprint $table) {
            $table->dropColumn('notes');
            $table->enum('status', ['programadas', 'gozadas', 'canceladas'])
                ->default('programadas')
                ->change();
        });
    }
};
