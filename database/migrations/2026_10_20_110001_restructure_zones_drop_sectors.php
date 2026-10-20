<?php

use App\Models\Zone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W13: el sector ya no se usa, así que se va con su tabla. La zona gana un
     * estado y los residuos promedio. El área deja de escribirse a mano: se
     * calcula del perímetro (Zone::replaceCoords), por eso se recalcula aquí
     * para las zonas que ya tienen uno y se vacía en las que no.
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sector_id');
        });

        Schema::dropIfExists('sectors');

        Schema::table('zones', function (Blueprint $table) {
            $table->decimal('average_waste_kg', 10, 2)->nullable()->after('area')->comment('Residuos promedio en kg');
            $table->enum('status', ['activa', 'inactiva'])->default('activa')->after('description');
        });

        foreach (DB::table('zones')->pluck('id') as $zoneId) {
            $coords = DB::table('zonecoords')->where('zone_id', $zoneId)->orderBy('position')
                ->get(['latitude', 'longitude'])
                ->map(fn ($point) => (array) $point)
                ->all();

            DB::table('zones')->where('id', $zoneId)->update(['area' => Zone::areaFromCoords($coords)]);
        }
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn(['average_waste_kg', 'status']);
        });

        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('area', 10, 3)->nullable()->comment('Area en km2');
            $table->string('description', 255)->nullable();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->foreignId('sector_id')->nullable()->after('description')->constrained('sectors')->nullOnDelete();
        });
    }
};
