<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Models\Team;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Connections and teams for the secret reference tests (ADR-028). A team may
 * only use references under its own prefixes, so every connection that
 * resolves one needs a team that has them.
 */
final class SecretsWorld
{
    /** Broad enough for the stores the driver tests talk to. */
    public const DEFAULT_PREFIXES = [
        'vault://secret',
        'vault://kv',
        'aws-sm://prod',
        'aws-sm://arn:aws:secretsmanager:eu-west-1:123456789012:secret:prod',
        'azure-kv://corp-vault',
    ];

    /**
     * A connection that is not stored, with its team attached in memory: for
     * driver tests that need no database rows.
     *
     * @param  list<string>|null  $prefixes  the team's reference prefixes (default DEFAULT_PREFIXES)
     * @param  array<string, mixed>  $attributes
     */
    public static function connection(?array $prefixes = null, array $attributes = []): Connection
    {
        $team = new Team(['name' => 'Team', 'slug' => 'team']);
        $team->forceFill(['id' => 1, 'secret_reference_prefixes' => $prefixes ?? self::DEFAULT_PREFIXES]);

        $connection = Connection::factory()->make(array_merge(['team_id' => 1], $attributes));
        $connection->setRelation('team', $team);

        return $connection;
    }

    /**
     * A stored team and a stored connection with an ordinary password. Use
     * withReference() to put a reference in without the saving check.
     *
     * @param  list<string>|null  $prefixes
     * @param  array<string, mixed>  $attributes
     */
    public static function stored(?array $prefixes = null, array $attributes = []): Connection
    {
        $team = Team::factory()->create(['secret_reference_prefixes' => $prefixes ?? self::DEFAULT_PREFIXES]);

        return Connection::factory()->create(array_merge(['team_id' => $team->id, 'password' => 'plain-password'], $attributes));
    }

    /** Write a reference straight into the row, past the model's saving check (as an old row or a direct edit would). */
    public static function withReference(Connection $connection, string $reference, string $column = 'password'): Connection
    {
        DB::table('connections')->where('id', $connection->id)->update([$column => Crypt::encryptString($reference)]);

        return Connection::query()->findOrFail($connection->id);
    }
}
