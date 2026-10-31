<?php

use App\Models\Connection;
use App\Models\Team;
use Tests\Support\ApiWorld;

test('a dba and an auditor list their team\'s connections, a developer only those granted', function () {
    $world = ApiWorld::make();
    $second = Connection::factory()->create(['team_id' => $world->team->id]);
    $foreign = Connection::factory()->create(['team_id' => Team::factory()->create()->id]);

    foreach ([$world->dba, $world->auditor] as $user) {
        $ids = ApiWorld::as(ApiWorld::token($user))->getJson('/api/v1/connections')->assertOk()->json('data.*.id');

        expect($ids)->toEqualCanonicalizing([$world->connection->id, $second->id])
            ->and($ids)->not->toContain($foreign->id);
    }

    $ids = ApiWorld::as(ApiWorld::token($world->developer))->getJson('/api/v1/connections')->json('data.*.id');

    expect($ids)->toBe([$world->connection->id]);
});

test('an admin lists every connection', function () {
    $world = ApiWorld::make();
    $foreign = Connection::factory()->create(['team_id' => Team::factory()->create()->id]);

    $ids = ApiWorld::as(ApiWorld::token($world->admin))->getJson('/api/v1/connections')->json('data.*.id');

    expect($ids)->toEqualCanonicalizing([$world->connection->id, $foreign->id]);
});

test('an expired grant removes the connection from the developer\'s list', function () {
    $world = ApiWorld::make();
    $world->connection->grantedUsers()->updateExistingPivot($world->developer->id, ['expires_at' => now()->subMinute()]);

    ApiWorld::as(ApiWorld::token($world->developer))->getJson('/api/v1/connections')->assertOk()->assertExactJson(['data' => []]);
});

test('the listing carries no host, database, user name or secret', function () {
    $world = ApiWorld::make();

    $response = ApiWorld::as(ApiWorld::token($world->dba))->getJson('/api/v1/connections')->assertOk();

    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'driver', 'team_id'])
        ->and($response->getContent())->not->toContain('secret-password')->not->toContain('db.internal')->not->toContain($world->connection->database);
});

test('either the read or the submit scope is enough to list connections', function () {
    $world = ApiWorld::make();

    ApiWorld::as(ApiWorld::token($world->dba, ['requests:read']))->getJson('/api/v1/connections')->assertOk();
    ApiWorld::as(ApiWorld::token($world->dba, ['requests:submit']))->getJson('/api/v1/connections')->assertOk();
    ApiWorld::as(ApiWorld::token($world->dba, ['results:read']))->getJson('/api/v1/connections')->assertForbidden();
});
