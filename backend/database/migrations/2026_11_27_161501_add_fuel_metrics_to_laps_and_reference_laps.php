<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_laps', function (Blueprint $table) {
            $table->float('fuel_level_l')->nullable()->after('is_wet');
            $table->float('fuel_used_l')->nullable()->after('fuel_level_l');
        });

        Schema::table('laps', function (Blueprint $table) {
            $table->float('fuel_level_l')->nullable()->after('is_wet');
            $table->float('fuel_used_l')->nullable()->after('fuel_level_l');
        });
    }

    public function down(): void
    {
        Schema::table('reference_laps', function (Blueprint $table) {
            $table->dropColumn(['fuel_level_l', 'fuel_used_l']);
        });

        Schema::table('laps', function (Blueprint $table) {
            $table->dropColumn(['fuel_level_l', 'fuel_used_l']);
        });
    }
};
