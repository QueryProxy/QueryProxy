<?php

namespace App\Providers;

use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // System admins pass every ability check up-front — except the ones about a
        // query request, because QueryRequestPolicy encodes the request state machine
        // alongside permission. Bypassing it let an admin approve, reject or cancel a
        // request that had already finished, which re-queued the job and rewrote the
        // approval trail. That policy admits admins itself wherever this used to.
        Gate::before(function (User $user, string $ability, array $arguments = []) {
            if (! $user->isAdmin()) {
                return null;
            }

            return ($arguments[0] ?? null) instanceof QueryRequest ? null : true;
        });
    }
}
