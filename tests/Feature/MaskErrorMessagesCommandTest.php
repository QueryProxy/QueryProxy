<?php

use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Notifications\QueryRequestFinished;
use App\Services\Masking\ErrorMessageSanitizer;
use App\Services\Masking\Masker;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function failureNotification(QueryRequest $request): DatabaseNotification
{
    $request->requester->notify(new QueryRequestFinished($request));

    return $request->requester->notifications()->latest()->firstOrFail();
}

function failedRequest(Connection $connection, string $message): QueryRequest
{
    return QueryRequest::factory()->create([
        'team_id' => $connection->team_id,
        'connection_id' => $connection->id,
        'status' => QueryRequestStatus::Failed,
        'error_message' => $message,
    ]);
}

test('the dry run reports the count and writes nothing', function () {
    $team = Team::factory()->create();
    $request = failedRequest(Connection::factory()->create(['team_id' => $team->id]), "Duplicate entry 'bob@x.io' for key 'users.email'");

    $this->artisan('queryproxy:mask-error-messages', ['--dry-run' => true])
        ->expectsOutputToContain('1 of 1')
        ->assertSuccessful();

    expect($request->fresh()->error_message)->toBe("Duplicate entry 'bob@x.io' for key 'users.email'")
        ->and(AuditLog::where('action', 'error_messages.masked')->exists())->toBeFalse();
});

test('a real run masks stored messages, leaves safe ones and audits once', function () {
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $leaky = failedRequest($connection, "Duplicate entry 'bob@x.io' for key 'users.email'");
    $safe = failedRequest($connection, 'SQLSTATE[HY000]: General error: 1 no such table: missing_table');
    $updatedAt = $leaky->updated_at;

    $this->artisan('queryproxy:mask-error-messages')->expectsOutputToContain('1 of 2')->assertSuccessful();

    expect($leaky->fresh()->error_message)->not->toContain('bob')
        ->and($leaky->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($safe->fresh()->error_message)->toBe('SQLSTATE[HY000]: General error: 1 no such table: missing_table');

    $audit = AuditLog::where('action', 'error_messages.masked')->sole();

    expect($audit->metadata)->toBe([
        'scanned' => 2,
        'changed' => 1,
        'fixed_message' => 0,
        'skipped' => 0,
        'notifications_scanned' => 0,
        'notifications_changed' => 0,
        'notifications_fixed_message' => 0,
        'notifications_skipped' => 0,
    ])
        ->and($audit->user_id)->toBeNull();

    // A second run finds nothing left to change.
    $this->artisan('queryproxy:mask-error-messages')->expectsOutputToContain('0 of 2')->assertSuccessful();
});

test('a request without a connection gets the fixed message', function () {
    $team = Team::factory()->create();
    $request = failedRequest(Connection::factory()->create(['team_id' => $team->id]), "Duplicate entry 'bob@x.io' for key 'users.email'");

    // Orphan the row without tripping the foreign key (checked at commit, which the test transaction never reaches).
    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('query_requests')->where('id', $request->id)->update(['connection_id' => 999999]);

    $this->artisan('queryproxy:mask-error-messages', ['--dry-run' => true])
        ->expectsOutputToContain('1 message(s) and 0 notification(s) replaced by the fixed message')
        ->assertSuccessful();

    $this->artisan('queryproxy:mask-error-messages')
        ->expectsOutputToContain('1 message(s) and 0 notification(s) replaced by the fixed message')
        ->assertSuccessful();

    expect($request->fresh()->error_message)->toBe(ErrorMessageSanitizer::FALLBACK_MESSAGE)
        ->and(AuditLog::where('action', 'error_messages.masked')->sole()->metadata['fixed_message'])->toBe(1);
});

test('a connection whose rules cannot be read is skipped, reported and left untouched', function () {
    $team = Team::factory()->create();
    $request = failedRequest(Connection::factory()->create(['team_id' => $team->id]), "Duplicate entry 'bob@x.io' for key 'users.email'");

    $this->partialMock(Masker::class, fn ($mock) => $mock->shouldReceive('rulesFor')->andThrow(new RuntimeException('rules unreadable')));

    $this->artisan('queryproxy:mask-error-messages')
        ->expectsOutputToContain('1 skipped')
        ->assertFailed();

    expect($request->fresh()->error_message)->toBe("Duplicate entry 'bob@x.io' for key 'users.email'")
        ->and(AuditLog::where('action', 'error_messages.masked')->sole()->metadata['skipped'])->toBe(1);
});

test('a run that dies halfway still leaves its audit entry', function () {
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $first = failedRequest($connection, "Duplicate entry 'bob@x.io' for key 'users.email'");
    failedRequest($connection, "Duplicate entry 'eve@x.io' for key 'users.email'");

    $calls = 0;
    $this->mock(ErrorMessageSanitizer::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('sanitize')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('boom');
            }

            return 'masked';
        });
    });

    expect(fn () => Artisan::call('queryproxy:mask-error-messages'))->toThrow(RuntimeException::class, 'boom');

    $audit = AuditLog::where('action', 'error_messages.masked')->sole();

    expect($first->fresh()->error_message)->toBe('masked')
        ->and($audit->metadata['scanned'])->toBe(2)
        ->and($audit->metadata['changed'])->toBe(1);
});

