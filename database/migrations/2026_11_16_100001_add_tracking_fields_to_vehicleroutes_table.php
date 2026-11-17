<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento del recorrido desde la app del conductor: el momento exacto de
 * inicio y fin (time_route solo guarda la hora) y la observación con la que el
 * conductor cierra el recorrido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicleroutes', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable()->after('time_route');
            $table->dateTime('finished_at')->nullable()->after('started_at');
            $table->string('observation', 500)->nullable()->after('block_reason');
        });

        // Los ya iniciados desde el panel (W21) recuperan su inicio a partir de date_route + time_route.
        DB::table('vehicleroutes')
            ->whereNotNull('time_route')
            ->whereIn('status', ['iniciado', 'finalizado'])
            ->update(['started_at' => DB::raw('TIMESTAMP(date_route, time_route)')]);
    }

    public function down(): void
    {
        Schema::table('vehicleroutes', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'finished_at', 'observation']);
        });
    }
};
