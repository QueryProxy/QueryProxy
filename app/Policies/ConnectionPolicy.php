<?php

namespace App\Policies;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    /**
     * May the user submit a statement the SQL guard classifies as a write or
     * DDL? A DBA may; a developer only with a write grant. A read grant refuses
     * it (ADR-025). Called after `use`, so it does not repeat the team check.
     */
    public function write(User $user, Connection $connection): Response
    {
        if ($user->isDbaIn($connection->team)) {
            return Response::allow();
        }

        if ($user->isDeveloperIn($connection->team) && $connection->grantedAccessLevel($user)?->allowsWrite()) {
            return Response::allow();
        }

        return Response::deny('Your access to this connection is read-only; only SELECT-type requests can be submitted.');
    }
}
