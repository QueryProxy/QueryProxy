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

    public bool $slackEnabled = true;

    public string $teamsWebhookUrl = '';

    public string $teamsSigningSecret = '';

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
        $this->save(ChatProvider::Slack, $this->slackWebhookUrl, $this->slackSigningSecret, $this->slackEnabled, 'slackWebhookUrl');
        $this->slackSigningSecret = '';
        $this->slackWebhookUrl = '';
    }

    public function saveTeams(): void
    {
        $this->save(ChatProvider::Teams, $this->teamsWebhookUrl, $this->teamsSigningSecret, $this->teamsEnabled, 'teamsWebhookUrl');
        $this->teamsSigningSecret = '';
        $this->teamsWebhookUrl = '';
    }

    public function remove(string $provider): void
    {
        $this->assertDba($this->team());

        $provider = ChatProvider::from($provider);

        ChatIntegration::where('team_id', $this->team()->id)
            ->where('provider', $provider)
            ->delete();

        audit()->record('chat_integration.removed', team: $this->team(), metadata: ['provider' => $provider->value]);

        $this->reset(
            $provider === ChatProvider::Slack ? 'slackWebhookUrl' : 'teamsWebhookUrl',
            $provider === ChatProvider::Slack ? 'slackSigningSecret' : 'teamsSigningSecret',
        );

        session()->flash('status', $provider->label().' integration removed.');
    }

    private function save(ChatProvider $provider, string $url, string $secret, bool $enabled, string $urlField): void
    {
        $team = $this->team();
        $this->assertDba($team);

        $this->resetErrorBag();

        $existing = ChatIntegration::where('team_id', $team->id)->where('provider', $provider)->first();

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

        session()->flash('status', $provider->label().' integration saved.');
    }

    public function render()
    {
        $integrations = ChatIntegration::where('team_id', $this->team()->id)->get();

        return view('livewire.settings.chat-ops', [
            'hasSlack' => $integrations->firstWhere('provider', ChatProvider::Slack) !== null,
            'hasTeams' => $integrations->firstWhere('provider', ChatProvider::Teams) !== null,
        ]);
    }
}
