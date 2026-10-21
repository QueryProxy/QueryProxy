<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            // Set when the one-time "still pending" reminder went out (D-01: a
            // request is reminded about, never expired).
            $table->timestamp('reminded_at')->nullable()->after('review_channel');

            // Set when a fresh chat card replaced one whose buttons had expired.
            $table->timestamp('chat_renewed_at')->nullable()->after('reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropColumn(['reminded_at', 'chat_renewed_at']);
        });
    }
};
