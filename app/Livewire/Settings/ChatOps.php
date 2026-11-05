<?php

namespace App\Livewire\Settings;

use App\Enums\AuditEvent;
use App\Enums\ChatProvider;
use App\Models\ChatIntegration;
use App\Models\SlackIdentitySuggestion;
use App\Models\Team;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('ChatOps Settings')]
class ChatOps extends Component
{
    public string $slackWebhookUrl = '';

    public string $slackSigningSecret = '';

    public string $slackCurrentSigningSecret = '';

    public bool $slackEnabled = true;

    /** Direct messages to the team's DBAs for new requests (Slack app only). */
    public bool $slackDmApprovers = false;

    public string $teamsWebhookUrl = '';

    public string $teamsSigningSecret = '';

    public string $teamsCurrentSigningSecret = '';

    public bool $teamsEnabled = true;

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    /**
     * Route middleware only guards the initial page load; every action must
     * re-check because Livewire updates arrive on a separate endpoint and the
     * actor's role may have been changed since the page was served.
     */
    private function assertDba(Team $team): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->isDbaIn($team), 403);
    }

    public function mount(): void
    {
        // The webhook URL is a bearer credential — it is never echoed back to
        // the browser. A blank field on save means "keep the stored one".
        $integrations = ChatIntegration::where('team_id', $this->team()->id)->get();

        if ($slack = $integrations->firstWhere('provider', ChatProvider::Slack)) {
            $this->slackEnabled = $slack->enabled;
            $this->slackDmApprovers = (bool) $slack->dm_approvers;
        }

        if ($teams = $integrations->firstWhere('provider', ChatProvider::Teams)) {
            $this->teamsEnabled = $teams->enabled;
        }
    }

    /**
     * The DM switch saves itself: it is the only control of the Slack app
     * section, so a separate Save button would only get in the way.
     */
    public function updatedSlackDmApprovers(bool $value): void
    {
        $team = $this->team();
        $this->assertDba($team);

        $integration = $this->installedSlack($team);

        if (! $integration) {
            $this->slackDmApprovers = false;
            $this->addError('slackApp', 'The Slack app is not installed for this team.');

            return;
        }

        // The DMs go out through the same bot path as the channel card, which
        // needs a channel; switching them on without one would promise
        // messages that never leave.
        if ($value && ! $integration->usesSlackBot()) {
            $this->slackDmApprovers = false;
            $this->addError('slackApp', 'Direct messages need a channel: add the app to Slack again and pick one.');

            return;
        }

        $this->resetErrorBag();

        $integration->update(['dm_approvers' => $value]);

        session()->flash('status', $value
            ? 'Direct messages to approvers switched on.'
            : 'Direct messages to approvers switched off.');

        audit()->record('chat_integration.saved', team: $team, metadata: [
            'provider' => ChatProvider::Slack->value,
            'enabled' => $integration->enabled,
            'dm_approvers' => $value,
        ]);
    }

    /**
     * Forget the Slack app installation of this team: the bot token is deleted
     * and the pending identity suggestions of that workspace go with it. Unlike
     * removing the whole integration this is open to the team's DBAs, because
     * installing again is one OAuth round trip and needs no secret. The app
     * itself stays in the Slack workspace until someone removes it there.
     */
    public function uninstallSlack(): void
    {
        $team = $this->team();
        $this->assertDba($team);

        $this->resetErrorBag();

        $integration = $this->installedSlack($team);

        if (! $integration) {
            $this->addError('slackApp', 'The Slack app is not installed for this team.');

            return;
        }

        $this->forgetSlackApp($integration, $team);

        $this->slackDmApprovers = false;

        session()->flash('status', 'Slack app removed. Remove it from the workspace in Slack as well to revoke its access there.');
    }

    /** The team's Slack row, if the app is installed on it. */
    private function installedSlack(Team $team): ?ChatIntegration
    {
        $integration = ChatIntegration::where('team_id', $team->id)
            ->where('provider', ChatProvider::Slack)
            ->first();

        return $integration && filled($integration->bot_token) ? $integration : null;
    }

    /**
     * Drop the bot token and what hangs off it. A row that has no webhook mode
     * behind it would be an empty shell, so it is deleted; one with a webhook
     * keeps working in webhook mode.
     */
    private function forgetSlackApp(ChatIntegration $integration, Team $team): void
    {
        $workspaceId = $integration->slack_team_id;

        $integration->clearSlackInstallation();

        // Suggestions came from the workspace that was just disconnected. The
        // ones an admin dismissed stay, so installing again does not bring
        // back a match that was already rejected.
        SlackIdentitySuggestion::pending()->where('team_id', $team->id)
            ->when($workspaceId, fn ($query) => $query->where('slack_team_id', $workspaceId))
            ->delete();

        if (blank($integration->webhook_url) && blank($integration->signing_secret)) {
            $integration->delete();
        } else {
            $integration->update(['dm_approvers' => false]);
        }

        audit()->record(AuditEvent::SlackUninstalled, team: $team, metadata: [
            'slack_team_id' => $workspaceId,
            'reason' => 'removed_by_user',
        ]);
    }

    public function saveSlack(): void
    {
        $this->save(ChatProvider::Slack, $this->slackWebhookUrl, $this->slackSigningSecret, $this->slackCurrentSigningSecret, $this->slackEnabled);
        $this->slackSigningSecret = '';
        $this->slackCurrentSigningSecret = '';
        $this->slackWebhookUrl = '';
    }

    public function saveTeams(): void
    {
        $this->save(ChatProvider::Teams, $this->teamsWebhookUrl, $this->teamsSigningSecret, $this->teamsCurrentSigningSecret, $this->teamsEnabled);
        $this->teamsSigningSecret = '';
        $this->teamsCurrentSigningSecret = '';
        $this->teamsWebhookUrl = '';
    }

    /**
     * Removal is held to the same bar as writing the signing secret. Setting a
     * secret is admin-only, and configuring an integration from scratch requires
     * one — so a DBA who deleted an integration could not put it back, and the
     * delete button was a one-way door out of ChatOps for the whole team. The
     * reversible day-to-day control is the `enabled` flag, which stays with the
     * team's DBAs; only a system admin may tear the integration down.
     */
    public function remove(string $provider): void
    {
        $team = $this->team();
        $this->assertDba($team);

        $this->resetErrorBag();

        if (! auth()->user()->isAdmin()) {
            $this->addError('remove', 'Only a system admin may remove a ChatOps integration, because setting the signing secret needed to configure it again is admin-only. Untick "Enabled" and save to disable it instead, or ask an admin to remove it.');

            return;
        }

        $provider = ChatProvider::from($provider);

        // The whole integration goes, bot token included: the app uninstall
        // is recorded as such, before the row is gone.
        $wasInstalled = false;

        if ($provider === ChatProvider::Slack && ($installed = $this->installedSlack($team))) {
            $this->forgetSlackApp($installed, $team);
            $this->slackDmApprovers = false;
            $wasInstalled = true;
        }

        $removed = ChatIntegration::where('team_id', $team->id)
            ->where('provider', $provider)
            ->delete();

        // Its own action, kept distinct from `chat_integration.saved`: removal
        // takes the signing secret with it and cannot be undone without an admin.
        audit()->record('chat_integration.removed', team: $team, metadata: [
            'provider' => $provider->value,
            'existed' => $removed > 0 || $wasInstalled,
        ]);

        $prefix = $this->fieldPrefix($provider);

        $this->reset($prefix.'WebhookUrl', $prefix.'SigningSecret', $prefix.'CurrentSigningSecret');

        session()->flash('status', $provider->label().' integration removed.');
    }

    /** The `slack` / `teams` prefix shared by this provider's public properties. */
    private function fieldPrefix(ChatProvider $provider): string
    {
        return $provider === ChatProvider::Slack ? 'slack' : 'teams';
    }

    /**
     * The signing secret is what tells the two webhook endpoints that a
     * callback really came from the chat platform. Anyone who can *choose* it
     * can sign their own callbacks, so it is held to a different standard than
     * the rest of the form: only a system admin may write it, and replacing an
     * existing one means proving you already hold it. Webhook URL and the
     * enabled flag stay with the team's DBAs, who own day-to-day ChatOps.
     */
    private function save(ChatProvider $provider, string $url, string $secret, string $currentSecret, bool $enabled): void
    {
        $team = $this->team();
        $this->assertDba($team);

        $user = auth()->user();

        $prefix = $this->fieldPrefix($provider);
        $urlField = $prefix.'WebhookUrl';
        $secretField = $prefix.'SigningSecret';
        $currentSecretField = $prefix.'CurrentSigningSecret';

        $this->resetErrorBag();

        $existing = ChatIntegration::where('team_id', $team->id)->where('provider', $provider)->first();

        // Refuse loudly rather than dropping the field silently: a DBA who
        // thinks they rotated the secret must not walk away believing it.
        if ($secret !== '' && ! $user->isAdmin()) {
            $this->addError($secretField, 'Only a system admin may set or rotate the signing secret.');

            return;
        }

        if ($url === '' && ! $existing) {
            $this->addError($urlField, 'A valid https:// webhook URL is required.');

            return;
        }

        if ($url !== '') {
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
                $this->addError($urlField, 'A valid https:// webhook URL is required.');

                return;
            }

            // SSRF guard: outbound notifications only go to known chat hosts.
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $allowed = collect(config('queryproxy.chat_webhook_allowed_hosts', []))
                ->contains(fn (string $pattern) => Str::is(strtolower($pattern), $host));

            if (! $allowed) {
                $this->addError($urlField, "Webhook host \"{$host}\" is not allowed. Permitted hosts: "
                    .implode(', ', config('queryproxy.chat_webhook_allowed_hosts', []))
                    .' (extend via QUERYPROXY_CHAT_WEBHOOK_ALLOWED_HOSTS).');

                return;
            }
        }

        if (! $existing && $secret === '') {
            $this->addError($urlField, 'A signing secret is required when configuring the integration.');

            return;
        }

        // An app-only Slack row has no secret of its own. A webhook without one
        // would be stored but could never verify a callback, so refuse it
        // rather than let it look configured.
        $hadSecret = filled($existing?->signing_secret);

        if ($url !== '' && $secret === '' && ! $hadSecret) {
            $this->addError(
                $user->isAdmin() ? $secretField : $urlField,
                $user->isAdmin()
                    ? 'A webhook needs a signing secret; enter one below.'
                    : 'This integration has no signing secret yet, and only a system admin can set one. Ask an admin to add the webhook.',
            );

            return;
        }

        // Rotation, not first setup: knowledge of the secret in place is the
        // only thing separating a rotation from a takeover of the endpoint.
        // With no secret in place there is nothing to prove.
        if ($secret !== '' && $hadSecret && ! $existing->matchesSigningSecret($currentSecret)) {
            $this->addError($currentSecretField, 'The current signing secret does not match.');

            return;
        }

        $attributes = ['enabled' => $enabled];

        if ($url !== '') {
            $attributes['webhook_url'] = $url;
        }

        if ($secret !== '') {
            $attributes['signing_secret'] = $secret;
        }

        ChatIntegration::updateOrCreate(
            ['team_id' => $team->id, 'provider' => $provider],
            $attributes,
        );

        audit()->record('chat_integration.saved', team: $team, metadata: [
            'provider' => $provider->value, 'enabled' => $enabled,
        ]);

        if ($secret !== '') {
            // Its own action, because "the key to the webhook endpoint changed"
            // is a different event to "the integration was edited" — and the
            // secret itself never goes anywhere near the metadata.
            audit()->record('chat_integration.secret_rotated', team: $team, metadata: [
                'provider' => $provider->value,
                'initial_setup' => ! $hadSecret,
            ]);
        }

        session()->flash('status', $provider->label().' integration saved.');
    }

    public function render()
    {
        $integrations = ChatIntegration::where('team_id', $this->team()->id)->get();
        $isAdmin = auth()->user()->isAdmin();
        $slack = $integrations->firstWhere('provider', ChatProvider::Slack);

        return view('livewire.settings.chat-ops', [
            'hasSlack' => $slack !== null,
            'hasSlackWebhook' => filled($slack?->webhook_url),
            'hasSlackSecret' => filled($slack?->signing_secret),
            'slackApp' => $slack && filled($slack->bot_token) ? $slack->load('installer') : null,
            'slackAppAvailable' => filled(config('services.slack.app.client_id')) && filled(config('services.slack.app.client_secret')),
            'hasTeams' => $integrations->firstWhere('provider', ChatProvider::Teams) !== null,
            'canRotateSecret' => $isAdmin,
            'canRemove' => $isAdmin,
        ]);
    }
}
