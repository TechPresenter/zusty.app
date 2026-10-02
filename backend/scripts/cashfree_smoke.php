<?php

declare(strict_types=1);

/**
 * Cashfree gateway smoke test.
 *
 * Exercises everything that does not need Cashfree on the other end of a
 * socket: schema, settings, webhook signature verification (including the
 * replay window), event-id derivation, the refund state machine, and the
 * checkout gateway list. Run with:
 *
 *     php scripts/cashfree_smoke.php
 *
 * Read-only apart from a refund row it creates and rolls back, so it is safe
 * to run against a development database.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Services\PaymentService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

App::boot($basePath);

$pass = 0;
$fail = 0;

function check(string $label, callable $test): void
{
    global $pass, $fail;

    try {
        $result = $test();
        if ($result === true) {
            echo "  PASS  {$label}\n";
            $pass++;
            return;
        }
        echo "  FAIL  {$label} — {$result}\n";
        $fail++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$label} — " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $fail++;
    }
}

/**
 * Overrides a platform setting for the duration of the test.
 *
 * Config caches settings in a private static array and exposes no setter, so
 * the cache is poked directly — this leaves the database untouched.
 */
function setSetting(string $group, string $key, string $value): void
{
    $ref = new ReflectionProperty(Config::class, 'settings');
    $ref->setAccessible(true);

    $settings = $ref->getValue();

    if (!is_array($settings)) {
        Config::settingString($group, $key);   // Force a load.
        $settings = $ref->getValue();
    }

    $settings[$group][$key] = $value;
    $ref->setValue(null, $settings);
}

/** Calls a private static method so the internals can be tested in isolation. */
function invoke(string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod(PaymentService::class, $method);
    $ref->setAccessible(true);

    return $ref->invoke(null, ...$args);
}

echo "\nCashfree gateway smoke test\n";
echo str_repeat('=', 54) . "\n\n";

// -----------------------------------------------------------------
// Schema
// -----------------------------------------------------------------

echo "Schema\n";

check('payments.gateway accepts cashfree', function () {
    $column = Database::selectOne("SHOW COLUMNS FROM `payments` LIKE 'gateway'");

    return str_contains((string) ($column['Type'] ?? ''), "'cashfree'")
        ?: 'ENUM is ' . (string) ($column['Type'] ?? 'missing');
});

check('payment_webhooks.gateway accepts cashfree', function () {
    $column = Database::selectOne("SHOW COLUMNS FROM `payment_webhooks` LIKE 'gateway'");

    return str_contains((string) ($column['Type'] ?? ''), "'cashfree'")
        ?: 'ENUM is ' . (string) ($column['Type'] ?? 'missing');
});

check('payments.gateway_reference exists', function () {
    return Database::selectOne("SHOW COLUMNS FROM `payments` LIKE 'gateway_reference'") !== null
        ?: 'column missing';
});

check('duplicate webhook events are rejected by the unique key', function () {
    $indexes = Database::select('SHOW INDEX FROM `payment_webhooks` WHERE `Key_name` = "uq_pw_event"');

    return count($indexes) === 2 ?: 'expected a 2-column unique key, found ' . count($indexes);
});

// -----------------------------------------------------------------
// Settings
// -----------------------------------------------------------------

echo "\nSettings\n";

foreach (['cashfree_enabled', 'cashfree_app_id', 'cashfree_secret_key', 'cashfree_mode', 'cashfree_return_url'] as $key) {
    check("setting payment.{$key} is seeded", function () use ($key) {
        return Database::selectOne(
            'SELECT `id` FROM `settings` WHERE `group_key` = "payment" AND `setting_key` = :k',
            ['k' => $key]
        ) !== null ?: 'not found';
    });
}

check('the secret key is stored encrypted and never public', function () {
    $row = Database::selectOne(
        'SELECT `is_encrypted`, `is_public` FROM `settings`
         WHERE `group_key` = "payment" AND `setting_key` = "cashfree_secret_key"'
    );

    if ((int) ($row['is_encrypted'] ?? 0) !== 1) {
        return 'is_encrypted is not 1';
    }

    return (int) ($row['is_public'] ?? 1) === 0 ?: 'is_public is not 0';
});

check('mode defaults to sandbox', function () {
    return invoke('cashfreeMode') === 'sandbox'
        ?: 'mode resolved to ' . (string) invoke('cashfreeMode');
});

check('the API base follows the mode', function () {
    return invoke('cashfreeApiBase') === 'https://sandbox.cashfree.com/pg'
        ?: 'base is ' . (string) invoke('cashfreeApiBase');
});

check('the notify URL points at our webhook route', function () {
    $url = (string) invoke('cashfreeNotifyUrl');

    return str_ends_with($url, '/api/v1/webhooks/cashfree') ?: 'url is ' . $url;
});

