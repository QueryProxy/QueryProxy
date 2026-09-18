<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // TOTP counter (unix time / period) of the last accepted code, so
            // the same code cannot be presented twice inside its window.
            $table->unsignedBigInteger('two_factor_last_used_timestamp')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_used_timestamp');
        });
    }
};
