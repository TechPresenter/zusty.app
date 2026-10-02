<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Core\Request;

/**
 * Observability for the REST API: writing the request log, and reading the
 * slow-query log that `Database::statement()` fills in.
 *
 * The recorder is deliberately unable to fail loudly. An observability write is
 * never worth turning a working API response into a 500, so it swallows its own
 * exceptions and reports them to the file log instead.
 *
 * It is also opt-out on two axes:
 *   - `developer.api_logging` (boolean, default on) switches it off entirely;
 *   - `security.api_log_sample_rate` (0–100, default 100) keeps a percentage of
 *     requests, which is how a busy production site keeps the table small.
 * Neither setting has to exist — the defaults below apply when the row is
 * missing from the `settings` table.
 */
final class ApiLogService
{
    /** Request bodies are truncated to this many characters before storage. */
    public const MAX_BODY_CHARS = 2000;

    /**
     * Any payload key containing one of these fragments has its value replaced
     * before the body is stored. Matching is case-insensitive and by substring,
     * so `new_password`, `password_confirmation` and `otpCode` are all caught.
     *
     * The list covers two kinds of value. Credentials — a password, a token, a
     * card number — are obvious. The rest are identifiers that are not secrets
     * in the cryptographic sense but which nobody should be able to read out of
     * a request log: a bank account number, an IFSC, a UPI handle, a PAN, an
     * Aadhaar or passport number. Those arrive through
     * `POST /wallet/bank-accounts` (`account_number`, `ifsc_code`, `upi_id`)
     * and `POST /kyc/details` (`bank_account_no`, `bank_ifsc`, `pan_number`,
     * `id_proof_number`), and until they were listed here they were written to
     * `api_logs.request_body` in the clear and rendered verbatim in the admin
     * log viewer — a KYC document number readable by anyone with
     * `developer.logs`, which is a much wider group than `kyc.download`.
     *
     * Substring matching means `bank_account_no`, `account_number` and
     * `accountNumber` are all caught by `account`, and `id_proof_number` by
     * `id_proof`.
     */
    private const SECRET_FRAGMENTS = [
        // Credentials.
        'password', 'token', 'secret', 'otp', 'authorization', 'card', 'cvv', 'pin',
        // Payout details.
        'account_number', 'account_no', 'accountnumber', 'ifsc', 'upi', 'vpa', 'iban', 'swift',
        // Identity documents. `aadhaar` also catches `aadhaar_number` (the
        // verification centre's field). The CIN is matched as `cin_number` /
        // `cinnumber` here and as the bare key `cin` below: a bare `cin`
        // substring would also redact `pricing`, `vaccine` and friends.
        'pan_number', 'pannumber', 'aadhaar', 'aadhar', 'passport', 'id_proof', 'document_number',
        'gst_number', 'gstin', 'tax_id', 'ssn', 'cin_number', 'cinnumber', 'cin_no', 'llpin',
    ];

    /** Keys redacted only on an exact (case-insensitive) match. */
    private const SECRET_KEYS = ['cin', 'pan', 'gst', 'uid'];

    public const REDACTED = '[REDACTED]';

    // -----------------------------------------------------------------
    // Request log
    // -----------------------------------------------------------------

