<?php

namespace App\Enums;

/**
 * Catalog of audit event names. Every `audit()->record()` call must use a
 * name listed here (a test enforces it). To add an event: add a case, give
 * it a description and a category, and record it; nothing else to register.
 *
 * Names are stored in audit records, so a case value is never renamed.
 */
enum AuditEvent: string
{
    // Audit chain
    case AuditCheckpoint = 'audit.checkpoint';

    // Authentication
    case AuthLogin = 'auth.login';
    case AuthLogout = 'auth.logout';
    case AuthPasswordReset = 'auth.password_reset';
    case AuthSsoLogin = 'auth.sso_login';
    case AuthSsoRefused = 'auth.sso_refused';

    // Users
    case UserCreated = 'user.created';
    case UserDeleted = 'user.deleted';
    case UserSuspended = 'user.suspended';
    case UserUnsuspended = 'user.unsuspended';
    case UserAdminToggled = 'user.admin_toggled';
    case UserNameChanged = 'user.name_changed';
    case UserPasswordChanged = 'user.password_changed';
    case UserPasswordReset = 'user.password_reset';
    case UserTwoFactorEnabled = 'user.two_factor_enabled';
    case UserTwoFactorDisabled = 'user.two_factor_disabled';
    case UserTwoFactorReset = 'user.two_factor_reset';
    case UserSsoProvisioned = 'user.sso_provisioned';
    case UserSsoLinked = 'user.sso_linked';
    case UserRoleSynced = 'user.role_synced';

    // Teams
    case TeamCreated = 'team.created';
    case TeamDeleted = 'team.deleted';
    case TeamMemberAdded = 'team.member_added';
    case TeamMemberRemoved = 'team.member_removed';
    case TeamMemberRoleChanged = 'team.member_role_changed';
    case TeamSecretPrefixesChanged = 'team.secret_prefixes_changed';

    // Connections
    case ConnectionCreated = 'connection.created';
    case ConnectionUpdated = 'connection.updated';
    case ConnectionDeleted = 'connection.deleted';
    case ConnectionTested = 'connection.tested';
    case ConnectionGrantAdded = 'connection.grant_added';
    case ConnectionGrantRevoked = 'connection.grant_revoked';

    // External secret references (ADR-028)
    case SecretResolveFailed = 'secret.resolve_failed';

    // Time-bound grants (ADR-025)
    case GrantRequested = 'grant.requested';
    case GrantApproved = 'grant.approved';
    case GrantRejected = 'grant.rejected';
    case GrantExpired = 'grant.expired';

    // API tokens (ADR-024)
    case TokenCreated = 'token.created';
    case TokenRevoked = 'token.revoked';

    // SCIM provisioning (ADR-026)
    case ScimUserProvisioned = 'scim.user_provisioned';
    case ScimUserUpdated = 'scim.user_updated';
    case ScimUserSuspended = 'scim.user_suspended';
    case ScimUserUnsuspended = 'scim.user_unsuspended';
    case ScimGroupSynced = 'scim.group_synced';
    case ScimTokenCreated = 'scim.token_created';
    case ScimTokenRevoked = 'scim.token_revoked';

    // Masking
    case MaskingRuleCreated = 'masking_rule.created';
    case MaskingRuleUpdated = 'masking_rule.updated';
    case MaskingRuleToggled = 'masking_rule.toggled';
    case MaskingRuleDeleted = 'masking_rule.deleted';
    case MaskingRuleDefaultsAdded = 'masking_rule.defaults_added';
    case MaskingRuleTurkeyPackAdded = 'masking_rule.tr_rules_added';
    case MaskingKeyRotated = 'masking_key.rotated';
    case ErrorMessagesMasked = 'error_messages.masked';

    // ChatOps
    case ChatIntegrationSaved = 'chat_integration.saved';
    case ChatIntegrationSecretRotated = 'chat_integration.secret_rotated';
    case ChatIntegrationRemoved = 'chat_integration.removed';
    case ChatIdentityLinked = 'chat_identity.linked';
    case ChatIdentityRemoved = 'chat_identity.removed';

    // Slack app (ADR-027)
    case SlackInstalled = 'slack.installed';
    case SlackUninstalled = 'slack.uninstalled';

    // Query requests
    case RequestSubmitted = 'request.submitted';
    case RequestApproved = 'request.approved';
    case RequestRejected = 'request.rejected';
    case RequestCancelled = 'request.cancelled';
    case RequestExecutionStarted = 'request.execution_started';
    case RequestExecutionCompleted = 'request.execution_completed';
    case RequestExecutionFailed = 'request.execution_failed';
    case RequestExecutionDeferred = 'request.execution_deferred';
    case ExecutionLateCompletion = 'execution.late_completion';
    case ExecutionCancelled = 'execution.cancelled';
    case ResultDownloaded = 'result.downloaded';

    // Schema explorer (ADR-029)
    case SchemaRead = 'schema.read';

