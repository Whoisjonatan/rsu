<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Observaciones libres de la programación (W18). */
    public function up(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->string('notes', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
