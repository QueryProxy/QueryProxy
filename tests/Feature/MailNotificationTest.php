<?php

use App\Enums\GrantAccessLevel;
use App\Enums\GrantRequestStatus;
use App\Enums\MailEvent;
use App\Livewire\Profile;
use App\Models\Connection;
use App\Models\ConnectionGrant;
use App\Models\ConnectionGrantRequest;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\SafeMailChannel;
use App\Notifications\GrantDecided;
use App\Notifications\GrantRequested;
use App\Notifications\QueryRequestDecided;
use App\Notifications\QueryRequestFinished;
use App\Notifications\QueryRequestReminder;
use App\Notifications\QueryRequestStopped;
use App\Notifications\QueryRequestSubmitted;
use App\Services\Approvals\ApprovalNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;

const MAIL_TEST_SQL = "SELECT * FROM patients WHERE email = 'ada@example.com' AND ssn = 123456789";

/** @return array{0: Team, 1: User, 2: User, 3: Connection, 4: QueryRequest} */
function mailSetup(array $requestOverrides = []): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create(['name' => 'Dana Reviewer']);
    $developer = User::factory()->create(['name' => 'Dev Requester']);
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'Billing DB']);
    $request = QueryRequest::factory()->create(array_merge([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'user_id' => $developer->id,
        'sql_original' => MAIL_TEST_SQL,
        'sql_prepared' => MAIL_TEST_SQL.' LIMIT 1000',
    ], $requestOverrides));

    return [$team, $dba, $developer, $connection, $request];
}

function grantRequestFor(Connection $connection, User $requester, array $overrides = []): ConnectionGrantRequest
{
    return ConnectionGrantRequest::create(array_merge([
        'team_id' => $connection->team_id,
        'connection_id' => $connection->id,
        'user_id' => $requester->id,
        'access_level' => GrantAccessLevel::Write,
        'duration_minutes' => 240,
        'reason' => 'Fixing order 98765 for customer ada@example.com',
        'status' => GrantRequestStatus::Pending,
    ], $overrides));
}

/** @return Collection<int, SentMessage> */
function mailTransportMessages()
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
}

function optIn(User $user, MailEvent ...$events): User
{
    $user->setMailPreferences(array_fill_keys(array_map(fn (MailEvent $e) => $e->value, $events), true));

    return $user->fresh();
}

/** The rendered HTML and plain text of a notification's mail. */
function renderedMail(BaseNotification $notification, User $user): string
{
    $mail = $notification->toMail($user);

    return $mail->subject.' '.$mail->render().' '.implode(' ', array_merge($mail->introLines, $mail->outroLines));
}

dataset('mail notifications', function () {
    return [
        'submitted' => [fn () => new QueryRequestSubmitted(mailSetup()[4]), MailEvent::RequestSubmitted],
        'decided' => [fn () => new QueryRequestDecided(mailSetup(['status' => 'approved'])[4]), MailEvent::RequestDecided],
        'finished' => [fn () => new QueryRequestFinished(mailSetup(['status' => 'completed', 'duration_ms' => 12])[4]), MailEvent::RequestFinished],
        'reminder' => [fn () => new QueryRequestReminder(mailSetup()[4]), MailEvent::RequestReminder],
        'stopped' => [function () {
            [, $dba, , , $request] = mailSetup(['status' => 'failed']);

            return new QueryRequestStopped($request, $dba);
        }, MailEvent::RequestFinished],
        'grant requested' => [function () {
            [, , $developer, $connection] = mailSetup();

            return new GrantRequested(grantRequestFor($connection, $developer));
        }, MailEvent::GrantRequested],
        'grant decided' => [function () {
            [, , $developer, $connection] = mailSetup();

            return new GrantDecided(grantRequestFor($connection, $developer, ['status' => GrantRequestStatus::Rejected]));
        }, MailEvent::GrantDecided],
    ];
});

test('every notification stays in-app only until the user opts in', function (Closure $make) {
    $user = User::factory()->create();
    $notification = $make();

    expect($notification->via($user))->toBe(['database']);
})->with('mail notifications');

test('an opted-in user gets the mail channel, only for the chosen event', function (Closure $make, MailEvent $event) {
    $user = optIn(User::factory()->create(), $event);
    $other = optIn(User::factory()->create(), ...array_filter(MailEvent::cases(), fn (MailEvent $e) => $e !== $event));
    $notification = $make();

    expect($notification->via($user))->toBe(['database', SafeMailChannel::class])
        ->and($notification->via($other))->toBe(['database']);
})->with('mail notifications');

