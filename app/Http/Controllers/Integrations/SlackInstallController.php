<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\AuditEvent;
use App\Enums\ChatProvider;
use App\Http\Controllers\Controller;
use App\Jobs\SuggestSlackIdentities;
use App\Models\ChatIntegration;
use App\Models\Team;
use App\Models\User;
use App\Services\Chat\SlackApiException;
use App\Services\Chat\SlackClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Installs the QueryProxy Slack app for a team through OAuth v2 (ADR-027).
 *
 * The callback is a browser redirect from Slack, not a signed request. What
 * protects it is the `state` value: random, kept in the installing user's
 * session together with the user and the team, valid for ten minutes and
 * removed from the session the moment the callback reads it, whatever the
 * outcome. A callback without the matching state is refused before anything
 * is exchanged with Slack.
 */
class SlackInstallController extends Controller
{
    public const SESSION_KEY = 'slack_oauth';

    public const STATE_LIFETIME_MINUTES = 10;

    public function install(Request $request): RedirectResponse
    {
        abort_unless($this->isConfigured(), 404);

        $team = $this->teamToInstallFor($request->user());

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'user_id' => $request->user()->id,
            'team_id' => $team->id,
            'expires_at' => now()->addMinutes(self::STATE_LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return redirect()->away(config('queryproxy.slack_authorize_url').'?'.http_build_query([
            'client_id' => config('services.slack.app.client_id'),
            'scope' => implode(',', config('queryproxy.slack_oauth_scopes')),
            'redirect_uri' => route('integrations.slack.callback'),
            'state' => $state,
        ]));
    }

    public function callback(Request $request, SlackClient $slack): RedirectResponse
    {
        abort_unless($this->isConfigured(), 404);

        // Single use: gone from the session whether the check below passes or not.
        $stored = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        abort_unless(
            is_array($stored)
            && is_string($state) && $state !== ''
            && hash_equals((string) ($stored['state'] ?? ''), $state)
            && (int) ($stored['expires_at'] ?? 0) >= now()->getTimestamp()
            && (int) ($stored['user_id'] ?? 0) === $request->user()->id,
            403,
            'The Slack install request is invalid or has expired. Start the install again.',
        );

        $team = Team::query()->findOrFail((int) $stored['team_id']);
        $user = $request->user();

        // The role is checked again: it may have changed during the round trip.
        abort_unless($user->isAdmin() || $user->isDbaIn($team), 403);

        if ($request->query('error') !== null) {
            return $this->back('The Slack install was cancelled.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back('Slack did not return an authorization code.');
        }

        try {
            $grant = $slack->exchangeOAuthCode($code, route('integrations.slack.callback'));
        } catch (SlackApiException $e) {
            return $this->back('Slack refused the install ('.$e->slackError.').');
        }

        $botToken = $grant['access_token'] ?? null;
        $workspaceId = $grant['team']['id'] ?? null;

        if (! is_string($botToken) || $botToken === '' || ! is_string($workspaceId) || $workspaceId === '') {
            return $this->back('Slack did not return a bot token for this workspace.');
        }

        $integration = ChatIntegration::query()->firstOrNew([
            'team_id' => $team->id,
            'provider' => ChatProvider::Slack,
        ]);

        $reinstall = $integration->exists && filled($integration->bot_token);
        $channelId = $grant['incoming_webhook']['channel_id'] ?? null;

        // A webhook URL and signing secret already in place stay: the webhook
        // mode is the fallback when the bot cannot post.
        $integration->fill([
            'enabled' => true,
            'bot_token' => $botToken,
            'bot_user_id' => is_string($grant['bot_user_id'] ?? null) ? $grant['bot_user_id'] : null,
            'slack_team_id' => $workspaceId,
            'slack_team_name' => is_string($grant['team']['name'] ?? null) ? $grant['team']['name'] : null,
            'slack_channel_id' => is_string($channelId) && $channelId !== '' ? $channelId : $integration->slack_channel_id,
            'installed_by' => $user->id,
            'installed_at' => now(),
        ])->save();

        // Never the token: the scopes and where the app was installed.
        audit()->record(AuditEvent::SlackInstalled, team: $team, actor: $user, metadata: [
            'slack_team_id' => $workspaceId,
            'slack_team_name' => $integration->slack_team_name,
            'channel_id' => $integration->slack_channel_id,
            'scopes' => array_values(array_filter(explode(',', (string) ($grant['scope'] ?? '')))),
            'reinstall' => $reinstall,
        ]);

        SuggestSlackIdentities::dispatch($integration->id);

        return redirect()->route('settings.chatops')->with('status', 'Slack app installed for workspace '.($integration->slack_team_name ?? $workspaceId).'.');
    }

    private function isConfigured(): bool
    {
        return filled(config('services.slack.app.client_id')) && filled(config('services.slack.app.client_secret'));
    }

    private function teamToInstallFor(User $user): Team
    {
        $team = $user->currentTeam() ?? abort(403);

        abort_unless($user->isAdmin() || $user->isDbaIn($team), 403);

        return $team;
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('settings.chatops')->with('slack_install_error', $message);
    }
}
