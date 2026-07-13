<?php

declare(strict_types=1);

namespace ZeroDrop\Exception;

/** Thrown on transport-level failures. */
final class NetworkException extends ZeroDropException
{
    public function __construct(string $message)
    {
        parent::__construct($message . ' (check https://zerodrop.instatus.com)');
    }
}
