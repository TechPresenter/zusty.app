<?php

declare(strict_types=1);

namespace Zuste\Helpers;

use Zuste\Core\Logger;

/**
 * Thin cURL wrapper for outbound calls (Razorpay, Stripe, FCM, SMS).
 *
 * TLS verification is always on and never configurable — a payment or push
 * call that silently downgrades its transport security is worse than one that
 * fails loudly.
 */
final class Http
{
    /**
     * @param  array<string,string> $headers
     * @return array{status:int,body:string,json:array<mixed>|null,error:string|null,duration_ms:float}
     */
    public static function request(
        string $method,
        string $url,
        mixed $body = null,
        array $headers = [],
        int $timeout = 30,
        ?array $basicAuth = null
    ): array {
        $started = microtime(true);

        $handle = curl_init();

        $defaultHeaders = ['Accept: application/json'];
        $payload        = null;

        if ($body !== null) {
            if (is_array($body)) {
                $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/json';

                if (str_contains(strtolower($contentType), 'x-www-form-urlencoded')) {
                    $payload = http_build_query($body);
                } else {
                    $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $defaultHeaders[] = 'Content-Type: ' . $contentType;
            } else {
                $payload = (string) $body;
            }
        }

        foreach ($headers as $name => $value) {
            $defaultHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $defaultHeaders,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Zuste-Backend/1.0',
        ]);

        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        if ($basicAuth !== null) {
            curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($handle, CURLOPT_USERPWD, $basicAuth[0] . ':' . $basicAuth[1]);
        }

        $responseBody = curl_exec($handle);
        $status       = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error        = curl_errno($handle) !== 0 ? curl_error($handle) : null;

        curl_close($handle);

        $bodyString = is_string($responseBody) ? $responseBody : '';
        $json       = null;

        if ($bodyString !== '') {
            $decoded = json_decode($bodyString, true);
            $json    = is_array($decoded) ? $decoded : null;
        }

        $duration = round((microtime(true) - $started) * 1000, 2);

        if ($error !== null || $status >= 400) {
            Logger::warning('Outbound HTTP call failed', [
                'method'      => strtoupper($method),
                'url'         => self::redactUrl($url),
                'status'      => $status,
                'error'       => $error,
                'duration_ms' => $duration,
                // Response bodies from gateways can contain customer data.
                'body'        => mb_substr($bodyString, 0, 500),
            ]);
        }

        return [
            'status'      => $status,
            'body'        => $bodyString,
            'json'        => $json,
            'error'       => $error,
            'duration_ms' => $duration,
        ];
    }

    /** @param array<string,string> $headers */
    public static function get(string $url, array $headers = [], int $timeout = 30): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /** @param array<string,mixed>|string|null $body */
    public static function post(string $url, mixed $body = null, array $headers = [], int $timeout = 30, ?array $basicAuth = null): array
    {
        return self::request('POST', $url, $body, $headers, $timeout, $basicAuth);
    }

    public static function successful(array $response): bool
    {
        return $response['error'] === null && $response['status'] >= 200 && $response['status'] < 300;
    }

    /** Strip credentials and tokens from a URL before it reaches the log. */
    private static function redactUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '[invalid url]';
        }

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
    }
}
