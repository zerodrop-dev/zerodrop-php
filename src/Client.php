<?php

declare(strict_types=1);

namespace ZeroDrop;

use ZeroDrop\Exception\AuthException;
use ZeroDrop\Exception\HeldException;
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
    public const VERSION = '0.4.0';

    private const DEFAULT_BASE_URL = 'https://zerodrop.dev';
    private const FREE_DOMAIN = 'zerodrop-sandbox.online';
    private const ADJECTIVES = ['swift', 'dark', 'cold', 'null', 'void', 'zero', 'dead', 'raw', 'base', 'core'];
    private const ALPHANUM = 'abcdefghijklmnopqrstuvwxyz0123456789';

    // A free key from https://zerodrop.dev/keys: zd_<prefix>_<32 letters/digits>.
    // Inboxes named <prefix>-... can be read only with that key.
    private const KEY_FORMAT = '/^zd_([a-z0-9]{4})_[A-Za-z0-9]{32}$/';

    // A key goes into an HTTP header, so after trimming it may hold only
    // visible ASCII. Anything else is refused without echoing the key.
    private const KEY_CHARS = '/^[\x21-\x7e]+\z/';

    // Whitespace around a key, including the no-break spaces and byte-order
    // marks that copying and pasting can bring along.
    private const KEY_PADDING = '/^[\s\x00\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+'
        . '|[\s\x00\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+\z/u';

    // A Workspace's own domain, from the $domain argument or ZERODROP_DOMAIN.
    private const HOST_NAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D';

    private readonly ?string $apiKey;
    private readonly ?string $prefix;
    private readonly string $domain;
    private string $baseUrl;

    /**
     * @param string|null $apiKey  Free API key from https://zerodrop.dev/keys.
     *                             Defaults to the ZERODROP_API_KEY environment
     *                             variable. Without a key, anyone who knows an
     *                             inbox's name can read it.
     * @param string      $baseUrl Override for self-hosted instances.
     * @param string|null $domain  Your Workspace's own domain, such as
     *                             yourcompany-qa.com. Generated addresses use
     *                             it, and its inboxes are read with your key.
     *                             Defaults to the ZERODROP_DOMAIN environment
     *                             variable, then the shared zerodrop-sandbox.online.
     *
     * @throws \InvalidArgumentException if the key holds a character that can't
     *                                   be sent in an HTTP header (a line break,
     *                                   a byte-order mark...), or the domain
     *                                   isn't a domain name
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $domain = null,
    ) {
        $key = self::trimKey((string) $apiKey);
        if ($key === '') {
            $key = self::trimKey((string) (getenv('ZERODROP_API_KEY') ?: ($_ENV['ZERODROP_API_KEY'] ?? $_SERVER['ZERODROP_API_KEY'] ?? '')));
        }
        if ($key !== '' && preg_match(self::KEY_CHARS, $key) !== 1) {
            throw new \InvalidArgumentException(
                "ZeroDrop: the API key contains a character that can't be sent in an HTTP header, "
                . 'such as a line break or a byte-order mark. Copy it again from https://zerodrop.dev/keys.'
            );
        }
        $this->apiKey = $key === '' ? null : $key;
        $this->prefix = $this->apiKey !== null && preg_match(self::KEY_FORMAT, $this->apiKey, $m) === 1 ? $m[1] : null;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->domain = self::resolveDomain($domain);

        // Every inbox on a Workspace's domain needs its key. Said with
        // error_log() (stderr from the CLI): a trigger_error() warning becomes
        // an exception under error handlers such as Laravel's or PHPUnit 9's.
        if ($this->domain !== self::FREE_DOMAIN && $this->prefix === null) {
            error_log(
                "[ZeroDrop] Inboxes on {$this->domain} belong to your Workspace and can be read only with its API key "
                . '(zd_xxxx_… from https://zerodrop.dev/keys). Set ZERODROP_API_KEY.'
            );
        }
    }

    private static function trimKey(string $key): string
    {
        // Not valid UTF-8: plain trim(), and the character check refuses it.
        return preg_replace(self::KEY_PADDING, '', $key) ?? trim($key);
    }

    // The receiving domain: the argument, else ZERODROP_DOMAIN, else the shared
    // one. Trimmed like a key, then lowercased without one trailing dot.
    private static function resolveDomain(?string $option): string
    {
        $value = self::trimKey((string) $option);
        if ($value === '') {
            $value = self::trimKey((string) (getenv('ZERODROP_DOMAIN') ?: ($_ENV['ZERODROP_DOMAIN'] ?? $_SERVER['ZERODROP_DOMAIN'] ?? '')));
        }
        if ($value === '') {
            return self::FREE_DOMAIN;
        }
        $domain = strtolower($value);
        $domain = str_ends_with($domain, '.') ? substr($domain, 0, -1) : $domain;
        if (preg_match(self::HOST_NAME, $domain) !== 1) {
            throw new \InvalidArgumentException(
                "ZeroDrop: the domain argument (or ZERODROP_DOMAIN) isn't a domain name. Use your Workspace's domain, such as yourcompany-qa.com."
            );
        }
        return $domain;
    }

    /**
     * Your key's inbox prefix ("k7x2"), or null without a key.
     */
    public function prefix(): ?string
    {
        return $this->prefix;
    }

    /**
     * The domain generated addresses use: your Workspace's, or zerodrop-sandbox.online.
     */
    public function domain(): string
    {
        return $this->domain;
    }

    /**
     * Returns a ready-to-use email address instantly.
     * No network request is made. With a key, the name starts with your
     * prefix: k7x2-swift-x7k29ab@zerodrop-sandbox.online.
     */
    public function generateInbox(): string
    {
        $adjective = self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)];
        $suffix = '';
        for ($i = 0; $i < 7; $i++) {
            $suffix .= self::ALPHANUM[random_int(0, strlen(self::ALPHANUM) - 1)];
        }
        $name = sprintf('%s-%s', $adjective, $suffix);
        if ($this->prefix !== null) {
            $name = $this->prefix . '-' . $name;
        }
        return sprintf('%s@%s', $name, $this->domain);
    }

    /**
     * Returns the newest email matching the filter, or null.
     *
     * @throws AuthException when the inbox needs another key; the message says why
     * @throws HeldException when nothing matches and ZeroDrop held mail to the inbox
     *                       (daily limit without a key); the message says why
     * @throws NetworkException on transport failure
     */
    public function fetchLatest(string $inbox, ?Filter $filter = null): ?Email
    {
        $name = $this->inboxName($inbox);
        $url = sprintf('%s/api/inbox/%s?source=php-sdk', $this->baseUrl, rawurlencode($name));

        [$status, $body] = $this->httpGet($url);

        if ($status === 401) {
            $reason = json_decode($body, true)['error'] ?? null;
            throw new AuthException(is_string($reason) ? $reason : null);
        }
        if ($status !== 200) {
            throw new NetworkException("API returned {$status}");
        }

        /** @var array{emails?: list<array<string, mixed>>} $payload */
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new NetworkException('Invalid JSON response');
        }

        // A held-mail notice ("held": true) stands in for an email ZeroDrop
        // didn't store: without an API key, one sender can reach only a few
        // different inboxes a day. A notice is never returned as an email;
        // if nothing else matches, the first one's reason is thrown.
        $notice = null;
        foreach ($payload['emails'] ?? [] as $raw) {
            if (($raw['held'] ?? null) === true) {
                $notice ??= $raw;
                continue;
            }
            $email = Email::fromArray($raw);
            if ($filter === null || $filter->matches($email)) {
                return $email;
            }
        }

        if ($notice !== null) {
            $reason = $notice['reason'] ?? null;
            $reason = is_string($reason) ? trim($reason) : '';
            throw new HeldException($reason !== '' ? $reason : null);
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
     * @throws AuthException when the inbox needs another key; the message says why
     * @throws HeldException at once, not after the timeout, when ZeroDrop held mail
     *                       to the inbox (daily limit without a key); the message says why
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

        $headers = ['User-Agent: zerodrop-php/' . self::VERSION];
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

    // The inbox an address is read from: its local part. On your Workspace's
    // own domain, mail is filed under your key's prefix, so an address you
    // named yourself, such as qa@yourcompany-qa.com, is read as k7x2-qa.
    private function inboxName(string $inbox): string
    {
        $name = str_contains($inbox, '@') ? explode('@', $inbox, 2)[0] : $inbox;
        $name = strtolower($name);
        if ($this->prefix === null || $this->domain === self::FREE_DOMAIN || !str_contains($inbox, '@')) {
            return $name;
        }
        $domain = strtolower(substr($inbox, strrpos($inbox, '@') + 1));
        $domain = str_ends_with($domain, '.') ? substr($domain, 0, -1) : $domain;
        if ($domain !== $this->domain || str_starts_with($name, $this->prefix . '-')) {
            return $name;
        }
        return $this->prefix . '-' . $name;
    }
}
