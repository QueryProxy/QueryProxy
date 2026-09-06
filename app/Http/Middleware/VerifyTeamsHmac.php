<?php

namespace App\Http\Middleware;

use App\Enums\ChatProvider;
use App\Models\ChatIntegration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teams-style HMAC verification (ADR-004): the caller sends
 * 'Authorization: HMAC <base64(HMAC_SHA256(raw_body, base64_decoded_secret))>'
 * as Microsoft Teams outgoing webhooks do.
 */
class VerifyTeamsHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'HMAC ')) {
            abort(401, 'Missing Teams HMAC authorization.');
        }

        $provided = substr($header, 5);
        $body = $request->getContent();

        $integration = ChatIntegration::query()
            ->enabledFor(ChatProvider::Teams)
            ->get()
            ->first(function (ChatIntegration $integration) use ($body, $provided) {
                $secret = base64_decode($integration->signing_secret, true) ?: $integration->signing_secret;
                $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

                return hash_equals($expected, $provided);
            });

        if (! $integration) {
            abort(401, 'Invalid Teams HMAC signature.');
        }

        $request->attributes->set('chat_integration', $integration);

        return $next($request);
    }
}
