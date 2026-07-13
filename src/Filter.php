<?php

declare(strict_types=1);

namespace ZeroDrop;

/**
 * Filter for narrowing which email is returned.
 * All string matches are case-insensitive partial matches.
 */
final class Filter
{
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $subject = null,
        public readonly ?string $body = null,
        public readonly ?bool $hasOtp = null,
        public readonly ?bool $hasMagicLink = null,
    ) {
    }

    public function matches(Email $email): bool
    {
        if ($this->from !== null && stripos($email->from, $this->from) === false) {
            return false;
        }
        if ($this->subject !== null && stripos($email->subject, $this->subject) === false) {
            return false;
        }
        if ($this->body !== null && stripos($email->body, $this->body) === false) {
            return false;
        }
        if ($this->hasOtp !== null) {
            if ($this->hasOtp && $email->otp === null) {
                return false;
            }
            if (!$this->hasOtp && $email->otp !== null) {
                return false;
            }
        }
        if ($this->hasMagicLink !== null) {
            if ($this->hasMagicLink && $email->magicLink === null) {
                return false;
            }
            if (!$this->hasMagicLink && $email->magicLink !== null) {
                return false;
            }
        }
        return true;
    }
}
