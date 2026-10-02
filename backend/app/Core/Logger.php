<?php

declare(strict_types=1);

namespace Zuste\Core;

use Throwable;

/**
 * PSR-3 shaped logger writing to rotating files and, for warning level and
 * above, to the `system_logs` table so the admin panel can surface issues.
 *
 * Context values are redacted before they are written — a stack trace or a
 * request dump must never persist a password or an API key.
 */
final class Logger
{
    public const DEBUG     = 'debug';
    public const INFO      = 'info';
    public const NOTICE    = 'notice';
    public const WARNING   = 'warning';
    public const ERROR     = 'error';
    public const CRITICAL  = 'critical';
    public const ALERT     = 'alert';
    public const EMERGENCY = 'emergency';

    private const SEVERITY = [
        self::DEBUG => 0, self::INFO => 1, self::NOTICE => 2, self::WARNING => 3,
        self::ERROR => 4, self::CRITICAL => 5, self::ALERT => 6, self::EMERGENCY => 7,
    ];

    /** Keys whose values are replaced with [REDACTED] wherever they appear. */
    private const REDACT_KEYS = [
        'password', 'password_hash', 'new_password', 'current_password', 'password_confirmation',
        'token', 'access_token', 'refresh_token', 'id_token', 'api_key', 'secret', 'key_secret',
        'authorization', 'cookie', 'card_number', 'cvv', 'account_number', 'document_number',
        'jwt_secret', 'encryption_key', 'service_account_json', 'signature', 'otp', 'code',
    ];

    private static string $requestId = '';

    private static bool $dbLoggingAvailable = true;

    public static function requestId(): string
    {
        if (self::$requestId === '') {
            self::$requestId = Crypto::uuid4();
        }

        return self::$requestId;
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function notice(string $message, array $context = []): void
    {
        self::log(self::NOTICE, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    /** Log a throwable with its class, location and (in debug) its trace. */
    public static function exception(Throwable $e, string $level = self::ERROR, array $extra = []): void
    {
        $context = array_merge($extra, [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        if ((bool) Config::get('app.debug', false)) {
            $context['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 15);
        }

        self::log($level, 'Unhandled exception: ' . $e->getMessage(), $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        $level     = isset(self::SEVERITY[$level]) ? $level : self::INFO;
        $minLevel  = (string) Config::get('log.min_level', self::DEBUG);
        $threshold = self::SEVERITY[$minLevel] ?? 0;

        if (self::SEVERITY[$level] < $threshold) {
            return;
        }

        $context = self::redact($context);

        self::writeToFile($level, $message, $context, $channel);

        if (self::SEVERITY[$level] >= self::SEVERITY[self::WARNING]) {
            self::writeToDatabase($level, $message, $context, $channel);
        }
    }

    /** @param array<string,mixed> $context */
    private static function writeToFile(string $level, string $message, array $context, string $channel): void
    {
        $directory = (string) Config::get('log.path', '');
        if ($directory === '') {
            return;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }

        $file = (string) Config::get('log.channel', 'daily') === 'single'
            ? $directory . '/app.log'
            : $directory . '/app-' . date('Y-m-d') . '.log';

        $line = sprintf(
            "[%s] %s.%s: %s %s%s",
            date('Y-m-d H:i:s'),
            $channel,
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            PHP_EOL
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        if (!file_exists($directory . '/.htaccess')) {
            @file_put_contents($directory . '/.htaccess', "Require all denied\n");
        }
    }

    /** @param array<string,mixed> $context */
    private static function writeToDatabase(string $level, string $message, array $context, string $channel): void
    {
        if (!self::$dbLoggingAvailable) {
            return;
        }

        try {
            Database::insert('system_logs', [
                'level'      => $level,
                'channel'    => $channel,
                'message'    => mb_substr($message, 0, 5000),
                'context'    => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                'request_id' => self::requestId(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // The database itself may be the thing that is broken — degrade
            // to file-only logging rather than recursing.
            self::$dbLoggingAvailable = false;
        }
    }

    /**
     * Recursively replace sensitive values.
     *
     * @param  array<mixed> $data
     * @return array<mixed>
     */
    private static function redact(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['...' => 'max depth'];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $lowerKey = is_string($key) ? strtolower($key) : '';

            if ($lowerKey !== '' && in_array($lowerKey, self::REDACT_KEYS, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value, $depth + 1);
            } elseif (is_object($value)) {
                $clean[$key] = $value instanceof \JsonSerializable
                    ? $value->jsonSerialize()
                    : $value::class;
            } elseif (is_string($value) && strlen($value) > 2000) {
                $clean[$key] = substr($value, 0, 2000) . '…[truncated]';
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
