<?php

declare(strict_types=1);

/**
 * Security regression smoke test.
 *
 * Each check corresponds to a vulnerability that was found and fixed. They
 * exist so the hole cannot silently reopen — a refactor that reverts any of
 * these fails here rather than in production.
 *
 *     php scripts/security_smoke.php
 *
 * Covers:
 *   - Razorpay webhooks authenticating against an unconfigured (empty) secret,
 *     which let anyone forge a captured payment.
 *   - Unverified webhooks consuming the (gateway, event_id) de-dup key, so a
 *     forged event could suppress the genuine one that followed.
 *   - X-Forwarded-For being believed from any client, which defeated every
 *     IP-keyed rate limit and the admin IP allow-list.
 *   - Notification templates carrying an action_type outside the column ENUM,
 *     which made every KYC transition throw under STRICT_TRANS_TABLES.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.
");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Zuste\Core\Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

Zuste\Core\App::boot($basePath);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($detail === '' ? '' : "\n        {$detail}") . "\n";
    }
}

/** Calls a private static so the guard itself can be exercised. */
function invoke(string $class, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);

    return $ref->invoke(null, ...$args);
}

echo "\nSecurity fix verification\n" . str_repeat('=', 60) . "\n\n";

// -----------------------------------------------------------------
echo "Razorpay webhook — empty secret\n";

$body   = '{"event":"payment.captured","payload":{"payment":{"entity":{"id":"pay_forged","order_id":"order_x"}}}}';
$forged = hash_hmac('sha256', $body, '');

check(
    'a signature computed with an empty secret is REJECTED',
    invoke(Zuste\Services\PaymentService::class, 'verifyRazorpayWebhookSignature', [$body, $forged]) === false,
    'an unconfigured secret still authenticates a forged capture event'
);

check(
    'an empty signature is rejected',
    invoke(Zuste\Services\PaymentService::class, 'verifyRazorpayWebhookSignature', [$body, '']) === false
);

// With a real secret, a correct signature must still pass — the fix must not
// break the working path.
$ref = new ReflectionClass(Zuste\Core\Config::class);
$prop = $ref->getProperty('settings');
$prop->setAccessible(true);
$settings = $prop->getValue();
if (!is_array($settings)) {
    Zuste\Core\Config::settingString('payment', 'razorpay_webhook_secret');
    $settings = $prop->getValue();
}
$settings['payment']['razorpay_webhook_secret'] = 'a-real-webhook-secret';
$prop->setValue(null, $settings);

check(
    'a correct signature with a configured secret is ACCEPTED',
    invoke(
        Zuste\Services\PaymentService::class,
        'verifyRazorpayWebhookSignature',
        [$body, hash_hmac('sha256', $body, 'a-real-webhook-secret')]
    ) === true,
    'the fix broke legitimate Razorpay webhooks'
);

check(
    'a signature from the wrong secret is rejected',
    invoke(
        Zuste\Services\PaymentService::class,
        'verifyRazorpayWebhookSignature',
        [$body, hash_hmac('sha256', $body, 'some-other-secret')]
    ) === false
);

Zuste\Core\Config::flushSettings();

// -----------------------------------------------------------------
echo "\nWebhook de-dup ordering\n";

$src       = (string) file_get_contents($basePath . '/app/Services/PaymentService.php');
$rejectPos = strpos($src, "throw HttpException::unauthorized('Invalid webhook signature.'");
$realInsert = strpos($src, "'is_verified' => 1,");

check(
    'the genuine de-dup row is only written AFTER verification passes',
    $rejectPos !== false && $realInsert !== false && $realInsert > $rejectPos,
    'an unverified event can still consume the (gateway, event_id) unique key'
);

check(
    'a rejected webhook is still recorded, under a non-colliding id',
    str_contains($src, "'event_id'      => 'rejected:' . Crypto::uuid4()"),
    'rejected webhooks are no longer audited'
);

// -----------------------------------------------------------------
echo "\nProxy trust\n";

check(
    'trusted_proxies defaults to empty (trust nothing)',
    (array) Zuste\Core\Config::get('app.trusted_proxies', []) === [],
    'trusted_proxies is populated: ' . json_encode(Zuste\Core\Config::get('app.trusted_proxies'))
);

// Simulate a request that spoofs its origin.
$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

$request = Zuste\Core\Request::capture();

check(
    'X-Forwarded-For from an untrusted hop is IGNORED',
    $request->ip() === '203.0.113.9',
    'ip() returned ' . $request->ip() . ' — the header was believed'
);

// Now trust that hop and confirm the header IS honoured.
Zuste\Core\Config::set('app.trusted_proxies', ['203.0.113.9']);
$trusted = Zuste\Core\Request::capture();

