<?php

declare(strict_types=1);

namespace ZeroDrop\Exception;

/** Thrown when an invalid API key is provided. */
final class AuthException extends ZeroDropException
{
    public function __construct()
    {
        parent::__construct('Invalid or missing API key');
    }
}