    /**
     * Append one row to `api_logs`.
     *
     * Call this once per API request, after the response status is known —
     * from a shutdown function, because the response helpers exit.
     *
     * @param float|null  $startedAt  microtime(true) at the start of the request
     * @param int|null    $queryCount Explicit query count; defaults to the
     *                                counter Database keeps for this request.
     */
    public static function record(
        Request $request,
        int $statusCode,
        ?float $startedAt = null,
        ?string $errorMessage = null,
        ?int $queryCount = null
    ): void {
        try {
            if (!self::shouldRecord($statusCode)) {
                return;
            }

            $userId    = $request->userId();
            $routeName = $request->attribute('route_name');
            $ip        = $request->ip();
            $userAgent = $request->userAgent();
            $platform  = strtolower($request->header('x-platform'));
            $version   = $request->header('x-app-version');

            $duration = $startedAt !== null && $startedAt > 0
                ? (int) round((microtime(true) - $startedAt) * 1000)
                : 0;

            $queries = $queryCount ?? Database::queryCount();

            Database::insert('api_logs', [
                'method'        => mb_substr($request->method(), 0, 10),
                'path'          => mb_substr($request->path(), 0, 255),
                'route_name'    => is_string($routeName) && $routeName !== '' ? mb_substr($routeName, 0, 120) : null,
                // The column is SMALLINT UNSIGNED; a bad status must not make
                // the insert itself fail.
                'status_code'   => max(100, min(599, $statusCode)),
                'user_id'       => $userId > 0 ? $userId : null,
                'ip_address'    => $ip !== '' ? mb_substr($ip, 0, 45) : null,
                'user_agent'    => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
                'platform'      => $platform !== '' ? mb_substr($platform, 0, 20) : null,
                'app_version'   => $version !== '' ? mb_substr($version, 0, 20) : null,
                'duration_ms'   => max(0, min(4294967295, $duration)),
                'query_count'   => max(0, min(65535, $queries)),
                'memory_kb'     => (int) round(memory_get_peak_usage(true) / 1024),
                // `api_logs.request_body` is defined as "Redacted; only stored
                // for errors". Keeping every successful body would store the
                // email address behind every sign-in and every profile edit for
                // as long as the row lives, which is not what the column is for
                // and not what an operator debugging a 500 needs.
                'request_body'  => $statusCode >= 400 ? self::redactBody($request) : null,
                'error_message' => $errorMessage !== null && $errorMessage !== ''
                    ? mb_substr($errorMessage, 0, 500)
                    : null,
                'request_id'    => mb_substr(Logger::requestId(), 0, 36),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Deliberately terminal: the API response has already been sent and
            // a logging failure must not surface to the client.
            Logger::error('Could not record an API log entry', [
                'error'  => $e->getMessage(),
                'path'   => $request->path(),
                'method' => $request->method(),
            ]);
        }
    }

    /**
     * Should this request be written at all?
     *
     * Failures are always kept, whatever the sample rate: a 1-in-100 sample of
     * a 500 is useless when you are trying to reproduce it.
     */
    private static function shouldRecord(int $statusCode): bool
    {
        if (!Config::settingBool('developer', 'api_logging', true)) {
            return false;
        }

        $rate = self::sampleRate();

        if ($rate >= 100 || $statusCode >= 400) {
            return true;
        }

        if ($rate <= 0) {
            return false;
        }

        return random_int(1, 100) <= $rate;
    }

    /** Percentage of successful requests kept, 0–100. */
    public static function sampleRate(): int
    {
        return max(0, min(100, Config::settingInt('security', 'api_log_sample_rate', 100)));
    }

    /**
     * Delete request-log rows older than N days. Returns rows removed.
     */
    public static function prune(int $days): int
    {
        $days = max(1, min(3650, $days));

        try {
            return Database::execute(
                'DELETE FROM `api_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY)',
                ['days' => $days]
            );
        } catch (\Throwable $e) {
            Logger::error('Could not prune api_logs', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Headline numbers for the developer overview.
     *
     * @return array{requests_24h:int,errors_24h:int,server_errors_24h:int,error_rate:float,
     *               avg_ms:int,p95_ms:int,slowest_ms:int,total:int,oldest:?string,
     *               slowest_paths:list<array<string,mixed>>}
     */
    public static function stats(): array
    {
        $empty = [
            'requests_24h'      => 0,
            'errors_24h'        => 0,
            'server_errors_24h' => 0,
            'error_rate'        => 0.0,
            'avg_ms'            => 0,
            'p95_ms'            => 0,
            'slowest_ms'        => 0,
            'total'             => 0,
            'oldest'            => null,
            'slowest_paths'     => [],
        ];

        try {
            $window = Database::selectOne(
                'SELECT COUNT(*)                                              AS `requests`,
                        SUM(CASE WHEN `status_code` >= 400 THEN 1 ELSE 0 END) AS `errors`,
                        SUM(CASE WHEN `status_code` >= 500 THEN 1 ELSE 0 END) AS `server_errors`,
                        COALESCE(ROUND(AVG(`duration_ms`)), 0)                AS `avg_ms`,
                        COALESCE(MAX(`duration_ms`), 0)                       AS `slowest_ms`
                   FROM `api_logs`
                  WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            ) ?? [];

            $requests = (int) ($window['requests'] ?? 0);
            $errors   = (int) ($window['errors'] ?? 0);

            $lifetime = Database::selectOne(
                'SELECT COUNT(*) AS `total`, MIN(`created_at`) AS `oldest` FROM `api_logs`'
            ) ?? [];

            $slowestPaths = Database::select(
                'SELECT `path`,
                        COUNT(*)                     AS `hits`,
                        ROUND(AVG(`duration_ms`))    AS `avg_ms`,
                        MAX(`duration_ms`)           AS `max_ms`
                   FROM `api_logs`
                  WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  GROUP BY `path`
                 HAVING COUNT(*) > 0
                  ORDER BY `avg_ms` DESC
                  LIMIT 8'
            );

            return [
                'requests_24h'      => $requests,
                'errors_24h'        => $errors,
                'server_errors_24h' => (int) ($window['server_errors'] ?? 0),
                'error_rate'        => $requests > 0 ? round($errors / $requests * 100, 1) : 0.0,
                'avg_ms'            => (int) ($window['avg_ms'] ?? 0),
                'p95_ms'            => self::percentileDuration($requests, 95),
                'slowest_ms'        => (int) ($window['slowest_ms'] ?? 0),
                'total'             => (int) ($lifetime['total'] ?? 0),
                'oldest'            => $lifetime['oldest'] ?? null,
                'slowest_paths'     => $slowestPaths,
            ];
        } catch (\Throwable $e) {
            Logger::error('Could not build API log statistics', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * Nth-percentile duration over the last 24 hours.
     *
     * Computed with an OFFSET rather than a window function so it behaves the
     * same on MySQL 5.7 and MariaDB 10.4. The offset is derived from a COUNT
     * this class ran itself, never from request input.
     */
    private static function percentileDuration(int $sampleSize, int $percentile): int
    {
        if ($sampleSize < 1) {
            return 0;
        }

        $offset = (int) max(0, min($sampleSize - 1, (int) floor($sampleSize * ($percentile / 100)) - 1));

        $value = Database::scalar(
            'SELECT `duration_ms` FROM `api_logs`
              WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              ORDER BY `duration_ms` ASC
              LIMIT 1 OFFSET ' . $offset
        );

        return (int) ($value ?? 0);
    }

    // -----------------------------------------------------------------
    // Redaction
    // -----------------------------------------------------------------

    /**
     * The request body as it should be stored: secrets replaced, capped.
     *
     * Returns null for verbs that carry no body, and for empty bodies, so the
     * column stays NULL rather than holding "". `record()` only calls this for
     * a failed response — see the note there.
     */
    public static function redactBody(Request $request): ?string
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $raw = trim($request->rawBody());

        if ($raw === '') {
            // Multipart uploads never reach rawBody(); fall back to the parsed
            // bag so at least the field names are recorded.
            $parsed = $request->all();

            return $parsed === []
                ? null
                : self::cap((string) json_encode(self::redactArray($parsed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::cap(self::redact($raw));
    }

    /**
     * Redact a raw body string. JSON and form encodings are parsed and rebuilt;
     * anything else falls back to a pattern scrub so an unknown encoding still
     * cannot leak a password.
     */
    public static function redact(string $raw): string
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $encoded = json_encode(self::redactArray($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($encoded)) {
                return $encoded;
            }
        }

        // urlencoded bodies. `parse_str` produces an array from literally any
        // string, so the test has to be much stricter than "contains an =".
        //
        // It used to be exactly that, and the consequence was a leak rather
        // than a cosmetic problem: given the plain-text body
        // "password: hunter2 and pin=1234", parse_str reads everything before
        // the "=" as one key, rewriting spaces to underscores. The key then
        // contains "password", so its *value* was redacted — while "hunter2",
        // now sitting inside the key, was written to the log untouched. Only a
        // body that really is application/x-www-form-urlencoded may take this
        // branch; everything else goes to the pattern scrub, which redacts
        // values wherever they appear.
        if (self::looksUrlEncoded($raw)) {
            $parsed = [];
            parse_str($raw, $parsed);

            if ($parsed !== []) {
                return http_build_query(self::redactArray($parsed));
            }
        }

        return self::scrub($raw);
    }

    /**
     * Is this string an actual urlencoded form body?
     *
     * One or more `key=value` pairs joined by `&`. Keys are restricted to the
     * characters a form field name can use once encoded (PHP's own array
     * syntax, `a[b]`, included). Nothing may contain raw whitespace, because a
     * real urlencoded body encodes it — so prose, XML and JSON fragments all
     * fail here and fall through to the scrub instead.
     */
    private static function looksUrlEncoded(string $raw): bool
    {
        return preg_match('/^[A-Za-z0-9_.\-%\[\]]+=[^&\s]*(&[A-Za-z0-9_.\-%\[\]]+=[^&\s]*)*$/D', $raw) === 1;
    }

    /**
     * @param  array<mixed> $data
     * @return array<mixed>
     */
    private static function redactArray(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (self::isSecretKey((string) $key)) {
                $clean[$key] = self::REDACTED;
                continue;
            }

            $clean[$key] = is_array($value) ? self::redactArray($value, $depth + 1) : $value;
        }

        return $clean;
    }

    private static function isSecretKey(string $key): bool
    {
        $needle = strtolower($key);

        if (in_array($needle, self::SECRET_KEYS, true)) {
            return true;
        }

        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Last-resort scrub for a body we could not parse: blank the value that
     * follows any secret-looking key, in `key: value`, `key=value` and
     * `<key>value</key>` form.
     *
     * If either pass fails outright the whole body is discarded rather than
     * stored — an unreadable body is worth less than the risk of keeping one.
     */
    private static function scrub(string $raw): string
    {
        $fragments = implode('|', self::SECRET_FRAGMENTS);
        $keyPart   = '[A-Za-z0-9_\-\.]*(?:' . $fragments . ')[A-Za-z0-9_\-\.]*';

        $result = preg_replace_callback(
            '/("|\')?(' . $keyPart . ')\1?\s*(=|:)\s*("[^"]*"|\'[^\']*\'|[^&,}\s]*)/i',
            static fn (array $m): string => $m[2] . $m[3] . '"' . self::REDACTED . '"',
            $raw
        );

        if ($result === null) {
            return self::REDACTED;
        }

        // XML-ish payloads: <otp>123456</otp>
        $result = preg_replace(
            '#<(' . $keyPart . ')(\s[^>]*)?>.*?</\1>#is',
            '<$1>' . self::REDACTED . '</$1>',
            $result
        );

        return $result ?? self::REDACTED;
    }

    private static function cap(string $body): string
    {
        return mb_strlen($body) > self::MAX_BODY_CHARS
            ? mb_substr($body, 0, self::MAX_BODY_CHARS - 1) . '…'
            : $body;
    }

    // -----------------------------------------------------------------
    // Slow query log
    //
    // The writer lives in Database::statement(), which is the only place that
    // can time a statement. This class only reads and clears, so there is one
    // recorder and one normalisation rule rather than two that disagree about
    // what counts as the same query.
    // -----------------------------------------------------------------

    /**
     * The threshold, in milliseconds, a statement has to exceed before
     * `Database` writes it to `query_log`.
     *
     * Read from exactly the setting Database enforces, so the monitor can never
     * advertise a bar that is not the real one. Zero or less disables recording.
     */
    public static function slowQueryThresholdMs(): int
    {
        return Config::settingInt('security', 'slow_query_threshold_ms', 500);
    }

    /** Empty the slow-query log. Returns rows removed. */
    public static function clearQueryLog(): int
    {
        try {
            return Database::execute('DELETE FROM `query_log`');
        } catch (\Throwable $e) {
            Logger::error('Could not clear the query log', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