check(
    'X-Forwarded-For from a trusted hop IS honoured',
    $trusted->ip() === '1.2.3.4',
    'ip() returned ' . $trusted->ip()
);

Zuste\Core\Config::set('app.trusted_proxies', ['203.0.113.0/24']);
$cidr = Zuste\Core\Request::capture();

check(
    'a CIDR block matches',
    $cidr->ip() === '1.2.3.4',
    'ip() returned ' . $cidr->ip()
);

Zuste\Core\Config::set('app.trusted_proxies', ['198.51.100.0/24']);
$wrongCidr = Zuste\Core\Request::capture();

check(
    'a non-matching CIDR does not grant trust',
    $wrongCidr->ip() === '203.0.113.9',
    'ip() returned ' . $wrongCidr->ip()
);

Zuste\Core\Config::set('app.trusted_proxies', []);

// -----------------------------------------------------------------
echo "\nNotification action types\n";

$D    = Zuste\Core\Database::class;
$col  = $D::selectOne('SHOW COLUMNS FROM `notifications` LIKE "action_type"');
preg_match_all("/'([^']+)'/", (string) ($col['Type'] ?? ''), $m);
$members = $m[1];

$used = $D::column(
    'SELECT DISTINCT `action_type` FROM `notification_templates`
      WHERE `action_type` IS NOT NULL AND `action_type` <> ""'
);

$invalid = array_values(array_diff($used, $members));

check(
    'every seeded template action_type is a valid ENUM member',
    $invalid === [],
    'invalid: ' . implode(', ', $invalid)
);

check(
    'the guard coerces an unknown action_type instead of throwing',
    invoke(Zuste\Services\NotificationService::class, 'safeActionType', ['not_a_real_type', 'probe']) === 'none'
);

check(
    'the guard passes a valid action_type through unchanged',
    invoke(Zuste\Services\NotificationService::class, 'safeActionType', ['kyc', 'probe']) === 'kyc'
);

// -----------------------------------------------------------------
echo "\nAdmin two-factor enforcement\n";

// 2FA was enrolled, shown as active in the security panel, and enforced
// NOWHERE: AuthController::login granted a full session on a correct password
// alone, and SecurityService::verifyTwoFactorCode had zero callers. These
// checks exist so that cannot silently come back.

$loginSource = (string) file_get_contents($basePath . '/app/Controllers/Admin/AuthController.php');
$routeSource = (string) file_get_contents($basePath . '/routes/admin.php');

check(
    'admin login consults two_factor_enabled before granting a session',
    str_contains($loginSource, 'two_factor_enabled')
        && strpos($loginSource, 'two_factor_enabled') < strpos($loginSource, 'private function grantSession'),
    'the login flow never checks whether the account has a second factor'
);

check(
    'the pending identity is held apart from the admin session key',
    str_contains($loginSource, "PENDING_2FA_KEY = 'admin_pending_2fa'"),
    'a half-authenticated visitor may be holding the real session key'
);

check(
    'the challenge accepts a recovery code as well as a TOTP',
    str_contains($loginSource, 'consumeRecoveryCode'),
    'a lost authenticator would lock the admin out permanently'
);

check(
    'the second factor is rate limited independently of the password',
    str_contains($loginSource, "'admin-2fa:'"),
    'a valid password would buy unlimited guesses at a six-digit code'
);

check(
    'the pending window expires',
    str_contains($loginSource, 'PENDING_2FA_TTL'),
    'a password would grant an indefinite window to brute-force the code'
);

check(
    'the challenge route is registered',
    str_contains($routeSource, "'/login/two-factor'"),
    'the challenge page is unreachable'
);

// The TOTP primitive itself has to work, or the gate is theatre.
$totpSecret = Zuste\Core\Totp::generateSecret();

check(
    'a freshly generated TOTP verifies',
    Zuste\Core\Totp::verify($totpSecret, Zuste\Core\Totp::codeAt($totpSecret)),
    'Totp::verify rejected a code it had just produced'
);

check(
    'an arbitrary code is rejected',
    !Zuste\Core\Totp::verify($totpSecret, '000000'),
    'Totp::verify accepted an arbitrary code'
);

check(
    'a code minted from another secret is rejected',
    !Zuste\Core\Totp::verify(
        $totpSecret,
        Zuste\Core\Totp::codeAt(Zuste\Core\Totp::generateSecret())
    ),
    'Totp::verify accepted a code from a different secret'
);

check(
    'a code from ten minutes ago is rejected',
    !Zuste\Core\Totp::verify($totpSecret, Zuste\Core\Totp::codeAt($totpSecret, time() - 600)),
    'a stale code still verifies, so an observed code stays usable indefinitely'
);

// -----------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
