<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\OidcClient;
use App\Services\Auth\OidcException;
use App\Services\Auth\OidcRoleSync;
use App\Services\Auth\OidcUserProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sign-in through the OpenID Connect provider (ADR-023): authorization code
 * flow with PKCE, `state` and `nonce`. The pending attempt lives in the
 * session and is consumed by the first callback, valid or not.
 */
class OidcController extends Controller
{
    /** Session key of the attempt in progress. */
    public const FLOW_KEY = 'oidc.flow';

    /** Session flag set when the session was opened through SSO (see RequireTwoFactor). */
    public const SESSION_FLAG = 'auth.via_oidc';

    /** Minutes the user has to finish the sign-in at the provider. */
    private const FLOW_TTL_MINUTES = 10;

    public function redirect(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless($oidc->isConfigured(), 404);

        $flow = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => OidcClient::newCodeVerifier(),
            'started_at' => now()->getTimestamp(),
        ];

        try {
            $url = $oidc->authorizationUrl($flow['state'], $flow['nonce'], $flow['verifier'], route('oidc.callback'));
        } catch (OidcException $e) {
            return $this->refuse($e);
        }

        $request->session()->put(self::FLOW_KEY, $flow);

        return redirect()->away($url);
    }

    public function callback(
        Request $request,
        OidcClient $oidc,
        OidcUserProvisioner $provisioner,
        OidcRoleSync $roleSync,
    ): RedirectResponse {
        abort_unless($oidc->isConfigured(), 404);

        // One attempt, one callback: whatever happens next, it cannot be replayed.
        $flow = $request->session()->pull(self::FLOW_KEY);

        try {
            $claims = $this->completeFlow($request, $oidc, $flow);

            $issuer = (string) $claims['iss'];

            // With a role map the groups decide who gets which role. A token
            // that carries no group claim at all (a mapper or scope gone
            // wrong, or the provider's "groups overage": a reference to fetch
            // them instead of the list) says nothing about the user's groups,
            // so the sign-in is refused and nothing is touched, rather than
            // read as "in no group" and every mapped role revoked. An empty
            // list is a statement, and is synced like any other.
            [$groups, $claimPresent] = $roleSync->groupsFrom($claims);

            if (! $claimPresent && $roleSync->roleMap() !== []) {
                $overage = isset($claims['_claim_names']) || isset($claims['hasgroups']);

                Log::warning('OIDC sign-in refused: the ID token has no group claim while QUERYPROXY_OIDC_ROLE_MAP is set.', [
                    'sub' => $claims['sub'],
                    'group_claim' => config('queryproxy.oidc.group_claim'),
                    'groups_overage' => $overage,
                ]);

                throw new OidcException('No group claim in the token.', __('Single sign-on could not read your groups from the identity provider. Contact your administrator.'));
            }

            $user = $provisioner->resolve($claims, $issuer);

            $roleSync->sync($user, $groups);
        } catch (OidcException $e) {
            return $this->refuse($e);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_FLAG, true);

        audit()->record(AuditEvent::AuthSsoLogin, metadata: [
            'issuer' => $issuer,
            'groups_claim_present' => $claimPresent,
        ]);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Checks the callback against the stored attempt and returns the claims.
     *
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    private function completeFlow(Request $request, OidcClient $oidc, mixed $flow): array
    {
        if (! is_array($flow) || ! isset($flow['state'], $flow['nonce'], $flow['verifier'], $flow['started_at'])) {
            throw new OidcException('The callback has no sign-in attempt in progress.');
        }

        if (now()->getTimestamp() - (int) $flow['started_at'] > self::FLOW_TTL_MINUTES * 60) {
            throw new OidcException('The sign-in attempt expired.');
        }

        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            throw new OidcException('The provider returned an error: '.Str::limit(preg_replace('/[^A-Za-z0-9_.-]/', '', $error), 64, ''));
        }

        $state = $request->query('state');
        if (! is_string($state) || ! hash_equals($flow['state'], $state)) {
            throw new OidcException('The state parameter does not match the sign-in attempt.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 4096) {
            throw new OidcException('The callback carries no authorization code.');
        }

        return $oidc->fetchClaims(
            $code,
            $flow['verifier'],
            $flow['nonce'],
            route('oidc.callback'),
            (string) config('queryproxy.oidc.group_claim'),
        );
    }

    private function refuse(OidcException $e): RedirectResponse
    {
        Log::warning('OIDC sign-in refused: '.$e->getMessage());

        return redirect()->route('login')->withErrors(['sso' => $e->userMessage()]);
    }
}
