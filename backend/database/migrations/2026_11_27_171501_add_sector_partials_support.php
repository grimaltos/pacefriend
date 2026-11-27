<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->json('sectors')->nullable()->after('corners');
        });

        Schema::table('reference_laps', function (Blueprint $table) {
            $table->json('sector_splits_ms')->nullable()->after('corner_baselines');
        });

        Schema::table('driving_sessions', function (Blueprint $table) {
            $table->unsignedInteger('clean_sectors_count')->default(0)->after('clean_laps_count');
            $table->unsignedInteger('optimal_lap_time_ms')->nullable()->after('best_lap_time_ms');
            $table->json('best_sectors_ms')->nullable()->after('optimal_lap_time_ms');
        });

        Schema::table('laps', function (Blueprint $table) {
            $table->boolean('is_complete')->default(true)->after('is_clean');
            $table->json('sector_metrics')->nullable()->after('corner_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('sectors');
        });

        Schema::table('reference_laps', function (Blueprint $table) {
            $table->dropColumn('sector_splits_ms');
        });

        Schema::table('driving_sessions', function (Blueprint $table) {
            $table->dropColumn(['clean_sectors_count', 'optimal_lap_time_ms', 'best_sectors_ms']);
        });

        Schema::table('laps', function (Blueprint $table) {
            $table->dropColumn(['is_complete', 'sector_metrics']);
        });
    }
};
