<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_clips', function (Blueprint $table) {
            $table->id();
            $table->char('hash', 64)->unique();
            $table->string('provider', 32);
            $table->string('voice_id', 96);
            $table->text('text');
            $table->string('disk_path', 255);
            $table->string('mime_type', 48)->default('audio/wav');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_clips');
    }
};
