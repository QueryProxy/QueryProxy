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

    // Users
    case UserCreated = 'user.created';
    case UserDeleted = 'user.deleted';
    case UserAdminToggled = 'user.admin_toggled';
    case UserNameChanged = 'user.name_changed';
    case UserPasswordChanged = 'user.password_changed';
    case UserPasswordReset = 'user.password_reset';
    case UserTwoFactorEnabled = 'user.two_factor_enabled';
    case UserTwoFactorDisabled = 'user.two_factor_disabled';
    case UserTwoFactorReset = 'user.two_factor_reset';

    // Teams
    case TeamCreated = 'team.created';
    case TeamDeleted = 'team.deleted';
    case TeamMemberAdded = 'team.member_added';
    case TeamMemberRemoved = 'team.member_removed';
    case TeamMemberRoleChanged = 'team.member_role_changed';

    // Connections
    case ConnectionCreated = 'connection.created';
    case ConnectionUpdated = 'connection.updated';
    case ConnectionDeleted = 'connection.deleted';
    case ConnectionTested = 'connection.tested';
    case ConnectionGrantAdded = 'connection.grant_added';
    case ConnectionGrantRevoked = 'connection.grant_revoked';

    // Masking
    case MaskingRuleCreated = 'masking_rule.created';
    case MaskingRuleUpdated = 'masking_rule.updated';
    case MaskingRuleToggled = 'masking_rule.toggled';
    case MaskingRuleDeleted = 'masking_rule.deleted';
    case MaskingRuleDefaultsAdded = 'masking_rule.defaults_added';
    case ErrorMessagesMasked = 'error_messages.masked';

    // ChatOps
    case ChatIntegrationSaved = 'chat_integration.saved';
    case ChatIntegrationSecretRotated = 'chat_integration.secret_rotated';
    case ChatIntegrationRemoved = 'chat_integration.removed';
    case ChatIdentityLinked = 'chat_identity.linked';
    case ChatIdentityRemoved = 'chat_identity.removed';

    // Query requests
    case RequestSubmitted = 'request.submitted';
    case RequestApproved = 'request.approved';
    case RequestRejected = 'request.rejected';
    case RequestCancelled = 'request.cancelled';
    case RequestExecutionStarted = 'request.execution_started';
    case RequestExecutionCompleted = 'request.execution_completed';
    case RequestExecutionFailed = 'request.execution_failed';
    case ExecutionLateCompletion = 'execution.late_completion';
    case ResultDownloaded = 'result.downloaded';

    public function description(): string
    {
        return match ($this) {
            self::AuditCheckpoint => 'Daily checkpoint: latest record id and chain hash at that point.',

            self::AuthLogin => 'A user signed in (metadata.two_factor is set for the second factor).',
            self::AuthLogout => 'A user signed out.',
            self::AuthPasswordReset => 'A user reset their own password through the reset link.',

            self::UserCreated => 'An administrator created a user.',
            self::UserDeleted => 'An administrator deleted a user.',
            self::UserAdminToggled => 'The system admin flag of a user was changed.',
            self::UserNameChanged => 'A user changed their name.',
            self::UserPasswordChanged => 'A user changed their password.',
            self::UserPasswordReset => 'An administrator reset the password of a user.',
            self::UserTwoFactorEnabled => 'A user enabled two-factor authentication.',
            self::UserTwoFactorDisabled => 'A user disabled two-factor authentication.',
            self::UserTwoFactorReset => 'An administrator reset the two-factor authentication of a user.',

            self::TeamCreated => 'A team was created.',
            self::TeamDeleted => 'A team was deleted.',
            self::TeamMemberAdded => 'A user was added to a team.',
            self::TeamMemberRemoved => 'A user was removed from a team.',
            self::TeamMemberRoleChanged => 'The team role of a member was changed.',

            self::ConnectionCreated => 'A database connection was created.',
            self::ConnectionUpdated => 'A database connection was changed.',
            self::ConnectionDeleted => 'A database connection was deleted.',
            self::ConnectionTested => 'A database connection was tested.',
            self::ConnectionGrantAdded => 'A developer was granted access to a connection.',
            self::ConnectionGrantRevoked => 'A developer lost access to a connection.',

            self::MaskingRuleCreated => 'A masking rule was created.',
            self::MaskingRuleUpdated => 'A masking rule was changed.',
            self::MaskingRuleToggled => 'A masking rule was enabled or disabled.',
            self::MaskingRuleDeleted => 'A masking rule was deleted.',
            self::MaskingRuleDefaultsAdded => 'The default masking rules were added to a team.',
            self::ErrorMessagesMasked => 'Stored error messages were re-masked by the backfill command.',

            self::ChatIntegrationSaved => 'A ChatOps integration was saved.',
            self::ChatIntegrationSecretRotated => 'The signing secret of a ChatOps integration was rotated.',
            self::ChatIntegrationRemoved => 'A ChatOps integration was removed.',
            self::ChatIdentityLinked => 'A chat identity was linked to a user.',
            self::ChatIdentityRemoved => 'A chat identity was unlinked from a user.',

            self::RequestSubmitted => 'A query request was submitted for approval.',
            self::RequestApproved => 'A query request was approved (metadata.override marks an admin override).',
            self::RequestRejected => 'A query request was rejected, with channel and reason.',
            self::RequestCancelled => 'A pending query request was cancelled.',
            self::RequestExecutionStarted => 'Execution of an approved request started.',
            self::RequestExecutionCompleted => 'Execution of a request completed.',
            self::RequestExecutionFailed => 'Execution of a request failed (masked error message).',
            self::ExecutionLateCompletion => 'A request finished after it had already timed out.',
            self::ResultDownloaded => 'The stored result of a request was downloaded.',
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
