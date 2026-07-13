<?php

declare(strict_types=1);

namespace ZeroDrop;

/**
 * A caught email with auto-extracted fields.
 */
final class Email
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $rawBody,
        public readonly ?\DateTimeImmutable $receivedAt,
        /** Auto-extracted 4-8 digit verification code, or null. */
        public readonly ?string $otp,
        /** Auto-extracted verification/reset URL, or null. */
        public readonly ?string $magicLink,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $receivedAt = null;
        if (!empty($raw['receivedAt'])) {
            try {
                $receivedAt = new \DateTimeImmutable($raw['receivedAt']);
            } catch (\Exception) {
                // leave null
            }
        }

        $rawBody = (string) ($raw['raw'] ?? '');

        return new self(
            id: (string) ($raw['id'] ?? ''),
            from: (string) ($raw['from'] ?? ''),
            to: (string) ($raw['to'] ?? ''),
            subject: (string) ($raw['subject'] ?? ''),
            body: self::extractBody($rawBody),
            rawBody: $rawBody,
            receivedAt: $receivedAt,
            otp: !empty($raw['otp']) ? (string) $raw['otp'] : null,
            magicLink: !empty($raw['magicLink']) ? (string) $raw['magicLink'] : null,
        );
    }

    private static function extractBody(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if (preg_match('#Content-Type: text/plain[^\r\n]*\r\n\r\n(.*?)(?:\r\n--|\r\n\r\n--)#s', $raw, $m)) {
            return trim($m[1]);
        }

        $parts = explode("\r\n\r\n", $raw, 2);
        if (count($parts) === 2) {
            $body = trim($parts[1]);
            return strlen($body) > 5000 ? substr($body, 0, 5000) : $body;
        }

        return '';
    }
}
