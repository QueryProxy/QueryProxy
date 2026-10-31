<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
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

    /** Session key of the time (Unix seconds) the session was last opened through SSO (ADR-024). */
    public const SESSION_AT_KEY = 'auth.via_oidc_at';

    /** How far the provider's clock may trail ours when `auth_time` is compared with the start of a confirmation. */
    private const REAUTH_CLOCK_SKEW_SECONDS = 30;

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

    /**
     * Sign in through SSO again while already signed in, to confirm an
     * action that needs a fresh sign-in (creating an API token, ADR-024). The
     * provider is asked to authenticate the user anew, and the callback
     * accepts only the same account.
     */
    public function reauthenticate(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless($oidc->isConfigured(), 404);

        $flow = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => OidcClient::newCodeVerifier(),
            'started_at' => now()->getTimestamp(),
            'reauth_user_id' => $request->user()->id,
        ];

        try {
            $url = $oidc->authorizationUrl($flow['state'], $flow['nonce'], $flow['verifier'], route('oidc.callback'), forceLogin: true);
        } catch (OidcException $e) {
            return redirect()->route('profile')->withErrors(['sso' => $e->userMessage()]);
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

        // Signed-in users used to be turned away by the guest middleware; only
        // an attempt started by reauthenticate() may arrive here signed in.
        $pending = $request->session()->get(self::FLOW_KEY);
        $reauthFor = is_array($pending) ? ($pending['reauth_user_id'] ?? null) : null;

        if (Auth::check() && $reauthFor === null) {
            return redirect()->route('dashboard');
        }

        // One attempt, one callback: whatever happens next, it cannot be replayed.
        $flow = $request->session()->pull(self::FLOW_KEY);

        try {
            $claims = $this->completeFlow($request, $oidc, $flow);

            $issuer = (string) $claims['iss'];

            if ($reauthFor !== null) {
                // Before anything is provisioned or linked: the sign-in must be the identity this session belongs to, and fresh.
                $this->assertConfirmsSignedInUser($request->user(), (int) $reauthFor, $claims, $issuer, (int) $flow['started_at']);
            }

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
        $request->session()->put(self::SESSION_AT_KEY, now()->getTimestamp());

        audit()->record(AuditEvent::AuthSsoLogin, metadata: [
            'issuer' => $issuer,
            'groups_claim_present' => $claimPresent,
        ]);

        if ($reauthFor !== null) {
            return redirect()->to(route('profile').'#api-tokens');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * A confirmation counts only if the provider says it is the signed-in
     * user (same issuer and subject) and that they authenticated after the
     * attempt began. `prompt=login` and `max_age=0` ask for that, but a
     * provider may ignore both, so the `auth_time` claim has the last word.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws OidcException
     */
    private function assertConfirmsSignedInUser(?User $current, int $expectedId, array $claims, string $issuer, int $startedAt): void
    {
        if ($current === null
            || $current->id !== $expectedId
            || ! $current->isSsoUser()
            || $current->external_issuer !== $issuer
            || $current->external_id !== (string) $claims['sub']) {
            throw new OidcException('The account that signed in is not the one that asked to confirm.', __('That sign-in belongs to a different account than the one you are signed in as.'));
        }

        $authTime = $claims['auth_time'] ?? null;

        if (! is_int($authTime) && ! (is_string($authTime) && ctype_digit($authTime))) {
            throw new OidcException('The ID token has no auth_time, so a fresh sign-in cannot be shown.', __('Your identity provider did not say when you signed in, so it cannot be a confirmation.'));
        }

        if ((int) $authTime < $startedAt - self::REAUTH_CLOCK_SKEW_SECONDS) {
            throw new OidcException('The provider reused an older sign-in (auth_time before the attempt began).', __('Your identity provider did not ask you to sign in again. Try again.'));
        }
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

        // A refused confirmation leaves the user signed in where they were.
        if (Auth::check()) {
            return redirect()->to(route('profile').'#api-tokens')->withErrors(['sso' => $e->userMessage()]);
        }

        return redirect()->route('login')->withErrors(['sso' => $e->userMessage()]);
    }
}
