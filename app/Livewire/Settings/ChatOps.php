<?php

namespace App\Livewire\Settings;

use App\Enums\ChatProvider;
use App\Models\ChatIntegration;
use App\Models\Team;
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

    public function mount(): void
    {
        $integrations = ChatIntegration::where('team_id', $this->team()->id)->get();

        if ($slack = $integrations->firstWhere('provider', ChatProvider::Slack)) {
            $this->slackWebhookUrl = $slack->webhook_url;
            $this->slackEnabled = $slack->enabled;
        }

        if ($teams = $integrations->firstWhere('provider', ChatProvider::Teams)) {
            $this->teamsWebhookUrl = $teams->webhook_url;
            $this->teamsEnabled = $teams->enabled;
        }
    }

    public function saveSlack(): void
    {
        $this->save(ChatProvider::Slack, $this->slackWebhookUrl, $this->slackSigningSecret, $this->slackEnabled, 'slackWebhookUrl');
        $this->slackSigningSecret = '';
    }

    public function saveTeams(): void
    {
        $this->save(ChatProvider::Teams, $this->teamsWebhookUrl, $this->teamsSigningSecret, $this->teamsEnabled, 'teamsWebhookUrl');
        $this->teamsSigningSecret = '';
    }

    public function remove(string $provider): void
    {
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
        $this->resetErrorBag();

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            $this->addError($urlField, 'A valid https:// webhook URL is required.');

            return;
        }

        $team = $this->team();

        $existing = ChatIntegration::where('team_id', $team->id)->where('provider', $provider)->first();

        if (! $existing && $secret === '') {
            $this->addError($urlField, 'A signing secret is required when configuring the integration.');

            return;
        }

        $attributes = ['webhook_url' => $url, 'enabled' => $enabled];

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
