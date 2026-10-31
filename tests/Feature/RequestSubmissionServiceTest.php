<?php

use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\QueryRequestSubmitted;
use App\Services\Masking\SensitiveFilterDetector;
use App\Services\Metrics\MetricsRecorder;
use App\Services\Requests\RequestSubmissionException;
use App\Services\Requests\RequestSubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

function submissionSetup(): array
{
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);

    return [$team, $developer, $connection];
}

test('the service creates a pending request with the prepared sql', function () {
    [$team, $developer, $connection] = submissionSetup();

    $request = app(RequestSubmissionService::class)
        ->submit($developer, $connection, 'SELECT * FROM customers', '  need it for a report  ', title: 'Customers');

    expect($request->exists)->toBeTrue()
        ->and($request->status)->toBe(QueryRequestStatus::Pending)
        ->and($request->team_id)->toBe($team->id)
        ->and($request->connection_id)->toBe($connection->id)
        ->and($request->user_id)->toBe($developer->id)
        ->and($request->title)->toBe('Customers')
        ->and($request->note)->toBe('need it for a report')
        ->and($request->sql_original)->toBe('SELECT * FROM customers')
        ->and($request->sql_prepared)->toBe("SELECT * FROM customers\nLIMIT 1000")
        ->and($request->type->value)->toBe('read');
});

test('an empty reason and title are stored as null', function () {
    [, $developer, $connection] = submissionSetup();

    $request = app(RequestSubmissionService::class)->submit($developer, $connection, 'SELECT 1', '   ');

    expect($request->note)->toBeNull()->and($request->title)->toBeNull();
});

test('the audit entry carries the actor, the prepared sql and the channel', function () {
    [, $developer, $connection] = submissionSetup();
    $service = app(RequestSubmissionService::class);

    $web = $service->submit($developer, $connection, 'SELECT 1', null);
    $api = $service->submit($developer, $connection, 'SELECT 2', null, 'api');

    $webLog = AuditLog::where('action', 'request.submitted')->where('query_request_id', $web->id)->sole();
    $apiLog = AuditLog::where('action', 'request.submitted')->where('query_request_id', $api->id)->sole();

    expect($webLog->user_id)->toBe($developer->id)
        ->and($webLog->sql)->toBe($web->sql_prepared)
        ->and($webLog->metadata)->toMatchArray(['type' => 'read', 'statements' => 1, 'channel' => 'web'])
        ->and($apiLog->metadata['channel'])->toBe('api');
});

test('guard violations throw and create nothing', function () {
    [, $developer, $connection] = submissionSetup();

    try {
        app(RequestSubmissionService::class)->submit($developer, $connection, 'DELETE FROM customers', null);
        $this->fail('Expected a RequestSubmissionException.');
    } catch (RequestSubmissionException $e) {
        expect($e->violations)->not->toBeEmpty();
    }

    expect(QueryRequest::count())->toBe(0)
        ->and(AuditLog::where('action', 'request.submitted')->count())->toBe(0);
});

test('a user without access to the connection is refused', function () {
    [$team, $developer] = submissionSetup();
    $other = Connection::factory()->create(['team_id' => $team->id]);

    expect(fn () => app(RequestSubmissionService::class)->submit($developer, $other, 'SELECT 1', null))
        ->toThrow(AuthorizationException::class);

    $stranger = User::factory()->create();
    [, , $connection] = submissionSetup();

    expect(fn () => app(RequestSubmissionService::class)->submit($stranger, $connection, 'SELECT 1', null))
        ->toThrow(AuthorizationException::class);

    expect(QueryRequest::count())->toBe(0);
});

test('a suspended user cannot leave a pending request', function () {
    [, $developer, $connection] = submissionSetup();
    $developer->forceFill(['suspended_at' => now()])->save();

    expect(fn () => app(RequestSubmissionService::class)->submit($developer, $connection, 'SELECT 1', null))
        ->toThrow(AuthorizationException::class);

    expect(QueryRequest::count())->toBe(0);
});

test('a failing sensitive filter detector does not stop the submission', function () {
    [, $developer, $connection] = submissionSetup();

    $this->mock(SensitiveFilterDetector::class)
        ->shouldReceive('detect')->andThrow(new RuntimeException('boom'));

    $request = app(RequestSubmissionService::class)->submit($developer, $connection, 'SELECT 1', null);

    expect($request->sensitive_filter_columns)->toBeNull()
        ->and($request->status)->toBe(QueryRequestStatus::Pending);
});

test('submission notifies the team dbas but not the requester', function () {
    Notification::fake();

    [$team, $developer, $connection] = submissionSetup();
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);

    app(RequestSubmissionService::class)->submit($developer, $connection, 'SELECT 1', null);

    Notification::assertSentTo($dba, QueryRequestSubmitted::class);
    Notification::assertNotSentTo($developer, QueryRequestSubmitted::class);
});

test('a refused submission is counted as a guard rejection and nothing else', function () {
    [, $developer, $connection] = submissionSetup();

    $metrics = Mockery::mock(MetricsRecorder::class);
    $metrics->shouldReceive('guardRejected')->once()->with(Mockery::on(fn ($violations) => $violations !== []));
    $metrics->shouldNotReceive('requestEntered');
    $this->app->instance(MetricsRecorder::class, $metrics);

    expect(fn () => app(RequestSubmissionService::class)->submit($developer, $connection, 'DELETE FROM customers', null))
        ->toThrow(RequestSubmissionException::class);
});

test('an accepted submission is counted as a pending request of its kind', function () {
    [, $developer, $connection] = submissionSetup();

    $metrics = Mockery::mock(MetricsRecorder::class);
    $metrics->shouldReceive('requestEntered')->once()
        ->with(QueryRequestStatus::Pending, Mockery::on(fn ($kind) => $kind->value === 'read'));
    $metrics->shouldNotReceive('guardRejected');
    $this->app->instance(MetricsRecorder::class, $metrics);

    app(RequestSubmissionService::class)->submit($developer, $connection, 'SELECT 1', null);
});