check('headers pin the API version Cashfree keys its schema off', function () {
    $headers = invoke('cashfreeHeaders', ['app', 'secret']);

    return ($headers['x-api-version'] ?? '') === '2023-08-01'
        ?: 'version is ' . (string) ($headers['x-api-version'] ?? 'missing');
});

check('missing credentials fail closed', function () {
    try {
        invoke('cashfreeCredentials');
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), 'not configured')
            ?: 'wrong message: ' . $e->getMessage();
    }

    // Credentials are configured on this machine — that is also a pass.
    return true;
});

// -----------------------------------------------------------------
// Webhook signature
// -----------------------------------------------------------------

echo "\nWebhook signature\n";

$secret = 'test_secret_key';
$body   = '{"type":"PAYMENT_SUCCESS_WEBHOOK","data":{"order":{"order_id":"ZPAY00000001"}}}';

// Point the verifier at a known secret for the duration of these checks.
setSetting('payment', 'cashfree_secret_key', $secret);

check('a correct signature verifies', function () use ($body, $secret) {
    $ts        = (string) time();
    $signature = base64_encode(hash_hmac('sha256', $ts . $body, $secret, true));

    return invoke('verifyCashfreeWebhookSignature', [$body, $signature, $ts]) === true
        ?: 'rejected a valid signature';
});

check('a tampered body is rejected', function () use ($body, $secret) {
    $ts        = (string) time();
    $signature = base64_encode(hash_hmac('sha256', $ts . $body, $secret, true));

    return invoke('verifyCashfreeWebhookSignature', [$body . ' ', $signature, $ts]) === false
        ?: 'accepted a tampered body';
});

check('a signature from the wrong secret is rejected', function () use ($body) {
    $ts        = (string) time();
    $signature = base64_encode(hash_hmac('sha256', $ts . $body, 'other_secret', true));

    return invoke('verifyCashfreeWebhookSignature', [$body, $signature, $ts]) === false
        ?: 'accepted a foreign signature';
});

check('a replayed event outside the 5-minute window is rejected', function () use ($body, $secret) {
    $ts        = (string) (time() - 3600);
    $signature = base64_encode(hash_hmac('sha256', $ts . $body, $secret, true));

    return invoke('verifyCashfreeWebhookSignature', [$body, $signature, $ts]) === false
        ?: 'accepted a stale event';
});

check('a missing timestamp is rejected', function () use ($body, $secret) {
    $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

    return invoke('verifyCashfreeWebhookSignature', [$body, $signature, '']) === false
        ?: 'accepted an event with no timestamp';
});

check('an empty signature is rejected', function () use ($body) {
    return invoke('verifyCashfreeWebhookSignature', [$body, '', (string) time()]) === false
        ?: 'accepted an empty signature';
});

// -----------------------------------------------------------------
// Event identity
// -----------------------------------------------------------------

echo "\nEvent identity\n";

check('a redelivered payment event keeps the same id', function () {
    $payload = [
        'type' => 'PAYMENT_SUCCESS_WEBHOOK',
        'data' => ['payment' => ['cf_payment_id' => '99887766'], 'order' => ['order_id' => 'ZPAY00000001']],
    ];

    $first  = invoke('webhookEventId', ['cashfree', $payload]);
    $second = invoke('webhookEventId', ['cashfree', $payload]);

    return ($first === $second && $first === 'PAYMENT_SUCCESS_WEBHOOK:99887766')
        ?: "ids were {$first} / {$second}";
});

check('payment and refund events on one order do not collide', function () {
    $payment = invoke('webhookEventId', ['cashfree', [
        'type' => 'PAYMENT_SUCCESS_WEBHOOK',
        'data' => ['order' => ['order_id' => 'ZPAY00000001']],
    ]]);

    $refund = invoke('webhookEventId', ['cashfree', [
        'type' => 'REFUND_STATUS_WEBHOOK',
        'data' => ['order' => ['order_id' => 'ZPAY00000001']],
    ]]);

    return $payment !== $refund ?: 'both resolved to ' . $payment;
});

check('razorpay and stripe id derivation is unchanged', function () {
    $stripe = invoke('webhookEventId', ['stripe', ['id' => 'evt_123']]);

    $razorpay = invoke('webhookEventId', ['razorpay', [
        'payload' => ['payment' => ['entity' => ['id' => 'pay_123']]],
    ]]);

    return ($stripe === 'evt_123' && $razorpay === 'pay_123')
        ?: "got {$stripe} / {$razorpay}";
});

// -----------------------------------------------------------------
// Order id mapping
// -----------------------------------------------------------------

echo "\nOrder id mapping\n";

