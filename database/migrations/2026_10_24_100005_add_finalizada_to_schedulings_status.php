<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una programación termina: sola al pasar su fecha de término o a mano
     * con "Finalizar". Las que ya terminaron se cierran aquí mismo.
     */
    public function up(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->enum('status', ['activa', 'inactiva', 'finalizada'])->default('activa')->change();
        });

        DB::table('schedulings')
            ->where('status', 'activa')
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => 'finalizada']);
    }

    public function down(): void
    {
        DB::table('schedulings')->where('status', 'finalizada')->update(['status' => 'inactiva']);

        Schema::table('schedulings', function (Blueprint $table) {
            $table->enum('status', ['activa', 'inactiva'])->default('activa')->change();
        });
    }
};
