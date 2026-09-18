<?php

namespace App\Livewire\Settings;

use App\Enums\ChatProvider;
use App\Models\ChatIntegration;
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
        }

        if ($teams = $integrations->firstWhere('provider', ChatProvider::Teams)) {
            $this->teamsEnabled = $teams->enabled;
        }
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

        $removed = ChatIntegration::where('team_id', $team->id)
            ->where('provider', $provider)
            ->delete();

        // Its own action, kept distinct from `chat_integration.saved`: removal
        // takes the signing secret with it and cannot be undone without an admin.
        audit()->record('chat_integration.removed', team: $team, metadata: [
            'provider' => $provider->value,
            'existed' => $removed > 0,
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

        // Rotation, not first setup: knowledge of the secret in place is the
        // only thing separating a rotation from a takeover of the endpoint.
        if ($secret !== '' && $existing && ! $existing->matchesSigningSecret($currentSecret)) {
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
                'initial_setup' => $existing === null,
            ]);
        }

        session()->flash('status', $provider->label().' integration saved.');
    }

    public function render()
    {
        $integrations = ChatIntegration::where('team_id', $this->team()->id)->get();
        $isAdmin = auth()->user()->isAdmin();

        return view('livewire.settings.chat-ops', [
            'hasSlack' => $integrations->firstWhere('provider', ChatProvider::Slack) !== null,
            'hasTeams' => $integrations->firstWhere('provider', ChatProvider::Teams) !== null,
            'canRotateSecret' => $isAdmin,
            'canRemove' => $isAdmin,
        ]);
    }
}