test('every notification is queued and has a mail body', function (Closure $make) {
    $notification = $make();

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and(renderedMail($notification, User::factory()->create()))->not->toBe('');
})->with('mail notifications');

test('a submitted request mails the opted-in DBA and not the one who has it off', function () {
    Notification::fake();
    [$team, $dba, , , $request] = mailSetup();
    $quiet = User::factory()->create();
    $team->users()->attach($quiet, ['role' => 'dba']);
    optIn($dba, MailEvent::RequestSubmitted);

    ApprovalNotifier::requestSubmitted($request);

    Notification::assertSentTo($dba, QueryRequestSubmitted::class, fn ($n, array $channels) => in_array(SafeMailChannel::class, $channels, true));
    Notification::assertSentTo($quiet, QueryRequestSubmitted::class, fn ($n, array $channels) => $channels === ['database']);
});

test('decision and finish mail go to the requester only when they opted in', function () {
    Notification::fake();
    [, , $developer, , $request] = mailSetup(['status' => 'approved']);

    ApprovalNotifier::requestDecided($request);
    Notification::assertSentTo($developer, QueryRequestDecided::class, fn ($n, array $channels) => $channels === ['database']);

    $developer = optIn($developer, MailEvent::RequestDecided, MailEvent::RequestFinished);
    $request->setRelation('requester', $developer);

    ApprovalNotifier::requestDecided($request);
    ApprovalNotifier::requestFinished($request);

    Notification::assertSentTo($developer, QueryRequestDecided::class, fn ($n, array $channels) => in_array(SafeMailChannel::class, $channels, true));
    Notification::assertSentTo($developer, QueryRequestFinished::class, fn ($n, array $channels) => in_array(SafeMailChannel::class, $channels, true));
});

test('the submission mail shows the statement without its string and number literals', function () {
    [, $dba, , , $request] = mailSetup();

    $body = renderedMail(new QueryRequestSubmitted($request), $dba);

    expect($body)->toContain('email = ?')
        ->and($body)->toContain('ssn = ?')
        ->and($body)->not->toContain('ada@example.com')
        ->and($body)->not->toContain('123456789');
});

test('the reminder mail redacts the statement too', function () {
    [, $dba, , , $request] = mailSetup();

    $body = renderedMail(new QueryRequestReminder($request), $dba);

    expect($body)->toContain('ssn = ?')
        ->and($body)->not->toContain('ada@example.com')
        ->and($body)->not->toContain('123456789');
});

test('chat_include_sql none removes the SQL from the mail', function () {
    config(['queryproxy.chat_include_sql' => 'none']);
    [, $dba, , , $request] = mailSetup();

    $body = renderedMail(new QueryRequestSubmitted($request), $dba);

    expect($body)->not->toContain('patients')
        ->and($body)->not->toContain('SELECT');
});

test('chat_include_sql full never puts the raw SQL into a mail', function () {
    config(['queryproxy.chat_include_sql' => 'full']);
    [, $dba, , , $request] = mailSetup();

    $body = renderedMail(new QueryRequestSubmitted($request), $dba);

    expect($body)->toContain('ssn = ?')
        ->and($body)->not->toContain('ada@example.com')
        ->and($body)->not->toContain('123456789');
});

test('SQL the redactor cannot parse is left out, not sent raw', function () {
    [, $dba, , , $request] = mailSetup(['sql_prepared' => "SELECT * FROM t WHERE a = 'secret-value-unterminated"]);

    $body = renderedMail(new QueryRequestSubmitted($request), $dba);

    expect($body)->not->toContain('secret-value-unterminated')
        ->and($body)->not->toContain('SELECT');
});

test('free text and result details never reach the mail', function () {
    [, $dba, $developer, $connection, $request] = mailSetup([
        'status' => 'rejected',
        'rejection_reason' => 'patient 98765 must not be touched',
        'error_message' => 'Duplicate entry bob@example.com for key users_email',
    ]);
    $request->forceFill(['reviewed_by' => $dba->id])->save();

    $rejected = renderedMail(new QueryRequestDecided($request->fresh()), $developer);
    $request->forceFill(['status' => 'failed'])->save();
    $failed = renderedMail(new QueryRequestFinished($request->fresh()), $developer);
    $grant = grantRequestFor($connection, $developer, ['status' => GrantRequestStatus::Rejected, 'decision_note' => 'because of 98765']);
    $requested = renderedMail(new GrantRequested($grant), $dba);
    $decided = renderedMail(new GrantDecided($grant), $developer);

    expect($rejected)->toContain('Billing DB')->not->toContain('98765')
        ->and($failed)->not->toContain('bob@example.com')->not->toContain('Duplicate')
        ->and($requested)->not->toContain('98765')->not->toContain('ada@example.com')
        ->and($decided)->not->toContain('98765');
});

