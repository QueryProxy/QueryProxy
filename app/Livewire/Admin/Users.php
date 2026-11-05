<?php

namespace App\Livewire\Admin;

use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\SlackIdentitySuggestion;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Chat\SlackWorkspaceBinder;
use App\Services\Users\UserLifecycle;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Users')]
class Users extends Component
{
    /** Rows of the Slack suggestions panel; the rest wait their turn. */
    private const SUGGESTION_LIMIT = 50;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $isAdmin = false;

    /**
     * Slack workspace a typed Slack member id belongs to. Left empty, the one
     * workspace the Slack app is installed in is used.
     */
    public string $slackWorkspaceId = '';

    /**
     * Route middleware only guards the initial page load; every action must
     * re-check because Livewire updates arrive on a separate endpoint and the
     * actor's privileges may have been revoked since the page was served.
     */
    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function createUser(): void
    {
        $this->assertAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'unique:users,email,NULL,id,deleted_at,NULL',
                // A soft deleted account keeps its address reserved (the unique
                // index still covers it) but is not listed; say why.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (User::onlyTrashed()->where('email', $value)->exists()) {
                        $fail('A deleted account still holds this address, so it cannot be reused.');
                    }
                },
            ],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $password = $this->password !== '' ? $this->password : Str::password(16);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $password,
            'is_admin' => $this->isAdmin,
        ]);

        audit()->record('user.created', metadata: [
            'user' => $user->email,
            'is_admin' => $user->is_admin,
        ]);

        if ($this->password === '') {
            // Flash instead of a public property: component state round-trips
            // through every subsequent Livewire request, a flash shows once.
            session()->flash('generated_password', $password);
        }

        $this->reset('name', 'email', 'password', 'isAdmin');
        session()->flash('status', "User \"{$user->email}\" created.");
    }

    public function toggleAdmin(int $userId): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'You cannot change your own admin status.');

            return;
        }

        $user->update(['is_admin' => ! $user->is_admin]);

        audit()->record('user.admin_toggled', metadata: [
            'user' => $user->email,
            'is_admin' => $user->is_admin,
        ]);
    }

    /** Generate a fresh password for a locked-out user; shown once, like on creation. */
    public function resetPassword(int $userId): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'Change your own password from your profile page.');

            return;
        }

        // An account tied to the identity provider gets no local password,
        // whatever QUERYPROXY_LOCAL_LOGIN says: it would outlive the
        // deprovisioning there (ADR-016 Amendment 2026-10-26, ADR-023).
        if ($user->isSsoUser()) {
            $this->addError('resetPassword', 'This user signs in through single sign-on; their password is managed at the identity provider.');

            return;
        }

        $password = Str::password(16);
        $user->update([
            'password' => $password,
            'remember_token' => Str::random(60),
        ]);

        // A password reset must also end the user's live sessions — otherwise
        // an attacker holding a stolen session simply keeps it.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        audit()->record('user.password_reset', metadata: ['user' => $user->email]);

        session()->flash('generated_password', $password);
        session()->flash('status', "New password generated for \"{$user->email}\".");
    }

    /** Unlock a user who lost their authenticator: clears secret + recovery codes. */
    public function resetTwoFactor(int $userId, TwoFactorService $twoFactor): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        $twoFactor->disable($user);

        audit()->record('user.two_factor_reset', metadata: ['user' => $user->email]);

        session()->flash('status', "Two-factor authentication reset for \"{$user->email}\".");
    }

    /** Block sign-in and end live sessions. Pending requests stay pending. */
    public function suspendUser(int $userId, UserLifecycle $lifecycle): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'You cannot suspend your own account.');

            return;
        }

        $lifecycle->suspend($user);

        session()->flash('status', "User \"{$user->email}\" suspended.");
    }

    public function unsuspendUser(int $userId, UserLifecycle $lifecycle): void
    {
        $this->assertAdmin();

        $lifecycle->unsuspend(User::findOrFail($userId));
    }

    /**
     * Soft delete. The user's pending requests are cancelled in the same
     * transaction; their request history stays.
     */
    public function deleteUser(int $userId, UserLifecycle $lifecycle): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->addError('email', 'You cannot delete your own account.');

            return;
        }

        $lifecycle->delete($user);
    }

    public function updateSlackId(int $userId, string $slackId): void
    {
        $this->updateChatId($userId, 'slack', $slackId);
    }

    public function updateTeamsId(int $userId, string $teamsId): void
    {
        $this->updateChatId($userId, 'teams', $teamsId);
    }

    private function updateChatId(int $userId, string $provider, string $externalId): void
    {
        $this->assertAdmin();

        abort_unless(in_array($provider, ['slack', 'teams'], true), 422);

        // The previous attempt's message must not outlive a successful one.
        $this->resetErrorBag('chat_id');

        $workspaceId = null;

        // A Slack member id only means something inside its workspace; Teams
        // identities carry none.
        if ($provider === 'slack' && trim($externalId) !== '') {
            $user = User::findOrFail($userId);
            $workspaceId = $this->resolveSlackWorkspace($user);

            if ($workspaceId === null) {
                $this->addError('chat_id', 'Choose the Slack workspace of this ID first: enter its workspace ID (T0123ABC) above the table.');

                return;
            }
        }

        $error = $this->linkChatIdentity(User::findOrFail($userId), $provider, $externalId, workspaceId: $workspaceId);

        if ($error !== null) {
            $this->addError('chat_id', $error);
        }
    }

    /**
     * The workspace a hand-typed Slack member id is linked in: the one entered
     * on the page, else the single workspace the app is installed in for the
     * user's own teams. Null when neither names exactly one.
     */
    private function resolveSlackWorkspace(User $user): ?string
    {
        if (trim($this->slackWorkspaceId) !== '') {
            return SlackWorkspaceBinder::normalize($this->slackWorkspaceId);
        }

        $installed = ChatIntegration::query()
            ->where('provider', 'slack')
            ->whereNotNull('bot_token')
            ->whereNotNull('slack_team_id')
            ->whereIn('team_id', $user->teams()->select('teams.id'))
            ->distinct()
            ->pluck('slack_team_id');

        return $installed->count() === 1 ? $installed->first() : null;
    }

    /**
     * Bind every Slack identity that has no workspace to the workspace entered
     * on the page. The id must be typed: nothing is inferred from the
     * installations, because the identities left unbound are exactly those the
     * installations could not name. Until this runs they match nothing.
     */
    public function bindUnboundSlackIdentities(): void
    {
        $this->assertAdmin();
        $this->resetErrorBag('chat_id');

        $workspaceId = SlackWorkspaceBinder::normalize($this->slackWorkspaceId);

        if ($workspaceId === null) {
            $this->addError('chat_id', 'Enter the Slack workspace ID (T0123ABC) above the table first; it is never filled in for you.');

            return;
        }

        $bound = app(SlackWorkspaceBinder::class)->bind($workspaceId, auth()->user());

        session()->flash('status', "{$bound} Slack ".($bound === 1 ? 'identity' : 'identities')." bound to workspace {$workspaceId}.");
    }

    /**
     * Link (or, for an empty id, unlink) a chat identity. Returns the reason
     * when the id cannot be linked, so each caller shows it where it belongs.
     *
     * @param  array<string, mixed>  $auditContext  extra audit metadata, e.g. where the id came from
     * @param  string|null  $workspaceId  the Slack workspace of a Slack id (stored as `team_id`); null for Teams
     */
    private function linkChatIdentity(User $user, string $provider, string $externalId, array $auditContext = [], ?string $workspaceId = null): ?string
    {
        $externalId = trim($externalId);

        if ($externalId === '') {
            ChatIdentity::where('user_id', $user->id)->where('provider', $provider)->delete();
            audit()->record('chat_identity.removed', metadata: ['user' => $user->email, 'provider' => $provider]);

            return null;
        }

        $taken = ChatIdentity::where('provider', $provider)
            ->where('external_id', $externalId)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($taken) {
            return ucfirst($provider)." ID {$externalId} is already linked to another user.";
        }

        ChatIdentity::updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
            ['external_id' => $externalId, 'team_id' => $provider === 'slack' ? $workspaceId : null],
        );

        audit()->record('chat_identity.linked', metadata: [
            'user' => $user->email, 'provider' => $provider, 'external_id' => $externalId,
        ] + ($provider === 'slack' ? ['slack_team_id' => $workspaceId] : []) + $auditContext);

        if ($provider === 'slack') {
            // Whatever way the id got here, the user no longer needs a proposal.
            SlackIdentitySuggestion::where('user_id', $user->id)->delete();
        }

        return null;
    }

    /**
     * Confirm a Slack member that users.lookupByEmail matched to a user. It goes
     * through the same linking path as typing the id by hand, so the uniqueness
     * check and the audit entry are identical; the e-mail match only saved the
     * admin from looking the id up.
     */
    public function approveSlackSuggestion(int $suggestionId): void
    {
        $this->assertAdmin();
        $this->resetErrorBag('suggestions');

        $suggestion = SlackIdentitySuggestion::query()->pending()->find($suggestionId);

        if (! $suggestion) {
            $this->addError('suggestions', 'This suggestion no longer exists.');

            return;
        }

        $user = User::query()->find($suggestion->user_id);

        // A page left open can offer a suggestion whose ground has moved: the
        // user is gone, the workspace was disconnected, or they were linked by hand.
        $stillInstalled = ChatIntegration::query()
            ->where('team_id', $suggestion->team_id)
            ->where('slack_team_id', $suggestion->slack_team_id)
            ->whereNotNull('bot_token')
            ->exists();

        // An identity without a workspace (made before they were bound) does not
        // count: confirming the suggestion binds it.
        $alreadyLinked = $user && ChatIdentity::where('user_id', $user->id)->where('provider', 'slack')->whereNotNull('team_id')->exists();

        // The match was made on an address the user may no longer have (SCIM,
        // OIDC or an admin changed it); the e-mail is the only evidence there is.
        $addressChanged = $user && strcasecmp($user->email, $suggestion->matched_email) !== 0;

        if (! $user || ! $stillInstalled || $alreadyLinked || $addressChanged) {
            $suggestion->delete();
            $this->addError('suggestions', 'This suggestion is out of date and was removed.');

            return;
        }

        $error = $this->linkChatIdentity($user, 'slack', $suggestion->slack_user_id, [
            'source' => 'slack_suggestion',
            'slack_team_id' => $suggestion->slack_team_id,
        ], $suggestion->slack_team_id);

        if ($error !== null) {
            $this->addError('suggestions', $error);

            return;
        }

        session()->flash('status', "Slack ID {$suggestion->slack_user_id} linked to \"{$user->email}\".");
    }

    /**
     * Rejecting keeps the row, marked as dismissed, so the weekly scan does not
     * propose the same Slack member for this user again.
     */
    public function rejectSlackSuggestion(int $suggestionId): void
    {
        $this->assertAdmin();
        $this->resetErrorBag('suggestions');

        SlackIdentitySuggestion::query()
            ->pending()
            ->whereKey($suggestionId)
            ->update(['status' => SlackIdentitySuggestion::DISMISSED, 'dismissed_at' => now()]);
    }

    /**
     * Pending proposals that can still be acted on: the user exists, still has
     * the address the match was made on, has no Slack ID, nobody else holds the proposed one, and the app is still
     * installed for the workspace they came from.
     */
    private function slackSuggestions(): Builder
    {
        return SlackIdentitySuggestion::query()
            ->pending()
            ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(users.email) = LOWER(slack_identity_suggestions.matched_email)'))
            ->whereNotIn('user_id', ChatIdentity::where('provider', 'slack')->whereNotNull('team_id')->select('user_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('chat_identities')
                ->where('chat_identities.provider', 'slack')
                ->whereColumn('chat_identities.external_id', 'slack_identity_suggestions.slack_user_id')
                ->whereColumn('chat_identities.user_id', '!=', 'slack_identity_suggestions.user_id'))
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('chat_integrations')
                ->whereColumn('chat_integrations.team_id', 'slack_identity_suggestions.team_id')
                ->whereColumn('chat_integrations.slack_team_id', 'slack_identity_suggestions.slack_team_id')
                ->whereNotNull('chat_integrations.bot_token'));
    }

    public function render()
    {
        return view('livewire.admin.users', [
            'users' => User::withCount('teams')->orderBy('name')->get(),
            'slackIds' => ChatIdentity::where('provider', 'slack')->pluck('external_id', 'user_id'),
            'bindTarget' => SlackWorkspaceBinder::normalize($this->slackWorkspaceId),
            'unboundSlackUserIds' => ChatIdentity::where('provider', 'slack')->whereNull('team_id')->pluck('user_id')->flip(),
            'teamsIds' => ChatIdentity::where('provider', 'teams')->pluck('external_id', 'user_id'),
            'suggestions' => $this->slackSuggestions()->with(['user', 'team'])->orderBy('id')->limit(self::SUGGESTION_LIMIT)->get(),
            'suggestionTotal' => $this->slackSuggestions()->count(),
            'workspaceNames' => ChatIntegration::query()->whereNotNull('bot_token')->pluck('slack_team_name', 'slack_team_id'),
        ]);
    }
}
