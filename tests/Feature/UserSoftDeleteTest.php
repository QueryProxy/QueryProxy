<?php

use App\Enums\QueryRequestStatus;
use App\Livewire\Admin\Users;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Studio\QueryStudio;
use App\Models\AuditLog;
use App\Models\ChatIdentity;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Users\UserLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @return array{0: Team, 1: User, 2: array<string, QueryRequest>}
 */
function softDeleteSetup(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'developer']);

    $connection = Connection::factory()->create(['team_id' => $team->id]);

    $requests = [];
    foreach (['pending', 'pending_two', 'approved', 'queued', 'completed'] as $label) {
        $status = match ($label) {
            'pending', 'pending_two' => QueryRequestStatus::Pending,
            'approved' => QueryRequestStatus::Approved,
            'queued' => QueryRequestStatus::Queued,
            'completed' => QueryRequestStatus::Completed,
        };

        $requests[$label] = QueryRequest::factory()->create([
            'team_id' => $team->id,
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);
    }

    return [$team, $user, $requests];
}

test('soft deleting a user keeps the request history and the row', function () {
    [, $user, $requests] = softDeleteSetup();

    app(UserLifecycle::class)->delete($user);

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id)->deleted_at)->not->toBeNull()
        ->and(QueryRequest::where('user_id', $user->id)->count())->toBe(count($requests));

    // The relation still resolves for history screens.
    expect($requests['completed']->fresh()->requester->id)->toBe($user->id);
});

test('soft delete cancels pending requests only', function () {
    [, $user, $requests] = softDeleteSetup();

    app(UserLifecycle::class)->delete($user);

    expect($requests['pending']->fresh()->status)->toBe(QueryRequestStatus::Cancelled)
        ->and($requests['pending_two']->fresh()->status)->toBe(QueryRequestStatus::Cancelled)
        ->and($requests['approved']->fresh()->status)->toBe(QueryRequestStatus::Approved)
        ->and($requests['queued']->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($requests['completed']->fresh()->status)->toBe(QueryRequestStatus::Completed);
});

test('the system cancellation is audited without an actor even while an admin is signed in', function () {
    [, $user, $requests] = softDeleteSetup();

    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('deleteUser', $user->id);

    $logs = AuditLog::where('action', 'request.cancelled')->get();

    expect($logs)->toHaveCount(2);

    foreach ($logs as $log) {
        expect($log->user_id)->toBeNull()
            ->and($log->metadata['actor'])->toBe('system')
            ->and($log->metadata['reason'])->toBe('User deleted');
    }

    expect($logs->pluck('query_request_id')->sort()->values()->all())
        ->toBe(collect([$requests['pending']->id, $requests['pending_two']->id])->sort()->values()->all());

    // The deletion itself is the admin's act and keeps the admin as actor.
    $deleted = AuditLog::where('action', 'user.deleted')->sole();

    expect($deleted->user_id)->toBe($admin->id)
        ->and($deleted->metadata['cancelled_requests'])->toHaveCount(2);
});

test('soft delete and the cancellations are one transaction', function () {
    [, $user, $requests] = softDeleteSetup();

    // Cancels the first pending request, then blows up on the second one.
    $failing = new class extends ApprovalService
    {
        private int $calls = 0;

        public function cancelBySystem(QueryRequest $request, string $reason): bool
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('cancel loop failed');
            }

            return parent::cancelBySystem($request, $reason);
        }
    };

    expect(fn () => (new UserLifecycle($failing))->delete($user))
        ->toThrow(RuntimeException::class, 'cancel loop failed');

    expect(User::find($user->id))->not->toBeNull()
        ->and($requests['pending']->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and($requests['pending_two']->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and(AuditLog::where('action', 'request.cancelled')->count())->toBe(0)
        ->and(AuditLog::where('action', 'user.deleted')->count())->toBe(0);
});

test('soft delete removes chat identities and live sessions', function () {
    [, $user] = softDeleteSetup();

    ChatIdentity::create(['user_id' => $user->id, 'provider' => 'slack', 'external_id' => 'U_GONE']);
    ChatIdentity::create(['user_id' => $user->id, 'provider' => 'teams', 'external_id' => 'AAD_GONE']);

    DB::table('sessions')->insert([
        'id' => 'session-of-deleted-user',
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    app(UserLifecycle::class)->delete($user);

    expect(ChatIdentity::where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('a soft deleted user cannot log in', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    app(UserLifecycle::class)->delete($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse();
});

test('the database refuses to hard delete a user who has requests', function () {
    [, $user] = softDeleteSetup();

    expect(fn () => $user->forceDelete())->toThrow(QueryException::class);

    expect(QueryRequest::where('user_id', $user->id)->count())->toBeGreaterThan(0);
});

test('an admin cannot delete themselves', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('deleteUser', $admin->id)
        ->assertHasErrors('email');

    expect(User::find($admin->id))->not->toBeNull();
});

test('a user cancelling their own request keeps the actor and the gate', function () {
    [, $user, $requests] = softDeleteSetup();

    app(ApprovalService::class)->cancel($requests['pending'], $user);

    $log = AuditLog::where('action', 'request.cancelled')->sole();

    expect($requests['pending']->fresh()->status)->toBe(QueryRequestStatus::Cancelled)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->metadata['actor'] ?? null)->toBeNull();

    $stranger = User::factory()->create();

    expect(fn () => app(ApprovalService::class)->cancel($requests['pending_two'], $stranger))
        ->toThrow(AuthorizationException::class);
});

test('rolling back the soft delete migration is refused while a deleted or suspended account exists', function () {
    $migration = require database_path('migrations/2026_10_21_000003_add_soft_delete_and_suspension_to_users_table.php');

    $deleted = User::factory()->create();
    $deleted->delete();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'deleted or suspended');

    $deleted->forceDelete();
    User::factory()->create(['suspended_at' => now()]);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'deleted or suspended');
});

test('a deleted or suspended user cannot leave a new pending request behind', function () {
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);

    foreach (['deleted', 'suspended'] as $state) {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => 'developer']);
        $connection->grantedUsers()->attach($user);
        session(['current_team_id' => $team->id]);

        $component = Livewire::actingAs($user)
            ->test(QueryStudio::class)
            ->set('connectionId', $connection->id)
            ->set('sql', 'SELECT * FROM customers');

        // The account goes away after the page was loaded and authenticated.
        $state === 'deleted'
            ? app(UserLifecycle::class)->delete($user)
            : app(UserLifecycle::class)->suspend($user);

        $component->call('submit')->assertForbidden();
    }

    expect(QueryRequest::count())->toBe(0);
});

test('the users screen explains that a deleted account still holds the e-mail address', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $gone = User::factory()->create(['email' => 'gone@example.com']);
    $gone->delete();

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->set('name', 'Newcomer')
        ->set('email', 'gone@example.com')
        ->call('createUser')
        ->assertHasErrors(['email'])
        ->assertSee('A deleted account still holds this address');

    expect(User::where('email', 'gone@example.com')->count())->toBe(0);
});

test('the approvals list loads the team once instead of once per row', function () {
    [$team, $user] = softDeleteSetup();
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    session(['current_team_id' => $team->id]);

    DB::enableQueryLog();
    Livewire::actingAs($dba)->test(ApprovalsIndex::class);
    $teamQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q) => preg_match('/from "teams"/i', $q['query']) === 1 && str_contains($q['query'], '"teams"."id" ='))
        ->count();
    DB::disableQueryLog();

    expect($teamQueries)->toBeLessThanOrEqual(2);
});
