<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada tipo dice a qué grupo pertenece: "sistema" son los tipos de
     * usuario que entran al panel (Gestión de usuarios › Tipos de usuario) y
     * "personal" las funciones del personal operativo (Personal › Funciones).
     */
    public function up(): void
    {
        Schema::table('usertypes', function (Blueprint $table) {
            $table->string('kind', 20)->default('personal')->after('slug');
        });

        DB::table('usertypes')->whereIn('slug', ['administrador', 'supervisor'])->update(['kind' => 'sistema']);
    }

    public function down(): void
    {
        Schema::table('usertypes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
