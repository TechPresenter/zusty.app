<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * HTTP response builder with a single, consistent JSON envelope.
 *
 * Success:
 *   { "success": true, "message": "...", "data": {...}, "meta": {...} }
 * Failure:
 *   { "success": false, "message": "...", "code": "validation_failed",
 *     "errors": { "field": ["..."] } }
 *
 * The Flutter client relies on this shape, so every endpoint returns it.
 */
final class Response
{
    /** @var array<string,string> */
    private static array $headers = [];

    private static bool $sent = false;

    // -----------------------------------------------------------------
    // JSON responses
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed>|list<mixed>|null $data
     * @param array<string,mixed>                  $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = []
    ): never {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        self::json($payload, $status);
    }

    /** 201 Created. */
    public static function created(mixed $data = null, string $message = 'Created successfully.'): never
    {
        self::success($data, $message, 201);
    }

    /** 200 with no body payload. */
    public static function noContent(string $message = 'Done.'): never
    {
        self::success(null, $message, 200);
    }

    /**
     * Paginated collection.
     *
     * @param list<mixed> $items
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        string $message = 'OK',
        array $extraMeta = []
    ): never {
        $lastPage = $perPage > 0 ? (int) max(1, ceil($total / $perPage)) : 1;

        self::success($items, $message, 200, array_merge([
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
                // Zero on an out-of-range page as well as an empty set. Without
                // the $page > $lastPage guard, ?page=999 on 41 rows produced
                // {from: 4991, to: 41}, so a client rendering
                // "Showing {from}-{to} of {total}" printed "Showing 4991-41 of 41".
                'from'         => ($total === 0 || $page > $lastPage) ? 0 : (($page - 1) * $perPage) + 1,
                'to'           => ($total === 0 || $page > $lastPage) ? 0 : min($page * $perPage, $total),
                'has_more'     => $page < $lastPage,
            ],
        ], $extraMeta));
    }

    // -----------------------------------------------------------------
    // Errors
    // -----------------------------------------------------------------

    /**
     * @param array<string,list<string>> $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        string $code = 'error',
        array $errors = []
    ): never {
        $payload = [
            'success' => false,
            'message' => $message,
            'code'    => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $status);
    }

    /** @param array<string,list<string>> $errors */
    public static function validationError(array $errors, string $message = 'The given data was invalid.'): never
    {
        self::error($message, 422, 'validation_failed', $errors);
    }

    public static function unauthorized(string $message = 'Authentication required.', string $code = 'unauthenticated'): never
    {
        self::error($message, 401, $code);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): never
    {
        self::error($message, 403, 'forbidden');
    }

    public static function notFound(string $message = 'Resource not found.'): never
    {
        self::error($message, 404, 'not_found');
    }

    public static function methodNotAllowed(array $allowed = []): never
    {
        if ($allowed !== []) {
            self::header('Allow', implode(', ', $allowed));
        }

        self::error('Method not allowed.', 405, 'method_not_allowed');
    }

    public static function conflict(string $message = 'The request conflicts with the current state.'): never
    {
        self::error($message, 409, 'conflict');
    }

    public static function tooManyRequests(int $retryAfter = 60, string $message = 'Too many requests. Please slow down.'): never
    {
        self::header('Retry-After', (string) $retryAfter);
        self::error($message, 429, 'rate_limited');
    }

    public static function serverError(string $message = 'An unexpected error occurred.'): never
    {
        self::error($message, 500, 'server_error');
    }

    public static function serviceUnavailable(string $message = 'Service temporarily unavailable.'): never
    {
        self::header('Retry-After', '600');
        self::error($message, 503, 'maintenance');
    }

    // -----------------------------------------------------------------
    // Raw output
    // -----------------------------------------------------------------

    /** @param array<string,mixed>|list<mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        self::sendHeaders($status, 'application/json; charset=utf-8');

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ((bool) Config::get('app.debug', false)) {
            $flags |= JSON_PRETTY_PRINT;
        }

        echo json_encode($payload, $flags);
        self::finish();
    }

    public static function html(string $content, int $status = 200): never
    {
        // API responses carry `default-src 'none'`, which also blocks a page's
        // own <style>, its logo and the invoice's Print button. The documents
        // the API serves (invoices, the expired export-link page) are
        // self-contained, so let them style themselves, show images and run
        // that one handler (allowed by hash), and still load nothing else.
        if (str_starts_with(self::$headers['Content-Security-Policy'] ?? '', "default-src 'none'")) {
            self::header('Content-Security-Policy', implode('; ', [
                "default-src 'none'",
                "style-src 'unsafe-inline'",
                "img-src 'self' data: https:",
                // sha256 of "window.print()", InvoiceService::renderHtml's button.
                "script-src 'unsafe-hashes' 'sha256-MguIPR6qNR8D3B+eAlK+bIRTZe8t3wkOY4B/56Me9FU='",
                "base-uri 'none'",
                "form-action 'none'",
                "frame-ancestors 'none'",
            ]));
        }

        self::sendHeaders($status, 'text/html; charset=utf-8');
        echo $content;
        self::finish();
    }

    public static function text(string $content, int $status = 200): never
    {
        self::sendHeaders($status, 'text/plain; charset=utf-8');
        echo $content;
        self::finish();
    }

    /** Stream a file download (CSV/Excel exports, invoices, backups). */
    public static function download(string $content, string $filename, string $mimeType = 'application/octet-stream'): never
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'download';

        self::header('Content-Disposition', 'attachment; filename="' . $safeName . '"');
        self::header('Content-Length', (string) strlen($content));
        self::header('Cache-Control', 'no-store, no-cache, must-revalidate');
        self::header('Pragma', 'no-cache');

        self::sendHeaders(200, $mimeType);
        echo $content;
        self::finish();
    }

    /**
     * Stream a file for display in the browser (an <img> or <iframe>) rather
     * than as a download.
     *
     * Never cached — these are identity documents — and never sniffed: the
     * stored, upload-time-verified MIME type is the only type the browser may
     * consider. Callers that need the file framed by their own pages set the
     * frame headers on top (see the admin KYC preview).
     */
    public static function inline(string $content, string $filename, string $mimeType = 'application/octet-stream'): never
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'document';

        self::header('Content-Disposition', 'inline; filename="' . $safeName . '"');
        self::header('Content-Length', (string) strlen($content));
        self::header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        self::header('Pragma', 'no-cache');
        self::header('X-Content-Type-Options', 'nosniff');

        self::sendHeaders(200, $mimeType);
        echo $content;
        self::finish();
    }

    public static function redirect(string $url, int $status = 302): never
    {
        // Only allow relative paths or same-host absolute URLs — blocks open
        // redirects through ?next= style parameters.
        if (!str_starts_with($url, '/')) {
            $host    = parse_url($url, PHP_URL_HOST);
            $appHost = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);

            if ($host !== null && $host !== $appHost) {
                $url = '/';
            }
        }

        if (!headers_sent()) {
            self::applyStoredHeaders();
            header('Location: ' . $url, true, $status);
        }

        self::finish();
    }

    // -----------------------------------------------------------------
    // Header management
    // -----------------------------------------------------------------

    public static function header(string $name, string $value): void
    {
        self::$headers[$name] = $value;
    }

    /** Security headers applied to every response. */
    public static function securityHeaders(bool $isHtml = false): void
    {
        self::header('X-Content-Type-Options', 'nosniff');
        self::header('X-Frame-Options', 'DENY');
        self::header('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::header('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        self::header('X-XSS-Protection', '0'); // Modern browsers: CSP replaces the legacy auditor.

        if ((bool) Config::get('security.force_https', true)) {
            self::header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($isHtml) {
            // The admin panel ships Tailwind + its own JS from the same origin.
            self::header('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: blob: https:",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        } else {
            self::header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
    }

    /** Emit CORS headers for an allow-listed origin. */
    public static function cors(string $origin): void
    {
        $allowed = (array) Config::get('security.allowed_origins', []);

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            self::header('Access-Control-Allow-Origin', $origin);
            self::header('Vary', 'Origin');
            self::header('Access-Control-Allow-Credentials', 'true');
        }

        self::header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        // If-None-Match: the app revalidates /app/config and /app/taxonomies
        // with the version it cached. Without it here, every revalidation
        // from the web app failed its preflight, so after signing in the web
        // build kept the signed-out configuration for good.
        self::header(
            'Access-Control-Allow-Headers',
            'Authorization, Content-Type, Accept, X-Requested-With, X-CSRF-Token, X-Device-Id, X-App-Version, X-Platform, Idempotency-Key, If-None-Match'
        );
        self::header('Access-Control-Expose-Headers', 'X-Request-Id, X-RateLimit-Remaining, Retry-After, ETag');
        self::header('Access-Control-Max-Age', '86400');
    }

    private static function sendHeaders(int $status, string $contentType): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('X-Request-Id: ' . Logger::requestId());
        self::applyStoredHeaders();
    }

    private static function applyStoredHeaders(): void
    {
        foreach (self::$headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        self::$headers = [];
    }

    private static function finish(): never
    {
        self::$sent = true;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        exit;
    }

    public static function wasSent(): bool
    {
        return self::$sent;
    }
}
