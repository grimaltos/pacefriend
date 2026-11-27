<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->boolean('corners_is_curated')->default(true)->after('corners');
        });

        Schema::create('car_physics_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('car_name', 160);
            $table->boolean('is_wet')->default(false);
            $table->smallInteger('temp_band')->default(25);
            $table->float('p90_lat_g')->default(1.45);
            $table->float('p90_brake_g')->default(1.50);
            $table->float('p90_combined_g')->default(1.75);
            $table->unsignedInteger('samples_count')->default(0);
            $table->timestamps();

            $table->unique(['car_name', 'is_wet', 'temp_band'], 'car_physics_unique_band');
        });

        Schema::create('coaching_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->string('car_name', 160);
            $table->unsignedSmallInteger('turn');
            $table->string('issue_type', 48)->index();
            $table->integer('time_delta_ms');
            $table->boolean('had_incident')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_outcomes');
        Schema::dropIfExists('car_physics_profiles');

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('corners_is_curated');
        });
    }
};
