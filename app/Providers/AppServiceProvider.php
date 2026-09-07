<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureTeamRole;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

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

        // Route middleware normally guards only the initial page load; making
        // these persistent re-applies them to every Livewire update request,
        // so a demoted admin/DBA loses component actions immediately.
        Livewire::addPersistentMiddleware([EnsureAdmin::class, EnsureTeamRole::class]);

        // All component actions (approve, execute, CRUD) flow through the one
        // Livewire update endpoint — give it its own rate limit.
        RateLimiter::for('livewire', function (Request $request) {
            return Limit::perMinute(180)->by($request->user()?->id ?: $request->ip());
        });

        // Reuse Livewire's own update path so this replaces the default route
        // in place (rather than adding a second, un-throttled endpoint).
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post(EndpointResolver::updatePath(), $handle)
                ->middleware(['web', 'throttle:livewire']);
        });
    }
}
