<?php

declare(strict_types=1);

namespace ZeroDrop\Exception;

/**
 * Thrown on a 401: the inbox belongs to an account's prefix and the request
 * had no key or the wrong one. The message carries the server's reason.
 */
final class AuthException extends ZeroDropException
{
    public function __construct(?string $reason = null)
    {
        parent::__construct($reason ?? 'Invalid or missing API key');
    }
}
