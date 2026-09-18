<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureTeamRole;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifySlackSignature;
use App\Http\Middleware\VerifyTeamsHmac;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app may sit behind a TLS-terminating reverse proxy (docker-compose
        // publishes plain HTTP), but no proxy is trusted by default: trusting
        // every proxy let any client forge X-Forwarded-For, which handed out
        // unlimited login/2FA attempts (the throttle keys are per-IP) and forged
        // the audit trail's client IP. The trusted list comes from TRUSTED_PROXIES
        // and is applied in AppServiceProvider::boot() — this callback runs while
        // the kernel is being resolved, before .env is loaded, so env() is empty here.
        //
        // The header mask deliberately drops X-Forwarded-Host — a forged host
        // rewrote password-reset links in outgoing mail. Laravel's default also
        // lists HEADER_X_FORWARDED_AWS_ELB, which in Symfony is only an alias for
        // FOR|PROTO|PORT; spelling the four headers out leaves it behind.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // Second layer against host-header poisoning: only APP_URL's host (and its
        // www. sibling) may set the request host, so absolute URLs — signed routes,
        // password-reset mails — always point at this installation. The closure is
        // evaluated per request by TrustHosts::hosts(), so — unlike the body of this
        // callback — it sees the loaded configuration and environment. An empty or
        // unparsable APP_URL with no TRUSTED_HOSTS yields an empty list, which Symfony
        // reads as "no restriction", keeping a half-configured install bootable. Laravel
        // skips this middleware entirely in the local environment and under tests.
        //
        // An installation may legitimately answer to more than one name (a separate
        // internal hostname, a second domain). TRUSTED_HOSTS is a comma-separated list
        // of extra hostnames that are added to — never replace — APP_URL's host. It is
        // empty by default, so the default posture is unchanged. This does not weaken
        // the X-Forwarded-Host decision above: that header stays out of the trusted
        // mask, and every name listed here still has to survive Symfony's own
        // getHost() check against these patterns.
        $middleware->trustHosts(at: function (): array {
            /** @var array<int, string> $hostnames */
            $hostnames = [];

            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $bare = preg_replace('/^www\./i', '', $host);

                $hostnames[] = $bare;
                $hostnames[] = 'www.'.$bare;
            }

            foreach ((array) config('queryproxy.trusted_hosts', []) as $extra) {
                // A stray comma or a typo must not take the installation down, so
                // anything that is not a plausible hostname is dropped in silence.
                $extra = trim($extra);

                if ($extra !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $extra) === 1) {
                    $hostnames[] = $extra;
                }
            }

            return array_map(
                static fn (string $hostname): string => '^'.preg_quote($hostname).'$',
                array_values(array_unique($hostnames)),
            );
        }, subdomains: false);

        // AuthenticateSession ends a user's other sessions when their
        // password changes; SecurityHeaders adds the baseline response headers.
        $middleware->web(append: [
            AuthenticateSession::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'role' => EnsureTeamRole::class,
            '2fa.required' => RequireTwoFactor::class,
            'slack.signature' => VerifySlackSignature::class,
            'teams.hmac' => VerifyTeamsHmac::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
