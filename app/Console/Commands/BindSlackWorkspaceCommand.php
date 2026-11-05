<?php

namespace App\Console\Commands;

use App\Models\ChatIntegration;
use App\Services\Chat\SlackWorkspaceBinder;
use Illuminate\Console\Command;

/**
 * Binds every Slack identity that has no workspace to one Slack workspace.
 *
 * For deployments upgraded from a version that did not record the workspace of
 * a Slack identity and where it could not be worked out (for example webhook
 * mode, where the app is not installed). Until it runs, such identities match
 * nothing. The workspace is always given explicitly.
 */
class BindSlackWorkspaceCommand extends Command
{
    protected $signature = 'queryproxy:chat:bind-slack-workspace
        {team_id : The Slack workspace id, for example T0123ABC}
        {--force : Bind without asking for confirmation (required when not interactive)}';

    protected $description = 'Bind the Slack identities that have no workspace to one Slack workspace';

    public function handle(SlackWorkspaceBinder $binder): int
    {
        $workspaceId = SlackWorkspaceBinder::normalize((string) $this->argument('team_id'));

        if ($workspaceId === null) {
            $this->error('Not a Slack workspace id: expected T followed by letters and digits, for example T0123ABC.');

            return self::FAILURE;
        }

        $count = $binder->unboundCount();

        if ($count === 0) {
            $this->info('Every Slack identity already has a workspace. Nothing to do.');

            return self::SUCCESS;
        }

        // Before the question, and also with --force: the hint belongs in the log of a job too.
        $this->warnAboutUnknownWorkspace($workspaceId);

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Not interactive: pass --force to bind without a confirmation question.');

                return self::FAILURE;
            }

            $what = $count === 1 ? 'identity' : 'identities';
            $who = $count === 1
                ? 'That Slack member will be able to act as their QueryProxy user'
                : 'Those Slack members will be able to act as their QueryProxy users';

            if (! $this->confirm("Bind {$count} Slack {$what} without a workspace to {$workspaceId}? {$who} from that workspace.")) {
                $this->line('Nothing was changed.');

                return self::FAILURE;
            }
        }

        $bound = $binder->bind($workspaceId);

        $this->info("Bound {$bound} Slack ".($bound === 1 ? 'identity' : 'identities')." to {$workspaceId}.");

        return self::SUCCESS;
    }

    /**
     * A typo is valid in form. If the app is installed somewhere and the id is
     * none of those workspaces, say so before asking.
     */
    private function warnAboutUnknownWorkspace(string $workspaceId): void
    {
        $installed = ChatIntegration::query()
            ->where('provider', 'slack')
            ->whereNotNull('bot_token')
            ->whereNotNull('slack_team_id')
            ->distinct()
            ->pluck('slack_team_id');

        if ($installed->isNotEmpty() && ! $installed->contains($workspaceId)) {
            $this->warn("{$workspaceId} is not a workspace the app is installed in (installed: {$installed->implode(', ')}). Check it for a typo.");
        }
    }
}
