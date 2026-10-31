<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * A single sign-on attempt that must be refused. `getMessage()` is the log
 * reason (it never carries a token or a secret); `userMessage()` is what the
 * person at the login screen is told, which stays generic unless the detail
 * is safe to show.
 */
class OidcException extends RuntimeException
{
    public const GENERIC_MESSAGE = 'Single sign-on failed. Try again, or contact your administrator.';

    public function __construct(string $reason, private readonly ?string $userMessage = null, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }

    public function userMessage(): string
    {
        return $this->userMessage ?? __(self::GENERIC_MESSAGE);
    }
}
