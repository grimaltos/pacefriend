<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('garage61_token')->nullable()->after('preferences');
            $table->boolean('autopilot_enabled')->default(true)->after('garage61_token');
            $table->string('autopilot_mode', 24)->default('progressive')->after('autopilot_enabled');
            $table->unsignedInteger('autopilot_delta_ms')->default(1000)->after('autopilot_mode');
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->unsignedInteger('garage61_track_id')->nullable()->index()->after('slug');
        });

        Schema::table('reference_laps', function (Blueprint $table) {
            $table->string('external_id', 96)->nullable()->index()->after('source');
            $table->string('driver_name', 140)->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('reference_laps', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'driver_name']);
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('garage61_track_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['garage61_token', 'autopilot_enabled', 'autopilot_mode', 'autopilot_delta_ms']);
        });
    }
};
