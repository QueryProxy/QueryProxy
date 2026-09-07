<?php

namespace App\Http\Middleware;

use App\Enums\ChatProvider;
use App\Models\ChatIntegration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teams-style HMAC verification (ADR-004) with replay protection: the caller
 * (a Power Automate flow or custom automation) sends
 *
 *   X-QueryProxy-Timestamp: <unix seconds>
 *   Authorization: HMAC <base64(HMAC_SHA256("{timestamp}:{raw_body}", base64_decoded_secret))>
 *
 * The timestamp is part of the signed payload and must be within ±5 minutes,
 * so a captured request cannot be replayed later (mirrors the Slack v0 scheme).
 */
class VerifyTeamsHmac
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'HMAC ')) {
            abort(401, 'Missing Teams HMAC authorization.');
        }

        $timestamp = (string) $request->header('X-QueryProxy-Timestamp', '');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            abort(401, 'Missing or stale X-QueryProxy-Timestamp header.');
        }

        $provided = substr($header, 5);
        $signedPayload = $timestamp.':'.$request->getContent();

        $integration = ChatIntegration::query()
            ->enabledFor(ChatProvider::Teams)
            ->get()
            ->first(function (ChatIntegration $integration) use ($signedPayload, $provided) {
                $secret = base64_decode($integration->signing_secret, true) ?: $integration->signing_secret;
                $expected = base64_encode(hash_hmac('sha256', $signedPayload, $secret, true));

                return hash_equals($expected, $provided);
            });

        if (! $integration) {
            abort(401, 'Invalid Teams HMAC signature.');
        }

        $request->attributes->set('chat_integration', $integration);

        return $next($request);
    }
}
