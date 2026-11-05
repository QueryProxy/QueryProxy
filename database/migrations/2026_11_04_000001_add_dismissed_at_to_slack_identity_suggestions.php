<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin who rejects a Slack identity suggestion no longer deletes the
     * row: it is kept with the time of the rejection, so the weekly scan does
     * not propose the same match again (ADR-027; plan
     * 2026-11-04-v0-4-2-acik-isler, Faz 01).
     */
    public function up(): void
    {
        Schema::table('slack_identity_suggestions', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('slack_identity_suggestions', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
