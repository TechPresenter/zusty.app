<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Helpers\Http;
use Zuste\Helpers\Str;

/**
 * SMS delivery with pluggable providers (MSG91, Twilio, Textlocal).
 *
 * Like mail, messages are queued and drained by cron. India's TRAI DLT rules
 * require a registered template id for transactional SMS, which is why
 * `template_id` is carried on every row.
 */
final class SmsService
{
    public static function queue(string $phone, string $message, ?string $templateId = null): int
    {
        $phone = Str::normalizePhone($phone);

        if ($phone === '') {
            return 0;
        }

        if (!Config::settingBool('sms', 'enabled', false) && !(bool) Config::get('sms.enabled', false)) {
            Logger::debug('SMS is disabled; message not queued.', ['to' => Str::maskPhone($phone)]);

            return 0;
        }

        return Database::insert('sms_queue', [
            'to_phone'    => $phone,
            'message'     => mb_substr($message, 0, 1000),
            'template_id' => $templateId ?? self::setting('otp_template_id'),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** Send immediately — used for OTP where a queue delay is unacceptable. */
    public static function sendNow(string $phone, string $message, ?string $templateId = null): bool
    {
        $phone = Str::normalizePhone($phone);

        if ($phone === '' || (!Config::settingBool('sms', 'enabled', false) && !(bool) Config::get('sms.enabled', false))) {
            return false;
        }

        try {
            [$ok] = self::deliver($phone, $message, $templateId);

            return $ok;
        } catch (\Throwable $e) {
            Logger::error('Immediate SMS delivery failed', [
                'to'    => Str::maskPhone($phone),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return array{sent:int,failed:int} */
    public static function drainQueue(int $limit = 50): array
    {
        $jobs = Database::select(
            "SELECT * FROM `sms_queue`
             WHERE `status` = 'pending' AND `available_at` <= NOW() AND `attempts` < 3
             ORDER BY `id` LIMIT " . max(1, $limit)
        );

        $sent   = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $jobId    = (int) $job['id'];
            $attempts = (int) $job['attempts'] + 1;

            Database::update('sms_queue', ['status' => 'sending', 'attempts' => $attempts], ['id' => $jobId]);

            try {
                [$ok, $reference] = self::deliver(
                    (string) $job['to_phone'],
                    (string) $job['message'],
                    $job['template_id'] ?? null
                );

                if (!$ok) {
                    throw new \RuntimeException('The SMS provider rejected the message.');
                }

                Database::update('sms_queue', [
                    'status'       => 'sent',
                    'provider_ref' => $reference,
                    'sent_at'      => date('Y-m-d H:i:s'),
                ], ['id' => $jobId]);

                $sent++;
            } catch (\Throwable $e) {
                $failed++;

                Database::update('sms_queue', [
                    'status'       => $attempts >= 3 ? 'failed' : 'pending',
                    'last_error'   => mb_substr($e->getMessage(), 0, 500),
                    'available_at' => date('Y-m-d H:i:s', time() + (180 * $attempts)),
                ], ['id' => $jobId]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @return array{0:bool,1:string|null} */
    private static function deliver(string $phone, string $message, ?string $templateId): array
    {
        return match (self::setting('provider', 'msg91')) {
            'twilio'    => self::viaTwilio($phone, $message),
            'textlocal' => self::viaTextlocal($phone, $message),
            default     => self::viaMsg91($phone, $message, $templateId),
        };
    }

    /** @return array{0:bool,1:string|null} */
    private static function viaMsg91(string $phone, string $message, ?string $templateId): array
    {
        $apiKey = self::setting('api_key');

        if ($apiKey === '') {
            throw new \RuntimeException('MSG91 API key is not configured.');
        }

        $response = Http::post(
            'https://control.msg91.com/api/v5/flow/',
            array_filter([
                'template_id' => $templateId,
                'sender'      => self::setting('sender_id', 'ZUSTEP'),
                'short_url'   => '0',
                'recipients'  => [['mobiles' => ltrim($phone, '+'), 'MESSAGE' => $message]],
            ]),
            ['authkey' => $apiKey, 'Content-Type' => 'application/json'],
            20
        );

        $ok = Http::successful($response) && (($response['json']['type'] ?? '') !== 'error');

        return [$ok, isset($response['json']['message']) ? (string) $response['json']['message'] : null];
    }

    /** @return array{0:bool,1:string|null} */
    private static function viaTwilio(string $phone, string $message): array
    {
        $accountSid = self::setting('api_key');   // Account SID
        $authToken  = self::setting('api_secret'); // Auth token
        $sender     = self::setting('sender_id');

        if ($accountSid === '' || $authToken === '') {
            throw new \RuntimeException('Twilio credentials are not configured.');
        }

        $response = Http::post(
            'https://api.twilio.com/2010-04-01/Accounts/' . $accountSid . '/Messages.json',
            ['To' => $phone, 'From' => $sender, 'Body' => $message],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            20,
            [$accountSid, $authToken]
        );

        return [
            Http::successful($response),
            isset($response['json']['sid']) ? (string) $response['json']['sid'] : null,
        ];
    }

    /** @return array{0:bool,1:string|null} */
    private static function viaTextlocal(string $phone, string $message): array
    {
        $apiKey = self::setting('api_key');

        if ($apiKey === '') {
            throw new \RuntimeException('Textlocal API key is not configured.');
        }

        $response = Http::post(
            'https://api.textlocal.in/send/',
            [
                'apikey'  => $apiKey,
                'numbers' => ltrim($phone, '+'),
                'sender'  => self::setting('sender_id', 'ZUSTEP'),
                'message' => $message,
            ],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            20
        );

        $ok = Http::successful($response) && (($response['json']['status'] ?? '') === 'success');

        return [$ok, isset($response['json']['batch_id']) ? (string) $response['json']['batch_id'] : null];
    }

    private static function setting(string $key, string $default = ''): string
    {
        $value = Config::settingString('sms', $key, '');

        return $value !== '' ? $value : (string) Config::get('sms.' . $key, $default);
    }
}
