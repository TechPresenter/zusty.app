<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Helpers\Str;

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
     * Any payload key containing one of these has its value replaced before
     * the body is stored. Keys are compared lower-cased with the separators
     * between words taken out, so `new_password`, `passwordConfirmation`,
     * `refresh-token`, `bank_account_no` and `accountNumber` are all caught.
     *
     * The list covers two kinds of value. Credentials — a password, a token, a
     * card number — are obvious. The rest are identifiers that are not secrets
     * in the cryptographic sense but which nobody should be able to read out of
     * a request log: a bank account number, an IFSC, a PAN, an Aadhaar or
     * passport number. Those arrive through `POST /wallet/bank-accounts`
     * (`account_number`, `ifsc_code`, `upi_id`) and `POST /kyc/details`
     * (`bank_account_no`, `bank_ifsc`, `pan_number`, `id_proof_number`), and
     * until they were listed here they were written to `api_logs.request_body`
     * in the clear and rendered verbatim in the admin log viewer — a KYC
     * document number readable by anyone with `developer.logs`, which is a
     * much wider group than `kyc.download`.
     *
     * Only words long enough not to turn up inside other words belong here.
     * The short ones are {@see SECRET_WORDS}.
     */
    private const SECRET_FRAGMENTS = [
        // Credentials. `otp` stays a fragment despite its length: `otpcode`
        // and `emailotp` are one word, and a code in the clear costs more
        // than a redacted `footprint`.
        'password', 'passwd', 'passcode', 'token', 'secret', 'authorization', 'apikey', 'privatekey',
        'otp', 'onetimecode', 'cvv', 'cvc', 'cardnumber', 'cardnum', 'cardno', 'creditcard', 'debitcard',
        // Payout details.
        'accountnumber', 'accountno', 'ifsc', 'iban', 'swiftcode', 'upiid', 'upihandle', 'upipin', 'vpaid',
        // Identity documents. `aadhaar` also catches `aadhaar_number` (the
        // verification centre's field).
        'pannumber', 'panno', 'aadhaar', 'aadhar', 'passport', 'idproof', 'documentnumber',
        'gstnumber', 'gstin', 'taxid', 'cinnumber', 'cinno', 'llpin',
    ];

    /**
     * Keys redacted when one of their WORDS is one of these: `upi_id`,
     * `upiId`, `pan`, `ownerPan`, `card_holder`, `upi_pin`.
     *
     * Matched as whole words because each is short enough to sit inside an
     * ordinary one — `upi` in `groupId`, `pin` in `shipping`, `pan` in
     * `company`, `cin` in `pricing`, `card` in `postcard`. As substrings they
     * redacted fields an operator needs.
     */
    private const SECRET_WORDS = [
        'pin', 'mpin', 'pwd', 'card', 'upi', 'vpa', 'swift', 'pan', 'cin', 'gst', 'ssn',
    ];

    /**
     * Keys redacted when they START with one of these once squashed
     * ({@see squash()}): `cardno`, `cardHolder`, `upihandle`, `vpaAddress`.
     * One-word spellings of a card or UPI field have no word boundary for
     * {@see SECRET_WORDS} to find, and no ordinary key starts this way.
     */
    private const SECRET_PREFIXES = ['card', 'upi', 'vpa'];

    /** Keys redacted when they END with one of these once squashed: `payerVpa`, `customerupi`. */
    private const SECRET_SUFFIXES = ['upi', 'vpa'];

    /**
     * Keys redacted only on an exact (case-insensitive) match.
     *
     * `code` is the one-time code sent with a verification or step-up
     * (`/auth/otp/verify`, `/me/privacy/exports`, `/me/privacy/delete-account`).
     * Elsewhere a `code` is redacted only beside one of
     * {@see ONE_TIME_CODE_WORDS}: `reason_code`, `phone_country_code` and
     * `referral_code` are what an operator reading a failed request needs to
     * see.
     */
    private const SECRET_KEYS = ['uid', 'code'];

    /**
     * A key with the word `code` and one of these words is a one-time code:
     * `verification_code`, `resetCode`, `email_code`, `two_factor_code`.
     */
    private const ONE_TIME_CODE_WORDS = [
        'verification', 'verify', 'confirm', 'confirmation', 'auth', 'login', 'signin', 'reset',
        'security', 'access', 'sms', 'email', 'mfa', 'factor', 'backup', 'recovery', 'magic', 'step',
    ];

    /**
     * Keys whose value is masked rather than redacted: any key containing one
     * of these (`customer_phone`, `alternateMobile`, `msisdn`, `billing_email`,
     * `new_email`), compared the same way as {@see SECRET_FRAGMENTS}.
     *
     * A failed sign-in, sign-up or payment has no user id, so the masked
     * value is the only hint of whose request it was, and the full address
     * or number is not something a log needs. Masking keeps the key and the
     * shape: an email keeps its first characters and its domain, a number its
     * last four digits ({@see mask()}). This used to match the exact keys
     * `email` and `phone` only, and a failed `POST /payments/booking/{id}`
     * kept `customer_phone` in the clear for the 180 days the row lives.
     */
    private const MASKED_FRAGMENTS = ['phone', 'mobile', 'msisdn', 'whatsapp', 'email'];

    /**
     * Masked on an exact (case-insensitive) match: what a sign-in or a code
     * is addressed to (`/auth/login`, `/auth/otp/send`).
     */
    private const MASKED_KEYS = ['identifier', 'destination', 'recipient'];

    /**
     * Generic keys that carry a contact when the request says so.
     *
     * `POST /me/contacts/change` and `/me/contacts/alternates` send
     * `{"channel": "email|phone", "value": "…"}`: the address or number is
     * under `value`, a key no list of words could mark as personal. So a
     * level whose `channel` (or `type`, `kind` …, {@see CONTACT_TYPE_KEYS})
     * names a contact channel ({@see CONTACT_CHANNELS}) has these siblings
     * masked in full, like a masked key. Without one, a value under the first
     * four is still masked when it is shaped like a phone number
     * ({@see looksLikePhone()}), and an email address is masked wherever it
     * appears ({@see maskEmails()}).
     */
    private const CONTACT_VALUE_KEYS = ['value', 'new_value', 'old_value', 'contact', 'to', 'target', 'address'];

    /** The {@see CONTACT_VALUE_KEYS} masked by shape alone: `to` and `address` are too often a date or a street. */
    private const CONTACT_SHAPE_KEYS = ['value', 'new_value', 'old_value', 'contact'];

    /** Keys that say which channel a sibling `value` belongs to. */
    private const CONTACT_TYPE_KEYS = ['channel', 'type', 'kind', 'medium', 'via', 'contact_type'];

    /** Channels whose value is an email address or a phone number. */
    private const CONTACT_CHANNELS = ['email', 'phone', 'mobile', 'sms', 'whatsapp', 'msisdn'];

    /**
     * An email address inside any text: kept to its first characters and its
     * domain wherever it appears. The local part cannot contain `*`, so an
     * address already masked is not matched again, and a file name such as
     * `logo@2x.png` is not an address. Migration 058 masks stored bodies
     * with the same pattern.
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*'
        . '\.(?!(?:png|jpe?g|gif|webp|svg|pdf|mp4|mov|heic|avif)(?![A-Za-z]))[A-Za-z]{2,}/';

    /** How much of a value is masked; see {@see redactValue()}. */
    private const MASK_EMAILS = 0;
    private const MASK_PHONE_SHAPED = 1;
    private const MASK_ALL = 2;

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
     * Secrets become [REDACTED]; values under a masked key are masked
     * ({@see mask()}), at any depth and in every element of a list under
     * one, as are a `value` beside `"channel": "email"` or `"phone"` and a
     * phone-shaped `value` anywhere ({@see CONTACT_VALUE_KEYS}). An email
     * address is masked wherever it appears. Keys are always kept, so the log
     * still says what was sent. A string holding JSON of its own is opened
     * and treated the same way, so a number cannot ride through inside an
     * encoded field.
     *
     * @param  array<mixed> $data
     * @param  int          $mode What everything here sits under: one of
     *                            the MASK_* levels.
     * @return array<mixed>
     */
    private static function redactArray(array $data, int $depth = 0, int $mode = self::MASK_EMAILS): array
    {
        if ($depth > 8) {
            return [];
        }

        $contactLevel = self::namesContactChannel($data);
        $clean        = [];

        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);

            if (self::isSecretKey((string) $key)) {
                $clean[$key] = self::REDACTED;
                continue;
            }

            $keyMode = $mode;

            if (self::isMaskedKey((string) $key) || ($contactLevel && in_array($name, self::CONTACT_VALUE_KEYS, true))) {
                $keyMode = self::MASK_ALL;
            } elseif (in_array($name, self::CONTACT_SHAPE_KEYS, true)) {
                $keyMode = max($mode, self::MASK_PHONE_SHAPED);
            }

            $clean[$key] = self::redactValue($value, $depth, $keyMode);
        }

        return $clean;
    }

    /**
     * Does this level say its contact value is an email address or a phone
     * number (`"channel": "phone"`)?
     *
     * @param array<mixed> $data
     */
    private static function namesContactChannel(array $data): bool
    {
        foreach (self::CONTACT_TYPE_KEYS as $typeKey) {
            foreach ($data as $key => $value) {
                if (is_string($key) && strtolower($key) === $typeKey && is_string($value)
                    && in_array(strtolower(trim($value)), self::CONTACT_CHANNELS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function redactValue(mixed $value, int $depth, int $mode): mixed
    {
        if (is_array($value)) {
            return self::redactArray($value, $depth + 1, $mode);
        }

        if (is_string($value)) {
            $embedded = self::embeddedJson($value);

            if ($embedded !== null) {
                return (string) json_encode(
                    self::redactArray($embedded, $depth + 1, $mode),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            if ($mode === self::MASK_ALL) {
                return self::mask($value);
            }

            if ($mode === self::MASK_PHONE_SHAPED && self::looksLikePhone($value)) {
                return Str::maskPhone($value);
            }

            return self::maskEmails($value);
        }

        // A number sent as a JSON number (`"customer_phone": 9876543210`).
        if (is_int($value) || is_float($value)) {
            $text = (string) $value;

            if ($mode === self::MASK_ALL) {
                $mask = self::mask($text);

                return $mask === $text ? $value : $mask;
            }

            if ($mode === self::MASK_PHONE_SHAPED && is_int($value) && self::looksLikePhone($text)) {
                return Str::maskPhone($text);
            }
        }

        return $value;
    }

    /**
     * Is this the whole of a phone number: seven to fifteen digits, an
     * optional leading +, and nothing but spaces, dots, dashes and brackets
     * between them? A date (`2026-10-10`) is not.
     */
    private static function looksLikePhone(string $value): bool
    {
        $trimmed = trim($value);

        if (preg_match('/^\+?[0-9][0-9 ().\-]{4,22}[0-9]$/', $trimmed) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return false;
        }

        $digits = preg_match_all('/\d/', $trimmed);

        return $digits >= 7 && $digits <= 15;
    }

    /** Every email address in [$text], masked ({@see EMAIL_PATTERN}); the rest of the text kept. */
    private static function maskEmails(string $text): string
    {
        if (!str_contains($text, '@')) {
            return $text;
        }

        return preg_replace_callback(
            self::EMAIL_PATTERN,
            static fn (array $m): string => Str::maskEmail($m[0]),
            $text
        ) ?? self::REDACTED;
    }

    /** @return array<mixed>|null A string's own JSON object or list, decoded. */
    private static function embeddedJson(string $value): ?array
    {
        $trimmed = ltrim($value);
        $start   = $trimmed[0] ?? '';

        if ($start !== '{' && $start !== '[') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Mask a value by its shape: an email address keeps its first characters
     * and its domain (`it****@x.com`), anything holding five or more digits
     * keeps its last four (`******3210`). Anything else (`+91`, `weekly`) is
     * kept: too short to identify anyone, and useful to whoever reads the log.
     */
    private static function mask(string $value): string
    {
        $trimmed = trim($value);

        if (str_contains($trimmed, '@')) {
            return Str::maskEmail($trimmed);
        }

        return preg_match_all('/\d/', $trimmed) >= 5 ? Str::maskPhone($trimmed) : $value;
    }

    private static function isSecretKey(string $key): bool
    {
        if (in_array(strtolower($key), self::SECRET_KEYS, true)) {
            return true;
        }

        $squashed = self::squash($key);

        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($squashed, $fragment)) {
                return true;
            }
        }

        foreach (self::SECRET_PREFIXES as $prefix) {
            if (str_starts_with($squashed, $prefix)) {
                return true;
            }
        }

        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($squashed, $suffix)) {
                return true;
            }
        }

        $words = self::words($key);

        if (array_intersect($words, self::SECRET_WORDS) !== []) {
            return true;
        }

        return in_array('code', $words, true) && array_intersect($words, self::ONE_TIME_CODE_WORDS) !== [];
    }

    private static function isMaskedKey(string $key): bool
    {
        if (in_array(strtolower($key), self::MASKED_KEYS, true)) {
            return true;
        }

        $squashed = self::squash($key);

        foreach (self::MASKED_FRAGMENTS as $fragment) {
            if (str_contains($squashed, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** `customer_Phone-No.` → `customerphoneno`: lower case, letters and digits only. */
    private static function squash(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
    }

    /**
     * A key's words, lower case: `otpCode`, `otp_code` and `OTP-code` are all
     * `['otp', 'code']`.
     *
     * @return list<string>
     */
    private static function words(string $key): array
    {
        $split = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;

        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($split)) ?: [],
            static fn (string $word): bool => $word !== ''
        ));
    }

    /**
     * Last-resort scrub for a body we could not parse: blank the value that
     * follows any secret-looking key and mask the one after a masked key, in
     * `key: value`, `key=value` and `<key>value</key>` form. Keys are judged
     * by the same rules as a parsed body, a phone-shaped `value` is masked,
     * and so is any email address left anywhere in the text.
     *
     * If any pass fails outright the whole body is discarded rather than
     * stored — an unreadable body is worth less than the risk of keeping one.
     */
    private static function scrub(string $raw): string
    {
        $result = preg_replace_callback(
            '/(["\']?)([A-Za-z][A-Za-z0-9_\-\.]*)(["\']?)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^&,}\s]*)/',
            static function (array $m): string {
                [$all, $open, $key, $close, $separator, $value] = $m;

                if (self::isSecretKey($key)) {
                    return $open . $key . $close . $separator . '"' . self::REDACTED . '"';
                }

                $quote = $value !== '' && ($value[0] === '"' || $value[0] === "'") ? $value[0] : '';
                $inner = $quote === '' ? $value : substr($value, 1, -1);

                if (self::isMaskedKey($key)) {
                    return $open . $key . $close . $separator . $quote . self::mask($inner) . $quote;
                }

                if (in_array(strtolower($key), self::CONTACT_SHAPE_KEYS, true) && self::looksLikePhone($inner)) {
                    return $open . $key . $close . $separator . $quote . Str::maskPhone($inner) . $quote;
                }

                return $all;
            },
            $raw
        );

        if ($result === null) {
            return self::REDACTED;
        }

        // XML-ish payloads: <otp>123456</otp> loses its whole content, and
        // <phone>9876543210</phone> keeps a masked number.
        preg_match_all('#<([A-Za-z][A-Za-z0-9_\-\.]*)[\s>]#', $result, $tags);

        $names  = array_values(array_unique($tags[1]));
        $secret = array_values(array_filter($names, static fn (string $name): bool => self::isSecretKey($name)));
        $masked = array_values(array_filter(
            $names,
            static fn (string $name): bool => !self::isSecretKey($name) && self::isMaskedKey($name)
        ));

        $alternation = static fn (array $list): string => implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '#'),
            $list
        ));

        if ($secret !== []) {
            $result = preg_replace(
                '#<(' . $alternation($secret) . ')(\s[^>]*)?>.*?</\1>#is',
                '<$1>' . self::REDACTED . '</$1>',
                $result
            );

            if ($result === null) {
                return self::REDACTED;
            }
        }

        if ($masked !== []) {
            $result = preg_replace_callback(
                '#<(' . $alternation($masked) . ')(\s[^>]*)?>([^<]*)</\1>#i',
                static fn (array $m): string => '<' . $m[1] . $m[2] . '>' . self::mask($m[3]) . '</' . $m[1] . '>',
                $result
            );
        }

        // An address anywhere else in the text, under whatever key or none.
        return $result === null ? self::REDACTED : self::maskEmails($result);
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
