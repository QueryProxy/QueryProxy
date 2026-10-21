<?php

use App\Livewire\Settings\ChatOps;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Facades\Http;

const TEAMS_WORKFLOWS_URL = 'https://prod-12.westeurope.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke?sig=secret';
const TEAMS_POWERPLATFORM_URL = 'https://default1234.ab.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/abc/triggers/manual/paths/invoke?sig=secret';
const TEAMS_LEGACY_URL = 'https://contoso.webhook.office.com/webhookb2/abc/IncomingWebhook/def/ghi';

/** A team with a single Teams integration at $url and one pending request. */
function teamsCardSetup(string $url): QueryRequest
{
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);

    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'teams',
        'webhook_url' => $url,
        'signing_secret' => 'teams-secret',
        'enabled' => true,
    ]);

    return QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
        'sql_prepared' => "SELECT * FROM users WHERE email = 'ada@example.com'",
    ]);
}

/** @return array<string, mixed> the JSON body of the only request sent. */
function teamsSentPayload(): array
{
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    return $recorded->first()[0]->data();
}

/** Structural check of the Adaptive Card 1.5 message envelope Workflows accepts. */
function expectValidAdaptiveCardMessage(array $payload): array
{
    expect($payload['type'])->toBe('message')
        ->and($payload['attachments'])->toHaveCount(1)
        ->and($payload['attachments'][0]['contentType'])->toBe('application/vnd.microsoft.card.adaptive');

    $card = $payload['attachments'][0]['content'];

    expect($card['$schema'])->toBe('http://adaptivecards.io/schemas/adaptive-card.json')
        ->and($card['type'])->toBe('AdaptiveCard')
        ->and($card['version'])->toBe('1.5')
        ->and($card['body'])->toBeArray()->not->toBeEmpty();

    $elementTypes = ['TextBlock', 'RichTextBlock', 'FactSet', 'Container', 'ColumnSet', 'Image'];
    foreach ($card['body'] as $element) {
        expect($elementTypes)->toContain($element['type']);

        if ($element['type'] === 'TextBlock') {
            expect($element['text'])->toBeString();
        }

        if ($element['type'] === 'RichTextBlock') {
            foreach ($element['inlines'] as $inline) {
                expect($inline['type'])->toBe('TextRun')->and($inline['text'])->toBeString();
            }
        }

        if ($element['type'] === 'FactSet') {
            foreach ($element['facts'] as $fact) {
                expect($fact)->toHaveKeys(['title', 'value']);
            }
        }
    }

    foreach ($card['actions'] ?? [] as $action) {
        expect($action['type'])->toBe('Action.OpenUrl')
            ->and($action['url'])->toStartWith('http');
    }

    return $card;
}

test('a Teams Workflows (logic.azure.com) webhook receives an Adaptive Card 1.5 message', function () {
    Http::fake();
    $request = teamsCardSetup(TEAMS_WORKFLOWS_URL);

    app(ChatNotifier::class)->requestSubmitted($request);

    $payload = teamsSentPayload();
    $card = expectValidAdaptiveCardMessage($payload);

    expect($payload)->not->toHaveKey('@type')
        ->and($payload)->not->toHaveKey('sections')
        ->and($card['actions'][0]['url'])->toBe(route('requests.show', $request))
        ->and($payload['queryproxy']['request_id'])->toBe($request->id)
        ->and($payload['queryproxy']['action_token'])->toBeString()->not->toBeEmpty();
});

test('a Teams powerplatform.com webhook receives an Adaptive Card 1.5 message', function () {
    Http::fake();
    $request = teamsCardSetup(TEAMS_POWERPLATFORM_URL);

    app(ChatNotifier::class)->requestSubmitted($request);

    expectValidAdaptiveCardMessage(teamsSentPayload());
});

test('a legacy Office 365 connector webhook keeps receiving the MessageCard', function () {
    Http::fake();
    $request = teamsCardSetup(TEAMS_LEGACY_URL);

    app(ChatNotifier::class)->requestSubmitted($request);

    $payload = teamsSentPayload();

    expect($payload['type'])->toBe('MessageCard')
        ->and($payload)->not->toHaveKey('attachments');
});

test('the Adaptive Card keeps redacting SQL literals', function () {
    Http::fake();
    config(['queryproxy.chat_include_sql' => 'literals_redacted']);
    $request = teamsCardSetup(TEAMS_WORKFLOWS_URL);

    app(ChatNotifier::class)->requestSubmitted($request);

    $body = Http::recorded()->first()[0]->body();

    expect($body)->toContain('email = ?')->not->toContain('ada@example.com');
});

test('a decision message is an Adaptive Card for Workflows webhooks', function () {
    Http::fake();
    $request = teamsCardSetup(TEAMS_WORKFLOWS_URL);
    $request->update(['status' => 'rejected', 'reviewed_by' => User::factory()->create()->id]);

    app(ChatNotifier::class)->requestDecided($request->fresh());

    $card = expectValidAdaptiveCardMessage(teamsSentPayload());

    expect($card['body'][0]['text'])->toContain("Request #{$request->id}")->toContain('rejected');
});

test('the default webhook allow-list accepts powerplatform.com hosts', function () {
    expect(config('queryproxy.chat_webhook_allowed_hosts'))->toContain('*.powerplatform.com');

    $team = Team::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    session(['current_team_id' => $team->id]);

    Livewire\Livewire::actingAs($admin)
        ->test(ChatOps::class)
        ->set('teamsWebhookUrl', TEAMS_POWERPLATFORM_URL)
        ->set('teamsSigningSecret', 'a-long-enough-signing-secret')
        ->call('saveTeams')
        ->assertHasNoErrors();

    expect(ChatIntegration::where('team_id', $team->id)->where('provider', 'teams')->exists())->toBeTrue();
});

test('a lookalike host is still refused by the allow-list', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    session(['current_team_id' => $team->id]);

    Livewire\Livewire::actingAs($admin)
        ->test(ChatOps::class)
        ->set('teamsWebhookUrl', 'https://evil.example.com/x.powerplatform.com')
        ->set('teamsSigningSecret', 'a-long-enough-signing-secret')
        ->call('saveTeams')
        ->assertHasErrors('teamsWebhookUrl');
});

test('the SQL preview is a TextRun so markdown characters are not interpreted', function () {
    Http::fake();
    config(['queryproxy.chat_include_sql' => 'full']);
    $request = teamsCardSetup(TEAMS_WORKFLOWS_URL);
    $request->update(['sql_prepared' => 'SELECT a*b, c*d, _x_ FROM t']);

    app(ChatNotifier::class)->requestSubmitted($request);

    $card = expectValidAdaptiveCardMessage(teamsSentPayload());
    $preview = collect($card['body'])->firstWhere('type', 'RichTextBlock');

    expect($preview['inlines'][0]['text'])->toContain('SELECT a*b, c*d, _x_ FROM t')
        ->and($preview['inlines'][0]['fontType'])->toBe('Monospace')
        ->and(collect($card['body'])->where('type', 'TextBlock')->pluck('text')->implode(' '))->not->toContain('a*b');
});
