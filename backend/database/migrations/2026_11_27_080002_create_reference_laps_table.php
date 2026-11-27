<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_laps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->string('car_name', 160);
            $table->string('label', 180)->nullable();
            $table->unsignedInteger('lap_time_ms');
            $table->decimal('track_temp_c', 5, 2)->default(26.00);
            $table->decimal('air_temp_c', 5, 2)->default(20.00);
            $table->boolean('is_wet')->default(false);
            $table->string('track_usage', 64)->nullable();
            $table->string('source', 32)->default('json_upload');
            $table->jsonb('normalized_points');
            $table->jsonb('corner_baselines');
            $table->timestamps();

            $table->index(['track_id', 'car_name', 'is_wet', 'track_temp_c'], 'ref_laps_weather_lookup_idx');
            $table->index(['user_id', 'track_id', 'car_name'], 'ref_laps_user_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_laps');
    }
};
