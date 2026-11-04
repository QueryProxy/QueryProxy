<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Slack app (ADR-027) next to the incoming webhook.
     *
     * An installation made through OAuth has a bot token and no webhook URL,
     * and the request signature is checked with the app's signing secret from
     * the environment, so both columns of the webhook mode become optional.
     * Existing rows keep their values.
     *
     * query_requests remembers where the card of a request was posted, so a
     * later status change can update that very message.
     */
    public function up(): void
    {
        Schema::table('chat_integrations', function (Blueprint $table) {
            $table->text('webhook_url')->nullable()->change();
            $table->text('signing_secret')->nullable()->change();

            // Encrypted with the application key, like webhook_url.
            $table->text('bot_token')->nullable();
            $table->string('bot_user_id', 32)->nullable();
            // The Slack workspace the app is installed in. Not unique: one
            // workspace can serve several QueryProxy teams.
            $table->string('slack_team_id', 32)->nullable()->index();
            $table->string('slack_team_name')->nullable();
            // Where request cards go (chat.postMessage).
            $table->string('slack_channel_id', 32)->nullable();
            // Direct message to the team's approvers for a new request.
            $table->boolean('dm_approvers')->default(false);
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
        });

        Schema::table('query_requests', function (Blueprint $table) {
            $table->string('slack_channel', 32)->nullable();
            $table->string('slack_ts', 32)->nullable();
        });
    }

    /**
     * Rows that only exist because of the app (no webhook URL or signing
     * secret) cannot be represented in the old shape and are removed.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropColumn(['slack_channel', 'slack_ts']);
        });

        DB::table('chat_integrations')
            ->whereNull('webhook_url')
            ->orWhereNull('signing_secret')
            ->delete();

        Schema::table('chat_integrations', function (Blueprint $table) {
            $table->dropForeign(['installed_by']);
            $table->dropIndex(['slack_team_id']);
        });

        Schema::table('chat_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'bot_token', 'bot_user_id', 'slack_team_id', 'slack_team_name',
                'slack_channel_id', 'dm_approvers', 'installed_by', 'installed_at',
            ]);
        });

        Schema::table('chat_integrations', function (Blueprint $table) {
            $table->text('webhook_url')->nullable(false)->change();
            $table->text('signing_secret')->nullable(false)->change();
        });
    }
};
