<?php

declare(strict_types=1);

namespace Zuste\Tests;

/**
 * Minimal HTTP client for the integration suite.
 *
 * Deliberately talks to the API over a real socket rather than invoking
 * controllers in-process: routing, middleware, CORS, security headers, session
 * handling and the JSON envelope are all part of what we are testing, and none
 * of them run if you call the controller directly.
 */
final class Http
{
    /** @var array<string,string> Bearer tokens by actor name. */
    private static array $tokens = [];

    private static string $baseUrl = 'http://127.0.0.1:8000/api/v1';

    /** Requests made, for the run summary. */
    public static int $requestCount = 0;

    /** Total wall time spent in HTTP, in milliseconds. */
    public static float $totalMs = 0.0;

    /** Per-endpoint timings, for the performance report. */
    private static array $timings = [];

    public static function setBaseUrl(string $url): void
    {
        self::$baseUrl = rtrim($url, '/');
    }

    public static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    public static function setToken(string $actor, string $token): void
    {
        self::$tokens[$actor] = $token;
    }

    public static function token(string $actor): ?string
    {
        return self::$tokens[$actor] ?? null;
    }

    public static function forgetToken(string $actor): void
    {
        unset(self::$tokens[$actor]);
    }

    // -----------------------------------------------------------------
    // Verbs
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $query */
    public static function get(string $path, array $query = [], ?string $actor = null, array $headers = []): Res
    {
        if ($query !== []) {
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
        }

        return self::send('GET', $path, null, $actor, $headers);
    }

    /** @param array<string,mixed>|null $body */
    public static function post(string $path, ?array $body = null, ?string $actor = null, array $headers = []): Res
    {
        return self::send('POST', $path, $body, $actor, $headers);
    }

    public static function put(string $path, ?array $body = null, ?string $actor = null, array $headers = []): Res
    {
        return self::send('PUT', $path, $body, $actor, $headers);
    }

    public static function patch(string $path, ?array $body = null, ?string $actor = null, array $headers = []): Res
    {
        return self::send('PATCH', $path, $body, $actor, $headers);
    }

    public static function delete(string $path, ?array $body = null, ?string $actor = null, array $headers = []): Res
    {
        return self::send('DELETE', $path, $body, $actor, $headers);
    }

    /**
     * Multipart upload, for testing the file endpoints for real.
     *
     * @param array<string,string> $files field => absolute path
     * @param array<string,mixed>  $fields
     */
    public static function upload(string $path, array $files, array $fields = [], ?string $actor = null): Res
    {
        $payload = $fields;

        foreach ($files as $field => $filePath) {
            $payload[$field] = new \CURLFile(
                $filePath,
                (string) (mime_content_type($filePath) ?: 'application/octet-stream'),
                basename($filePath)
            );
        }

        return self::raw('POST', $path, $payload, $actor, [], false);
    }

    /**
     * Sends a body exactly as given, bypassing JSON encoding.
     *
     * Needed for webhook tests, where the signature is computed over the exact
     * bytes and any re-encoding would invalidate it.
     */
    public static function rawBody(string $method, string $path, string $body, array $headers = []): Res
    {
        return self::raw($method, $path, $body, null, $headers, false);
    }

    // -----------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------

    /** @param array<string,mixed>|null $body */
    private static function send(string $method, string $path, ?array $body, ?string $actor, array $headers): Res
    {
        return self::raw(
            $method,
            $path,
            $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE),
            $actor,
            array_merge(['Content-Type' => 'application/json'], $headers),
            true
        );
    }

    private static function raw(
        string $method,
        string $path,
        mixed $body,
        ?string $actor,
        array $headers,
        bool $isJson
    ): Res {
        $url = str_starts_with($path, 'http') ? $path : self::$baseUrl . $path;

        $handle = curl_init();

        $headerLines = ['Accept: application/json', 'X-Platform: test'];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        if ($actor !== null && isset(self::$tokens[$actor])) {
            $headerLines[] = 'Authorization: Bearer ' . self::$tokens[$actor];
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $started = microtime(true);
        $raw     = curl_exec($handle);
        $ms      = (microtime(true) - $started) * 1000;

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        self::$requestCount++;
        self::$totalMs += $ms;

        // Group timings by route shape so /creators/12 and /creators/13 pool.
        $shape = preg_replace('#/\d+#', '/{id}', strtok($path, '?')) ?: $path;
        self::$timings[$method . ' ' . $shape][] = $ms;

        return new Res(
            status: $status,
            body: is_string($raw) ? $raw : '',
            headers: $responseHeaders,
            ms: $ms,
            transportError: $error === '' ? null : $error,
        );
    }

    /**
     * Slowest endpoints by median latency — the performance section of the run
     * report, measured rather than guessed.
     *
     * @return list<array{endpoint:string,calls:int,medianMs:float,maxMs:float}>
     */
    public static function slowest(int $limit = 15): array
    {
        $rows = [];

        foreach (self::$timings as $endpoint => $samples) {
            sort($samples);
            $mid = intdiv(count($samples), 2);

            $rows[] = [
                'endpoint' => $endpoint,
                'calls'    => count($samples),
                'medianMs' => round($samples[$mid], 1),
                'maxMs'    => round(max($samples), 1),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['medianMs'] <=> $a['medianMs']);

        return array_slice($rows, 0, $limit);
    }
}

/**
 * One HTTP response, with helpers for reaching into the API's
 * `{success, message, data, meta}` envelope.
 */
final class Res
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly float $ms,
        public readonly ?string $transportError = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function success(): bool
    {
        return ($this->json()['success'] ?? false) === true;
    }

    public function message(): string
    {
        return (string) ($this->json()['message'] ?? '');
    }

    public function code(): string
    {
        return (string) ($this->json()['code'] ?? '');
    }

    /** `data` as an object. */
    public function data(): array
    {
        $data = $this->json()['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /** `data` as a list, whether it is a bare list or nested under [$key]. */
    public function list(?string $key = null): array
    {
        $data = $this->data();

        if ($key !== null) {
            return is_array($data[$key] ?? null) ? $data[$key] : [];
        }

        if (array_is_list($data)) {
            return $data;
        }

        // Unwrap the single list the endpoint returned, e.g. {creators: [...]}.
        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        return [];
    }

    /** Dot path into the envelope, e.g. `data.user.id` or `meta.pagination.total`. */
    public function at(string $path): mixed
    {
        $cursor = $this->json();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Compact one-line description, used in failure output. */
    public function describe(int $bodyChars = 240): string
    {
        if ($this->transportError !== null) {
            return "transport error: {$this->transportError}";
        }

        return sprintf('HTTP %d — %s', $this->status, mb_substr(trim($this->body), 0, $bodyChars));
    }
}
