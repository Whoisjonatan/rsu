<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciudadanos de la app móvil: un tipo de usuario propio (kind "ciudadano"),
 * que no es personal operativo ni usuario del panel. Se registran solos desde
 * la app con su zona, ven las programaciones de esa zona y califican el
 * servicio de cada recorrido finalizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('usertypes')->where('slug', 'ciudadano')->exists()) {
            DB::table('usertypes')->insert([
                'name' => 'Ciudadano',
                'slug' => 'ciudadano',
                'kind' => 'ciudadano',
                'description' => 'Ciudadano registrado desde la app: ve las programaciones de su zona y califica el servicio.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('route_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicleroute_id')->constrained('vehicleroutes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('stars')->comment('1 a 5');
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            // Una calificación por ciudadano y recorrido (se puede editar).
            $table->unique(['vehicleroute_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_ratings');
        DB::table('users')->whereIn('usertype_id', DB::table('usertypes')->where('slug', 'ciudadano')->select('id'))->update(['usertype_id' => null]);
        DB::table('usertypes')->where('slug', 'ciudadano')->delete();
    }
};
