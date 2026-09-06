<?php

namespace App\Http\Middleware;

use App\Enums\ChatProvider;
use App\Models\ChatIntegration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slack request signing (ADR-004): X-Slack-Signature is
 * 'v0=' . HMAC_SHA256("v0:{timestamp}:{raw_body}", signing_secret).
 *
 * The signing secret is stored per team; the matching integration is attached
 * to the request as 'chat_integration'.
 */
class VerifySlackSignature
{
    public const REPLAY_WINDOW_SECONDS = 300;

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

        $integration = ChatIntegration::query()
            ->enabledFor(ChatProvider::Slack)
            ->get()
            ->first(function (ChatIntegration $integration) use ($base, $signature) {
                $expected = 'v0='.hash_hmac('sha256', $base, $integration->signing_secret);

                return hash_equals($expected, $signature);
            });

        if (! $integration) {
            abort(401, 'Invalid Slack signature.');
        }

        $request->attributes->set('chat_integration', $integration);

        return $next($request);
    }
}