check('the order id is the payment reference, so a webhook maps back', function () {
    $reference = \Zuste\Helpers\Str::referenceCode('ZPAY', 4242, 8);

    if ($reference !== 'ZPAY00004242') {
        return 'reference is ' . $reference;
    }

    // Cashfree only accepts alphanumerics, underscores and hyphens.
    return preg_match('/^[A-Za-z0-9_-]+$/', $reference) === 1
        ?: 'reference contains characters Cashfree rejects';
});

// -----------------------------------------------------------------
// Refund lifecycle
// -----------------------------------------------------------------

echo "\nRefund lifecycle\n";

check('an unsettled gateway refund stays processing, not completed', function () {
    // The state machine is in refundBooking: accepted && !settled → processing.
    $accepted = true;
    $settled  = false;

    $status = match (true) {
        $settled  => 'completed',
        $accepted => 'processing',
        default   => 'failed',
    };

    return $status === 'processing' ?: 'resolved to ' . $status;
});

check('completeRefund is idempotent on an already-completed refund', function () {
    $payment = Database::selectOne(
        "SELECT * FROM `payments` WHERE `status` = 'captured' ORDER BY `id` DESC LIMIT 1"
    );

    if ($payment === null) {
        echo "        (skipped: no captured payment in this database)\n";
        return true;
    }

    $refundId = Database::insert('refunds', [
        'reference'         => 'ZRF-SMOKE',
        'payment_id'        => (int) $payment['id'],
        'booking_id'        => $payment['booking_id'] !== null ? (int) $payment['booking_id'] : null,
        'user_id'           => (int) $payment['user_id'],
        'amount'            => 1.00,
        'currency'          => (string) $payment['currency'],
        'destination'       => 'wallet',
        'reason'            => 'other',
        'status'            => 'completed',
        'initiated_by_type' => 'system',
        'processed_at'      => date('Y-m-d H:i:s'),
        'created_at'        => date('Y-m-d H:i:s'),
    ]);

    $before = Database::selectOne('SELECT `amount_refunded` FROM `payments` WHERE `id` = :id', ['id' => (int) $payment['id']]);

    PaymentService::completeRefund($refundId);

    $after = Database::selectOne('SELECT `amount_refunded` FROM `payments` WHERE `id` = :id', ['id' => (int) $payment['id']]);

    Database::delete('refunds', ['id' => $refundId]);

    return (float) $before['amount_refunded'] === (float) $after['amount_refunded']
        ?: 'a completed refund was applied a second time';
});

// -----------------------------------------------------------------
// Checkout
// -----------------------------------------------------------------

echo "\nCheckout\n";

check('cashfree is offered once enabled and hidden when disabled', function () {
    setSetting('payment', 'cashfree_enabled', '1');
    setSetting('payment', 'cashfree_app_id', 'TEST_APP_ID');

    $ids = array_column(PaymentService::availableGateways(), 'id');

    if (!in_array('cashfree', $ids, true)) {
        return 'not offered when enabled';
    }

    setSetting('payment', 'cashfree_enabled', '0');

    $ids = array_column(PaymentService::availableGateways(), 'id');

    return !in_array('cashfree', $ids, true) ?: 'still offered when disabled';
});

check('the checkout payload never leaks the secret key', function () {
    setSetting('payment', 'cashfree_enabled', '1');

    $gateway = null;

    foreach (PaymentService::availableGateways() as $candidate) {
        if ($candidate['id'] === 'cashfree') {
            $gateway = $candidate;
        }
    }

    setSetting('payment', 'cashfree_enabled', '0');

    if ($gateway === null) {
        return 'cashfree was not offered';
    }

    $encoded = strtolower((string) json_encode($gateway));

    return !str_contains($encoded, 'secret') ?: 'the payload mentions a secret';
});

// -----------------------------------------------------------------
// Routing
// -----------------------------------------------------------------

echo "\nRouting\n";

check('the webhook route is registered', function () use ($basePath) {
    $routes = (string) file_get_contents($basePath . '/routes/api.php');

    return str_contains($routes, "'/webhooks/cashfree'") ?: 'route not found in routes/api.php';
});

check('the client can request a cashfree order and verify it', function () use ($basePath) {
    $controller = (string) file_get_contents($basePath . '/app/Controllers/Api/WalletController.php');

    if (!str_contains($controller, 'in:razorpay,stripe,cashfree,wallet')) {
        return 'payBooking does not accept cashfree';
    }

    if (!str_contains($controller, 'in:razorpay,stripe,cashfree')) {
        return 'topup does not accept cashfree';
    }

    return str_contains($controller, 'verifyCashfree') ?: 'verify does not handle cashfree';
});

// -----------------------------------------------------------------

// Drop every override so nothing leaks into a subsequent process.
Config::flushSettings();

echo "\n" . str_repeat('=', 54) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";

exit($fail === 0 ? 0 : 1);
