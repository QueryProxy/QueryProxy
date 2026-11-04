<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slack members found by e-mail address for QueryProxy users that have no
     * chat identity yet (ADR-027, decision D-18 b). A row is only a
     * suggestion: nothing is linked until an admin confirms it in the web UI.
     */
    public function up(): void
    {
        Schema::create('slack_identity_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slack_team_id', 32);
            $table->string('slack_user_id', 32);
            // The address the lookup used, kept so the admin sees why it matched.
            $table->string('matched_email');
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->unique(['user_id', 'slack_team_id']);
            $table->index('slack_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_identity_suggestions');
    }
};
