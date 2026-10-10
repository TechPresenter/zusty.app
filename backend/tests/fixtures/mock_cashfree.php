<?php

declare(strict_types=1);

/**
 * A stand-in for Cashfree's PG API, for the wallet and payments suite.
 *
 *     php -S 127.0.0.1:8097 tests/fixtures/mock_cashfree.php
 *
 * It answers the two calls the suite drives, the way Cashfree does:
 *
 *  - POST /pg/orders                creates the order and hands back a
 *                                   payment session, echoing our order id;
 *  - POST /pg/orders/{id}/refunds   accepts the refund as PENDING — Cashfree
 *                                   confirms refunds later, by webhook — or,
 *                                   for an order id containing `REFUSE`,
 *                                   refuses it with a 400.
 *
 * A request without the client id and secret headers is refused with a 401,
 * and anything else is a 404. Every request is appended as one JSON line —
 * method, path and body, never the headers, which carry the credentials — to
 * the file named by ZUSTE_MOCK_CASHFREE_LOG, so the suite can assert on what
 * the backend sent.
 */

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

$log = getenv('ZUSTE_MOCK_CASHFREE_LOG') ?: sys_get_temp_dir() . '/zuste-mock-cashfree.log';
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path'   => $path,
    'body'   => $body,
]) . "\n", FILE_APPEND | LOCK_EX);

/** @param array<string,mixed> $payload */
function reply(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (($_SERVER['HTTP_X_CLIENT_ID'] ?? '') === '' || ($_SERVER['HTTP_X_CLIENT_SECRET'] ?? '') === '') {
    reply(401, ['message' => 'authentication Failed', 'code' => 'request_failed', 'type' => 'authentication_error']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(404, ['message' => 'Unknown endpoint.', 'code' => 'request_invalid', 'type' => 'invalid_request_error']);
}

if (preg_match('#/orders$#', $path) === 1) {
    $orderId = (string) ($body['order_id'] ?? '');

    reply(200, [
        'cf_order_id'        => 'cf_' . substr(sha1($orderId), 0, 12),
        'order_id'           => $orderId,
        'order_amount'       => $body['order_amount'] ?? 0,
        'order_currency'     => $body['order_currency'] ?? 'INR',
        'order_status'       => 'ACTIVE',
        'payment_session_id' => 'session_it_' . substr(sha1($orderId . 'session'), 0, 16),
    ]);
}

if (preg_match('#/orders/([^/]+)/refunds$#', $path, $match) === 1) {
    $refundId = (string) ($body['refund_id'] ?? '');

    if (str_contains($match[1], 'REFUSE')) {
        reply(400, ['message' => 'refund_amount should be less than or equal to the paid amount', 'code' => 'refund_amount_invalid', 'type' => 'invalid_request_error']);
    }

    reply(200, [
        'cf_refund_id'  => 'cfr_' . substr(sha1($refundId), 0, 12),
        'refund_id'     => $refundId,
        'order_id'      => rawurldecode($match[1]),
        'refund_amount' => $body['refund_amount'] ?? 0,
        'refund_status' => 'PENDING',
    ]);
}

reply(404, ['message' => 'Unknown endpoint.', 'code' => 'request_invalid', 'type' => 'invalid_request_error']);
