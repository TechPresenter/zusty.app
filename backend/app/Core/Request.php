<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Immutable-ish view over the current HTTP request.
 *
 * JSON and form bodies are normalised into a single `input` bag, so
 * controllers never care how the client encoded its payload.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $query = [];

    /** @var array<string,mixed> */
    private array $body = [];

    /** @var array<string,string> */
    private array $headers = [];

    /** @var array<string,mixed> */
    private array $files = [];

    /** @var array<string,string> */
    private array $routeParams = [];

    /** @var array<string,mixed> Values attached by middleware (auth user, etc.) */
    private array $attributes = [];

    private string $method = 'GET';
    private string $path   = '/';
    private string $rawBody = '';

    public static function capture(): self
    {
        $request = new self();

        $request->method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $request->headers = self::readHeaders();

        // Support HTML form method spoofing for the admin panel.
        if ($request->method === 'POST') {
            $override = $_POST['_method'] ?? ($request->headers['x-http-method-override'] ?? null);
            if (is_string($override) && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
                $request->method = strtoupper($override);
            }
        }

        $uri           = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $request->path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');

        $request->query   = $_GET;
        $request->files   = $_FILES;
        $request->rawBody = self::readRawBody();
        $request->body    = self::parseBody($request->rawBody, $request->headers, $request->method);

        return $request;
    }

    // -----------------------------------------------------------------
    // Basics
    // -----------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');

        return str_contains($accept, 'application/json')
            || str_contains($this->header('content-type', ''), 'application/json')
            || $this->isAjax()
            || str_starts_with($this->path, '/api/');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    public function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        // Hostinger terminates TLS at the proxy and forwards this header.
        return strtolower($this->header('x-forwarded-proto', '')) === 'https';
    }

    public function url(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }

    // -----------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------

    /** Read from body first, then query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $this->routeParams[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->input($key, $default);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body)
            || array_key_exists($key, $this->query)
            || array_key_exists($key, $this->routeParams);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->routeParams, $this->body);
    }

    /**
     * @param  list<string> $keys
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        $all    = $this->all();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }

        return $result;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    // -----------------------------------------------------------------
    // Pagination helpers
    // -----------------------------------------------------------------

    /**
     * The requested page, clamped to something a query can actually express.
     *
     * `(int)` saturates an oversized numeric string at PHP_INT_MAX, and
     * (page - 1) * per_page then overflows to a float that reaches SQL as
     * scientific notation — a syntax error, surfaced to the caller as a 500
     * carrying the driver message. No real dataset has a millionth page, so
     * the ceiling costs nothing and removes the whole failure mode.
     */
    public const MAX_PAGE = 1_000_000;

    public function page(): int
    {
        $page = (int) ($this->query['page'] ?? 1);

        return max(1, min(self::MAX_PAGE, $page));
    }

    public function perPage(int $default = 20, int $max = 100): int
    {
        $perPage = (int) ($this->query['per_page'] ?? $this->query['limit'] ?? $default);

        return max(1, min($max, $perPage));
    }

    public function offset(int $default = 20, int $max = 100): int
    {
        return ($this->page() - 1) * $this->perPage($default, $max);
    }

    /**
     * Sort direction, whitelisted to ASC/DESC.
     */
    public function sortDirection(string $default = 'DESC'): string
    {
        $direction = strtoupper($this->string('sort_dir', $default));

        return $direction === 'ASC' ? 'ASC' : 'DESC';
    }

    /**
     * Sort column validated against an allow-list — never trust user input
     * to name a column.
     *
     * @param list<string> $allowed
     */
    public function sortColumn(array $allowed, string $default): string
    {
        $column = $this->string('sort_by', $default);

        return in_array($column, $allowed, true) ? $column : $default;
    }

    // -----------------------------------------------------------------
    // Headers / client
    // -----------------------------------------------------------------

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Extract the token from an `Authorization: Bearer …` header. */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header !== '' && preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $remote = filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';

        // Forwarding headers are only believed when the request actually
        // arrived FROM a trusted proxy.
        //
        // Trusting them unconditionally means any client can send
        // `X-Forwarded-For: 1.2.3.4` and become a different IP on every
        // request — which defeats the throttle middleware, the brute-force
        // lockout and the admin IP allow-list simultaneously. The header is
        // only meaningful when the hop that set it is one we control.
        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        foreach (['cf-connecting-ip', 'x-real-ip', 'x-forwarded-for'] as $header) {
            $value = $this->header($header);
            if ($value === '') {
                continue;
            }

            // X-Forwarded-For is a chain: client, proxy1, proxy2. The
            // left-most entry is the originating client.
            $candidate = trim(explode(',', $value)[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * Is [$remote] one of the proxies whose forwarding headers we believe?
     *
     * `app.trusted_proxies` holds addresses or CIDR blocks. The literal
     * `'*'` restores the old blanket-trust behaviour and is deliberately
     * something an operator has to opt into.
     */
    private static function isTrustedProxy(string $remote): bool
    {
        /** @var list<string> $trusted */
        $trusted = (array) Config::get('app.trusted_proxies', []);

        if ($trusted === []) {
            return false;
        }

        foreach ($trusted as $entry) {
            $entry = trim((string) $entry);

            if ($entry === '*') {
                return true;
            }

            if ($entry === $remote) {
                return true;
            }

            if (str_contains($entry, '/') && self::inCidr($remote, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** IPv4 CIDR membership, e.g. 10.0.0.0/8. */
    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $bits       = (int) $bits;

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    public function userAgent(): string
    {
        return mb_substr($this->header('user-agent'), 0, 255);
    }

    public function origin(): string
    {
        return $this->header('origin');
    }

    // -----------------------------------------------------------------
    // Files
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    /** @return array<string,mixed> */
    public function files(): array
    {
        return $this->files;
    }

    // -----------------------------------------------------------------
    // Route params + attributes
    // -----------------------------------------------------------------

    /** @param array<string,string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function routeParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeParamInt(string $key, int $default = 0): int
    {
        $value = $this->routeParams[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string,string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** The authenticated API user, set by AuthMiddleware. */
    public function user(): ?array
    {
        $user = $this->attributes['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    public function userId(): int
    {
        return (int) ($this->user()['id'] ?? 0);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @return array<string,string> */
    private static function readHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }

        // Fallback / supplement from $_SERVER (CGI never exposes getallheaders).
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] ??= (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] ??= (string) $_SERVER[$server];
            }
        }

        // Some CGI setups strip Authorization; Apache is told to re-add it in
        // .htaccess as REDIRECT_HTTP_AUTHORIZATION.
        if (!isset($headers['authorization'])) {
            $fallback = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
            if (is_string($fallback) && $fallback !== '') {
                $headers['authorization'] = $fallback;
            }
        }

        return $headers;
    }

    private static function readRawBody(): string
    {
        $body = file_get_contents('php://input');

        return $body === false ? '' : $body;
    }

    /**
     * @param  array<string,string> $headers
     * @return array<string,mixed>
     */
    private static function parseBody(string $raw, array $headers, string $method): array
    {
        $contentType = strtolower($headers['content-type'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        if ($_POST !== []) {
            return $_POST;
        }

        // PHP only populates $_POST for POST requests; parse PUT/PATCH manually.
        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)
            && str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $parsed = [];
            parse_str($raw, $parsed);

            return $parsed;
        }

        return [];
    }
}
