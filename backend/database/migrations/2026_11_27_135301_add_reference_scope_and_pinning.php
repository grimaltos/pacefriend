<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('reference_scope', 24)->default('team_then_public')->after('autopilot_enabled');
        });

        // Migrate any legacy 'team' mode into the separated scope + strategy fields
        DB::table('users')
            ->where('autopilot_mode', 'team')
            ->update([
                'reference_scope' => 'team_only',
                'autopilot_mode' => 'fastest',
            ]);

        DB::table('users')
            ->where('autopilot_mode', 'alien')
            ->update([
                'autopilot_mode' => 'fastest',
            ]);

        Schema::table('reference_laps', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->index()->after('is_wet');
        });
    }

    public function down(): void
    {
        Schema::table('reference_laps', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reference_scope');
        });
    }
};
