<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Binds a Slack identity to the Slack workspace it was linked in (plan
     * 2026-11-04-v0-4-2-acik-isler, Faz 03). `team_id` is the Slack workspace
     * id, not a QueryProxy team; it stays null for Teams identities. Only a
     * column and an index are added, so the migration needs no `->change()`.
     *
     * Existing Slack identities get the workspace the app is installed in for
     * the user's own teams, when that is exactly one. Where none can be named
     * (webhook mode, no team, several workspaces) the column stays null and the
     * identity matches nothing until an admin binds it: Teams & Users, or
     * `php artisan queryproxy:chat:bind-slack-workspace`.
     */
    public function up(): void
    {
        Schema::table('chat_identities', function (Blueprint $table) {
            $table->string('team_id', 32)->nullable()->index();
        });

        // `pretend` (SQL dump, the SQL Server statement test) has no data to read.
        if (DB::connection()->pretending()) {
            return;
        }

        $installations = DB::table('chat_integrations')
            ->where('provider', 'slack')
            ->whereNotNull('bot_token')
            ->whereNotNull('slack_team_id')
            ->get(['team_id', 'slack_team_id']);

        if ($installations->isEmpty()) {
            return;
        }

        // A user's identity belongs to the workspace their own teams are
        // installed in, provided that is exactly one. Another team's workspace
        // says nothing about them (a setup can mix workspaces and webhook mode).
        $workspacesByTeam = $installations->groupBy('team_id')->map(fn ($rows) => $rows->pluck('slack_team_id')->unique());

        DB::table('chat_identities')
            ->where('provider', 'slack')
            ->whereNull('team_id')
            ->eachById(function (object $identity) use ($workspacesByTeam) {
                $candidates = DB::table('team_user')
                    ->where('user_id', $identity->user_id)
                    ->pluck('team_id')
                    ->flatMap(fn ($teamId) => $workspacesByTeam->get($teamId, collect()))
                    ->unique();

                if ($candidates->count() === 1) {
                    DB::table('chat_identities')->where('id', $identity->id)->update(['team_id' => $candidates->first()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('chat_identities', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
        });

        Schema::table('chat_identities', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });
    }
};
