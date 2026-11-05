<?php

use App\Jobs\SuggestSlackIdentities;
use App\Livewire\Admin\Users;
use App\Models\ChatIdentity;
use App\Models\SlackIdentitySuggestion;
use App\Models\User;
use App\Services\Chat\SlackIdentitySuggester;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\SlackApp;

function lookupFake(array $byEmail): void
{
    Http::fake(function (Request $request) use ($byEmail) {
        if (! str_ends_with($request->url(), '/api/users.lookupByEmail')) {
            return Http::response(['ok' => true]);
        }

        $member = $byEmail[$request['email']] ?? null;

        return $member === null
            ? Http::response(['ok' => false, 'error' => 'users_not_found'])
            : Http::response(['ok' => true, 'user' => $member]);
    });
}

test('users without a chat identity get a suggestion from the e-mail lookup, and nothing is linked', function () {
    [$team, , , $integration] = SlackApp::team();
    $newcomer = User::factory()->create(['email' => 'new.person@example.com']);
    $stranger = User::factory()->create(['email' => 'no.slack@example.com']);
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $team->users()->attach($stranger, ['role' => 'developer']);

    lookupFake(['new.person@example.com' => ['id' => 'U_NEWCOMER', 'is_bot' => false, 'deleted' => false]]);

    $count = app(SlackIdentitySuggester::class)->suggestFor($integration);

    $suggestion = SlackIdentitySuggestion::sole();

    expect($count)->toBe(1)
        ->and($suggestion->user_id)->toBe($newcomer->id)
        ->and($suggestion->slack_user_id)->toBe('U_NEWCOMER')
        ->and($suggestion->slack_team_id)->toBe(SlackApp::WORKSPACE)
        ->and($suggestion->team_id)->toBe($team->id)
        ->and($suggestion->matched_email)->toBe('new.person@example.com')
        ->and($suggestion->status)->toBe('pending')
        // Only the two users with identities were skipped; no identity was created.
        ->and(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse()
        ->and(ChatIdentity::count())->toBe(2);

    // The bot token goes in the header, never in the lookup's body.
    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), '/api/users.lookupByEmail')
        && $sent->header('Authorization') === ['Bearer '.SlackApp::BOT_TOKEN]
        && ! str_contains($sent->body(), SlackApp::BOT_TOKEN));
});

test('users who already have a chat identity are not looked up', function () {
    [$team, , , $integration] = SlackApp::team();
    lookupFake([]);

    app(SlackIdentitySuggester::class)->suggestFor($integration);

    Http::assertNothingSent();
    expect(SlackIdentitySuggestion::count())->toBe(0);
});

test('bots, deactivated members and members linked to someone else are not suggested', function () {
    [$team, , , $integration] = SlackApp::team();

    foreach (['bot@example.com', 'gone@example.com', 'taken@example.com'] as $email) {
        $team->users()->attach(User::factory()->create(['email' => $email]), ['role' => 'developer']);
    }

    lookupFake([
        'bot@example.com' => ['id' => 'U_BOTUSER', 'is_bot' => true, 'deleted' => false],
        'gone@example.com' => ['id' => 'U_GONE', 'is_bot' => false, 'deleted' => true],
        'taken@example.com' => ['id' => 'U_DBA', 'is_bot' => false, 'deleted' => false],
    ]);

    expect(app(SlackIdentitySuggester::class)->suggestFor($integration))->toBe(0)
        ->and(SlackIdentitySuggestion::count())->toBe(0);
});

test('a second run does not repeat lookups for users already suggested', function () {
    [$team, , , $integration] = SlackApp::team();
    $newcomer = User::factory()->create(['email' => 'new.person@example.com']);
    $team->users()->attach($newcomer, ['role' => 'developer']);

    lookupFake(['new.person@example.com' => ['id' => 'U_NEWCOMER', 'is_bot' => false, 'deleted' => false]]);

    app(SlackIdentitySuggester::class)->suggestFor($integration);
    app(SlackIdentitySuggester::class)->suggestFor($integration);

    expect(SlackIdentitySuggestion::count())->toBe(1);
    Http::assertSentCount(1);
});

