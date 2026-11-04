<?php

namespace App\Http\Middleware;

use App\Enums\ChatProvider;
use App\Models\ChatIntegration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slack request signing (ADR-004): X-Slack-Signature is
 * 'v0=' . HMAC_SHA256("v0:{timestamp}:{raw_body}", signing_secret).
 *
 * Two secrets can sign a request:
 *
 *  - the QueryProxy Slack app's signing secret from the environment
 *    (ADR-027). It proves the request came from Slack, not which team it is
 *    for; the request is marked 'slack_app_verified' and the controller finds
 *    the team from the payload (workspace and request);
 *  - the signing secret stored per team for the incoming-webhook mode; the
 *    matching integration is attached as 'chat_integration'.
 */
class VerifySlackSignature
{
    public const REPLAY_WINDOW_SECONDS = 300;

    /**
     * The Slack workspace a request names: `team_id` of a slash command or an
     * Events API body, `team.id` of an interactivity payload. Only meaningful
     * for a request whose signature was verified.
     */
    public static function workspaceId(Request $request): ?string
    {
        $candidate = $request->input('team_id');

        if (! is_string($candidate) || $candidate === '') {
            $payload = json_decode((string) $request->input('payload'), true);
            $candidate = is_array($payload) ? ($payload['team']['id'] ?? null) : null;
        }

        return is_string($candidate) && $candidate !== '' ? mb_substr($candidate, 0, 64) : null;
    }

    /**
     * Slack calls from a few shared addresses, so the budget is per workspace
     * (QUERYPROXY_SLACK_RATE_LIMIT), counted only for requests whose
     * signature was verified: a forged body naming a workspace cannot spend
     * its budget. Done here, not as a route throttle, because the router runs
     * throttle middleware ahead of this one.
     */
    private function throttleWorkspace(Request $request): void
    {
        $key = 'slack-workspace:'.(self::workspaceId($request) ?? $request->ip());
        $limit = (int) config('queryproxy.slack_rate_limit', 300);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            abort(429, 'Too many Slack requests.', ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Slack-Signature', '');
        $timestamp = $request->header('X-Slack-Request-Timestamp', '');

        if ($signature === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            abort(401, 'Missing Slack signature headers.');
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            abort(401, 'Stale Slack request (possible replay).');
        }

        $base = 'v0:'.$timestamp.':'.$request->getContent();

        $appSecret = (string) config('services.slack.app.signing_secret');
        $appSigned = $appSecret !== '' && hash_equals('v0='.hash_hmac('sha256', $base, $appSecret), $signature);

        // Integrations whose own signing secret verifies this request. Looked
        // up for an app-signed request too: an operator who turns the Slack
        // app of a webhook-mode team into the QueryProxy app keeps the same
        // signing secret, and that team's buttons must go on working.
        $signed = ChatIntegration::query()
            ->enabledFor(ChatProvider::Slack)
            ->whereNotNull('signing_secret')
            ->get()
            ->filter(function (ChatIntegration $integration) use ($base, $signature) {
                $expected = 'v0='.hash_hmac('sha256', $base, $integration->signing_secret);

                return hash_equals($expected, $signature);
            })
            ->values();

        if ($appSigned) {
            $this->throttleWorkspace($request);

            $request->attributes->set('slack_app_verified', true);
            $request->attributes->set('slack_signed_integrations', $signed);

            return $next($request);
        }

        $integration = $signed->first();

        if (! $integration) {
            abort(401, 'Invalid Slack signature.');
        }

        $this->throttleWorkspace($request);

        $request->attributes->set('chat_integration', $integration);

        return $next($request);
    }
}
