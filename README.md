# zerodrop-php

[![Packagist Version](https://img.shields.io/packagist/v/zerodrop/zerodrop.svg)](https://packagist.org/packages/zerodrop/zerodrop)
[![CI](https://github.com/zerodrop-dev/zerodrop-php/actions/workflows/ci.yml/badge.svg)](https://github.com/zerodrop-dev/zerodrop-php/actions/workflows/ci.yml)
[![license](https://img.shields.io/github/license/zerodrop-dev/zerodrop-php.svg)](LICENSE)

Email verification infrastructure for CI pipelines.

Send a verification email. Catch it at the edge. Get `$email->otp` and `$email->magicLink` back — auto-extracted, no regex, no Docker, no signup.

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

## Parallel test runs

`generateInbox()` runs locally — no network request, no collisions. Safe with Paratest and parallel Dusk runs; every call returns a unique isolated inbox.

## Error handling

```php
use ZeroDrop\Exception\TimeoutException;
use ZeroDrop\Exception\AuthException;
use ZeroDrop\Exception\NetworkException;

try {
    $email = $mail->waitForLatest($inbox, timeout: 15);
} catch (TimeoutException $e) {
    // No email arrived — check your app is sending correctly
} catch (AuthException $e) {
    // Invalid API key
} catch (NetworkException $e) {
    // Transport failure — message includes status page link
}
```

## Workspaces

```php
$mail = new Client(apiKey: getenv('ZERODROP_API_KEY'));
```

## Self-hosted

```php
$mail = new Client(baseUrl: 'https://your-instance.yourdomain.com');
```

## API

| Method | Description |
|---|---|
| `new Client(?string $apiKey, string $baseUrl)` | Create a client. No args = free sandbox mode. |
| `generateInbox(): string` | Instant inbox address. No network request. |
| `fetchLatest(string $inbox, ?Filter $filter): ?Email` | Latest matching email or null. |
| `waitForLatest(string $inbox, float $timeout, float $pollInterval, ?Filter $filter): Email` | Block until email arrives. |

## Free vs Workspace

|  | Free | Workspace |
|---|---|---|
| Inbox generation | ✓ | ✓ |
| OTP auto-extraction | ✓ | ✓ |
| Magic link extraction | ✓ | ✓ |
| Email filtering | ✓ | ✓ |
| Email retention | 30 min | Extended |
| Custom domains | ✗ | ✓ |

Get a Workspace at [zerodrop.dev](https://zerodrop.dev)

## License

MIT
