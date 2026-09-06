<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(2, true),
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => '5432',
            'database' => 'app',
            'username' => 'reader',
            'password' => 'secret-password',
        ];
    }

    public function sqlite(string $path = ':memory:'): static
    {
        return $this->state([
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => $path,
            'username' => null,
            'password' => null,
        ]);
    }
}
