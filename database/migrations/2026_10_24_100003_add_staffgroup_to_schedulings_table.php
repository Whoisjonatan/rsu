<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El grupo de personal del que salió la programación, si salió de uno.
     * Sirve para que, al revisar si la tripulación de un grupo está libre, no
     * cuenten como ocupación las programaciones que el propio grupo generó.
     */
    public function up(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->foreignId('staffgroup_id')->nullable()->after('zone_id')->constrained('staffgroups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staffgroup_id');
        });
    }
};
