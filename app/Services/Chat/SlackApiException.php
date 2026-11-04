<?php

namespace App\Services\Chat;

use RuntimeException;

/**
 * Slack answered a Web API call with `ok: false`, or could not be reached.
 * Only the method and Slack's error code are carried; request bodies and
 * tokens never are.
 */
class SlackApiException extends RuntimeException
{
    /** Errors after which the stored bot token is of no further use. */
    private const DEAD_TOKEN_ERRORS = ['token_revoked', 'invalid_auth', 'account_inactive', 'not_authed', 'token_expired'];

    public function __construct(public readonly string $method, public readonly string $slackError)
    {
        parent::__construct("Slack API {$method} failed: {$slackError}");
    }

    public function isDeadToken(): bool
    {
        return in_array($this->slackError, self::DEAD_TOKEN_ERRORS, true);
    }
}
