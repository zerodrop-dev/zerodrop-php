<?php

declare(strict_types=1);

namespace ZeroDrop;

use ZeroDrop\Exception\AuthException;
use ZeroDrop\Exception\NetworkException;
use ZeroDrop\Exception\TimeoutException;

/**
 * ZeroDrop — disposable email inboxes for testing auth flows in CI.
 * OTPs and magic links auto-extracted at Cloudflare's edge.
 * No regex, no Docker, no signup.
 *
 *     $mail = new \ZeroDrop\Client();
 *     $inbox = $mail->generateInbox();
 *     $email = $mail->waitForLatest($inbox, timeout: 15);
 *     $email->otp;       // "847291"
 *     $email->magicLink; // "https://..."
 */
final class Client
{
    private const DEFAULT_BASE_URL = 'https://zerodrop.dev';
    private const FREE_DOMAIN = 'zerodrop-sandbox.online';
    private const ADJECTIVES = ['swift', 'dark', 'cold', 'null', 'void', 'zero', 'dead', 'raw', 'base', 'core'];
    private const ALPHANUM = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private string $baseUrl;

    public function __construct(
        private readonly ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Returns a ready-to-use email address instantly.
     * No network request is made.
     */
    public function generateInbox(): string
    {
        $adjective = self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)];
        $suffix = '';
        for ($i = 0; $i < 7; $i++) {
            $suffix .= self::ALPHANUM[random_int(0, strlen(self::ALPHANUM) - 1)];
        }
        return sprintf('%s-%s@%s', $adjective, $suffix, self::FREE_DOMAIN);
    }

    /**
     * Returns the newest email matching the filter, or null.
     *
     * @throws AuthException on invalid API key
     * @throws NetworkException on transport failure
     */
    public function fetchLatest(string $inbox, ?Filter $filter = null): ?Email
    {
        $name = $this->inboxName($inbox);
        $url = sprintf('%s/api/inbox/%s?source=php-sdk', $this->baseUrl, rawurlencode($name));

        [$status, $body] = $this->httpGet($url);

        if ($status === 401) {
            throw new AuthException();
        }
        if ($status !== 200) {
            throw new NetworkException("API returned {$status}");
        }

        /** @var array{emails?: list<array<string, mixed>>} $payload */
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new NetworkException('Invalid JSON response');
        }

        foreach ($payload['emails'] ?? [] as $raw) {
            $email = Email::fromArray($raw);
            if ($filter === null || $filter->matches($email)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Blocks until an email matching the filter arrives.
     * Polls every $pollInterval seconds up to $timeout seconds.
     *
     * @param float $timeout      seconds to wait (default 10)
     * @param float $pollInterval seconds between polls (default 2)
     *
     * @throws TimeoutException if nothing arrives in time
     * @throws AuthException on invalid API key
     */
    public function waitForLatest(
        string $inbox,
        float $timeout = 10.0,
        float $pollInterval = 2.0,
        ?Filter $filter = null,
    ): Email {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $email = $this->fetchLatest($inbox, $filter);
            if ($email !== null) {
                return $email;
            }

            if (microtime(true) + $pollInterval > $deadline) {
                throw new TimeoutException($inbox, $timeout);
            }

            usleep((int) ($pollInterval * 1_000_000));
        }
    }

    /**
     * @return array{0: int, 1: string} [status code, response body]
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new NetworkException('Failed to initialize cURL');
        }

        $headers = ['User-Agent: zerodrop-php/0.1.0'];
        if ($this->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new NetworkException($err !== '' ? $err : 'cURL request failed');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }

    private function inboxName(string $inbox): string
    {
        $name = str_contains($inbox, '@') ? explode('@', $inbox, 2)[0] : $inbox;
        return strtolower($name);
    }
}
