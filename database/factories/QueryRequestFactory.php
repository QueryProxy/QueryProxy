<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryRequest>
 */
class QueryRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'connection_id' => Connection::factory()->state(fn (array $attrs, ?Team $team = null) => []),
            'user_id' => User::factory(),
            'sql_original' => 'SELECT * FROM users',
            'sql_prepared' => 'SELECT * FROM users LIMIT 1000',
            'statement_count' => 1,
            'is_transaction' => false,
            'type' => 'read',
            'status' => 'pending',
        ];
    }

    public function configure(): static
    {
        // Keep the connection in the same team as the request unless overridden.
        return $this->afterMaking(function (QueryRequest $request) {
            $connection = Connection::find($request->connection_id);

            if ($connection && $connection->team_id !== $request->team_id) {
                $connection->update(['team_id' => $request->team_id]);
            }
        });
    }
}
