<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->decimal('length_km', 6, 3)->default(5.000);
            $table->jsonb('corners');
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX tracks_corners_gin_idx ON tracks USING gin (corners)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
