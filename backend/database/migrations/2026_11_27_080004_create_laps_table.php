<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driving_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reference_lap_id')->nullable()->constrained('reference_laps')->nullOnDelete();
            $table->string('car_name', 160);
            $table->unsignedInteger('lap_number');
            $table->unsignedInteger('lap_time_ms');
            $table->boolean('is_clean')->default(true);
            $table->unsignedSmallInteger('incident_delta')->default(0);
            $table->decimal('track_temp_c', 5, 2)->default(26.00);
            $table->decimal('air_temp_c', 5, 2)->default(20.00);
            $table->boolean('is_wet')->default(false);
            $table->unsignedSmallInteger('sample_rate_hz')->default(20);
            $table->jsonb('samples');
            $table->jsonb('corner_metrics')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'track_id', 'car_name', 'is_clean', 'id'], 'laps_sliding_window_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX laps_corner_metrics_gin_idx ON laps USING gin (corner_metrics)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laps');
    }
};
