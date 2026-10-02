<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\HttpException;
use Zuste\Helpers\Str;

/**
 * Privacy & account management: the parts the data export and the account
 * deletion share, i.e. who may confirm what, and how.
 *
 * Both are step-up actions. A valid session is not enough on its own,
 * because whoever holds a stolen token could otherwise walk off with a copy
 * of everything, or erase the account. So each request also carries proof
 * made on the spot: the account password, or a one-time code sent to the
 * account's OWN email or phone. The destination is never taken from the
 * client, so a code can only ever go somewhere the account already controls.
 *
 * @see DataExportService
 * @see AccountDeletionService
 */
final class PrivacyService
{
    /** What the user types as the final, deliberate step of a deletion. */
    public const CONFIRM_PHRASE = 'DELETE';

    /** One-time-code purposes, one per action, so a code cannot cross over. */
    public const PURPOSE_EXPORT = 'data_export';
    public const PURPOSE_DELETE = 'delete_account';

    /** @var list<string> */
    public const PURPOSES = [self::PURPOSE_EXPORT, self::PURPOSE_DELETE];

    /**
     * Everything the privacy screen needs in one call.
     *
     * @param  array<string,mixed> $user The authenticated user row.
     * @return array<string,mixed>
     */
    public static function overview(array $user): array
    {
        return [
            'verification' => self::verificationOptions($user),
            'export'       => DataExportService::status((int) $user['id']),
            'deletion'     => AccountDeletionService::preflight($user),
        ];
    }

    /**
     * The ways this account can prove it is its owner.
     *
     * @param  array<string,mixed> $user
     * @return array{password:bool,channels:list<array{channel:string,destination:string}>}
     */
    public static function verificationOptions(array $user): array
    {
        $channels = [];

        if (!empty($user['email'])) {
            $channels[] = ['channel' => 'email', 'destination' => Str::maskEmail((string) $user['email'])];
        }

        if (!empty($user['phone']) && self::smsAvailable()) {
            $channels[] = ['channel' => 'sms', 'destination' => self::maskedPhone($user)];
        }

        return [
            'password' => !empty($user['password_hash']),
            'channels' => $channels,
        ];
    }

    /**
     * Send a one-time code to the account's own email or phone.
     *
     * @param  array<string,mixed> $user
     * @return array{expires_in:int,length:int,channel:string,destination:string}
     */
    public static function sendCode(array $user, string $purpose, string $channel): array
    {
        self::assertPurpose($purpose);

        $destination = self::destination($user, $channel);
        $result      = AuthService::sendOtp($destination, $channel, $purpose, (int) $user['id']);

        return [
            'expires_in'  => (int) $result['expires_in'],
            'length'      => (int) $result['length'],
            'channel'     => $channel,
            'destination' => $channel === 'sms' ? self::maskedPhone($user) : Str::maskEmail($destination),
        ];
    }

    /**
     * Prove the request comes from the account's owner, right now.
     *
     * A code wins over a password when both are sent. The app only ever
     * sends one of them, and a code is the stronger proof of the two because
     * it shows control of the contact point as well as knowledge of a secret.
     *
     * @param  array<string,mixed> $user
     * @param  array<string,mixed> $input `password`, or `code` with `channel`
     * @return 'password'|'email_code'|'sms_code'
     */
    public static function verify(array $user, string $purpose, array $input): string
    {
        self::assertPurpose($purpose);

        $code = trim((string) ($input['code'] ?? ''));

        if ($code !== '') {
            $channel = (string) ($input['channel'] ?? 'email');

            // Throws its own field-level 422s: otp_not_found, otp_expired,
            // otp_invalid, and a 429 once the attempt budget is spent.
            AuthService::verifyOtp(self::destination($user, $channel), $code, $purpose);

            return $channel === 'sms' ? 'sms_code' : 'email_code';
        }

        $password = (string) ($input['password'] ?? '');

        if ($password !== '') {
            if (empty($user['password_hash'])) {
                throw new HttpException(
                    'Your account does not have a password. Confirm with a verification code instead.',
                    422,
                    'password_not_set',
                    ['password' => ['Your account does not have a password.']]
                );
            }

            if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
                throw new HttpException(
                    'Incorrect password.',
                    422,
                    'invalid_credentials',
                    ['password' => ['Incorrect password.']]
                );
            }

            return 'password';
        }

        throw HttpException::unprocessable(
            'Confirm it is you with your password or a verification code.',
            'reauth_required'
        );
    }

    /**
     * Where a code for this account goes on a channel. It is always one of
     * the account's own contact points and never a value from the request.
     *
     * @param array<string,mixed> $user
     */
    private static function destination(array $user, string $channel): string
    {
        if ($channel === 'sms') {
            if (empty($user['phone'])) {
                throw HttpException::unprocessable('There is no mobile number on your account. Use email instead.', 'no_phone');
            }

            if (!self::smsAvailable()) {
                throw HttpException::unprocessable('Codes by SMS are not available right now. Use email instead.', 'sms_unavailable');
            }

            $phone = Str::normalizePhone((string) ($user['phone_country_code'] ?? '') . (string) $user['phone']);

            if ($phone === '') {
                throw HttpException::unprocessable('The mobile number on your account cannot receive codes. Use email instead.', 'no_phone');
            }

            return $phone;
        }

        if ($channel !== 'email') {
            throw HttpException::unprocessable('Choose where to send the code: email or SMS.', 'invalid_channel');
        }

        if (empty($user['email'])) {
            throw HttpException::unprocessable('There is no email address on your account. Use SMS instead.', 'no_email');
        }

        return strtolower(trim((string) $user['email']));
    }

    /**
     * SMS codes need both the OTP sign-in method and an SMS provider. The
     * same two switches gate {@see AuthService::sendOtp()}, so the channel
     * is only offered when a code would actually be sent.
     */
    private static function smsAvailable(): bool
    {
        return AuthConfigService::enabled('mobile_otp', true)
            && (Config::settingBool('sms', 'enabled', false) || (bool) Config::get('sms.enabled', false));
    }

    /** @param array<string,mixed> $user */
    private static function maskedPhone(array $user): string
    {
        return trim((string) ($user['phone_country_code'] ?? '') . ' ' . Str::maskPhone((string) $user['phone']));
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw HttpException::unprocessable('Unknown verification purpose.', 'invalid_purpose');
        }
    }
}