test('links go to the request page and carry nothing that approves', function () {
    [, $dba, $developer, , $request] = mailSetup(['status' => 'approved']);
    $url = route('requests.show', $request);

    foreach ([new QueryRequestSubmitted($request), new QueryRequestDecided($request), new QueryRequestFinished($request), new QueryRequestReminder($request)] as $notification) {
        $mail = $notification->toMail($dba);

        expect($mail->actionUrl)->toBe($url)
            ->and($mail->actionUrl)->not->toContain('?')
            ->and($mail->actionUrl)->not->toContain('token')
            ->and($mail->actionUrl)->not->toContain('approve');
    }

    // The target is behind the login: a guest is sent to sign in.
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
    $this->post($url)->assertStatus(405);
});

test('grant mails link to pages behind the login', function () {
    [, , $developer, $connection] = mailSetup();
    $pending = grantRequestFor($connection, $developer);

    expect((new GrantRequested($pending))->toMail($developer)->actionUrl)->toBe(route('connections.index'))
        ->and((new GrantDecided($pending))->toMail($developer)->actionUrl)->toBe(route('dashboard'));

    $approved = grantRequestFor($connection, $developer, ['status' => GrantRequestStatus::Approved]);
    $grant = new ConnectionGrant(['access_level' => GrantAccessLevel::Write]);
    $grant->expires_at = now()->addHours(4);
    $mail = (new GrantDecided($approved, $grant))->toMail($developer);

    expect($mail->actionUrl)->toBe(route('studio'))
        ->and($mail->introLines)->toContain('Now in force: '.$grant->describe().'.');

    $this->get(route('connections.index'))->assertRedirect(route('login'));
});

test('names typed by users cannot inject links or markup into the mail', function () {
    [, $dba, $developer, $connection, $request] = mailSetup();
    $connection->update(['name' => '[Click here](http://evil.example/phish)']);
    $developer->update(['name' => '<b>Mallory</b> *bold*']);

    $html = (new QueryRequestSubmitted($request->fresh()))->toMail($dba)->render();

    expect((string) $html)->not->toContain('href="http://evil.example')
        ->and((string) $html)->not->toContain('<b>Mallory</b>')
        ->and((string) $html)->not->toContain('<em>bold</em>');
});