test('a rejected suggestion is kept, hidden from the list and not proposed again by the next scan', function () {
    [$team, , , $integration] = SlackApp::team();
    $newcomer = User::factory()->create(['email' => 'new.person@example.com']);
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $admin = User::factory()->create(['is_admin' => true]);

    lookupFake(['new.person@example.com' => ['id' => 'U_NEWCOMER', 'is_bot' => false, 'deleted' => false]]);

    app(SlackIdentitySuggester::class)->suggestFor($integration);

    Livewire::actingAs($admin)->test(Users::class)
        ->assertSee('Slack matching suggestions')
        ->call('rejectSlackSuggestion', SlackIdentitySuggestion::sole()->id)
        ->assertDontSee('Slack matching suggestions');

    $dismissed = SlackIdentitySuggestion::sole();

    expect($dismissed->dismissed_at)->not->toBeNull()
        ->and($dismissed->status)->toBe(SlackIdentitySuggestion::DISMISSED)
        ->and(SlackIdentitySuggestion::pending()->count())->toBe(0);

    // The weekly scan runs again: Slack names the same member, so nothing is proposed.
    expect(app(SlackIdentitySuggester::class)->suggestFor($integration))->toBe(0);

    $again = SlackIdentitySuggestion::sole();

    expect($again->id)->toBe($dismissed->id)
        ->and($again->dismissed_at)->not->toBeNull()
        ->and(SlackIdentitySuggestion::pending()->count())->toBe(0)
        ->and(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse();

    Livewire::actingAs($admin)->test(Users::class)->assertDontSee('Slack matching suggestions');
});

test('a rejected user is proposed again only when the address now points at another Slack member', function () {
    [$team, , , $integration] = SlackApp::team();
    $person = User::factory()->create(['email' => 'a@example.com']);
    $team->users()->attach($person, ['role' => 'developer']);

    // The member the address points at; changed below. Http::fake stubs do not replace each other.
    $memberId = 'U_OLD';
    Http::fake(function () use (&$memberId) {
        return Http::response(['ok' => true, 'user' => ['id' => $memberId, 'is_bot' => false, 'deleted' => false]]);
    });

    app(SlackIdentitySuggester::class)->suggestFor($integration);
    SlackIdentitySuggestion::sole()->update(['status' => SlackIdentitySuggestion::DISMISSED, 'dismissed_at' => now()]);

    // Same member: still rejected.
    expect(app(SlackIdentitySuggester::class)->suggestFor($integration))->toBe(0)
        ->and(SlackIdentitySuggestion::pending()->count())->toBe(0);

    // The address moved to another member: a fresh proposal on the same row.
    $memberId = 'U_NEW';

    expect(app(SlackIdentitySuggester::class)->suggestFor($integration))->toBe(1);

    $row = SlackIdentitySuggestion::sole();

    expect($row->slack_user_id)->toBe('U_NEW')
        ->and($row->status)->toBe(SlackIdentitySuggestion::PENDING)
        ->and($row->dismissed_at)->toBeNull()
        ->and(SlackIdentitySuggestion::pending()->count())->toBe(1);
});

test('a rejection that lands while the lookup is in flight is not overwritten', function () {
    [$team, , , $integration] = SlackApp::team();
    $newcomer = User::factory()->create(['email' => 'new.person@example.com']);
    $team->users()->attach($newcomer, ['role' => 'developer']);

    // The batch query found no row for the user; a dismissed one appears while Slack answers.
    Http::fake(function () use ($team, $newcomer) {
        SlackIdentitySuggestion::create([
            'team_id' => $team->id, 'user_id' => $newcomer->id, 'slack_team_id' => SlackApp::WORKSPACE,
            'slack_user_id' => 'U_NEWCOMER', 'matched_email' => 'new.person@example.com',
            'status' => 'pending', 'dismissed_at' => now(),
        ]);

        return Http::response(['ok' => true, 'user' => ['id' => 'U_NEWCOMER', 'is_bot' => false, 'deleted' => false]]);
    });

    expect(app(SlackIdentitySuggester::class)->suggestFor($integration))->toBe(0)
        ->and(SlackIdentitySuggestion::sole()->dismissed_at)->not->toBeNull()
        ->and(SlackIdentitySuggestion::pending()->count())->toBe(0);
});

test('only undismissed suggestions are pending', function () {
    [$team] = SlackApp::team();
    $one = User::factory()->create();
    $two = User::factory()->create();

    $open = SlackIdentitySuggestion::create([
        'team_id' => $team->id, 'user_id' => $one->id, 'slack_team_id' => SlackApp::WORKSPACE,
        'slack_user_id' => 'U_ONE', 'matched_email' => $one->email, 'status' => 'pending',
    ]);
    SlackIdentitySuggestion::create([
        'team_id' => $team->id, 'user_id' => $two->id, 'slack_team_id' => SlackApp::WORKSPACE,
        'slack_user_id' => 'U_TWO', 'matched_email' => $two->email, 'status' => 'pending', 'dismissed_at' => now(),
    ]);

    expect(SlackIdentitySuggestion::pending()->pluck('id')->all())->toBe([$open->id]);
});

test('the dismissed_at migration rolls back and applies again', function () {
    $path = 'database/migrations/2026_11_04_000001_add_dismissed_at_to_slack_identity_suggestions.php';

    expect(Schema::hasColumn('slack_identity_suggestions', 'dismissed_at'))->toBeTrue();

    $this->artisan('migrate:rollback', ['--path' => $path, '--realpath' => false])->assertSuccessful();
    expect(Schema::hasColumn('slack_identity_suggestions', 'dismissed_at'))->toBeFalse();

    $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
    expect(Schema::hasColumn('slack_identity_suggestions', 'dismissed_at'))->toBeTrue();
});

test('a Slack error stops the run without failing', function () {
    [$team, , , $integration] = SlackApp::team();
    $team->users()->attach(User::factory()->create(['email' => 'a@example.com']), ['role' => 'developer']);
    $team->users()->attach(User::factory()->create(['email' => 'b@example.com']), ['role' => 'developer']);

    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'ratelimited'])]);

    expect(app(SlackIdentitySuggester::class)->suggestFor($integration))->toBe(0);
    Http::assertSentCount(1);
});

