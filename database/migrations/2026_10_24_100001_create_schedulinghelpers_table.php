<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una programación lleva tantos ayudantes como admita el vehículo
     * (capacidad de ocupantes menos el conductor), no uno solo: el ayudante
     * pasa de la columna schedulings.helper_id a su propia tabla. `position`
     * conserva el orden en que se eligieron.
     */
    public function up(): void
    {
        Schema::create('schedulinghelpers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduling_id')->constrained('schedulings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();

            $table->unique(['scheduling_id', 'user_id']);
        });

        $now = now();

        DB::table('schedulings')->whereNotNull('helper_id')->orderBy('id')->each(function ($row) use ($now) {
            DB::table('schedulinghelpers')->insert([
                'scheduling_id' => $row->id,
                'user_id' => $row->helper_id,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        Schema::table('schedulings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('helper_id');
        });
    }

    /** Al volver solo cabe un ayudante: se queda el primero. */
    public function down(): void
    {
        Schema::table('schedulings', function (Blueprint $table) {
            $table->foreignId('helper_id')->nullable()->after('driver_id')->constrained('users')->nullOnDelete();
        });

        DB::table('schedulinghelpers')->where('position', 1)->orderBy('id')->each(function ($row) {
            DB::table('schedulings')->where('id', $row->scheduling_id)->update(['helper_id' => $row->user_id]);
        });

        Schema::dropIfExists('schedulinghelpers');
    }
};