test('a mail transport failure is logged and does not break the notification', function () {
    $this->mock(MailChannel::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP down')));
    Log::spy();
    [, $dba, , , $request] = mailSetup();
    $dba = optIn($dba, MailEvent::RequestSubmitted);

    $dba->notifyNow(new QueryRequestSubmitted($request));

    Log::shouldHaveReceived('warning')->once()->with('QueryProxy: e-mail notification failed', Mockery::on(
        fn (array $context) => $context['notification'] === QueryRequestSubmitted::class && str_contains($context['error'], 'SMTP down'),
    ));
    expect($dba->notifications()->count())->toBe(1);
});

test('only the mail is queued; the in-app copy is written at once', function () {
    config(['queue.default' => 'database']);
    [, $dba, , , $request] = mailSetup();
    $dba = optIn($dba, MailEvent::RequestSubmitted);

    $dba->notify(new QueryRequestSubmitted($request));

    expect($dba->notifications()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(mailTransportMessages())->toHaveCount(0);

    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

    expect(mailTransportMessages())->toHaveCount(1)
        ->and($dba->notifications()->count())->toBe(1);
});

test('without a mail preference nothing is queued at all', function () {
    config(['queue.default' => 'database']);
    [, $dba, , , $request] = mailSetup();

    $dba->notify(new QueryRequestSubmitted($request));

    expect($dba->notifications()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a user suspended while the mail waits in the queue gets no mail', function () {
    config(['queue.default' => 'database']);
    [, $dba, , , $request] = mailSetup();
    $dba = optIn($dba, MailEvent::RequestSubmitted);

    $dba->notify(new QueryRequestSubmitted($request));
    $dba->forceFill(['suspended_at' => now()])->save();
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

    expect(mailTransportMessages())->toHaveCount(0)
        ->and($dba->notifications()->count())->toBe(1);
});

test('a user deleted while the mail waits in the queue gets no mail', function () {
    config(['queue.default' => 'database']);
    [, $dba, , , $request] = mailSetup();
    $dba = optIn($dba, MailEvent::RequestSubmitted);

    $dba->notify(new QueryRequestSubmitted($request));
    $dba->delete();
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

    expect(mailTransportMessages())->toHaveCount(0);
});

test('a user who turned the event off while the mail waits gets no mail', function () {
    config(['queue.default' => 'database']);
    [, $dba, , , $request] = mailSetup();
    $dba = optIn($dba, MailEvent::RequestSubmitted);

    $dba->notify(new QueryRequestSubmitted($request));
    $dba->setMailPreferences([]);
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

    expect(mailTransportMessages())->toHaveCount(0);
});

test('a suspended reminder recipient gets no mail even when the notification is sent inline', function () {
    [$team, , , , $request] = mailSetup();
    $suspended = User::factory()->create(['suspended_at' => now()]);
    $team->users()->attach($suspended, ['role' => 'dba']);
    optIn($suspended, MailEvent::RequestReminder);

    ApprovalNotifier::requestReminder($request);

    expect(mailTransportMessages())->toHaveCount(0)
        ->and($suspended->notifications()->count())->toBe(1);
});

test('on a real queue a mail transport failure fails the job instead of vanishing', function () {
    config(['queue.default' => 'database']);
    $this->mock(MailChannel::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP down')));
    Log::spy();
    [, $dba, , , $request] = mailSetup();

    expect(fn () => app(SafeMailChannel::class)->send($dba, new QueryRequestSubmitted($request)))
        ->toThrow(RuntimeException::class, 'SMTP down');

    Log::shouldHaveReceived('warning')->once();
});

test('the real mailer receives the message with the redacted statement', function () {
    [, $dba, , , $request] = mailSetup();
    $dba = optIn($dba, MailEvent::RequestSubmitted);

    $dba->notifyNow(new QueryRequestSubmitted($request));

    $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    $sent = $messages->last()->getOriginalMessage();

    expect($messages)->toHaveCount(1)
        ->and($sent->getTo()[0]->getAddress())->toBe($dba->email)
        ->and($sent->getHtmlBody())->toContain('ssn = ?')->not->toContain('123456789')
        ->and($sent->getTextBody())->toContain('ssn = ?')->not->toContain('123456789')
        ->and($dba->notifications()->count())->toBe(1);
});

test('preferences default to everything off and ignore unknown keys', function () {
    $user = User::factory()->create();

    expect($user->notification_preferences)->toBeNull()
        ->and(array_filter($user->mailPreferences()))->toBe([]);

    $user->setMailPreferences(['request_submitted' => true, 'bogus' => true, 'grant_decided' => 'yes', 'request_decided' => '1', 'request_finished' => 'on']);
    $user = $user->fresh();

    expect($user->wantsMail(MailEvent::RequestSubmitted))->toBeTrue()
        ->and($user->wantsMail(MailEvent::RequestDecided))->toBeTrue()
        ->and($user->wantsMail(MailEvent::GrantDecided))->toBeFalse()
        ->and($user->wantsMail(MailEvent::RequestFinished))->toBeFalse()
        ->and($user->notification_preferences)->not->toHaveKey('bogus');
});

test('the profile page saves the choices', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->assertSet('mailPreferences.request_submitted', false)
        ->set('mailPreferences.request_submitted', true)
        ->set('mailPreferences.grant_decided', true)
        ->call('saveNotificationPreferences')
        ->assertHasNoErrors();

    $user = $user->fresh();
    expect($user->wantsMail(MailEvent::RequestSubmitted))->toBeTrue()
        ->and($user->wantsMail(MailEvent::GrantDecided))->toBeTrue()
        ->and($user->wantsMail(MailEvent::RequestFinished))->toBeFalse();
});

test('the profile page warns when mail is not configured', function () {
    $this->actingAs(User::factory()->create());

    config(['mail.default' => 'log']);
    Livewire::test(Profile::class)->assertSee('not set up to send e-mail');

    config(['mail.default' => 'smtp']);
    Livewire::test(Profile::class)->assertDontSee('not set up to send e-mail');
});
