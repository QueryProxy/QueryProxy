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
        // The app is designed to sit behind a TLS-terminating reverse proxy
        // (docker-compose publishes plain HTTP). Trusting the proxy lets
        // Laravel see HTTPS and the real client IP (recorded in audit logs).
        // Narrow this to your proxy's address when it isn't on a private network.
        $middleware->trustProxies(at: '*');

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