test('an installation without a bot token produces no suggestions', function () {
    [, , , $integration] = SlackApp::team();
    $integration->clearSlackInstallation();
    Http::fake();

    expect(app(SlackIdentitySuggester::class)->suggestFor($integration->fresh()))->toBe(0);
    Http::assertNothingSent();
});

test('the queued job runs the suggester for its integration', function () {
    [$team, , , $integration] = SlackApp::team();
    $newcomer = User::factory()->create(['email' => 'new.person@example.com']);
    $team->users()->attach($newcomer, ['role' => 'developer']);

    lookupFake(['new.person@example.com' => ['id' => 'U_NEWCOMER', 'is_bot' => false, 'deleted' => false]]);

    SuggestSlackIdentities::dispatchSync($integration->id);

    expect(SlackIdentitySuggestion::where('user_id', $newcomer->id)->exists())->toBeTrue();
});

test('a batch ends at a cursor and says when more may follow', function () {
    [$team, , , $integration] = SlackApp::team();
    $users = collect(range(1, 5))->map(fn ($i) => User::factory()->create(['email' => "member{$i}@example.com"]));
    $users->each(fn (User $user) => $team->users()->attach($user, ['role' => 'developer']));

    lookupFake([]);

    $first = app(SlackIdentitySuggester::class)->suggestBatch($integration, 0, 2);
    $second = app(SlackIdentitySuggester::class)->suggestBatch($integration, $first['cursor'], 2);
    $last = app(SlackIdentitySuggester::class)->suggestBatch($integration, $second['cursor'], 2);

    expect($first['more'])->toBeTrue()
        ->and($first['cursor'])->toBe($users[1]->id)
        ->and($second['more'])->toBeTrue()
        ->and($second['cursor'])->toBe($users[3]->id)
        ->and($last['more'])->toBeFalse()
        ->and($last['cursor'])->toBe($users[4]->id);
});

test('a team larger than one batch is covered by queued follow-up batches', function () {
    [$team, , , $integration] = SlackApp::team();
    $people = User::factory()->count(SlackIdentitySuggester::MAX_LOOKUPS + 1)->create();
    $team->users()->attach($people->pluck('id')->all(), ['role' => 'developer']);

    $byEmail = [$people->last()->email => ['id' => 'U_LAST_ONE', 'is_bot' => false, 'deleted' => false]];
    lookupFake($byEmail);
    Queue::fake();

    (new SuggestSlackIdentities($integration->id))->handle(app(SlackIdentitySuggester::class));

    Queue::assertPushed(SuggestSlackIdentities::class, fn ($job) => $job->afterUserId === $people[SlackIdentitySuggester::MAX_LOOKUPS - 1]->id
        && $job->rateLimitRetries === 0);

    // The follow-up reaches the user the first batch did not.
    (new SuggestSlackIdentities($integration->id, $people[SlackIdentitySuggester::MAX_LOOKUPS - 1]->id))->handle(app(SlackIdentitySuggester::class));

    expect(SlackIdentitySuggestion::where('slack_user_id', 'U_LAST_ONE')->exists())->toBeTrue();
});

