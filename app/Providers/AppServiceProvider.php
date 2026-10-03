<?php

namespace App\Providers;

use App\Database\ReadOnlyAwareLostConnectionDetector;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureTeamRole;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Set by `queue:listen` for the `queue:work --once` children it spawns, so
     * the listener warns once instead of every child warning on every poll.
     */
    private const QUEUE_LISTENER_CHILD_ENV = 'QUERYPROXY_QUEUE_LISTENER_CHILD';

    /**
     * Whether this process already ran the queue timing check.
     */
    private bool $queueTimingChecked = false;

    public function register(): void
    {
        // Wraps whatever detector is bound (the framework's, registered by
        // DatabaseServiceProvider): a read-only error must surface, not trigger
        // a reconnect and a silent retry of the write on a fresh, writable session.
        $this->app->extend(LostConnectionDetectorContract::class, function (LostConnectionDetectorContract $detector) {
            return new ReadOnlyAwareLostConnectionDetector($detector);
        });
    }

    public function boot(): void
    {
        // Trusted proxies live here rather than in bootstrap/app.php because that
        // middleware callback runs before .env is loaded. Providers boot before the
        // middleware pipeline, so TrustProxies still picks this up for every request.
        // Empty (the default) means no proxy is trusted at all: X-Forwarded-For is
        // ignored and $request->ip() stays the real peer address, which is what the
        // login/2FA throttles and the audit trail key off. The header mask is set in
        // bootstrap/app.php.
        if ($proxies = $this->trustedProxies()) {
            TrustProxies::at($proxies);
        }

        // Checked when a queue worker starts — the only process the timing
        // matters for — rather than on every request or scheduler tick.
        // WorkerStarting covers the `queue:work` daemon; `queue:listen` and
        // `queue:work --once` never raise it, so they are caught at command start.
        Event::listen(WorkerStarting::class, function (WorkerStarting $event): void {
            $this->checkQueueTimingOnce((string) $event->connectionName);
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->checkQueueTimingForCommand($event);
        });

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

    /**
     * Run the timing check for `queue:listen` and `queue:work --once` starts.
     *
     * The `queue:work --once` children of a listener are skipped: the listener
     * already warned for the same connection, and it spawns one child per
     * poll, which would otherwise repeat the warning every few seconds.
     */
    private function checkQueueTimingForCommand(CommandStarting $event): void
    {
        $input = $event->input;

        $isListen = $event->command === 'queue:listen';
        $isOnce = $event->command === 'queue:work'
            && $input->hasOption('once')
            && (bool) $input->getOption('once');

        if (! $isListen && ! $isOnce) {
            return;
        }

        if ($isOnce && $this->isQueueListenerChild()) {
            return;
        }

        $connection = $input->hasArgument('connection') ? $input->getArgument('connection') : null;

        $this->checkQueueTimingOnce(
            is_string($connection) && $connection !== '' ? $connection : (string) config('queue.default')
        );

        if ($isListen) {
            // Symfony Process hands the children this process's environment.
            putenv(self::QUEUE_LISTENER_CHILD_ENV.'=1');
            $_SERVER[self::QUEUE_LISTENER_CHILD_ENV] = $_ENV[self::QUEUE_LISTENER_CHILD_ENV] = '1';
        }
    }

    private function isQueueListenerChild(): bool
    {
        $value = $_SERVER[self::QUEUE_LISTENER_CHILD_ENV] ?? getenv(self::QUEUE_LISTENER_CHILD_ENV);

        return $value === '1';
    }

    /**
     * Run the timing check at most once per process, whichever start event
     * reaches it first.
     */
    private function checkQueueTimingOnce(string $connection): void
    {
        if ($this->queueTimingChecked) {
            return;
        }

        $this->queueTimingChecked = true;

        $this->warnOnUnsafeQueueTiming($connection);
    }

    /**
     * Warn when the active queue would re-reserve a job before a query may finish.
     *
     * With retry_after <= execution_timeout a slow query is handed out again
     * (or failed, with tries=1) while it is still running. config/queue.php
     * derives a safe default, so this only fires on an explicit, too-low
     * *_QUEUE_RETRY_AFTER. Runs once per `queue:work` / `queue:listen` start
     * for the connection that worker consumes; config reads only, no I/O.
     */
    private function warnOnUnsafeQueueTiming(string $connection): void
    {
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        if ($retryAfter === null) {
            return; // sync, sqs, ... have no re-reservation window
        }

        $executionTimeout = (int) config('queryproxy.execution_timeout', 300);

        if ((int) $retryAfter <= $executionTimeout) {
            Log::warning('Queue retry_after does not exceed the query execution timeout; long queries may be re-run or marked failed while still running.', [
                'queue_connection' => $connection,
                'retry_after' => (int) $retryAfter,
                'execution_timeout' => $executionTimeout,
            ]);
        }
    }

    /**
     * Reverse proxies allowed to set X-Forwarded-*, from configuration.
     *
     * Read through config() rather than env(): the container caches the
     * configuration on boot, and a cached config means .env is never loaded.
     *
     * @return array<int, string>
     */
    private function trustedProxies(): array
    {
        /** @var array<int, string> $proxies */
        $proxies = (array) config('queryproxy.trusted_proxies', []);

        return array_values($proxies);
    }
}