    public function description(): string
    {
        return match ($this) {
            self::AuditCheckpoint => 'Daily checkpoint: latest record id and chain hash at that point.',

            self::AuthLogin => 'A user signed in (metadata.two_factor is set for the second factor).',
            self::AuthLogout => 'A user signed out.',
            self::AuthPasswordReset => 'A user reset their own password through the reset link.',
            self::AuthSsoLogin => 'A user signed in through the OpenID Connect identity provider (metadata.issuer; metadata.groups_claim_present tells whether the provider sent the group claim).',
            self::AuthSsoRefused => 'An SSO sign-in was refused because the provider does not assert the e-mail address as verified (metadata.reason = link_unverified_email | provision_unverified_email, metadata.user, metadata.issuer). No account was created or linked.',

            self::UserCreated => 'An administrator created a user.',
            self::UserDeleted => 'An administrator deleted (soft-deleted) a user; metadata.cancelled_requests lists the pending requests cancelled with it.',
            self::UserSuspended => 'An administrator suspended a user: sign-in blocked, sessions ended, chat identity disabled. Pending requests are left alone.',
            self::UserUnsuspended => 'An administrator lifted the suspension of a user.',
            self::UserAdminToggled => 'The system admin flag of a user was changed.',
            self::UserNameChanged => 'A user changed their name.',
            self::UserPasswordChanged => 'A user changed their password.',
            self::UserPasswordReset => 'An administrator reset the password of a user.',
            self::UserTwoFactorEnabled => 'A user enabled two-factor authentication.',
            self::UserTwoFactorDisabled => 'A user disabled two-factor authentication.',
            self::UserTwoFactorReset => 'An administrator reset the two-factor authentication of a user.',
            self::UserSsoProvisioned => 'A user account was created at the first SSO sign-in (just-in-time provisioning; metadata.user is the e-mail address, metadata.issuer the provider).',
            self::UserSsoLinked => 'An existing local account was linked to an identity provider subject because the provider asserted the e-mail address as verified (metadata.user, metadata.issuer).',
            self::UserRoleSynced => 'A team membership of an SSO user was added, changed or removed to match the identity provider groups (metadata.change = added|changed|removed, metadata.team, metadata.from, metadata.to). When the identity provider pushed the change through SCIM, metadata.via = scim and the record has no actor.',

            self::TeamCreated => 'A team was created.',
            self::TeamDeleted => 'A team was deleted.',
            self::TeamMemberAdded => 'A user was added to a team.',
            self::TeamMemberRemoved => 'A user was removed from a team.',
            self::TeamMemberRoleChanged => 'The team role of a member was changed.',
            self::TeamSecretPrefixesChanged => 'A system administrator changed the external secret reference prefixes a team may use for connection passwords (metadata.before and metadata.after are lists of prefixes). A team without prefixes cannot use references (ADR-028).',

            self::ConnectionCreated => 'A database connection was created.',
            self::ConnectionUpdated => 'A database connection was changed.',
            self::ConnectionDeleted => 'A database connection was deleted.',
            self::ConnectionTested => 'A database connection was tested.',
            self::ConnectionGrantAdded => 'A DBA granted a developer access to a connection (metadata.developer, metadata.access_level = read|write, metadata.expires_at is null for a permanent grant, metadata.reason).',
            self::ConnectionGrantRevoked => 'A DBA removed a developer\'s access to a connection by hand (since v0.4.0 metadata.developer, metadata.access_level, metadata.expires_at, metadata.was_expired). Grants that end by time are recorded as grant.expired.',

            self::SecretResolveFailed => 'A connection credential kept in an external secret store could not be resolved when the connection was opened or tested (metadata.field = password | read_password, metadata.scheme = vault | aws-sm | azure-kv, metadata.location is the path of the reference without its key, metadata.reason). The secret value, tokens and the store\'s answer are never recorded.',

            self::GrantRequested => 'A developer asked for time-bound access to a connection (metadata.grant_request_id, metadata.access_level, metadata.duration_minutes, metadata.reason).',
            self::GrantApproved => 'A DBA approved an access request; the grant starts now (metadata.grant_request_id, metadata.developer, metadata.access_level, metadata.expires_at, metadata.duration_minutes).',
            self::GrantRejected => 'A DBA rejected an access request (metadata.grant_request_id, metadata.developer, metadata.reason).',
            self::GrantExpired => 'A time-bound grant ended and the scheduled job removed it (actor system; metadata.developer, metadata.access_level, metadata.expires_at). Access itself ended at expires_at; this record is written when the job runs.',

            self::TokenCreated => 'A user created a personal access token for the REST API (metadata.token_id, metadata.name, metadata.scopes, metadata.expires_at). The token value is never recorded.',
            self::TokenRevoked => 'A personal access token was revoked (metadata.token_id, metadata.name). When it was revoked because its owner was suspended or deleted, the actor is the administrator who did it and metadata.owner_id and metadata.reason (user_suspended | user_deleted) are set.',

            self::ScimUserProvisioned => 'The identity provider created a user through SCIM, without a local password (metadata.user, metadata.token_id; metadata.via = scim).',
            self::ScimUserUpdated => 'The identity provider changed a user through SCIM (metadata.user, metadata.changed lists the attributes: user_name, email, name, external_id).',
            self::ScimUserSuspended => 'The identity provider suspended a user through SCIM, by active=false or DELETE (metadata.user, metadata.reason = active_false | delete). Sessions and tokens end; the user is never deleted.',
            self::ScimUserUnsuspended => 'The identity provider lifted the suspension of a user through SCIM, by active=true (metadata.user).',
            self::ScimGroupSynced => 'The identity provider created, changed or deleted a group through SCIM (metadata.operation = created | updated | deleted, metadata.group, metadata.members_added, metadata.members_removed). Role changes that follow are recorded as user.role_synced with metadata.via = scim.',
            self::ScimTokenCreated => 'A SCIM token was created (metadata.token_id, metadata.name). The token value is never recorded.',
            self::ScimTokenRevoked => 'A SCIM token was revoked (metadata.token_id, metadata.name).',

            self::MaskingRuleCreated => 'A masking rule was created.',
            self::MaskingRuleUpdated => 'A masking rule was changed.',
            self::MaskingRuleToggled => 'A masking rule was enabled or disabled.',
            self::MaskingRuleDeleted => 'A masking rule was deleted.',
            self::MaskingRuleDefaultsAdded => 'The default masking rules were added to a team.',
            self::MaskingRuleTurkeyPackAdded => 'The Turkey PII masking rule pack was added to a team (metadata.created is the number of rules added).',
            self::MaskingKeyRotated => 'The HMAC masking key of a team was rotated; pseudonyms made before and after no longer match. The key is never recorded.',
            self::ErrorMessagesMasked => 'Stored error messages were re-masked by the backfill command.',

            self::ChatIntegrationSaved => 'A ChatOps integration was saved.',
            self::ChatIntegrationSecretRotated => 'The signing secret of a ChatOps integration was rotated.',
            self::ChatIntegrationRemoved => 'A ChatOps integration was removed.',
            self::ChatIdentityLinked => 'A chat identity was linked to a user.',
            self::ChatIdentityRemoved => 'A chat identity was unlinked from a user.',

            self::SlackInstalled => 'The QueryProxy Slack app was installed for a team through OAuth v2 (metadata.slack_team_id, metadata.slack_team_name, metadata.channel_id, metadata.scopes). The bot token is never recorded.',
            self::SlackUninstalled => 'The Slack app installation of a team ended and its bot token was deleted (metadata.slack_team_id, metadata.reason = app_uninstalled | tokens_revoked | token_rejected | removed_by_user). Written without an actor when Slack reported it; removed_by_user names the admin or DBA who removed it on the ChatOps settings page and adds metadata.token_revoked (bool, whether the Slack auth.revoke call succeeded) and, on failure, metadata.revoke_error (the Slack error code). The token is never recorded.',

            self::RequestSubmitted => 'A query request was submitted for approval (metadata.channel = web | api).',
            self::RequestApproved => 'A query request was approved (metadata.override marks an admin override; an override always carries metadata.reason).',
            self::RequestRejected => 'A query request was rejected, with channel and reason.',
            self::RequestCancelled => 'A pending query request was cancelled (metadata.channel = web | api when a user did it; by the requester, or by the system with metadata.actor = system and metadata.reason when its owner was deleted).',
            self::RequestExecutionStarted => 'Execution of an approved request started.',
            self::RequestExecutionCompleted => 'Execution of a request completed.',
            self::RequestExecutionFailed => 'Execution of a request failed (masked error message; metadata.refused_by = guard when a read request carried a write statement).',
            self::RequestExecutionDeferred => 'An approved request was put back on the queue because every slot of its connection was busy.',
            self::ExecutionLateCompletion => 'A request finished after it had already timed out.',
            self::ExecutionCancelled => 'A user stopped a running request: the database was asked to cancel the statement (metadata.signalled tells whether it was reached; metadata.duration_ms is the run time so far).',
            self::ResultDownloaded => 'The stored result of a request was downloaded (since v0.4.0 the REST API records metadata.channel = api and metadata.format).',

            self::SchemaRead => 'The table and column names of a connection were read from the target database for the schema explorer, through the fixed catalog queries and without an approval (ADR-029). Written for live reads only, not for answers served from the cache (metadata.source = live, metadata.objects = tables and views, metadata.columns, metadata.refreshed tells whether the user asked to bypass the cache, metadata.truncated is set when the size cap cut the listing).',
        };
    }

    /**
     * The part of the name before the dot.
     */
    public function category(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Event name => description, the source for generated documentation and
     * the export schema.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::cases() as $case) {
            $catalog[$case->value] = $case->description();
        }

        return $catalog;
    }
}
