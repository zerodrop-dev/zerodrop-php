<?php

declare(strict_types=1);

namespace ZeroDrop\Exception;

/**
 * Thrown when no email matches and the inbox got a held-mail notice instead:
 * without an API key, one sender can reach only a few different inboxes a
 * day, and ZeroDrop held the email rather than store it. The message carries
 * the server's reason.
 */
final class HeldException extends ZeroDropException
{
    public function __construct(?string $reason = null)
    {
        parent::__construct($reason ?? 'ZeroDrop held the email sent to this inbox: without an API key, '
            . 'one sender can reach only a few different inboxes a day. '
            . 'Get a free key at https://zerodrop.dev/keys and set ZERODROP_API_KEY.');
    }
}
