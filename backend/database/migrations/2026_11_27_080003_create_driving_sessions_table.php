<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driving_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_reference_lap_id')->nullable()->constrained('reference_laps')->nullOnDelete();
            $table->string('car_name', 160);
            $table->unsignedBigInteger('iracing_session_id')->nullable();
            $table->unsignedBigInteger('iracing_subsession_id')->nullable();
            $table->decimal('avg_track_temp_c', 5, 2)->default(26.00);
            $table->decimal('avg_air_temp_c', 5, 2)->default(20.00);
            $table->boolean('is_wet')->default(false);
            $table->unsignedInteger('laps_count')->default(0);
            $table->unsignedInteger('clean_laps_count')->default(0);
            $table->unsignedInteger('best_lap_time_ms')->nullable();
            $table->jsonb('active_coaching_plan')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_lap_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['user_id', 'iracing_session_id', 'iracing_subsession_id'], 'sessions_iracing_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driving_sessions');
    }
};
