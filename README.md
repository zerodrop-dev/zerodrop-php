# zerodrop-php

[![Packagist Version](https://img.shields.io/packagist/v/zerodrop/zerodrop.svg)](https://packagist.org/packages/zerodrop/zerodrop)
[![CI](https://github.com/zerodrop-dev/zerodrop-php/actions/workflows/ci.yml/badge.svg)](https://github.com/zerodrop-dev/zerodrop-php/actions/workflows/ci.yml)
[![license](https://img.shields.io/github/license/zerodrop-dev/zerodrop-php.svg)](LICENSE)

Email verification infrastructure for CI pipelines.

Send a verification email. Catch it at the edge. Get `$email->otp` and `$email->magicLink` back — auto-extracted, no regex, no Docker, no signup to try.

```php
$email = $mail->waitForLatest($inbox, timeout: 15);

$email->otp;       // "123456" — auto-extracted
$email->magicLink; // "https://..." — no regex needed
```

**[Documentation](https://docs.zerodrop.dev)** · [GitHub](https://github.com/zerodrop-dev) · [Status](https://zerodrop.instatus.com)

## Install

```bash
composer require --dev zerodrop/zerodrop
```

PHP 8.1+ · ext-curl · ext-json — no other dependencies.

## Quick start

```php
use ZeroDrop\Client;

$mail = new Client();
$inbox = $mail->generateInbox();
// => "swift-x7k29ab@zerodrop-sandbox.online"

// Trigger your app's email flow with the inbox address...

$email = $mail->waitForLatest($inbox, timeout: 15);

$email->subject;   // "Verify your email"
$email->otp;       // "123456" — auto-extracted, no regex
$email->magicLink; // "https://..." — auto-extracted
```

## PHPUnit

```php
use PHPUnit\Framework\TestCase;
use ZeroDrop\Client;

final class SignupTest extends TestCase
{
    public function testEmailVerification(): void
    {
        $mail = new Client();
        $inbox = $mail->generateInbox();

        // Trigger signup with $inbox...

        $email = $mail->waitForLatest($inbox, timeout: 15);

        $this->assertNotNull($email->otp);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $email->otp);
    }
}
```

## Pest

```php
use ZeroDrop\Client;

it('verifies the account via emailed OTP', function () {
    $mail = new Client();
    $inbox = $mail->generateInbox();

    // Trigger signup with $inbox...

    $email = $mail->waitForLatest($inbox, timeout: 15);

    expect($email->otp)->not->toBeNull()
        ->and($email->otp)->toMatch('/^\d{6}$/');
});
```

## Laravel Dusk

```php
use Laravel\Dusk\Browser;
use ZeroDrop\Client;

public function testSignupEmailVerification(): void
{
    $mail = new Client();
    $inbox = $mail->generateInbox();

    $this->browse(function (Browser $browser) use ($mail, $inbox) {
        $browser->visit('/signup')
                ->type('email', $inbox)
                ->type('password', 'TestPassword123!')
                ->press('Create account');

        $email = $mail->waitForLatest($inbox, timeout: 15);

        $browser->type('otp', $email->otp)
                ->press('Verify')
                ->assertPathIs('/dashboard');
    });
}
```

## Email filtering

```php
use ZeroDrop\Filter;

$email = $mail->waitForLatest(
    $inbox,
    timeout: 15,
    filter: new Filter(
        from: 'noreply@yourapp.com',
        subject: 'Verify',
        hasOtp: true,
    ),
);
```

All string filters are case-insensitive partial matches.

## Magic link flows

```php
$email = $mail->waitForLatest(
    $inbox,
    timeout: 15,
    filter: new Filter(hasMagicLink: true),
);

$browser->visit($email->magicLink)
        ->assertPathIs('/dashboard');
```

## Free API key (private inboxes)

Without a key, anyone who knows an inbox's name can read it, and one sender can reach only 5 different inboxes a day. With a free key, your inboxes start with your own prefix, only your key can read them, and mail to them isn't limited.

1. Sign in with GitHub at [zerodrop.dev/keys](https://zerodrop.dev/keys) and create a key.
2. Set it as `ZERODROP_API_KEY`. The client reads it automatically, or you can pass `apiKey:`.

```php
$mail = new Client(); // reads ZERODROP_API_KEY when it's set
$mail->generateInbox();
// "k7x2-swift-x7k29ab@zerodrop-sandbox.online", where k7x2 is your prefix
```

Reading a `k7x2-…` inbox without your key throws `AuthException` with the reason. `$mail->prefix()` returns your prefix if you build an address yourself. A key holding a character that can't be sent in an HTTP header (a line break, a byte-order mark) makes `new Client()` throw `InvalidArgumentException`; the message never includes the key.

Without a key, if ZeroDrop held an email because its sender passed the daily limit, the inbox gets a notice instead, and `fetchLatest()` and `waitForLatest()` throw `HeldException` saying so (0.3.0 or later; older versions return the notice as if it were the email, or time out when a filter skips it). A free key from [zerodrop.dev/keys](https://zerodrop.dev/keys) avoids the limit.

## Workspace Domain

A Workspace receives mail on a domain of its own, such as `yourcompany-qa.com`, so sign-up forms that block disposable domains accept it. Every inbox on it can be read only with your key. Set the domain as `ZERODROP_DOMAIN`, or pass it as `domain:`, next to your key:

```php
$mail = new Client(domain: 'yourcompany-qa.com'); // reads ZERODROP_API_KEY
$inbox = $mail->generateInbox();
// "k7x2-swift-x7k29ab@yourcompany-qa.com"
$email = $mail->waitForLatest($inbox, timeout: 15);
```

Any address on the domain works, including one you name yourself: mail to `qa@yourcompany-qa.com` is filed under your prefix, and `$mail->waitForLatest('qa@yourcompany-qa.com')` reads it from there. `$mail->domain()` returns the domain in use. Workspaces are set up with the founder: [zerodrop.dev/pricing](https://zerodrop.dev/pricing).

## Parallel test runs

`generateInbox()` runs locally — no network request, no collisions. Safe with Paratest and parallel Dusk runs; every call returns a unique isolated inbox. Mail to many inboxes a day needs a free API key: without one, one sender can reach only 5 different inboxes a day.

## Error handling

```php
use ZeroDrop\Exception\TimeoutException;
use ZeroDrop\Exception\AuthException;
use ZeroDrop\Exception\HeldException;
use ZeroDrop\Exception\NetworkException;

try {
    $email = $mail->waitForLatest($inbox, timeout: 15);
} catch (TimeoutException $e) {
    // No email arrived — check your app is sending correctly
} catch (AuthException $e) {
    // The inbox belongs to another key's prefix, or your key is wrong — $e->getMessage() says which
} catch (HeldException $e) {
    // No API key, and ZeroDrop held the email (daily limit) — $e->getMessage() says why; a free key avoids it
} catch (NetworkException $e) {
    // Transport failure — message includes status page link
}
```

## Workspaces

A Workspace key works the same way: set `ZERODROP_API_KEY`, or pass it yourself.

```php
$mail = new Client();                      // reads ZERODROP_API_KEY
$mail = new Client(apiKey: $workspaceKey); // or pass the key
```

A Workspace's own domain goes in `ZERODROP_DOMAIN` or `domain:`; see [Workspace Domain](#workspace-domain).

## Self-hosted

```php
$mail = new Client(baseUrl: 'https://your-instance.yourdomain.com');
```

## API

| Method | Description |
|---|---|
| `new Client(?string $apiKey, string $baseUrl, ?string $domain)` | Create a client. `$apiKey` defaults to the `ZERODROP_API_KEY` environment variable; without a key, inboxes are public to anyone who knows the name. `$domain`, your Workspace's own domain, defaults to the `ZERODROP_DOMAIN` environment variable, then the shared `zerodrop-sandbox.online`; a value that isn't a domain name throws `InvalidArgumentException`. |
| `generateInbox(): string` | Instant inbox address. No network request. With a key, the name starts with your prefix. |
| `prefix(): ?string` | Your key's inbox prefix (`"k7x2"`), or `null` without a key. |
| `domain(): string` | The domain generated addresses use: your Workspace's, or `zerodrop-sandbox.online`. |
| `fetchLatest(string $inbox, ?Filter $filter): ?Email` | Latest matching email or null. |
| `waitForLatest(string $inbox, float $timeout, float $pollInterval, ?Filter $filter): Email` | Block until email arrives. |

## Free vs Workspace

|  | Free | Workspace |
|---|---|---|
| Inbox generation | ✓ | ✓ |
| OTP auto-extraction | ✓ | ✓ |
| Magic link extraction | ✓ | ✓ |
| Email filtering | ✓ | ✓ |
| Email retention | 30 min | Up to 7 days, set for your team |
| Custom domains | ✗ | ✓ (see [Workspace Domain](#workspace-domain)) |
| API key | ✓ free at [zerodrop.dev/keys](https://zerodrop.dev/keys) | ✓ |
| Daily limit per sender | 5 inboxes without a key, none with one | None |

Get a Workspace at [zerodrop.dev](https://zerodrop.dev)

## License

MIT
