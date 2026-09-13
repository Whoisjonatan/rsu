<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('lastname', 120)->nullable()->after('name');
            $table->string('dni', 8)->nullable()->unique()->after('lastname');
            $table->date('birthdate')->nullable()->after('dni');
            $table->string('license', 20)->nullable()->after('birthdate');
            $table->string('phone', 20)->nullable()->after('license');
            $table->string('address', 255)->nullable()->after('phone');
            $table->string('profile_photo_path', 255)->nullable()->after('address');
            $table->enum('status', ['activo', 'inactivo'])->default('activo')->after('profile_photo_path');
            $table->foreignId('usertype_id')->nullable()->after('status')->constrained('usertypes')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->after('usertype_id')->constrained('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropConstrainedForeignId('usertype_id');
            $table->dropColumn([
                'lastname', 'dni', 'birthdate', 'license', 'phone',
                'address', 'profile_photo_path', 'status',
            ]);
        });
    }
};
