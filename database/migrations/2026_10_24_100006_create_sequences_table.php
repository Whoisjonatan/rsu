<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El último número entregado de cada correlativo (ver App\Support\Sequence).
     * Sacarlo del mayor código existente devolvía el número de una programación
     * recién eliminada; guardado aquí, un número no se vuelve a entregar.
     */
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        // Arranca donde va hoy la numeración PRG-NNNN.
        $last = DB::table('schedulings')
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 4))
            ->max() ?? 0;

        DB::table('sequences')->insert(['name' => 'schedulings', 'value' => $last, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
