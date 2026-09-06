<?php

namespace App\Policies;

use App\Models\Connection;
use App\Models\User;

class ConnectionPolicy
{
    public function view(User $user, Connection $connection): bool
    {
        return $user->isDbaIn($connection->team)
            || $user->isAuditorIn($connection->team)
            || $connection->isGrantedTo($user);
    }

    public function create(User $user): bool
    {
        $team = $user->currentTeam();

        return $team !== null && $user->isDbaIn($team);
    }

    public function update(User $user, Connection $connection): bool
    {
        return $user->isDbaIn($connection->team);
    }

    public function delete(User $user, Connection $connection): bool
    {
        return $user->isDbaIn($connection->team);
    }

    /** May the user submit queries through this connection? */
    public function use(User $user, Connection $connection): bool
    {
        if ($user->isDbaIn($connection->team)) {
            return true;
        }

        return $user->isDeveloperIn($connection->team) && $connection->isGrantedTo($user);
    }
}
