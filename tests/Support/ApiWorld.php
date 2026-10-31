<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Api\TokenService;
use Illuminate\Foundation\Testing\TestCase;

/**
 * One team with a connection and one user of each role, plus helpers to mint
 * API tokens and call /api/v1 as a given token. Shared by tests/Feature/Api.
 */
class ApiWorld
{
    public Team $team;

    public Connection $connection;

    public User $dba;

    public User $developer;

    public User $auditor;

    public User $admin;

    public static function make(): self
    {
        $world = new self;

        $world->team = Team::factory()->create();
        $world->connection = Connection::factory()->create(['team_id' => $world->team->id]);

        $world->dba = User::factory()->create();
        $world->developer = User::factory()->create();
        $world->auditor = User::factory()->create();
        $world->admin = User::factory()->create(['is_admin' => true]);

        $world->team->users()->attach($world->dba, ['role' => 'dba']);
        $world->team->users()->attach($world->developer, ['role' => 'developer']);
        $world->team->users()->attach($world->auditor, ['role' => 'auditor']);

        $world->connection->grantedUsers()->attach($world->developer);

        return $world;
    }

    /** A pending request filed by $requester on the world's connection. */
    public function pendingRequest(User $requester, array $attributes = []): QueryRequest
    {
        return QueryRequest::factory()->create([
            'team_id' => $this->team->id,
            'connection_id' => $this->connection->id,
            'user_id' => $requester->id,
        ] + $attributes);
    }

    /**
     * The token value (shown once, like to a real owner).
     *
     * @param  list<string>|null  $scopes  every scope when null
     */
    public static function token(User $user, ?array $scopes = null, int $days = 30): string
    {
        return app(TokenService::class)
            ->create($user, 'test token', $scopes ?? TokenService::scopes(), $days)
            ->plainTextToken;
    }

    /**
     * A test case ready to call the API with the token. The guards are reset
     * first: the sanctum guard remembers the user it resolved, and tests make
     * several calls as different users inside one application instance.
     */
    public static function as(string $token): TestCase
    {
        app('auth')->forgetGuards();

        return test()->withToken($token);
    }
}
