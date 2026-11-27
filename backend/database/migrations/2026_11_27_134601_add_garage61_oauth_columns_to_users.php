<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('garage61_auth_type', 16)->nullable()->after('garage61_token');
            $table->text('garage61_refresh_token')->nullable()->after('garage61_auth_type');
            $table->timestamp('garage61_token_expires_at')->nullable()->after('garage61_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['garage61_auth_type', 'garage61_refresh_token', 'garage61_token_expires_at']);
        });
    }
};
