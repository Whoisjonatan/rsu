<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El correlativo PRG-NNNN que W17 muestra como primera columna y con el que
 * W19 identifica cada fila al seleccionarla para la edicion masiva.
 *
 * Va nullable porque MySQL admite varios NULL bajo un indice unico: asi la
 * columna se puede anadir sobre datos existentes y rellenarlos despues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');
        });

        // Las programaciones que ya existieran se numeran por orden de alta.
        $numero = 0;

        foreach (DB::table('schedulings')->orderBy('id')->pluck('id') as $id) {
            DB::table('schedulings')
                ->where('id', $id)
                ->update(['code' => sprintf('PRG-%04d', ++$numero)]);
        }
    }

    public function down(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->dropUnique(['schedulings_code_unique']);
            $table->dropColumn('code');
        });
    }
};