test('a Slack rate limit queues a retry from the same place, a few times at most', function () {
    [$team, , , $integration] = SlackApp::team();
    $team->users()->attach(User::factory()->create(['email' => 'a@example.com']), ['role' => 'developer']);

    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'ratelimited'])]);
    Queue::fake();

    (new SuggestSlackIdentities($integration->id))->handle(app(SlackIdentitySuggester::class));

    Queue::assertPushed(SuggestSlackIdentities::class, fn ($job) => $job->rateLimitRetries === 1 && $job->afterUserId === 0);

    Queue::fake();

    (new SuggestSlackIdentities($integration->id, 0, SuggestSlackIdentities::MAX_RATE_LIMIT_RETRIES))->handle(app(SlackIdentitySuggester::class));

    Queue::assertNothingPushed();
});

test('another kind of Slack error does not queue a retry', function () {
    [$team, , , $integration] = SlackApp::team();
    $team->users()->attach(User::factory()->create(['email' => 'a@example.com']), ['role' => 'developer']);

    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'missing_scope'])]);
    Queue::fake();

    (new SuggestSlackIdentities($integration->id))->handle(app(SlackIdentitySuggester::class));

    Queue::assertNothingPushed();
});

test('a weekly schedule looks for users added since the install, for installed teams only', function () {
    [, , , $installed] = SlackApp::team();
    [, , , $webhookOnly] = SlackApp::team(['bot_token' => null, 'slack_team_id' => null, 'slack_channel_id' => null], '_2');
    Queue::fake();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'slack-identity-suggestions');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * 0');

    $event->run(app());

    Queue::assertPushed(SuggestSlackIdentities::class, 1);
    Queue::assertPushed(SuggestSlackIdentities::class, fn ($job) => $job->chatIntegrationId === $installed->id);
});

test('a rate limit after progress starts the retry count again', function () {
    [$team, , , $integration] = SlackApp::team();
    $first = User::factory()->create(['email' => 'first@example.com']);
    $second = User::factory()->create(['email' => 'second@example.com']);
    $team->users()->attach([$first->id, $second->id], ['role' => 'developer']);

    // The first lookup finds nobody; the second is rate limited.
    Http::fake(['slack.com/api/*' => Http::sequence()
        ->push(['ok' => false, 'error' => 'users_not_found'])
        ->push(['ok' => false, 'error' => 'ratelimited'])]);
    Queue::fake();

    // Retries used up by earlier windows that went nowhere would stop the chain here.
    (new SuggestSlackIdentities($integration->id, 0, SuggestSlackIdentities::MAX_RATE_LIMIT_RETRIES))->handle(app(SlackIdentitySuggester::class));

    Queue::assertPushed(SuggestSlackIdentities::class, fn ($job) => $job->afterUserId === $first->id && $job->rateLimitRetries === 1);
});

test('rate limits that make no progress in a row stop the chain', function () {
    [$team, , , $integration] = SlackApp::team();
    $first = User::factory()->create(['email' => 'first@example.com']);
    $team->users()->attach($first, ['role' => 'developer']);

    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'ratelimited'])]);
    Queue::fake();

    (new SuggestSlackIdentities($integration->id, 0, SuggestSlackIdentities::MAX_RATE_LIMIT_RETRIES - 1))->handle(app(SlackIdentitySuggester::class));
    Queue::assertPushed(SuggestSlackIdentities::class, fn ($job) => $job->rateLimitRetries === SuggestSlackIdentities::MAX_RATE_LIMIT_RETRIES);

    Queue::fake();
    (new SuggestSlackIdentities($integration->id, 0, SuggestSlackIdentities::MAX_RATE_LIMIT_RETRIES))->handle(app(SlackIdentitySuggester::class));
    Queue::assertNothingPushed();
});

test('a user whose identity has no workspace is looked up, a bound one is not, and a member held by someone else is skipped', function () {
    [$team, , , $integration] = SlackApp::team();
    $unbound = User::factory()->create(['email' => 'unbound@example.com']);
    $taker = User::factory()->create(['email' => 'taker@example.com']);
    $team->users()->attach($unbound, ['role' => 'developer']);
    $team->users()->attach($taker, ['role' => 'developer']);
    ChatIdentity::create(['user_id' => $unbound->id, 'provider' => 'slack', 'external_id' => 'U_UNBOUND']);

    lookupFake([
        'unbound@example.com' => ['id' => 'U_UNBOUND', 'is_bot' => false, 'deleted' => false],
        // U_DBA is already held by the team's DBA.
        'taker@example.com' => ['id' => 'U_DBA', 'is_bot' => false, 'deleted' => false],
    ]);

    app(SlackIdentitySuggester::class)->suggestFor($integration);

    expect(SlackIdentitySuggestion::pluck('user_id')->all())->toBe([$unbound->id]);

});
