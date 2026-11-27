<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driving_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reference_lap_id')->nullable()->constrained('reference_laps')->nullOnDelete();
            $table->string('status', 24)->default('ready');
            $table->string('llm_driver', 32)->default('local');
            $table->jsonb('quantitative_summary');
            $table->jsonb('priority_areas');
            $table->longText('narrative_markdown');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_reports');
    }
};