test('failure notifications are rebuilt from the masked message, leaving read state and other kinds alone', function () {
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $leaky = failedRequest($connection, "Duplicate entry 'bob@x.io' for key 'users.email'");
    $notification = failureNotification($leaky);
    $notification->markAsRead();
    $readAt = $notification->fresh()->read_at;

    $done = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'status' => QueryRequestStatus::Completed,
        'duration_ms' => 12,
    ]);
    $completed = failureNotification($done);
    $completedData = $completed->data;

    expect($notification->data['message'])->toContain('bob@x.io');

    // The dry run counts the notification and writes nothing.
    $this->artisan('queryproxy:mask-error-messages', ['--dry-run' => true])
        ->expectsOutputToContain('1 of 1 failure notification(s)')
        ->assertSuccessful();

    expect($notification->fresh()->data['message'])->toContain('bob@x.io');

    $this->artisan('queryproxy:mask-error-messages')
        ->expectsOutputToContain('1 of 1 failure notification(s)')
        ->assertSuccessful();

    $masked = $notification->fresh();

    expect($masked->data['message'])->not->toContain('bob@x.io')
        ->and($masked->data['message'])->toBe(sprintf('Request #%d failed: %s', $leaky->id, str($leaky->fresh()->error_message)->limit(120)))
        ->and($masked->data['kind'])->toBe('failed')
        ->and($masked->data['query_request_id'])->toBe($leaky->id)
        ->and($masked->read_at->equalTo($readAt))->toBeTrue()
        ->and($completed->fresh()->data)->toBe($completedData);

    $audit = AuditLog::where('action', 'error_messages.masked')->sole();

    expect($audit->metadata['notifications_scanned'])->toBe(1)
        ->and($audit->metadata['notifications_changed'])->toBe(1)
        ->and($audit->metadata['notifications_skipped'])->toBe(0);

    // A second run finds nothing left to change.
    $this->artisan('queryproxy:mask-error-messages')
        ->expectsOutputToContain('0 of 1 failure notification(s)')
        ->assertSuccessful();
});

test('a failure notification whose request or connection is gone gets the fixed message', function () {
    $team = Team::factory()->create();
    $orphaned = failedRequest(Connection::factory()->create(['team_id' => $team->id]), "Duplicate entry 'bob@x.io' for key 'users.email'");
    $noConnection = failureNotification($orphaned);

    $noRequest = failureNotification(failedRequest(Connection::factory()->create(['team_id' => $team->id]), "Duplicate entry 'eve@x.io' for key 'users.email'"));
    $missingRequestId = $noRequest->data['query_request_id'];

    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('query_requests')->where('id', $orphaned->id)->update(['connection_id' => 999999]);
    DB::table('query_requests')->where('id', $missingRequestId)->delete();

    $this->artisan('queryproxy:mask-error-messages', ['--dry-run' => true])
        ->expectsOutputToContain('1 message(s) and 2 notification(s) replaced by the fixed message')
        ->assertSuccessful();

    $this->artisan('queryproxy:mask-error-messages')
        ->expectsOutputToContain('2 notification(s) replaced by the fixed message')
        ->assertSuccessful();

    $fixed = ErrorMessageSanitizer::FALLBACK_MESSAGE;

    expect($noConnection->fresh()->data['message'])->toBe(sprintf('Request #%d failed: %s', $orphaned->id, $fixed))
        ->and($noRequest->fresh()->data['message'])->toBe(sprintf('Request #%d failed: %s', $missingRequestId, $fixed));

    $audit = AuditLog::where('action', 'error_messages.masked')->sole();

    expect($audit->metadata['notifications_scanned'])->toBe(2)
        ->and($audit->metadata['notifications_changed'])->toBe(2)
        ->and($audit->metadata['notifications_fixed_message'])->toBe(2);
});

test('a failure notification of a connection whose rules cannot be read is skipped and left untouched', function () {
    $team = Team::factory()->create();
    $request = failedRequest(Connection::factory()->create(['team_id' => $team->id]), "Duplicate entry 'bob@x.io' for key 'users.email'");
    $notification = failureNotification($request);

    $this->partialMock(Masker::class, fn ($mock) => $mock->shouldReceive('rulesFor')->andThrow(new RuntimeException('rules unreadable')));

    $this->artisan('queryproxy:mask-error-messages')->assertFailed();

    expect($notification->fresh()->data['message'])->toContain('bob@x.io')
        ->and(AuditLog::where('action', 'error_messages.masked')->sole()->metadata['notifications_skipped'])->toBe(1);
});
