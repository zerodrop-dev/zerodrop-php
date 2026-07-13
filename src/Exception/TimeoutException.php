<?php

declare(strict_types=1);

namespace ZeroDrop\Exception;

/** Thrown when no email arrives within the timeout. */
final class TimeoutException extends ZeroDropException
{
    public function __construct(string $inbox, float $timeout)
    {
        parent::__construct(sprintf(
            'No email received at "%s" within %.0fs — check that your app is sending to the correct address',
            $inbox,
            $timeout
        ));
    }
}
