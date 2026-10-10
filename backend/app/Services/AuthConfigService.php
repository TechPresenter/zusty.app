<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;

/**
 * The authority on which sign-in methods are available, and the builder of the
 * `auth` block the apps read from GET /api/v1/app/config.
 *
 * Two design points worth stating, because both were deliberate choices:
 *
 * 1. The enable flag lives in `integrations`.`is_enabled`, not in a new
 *    settings key. That row already existed for Google and Apple, already has
 *    a toggle action, an audit trail in `integration_logs`, a derived status
 *    and a place in the hub UI. A parallel `auth.google_enabled` setting would
 *    have duplicated all of it and given operators two switches that could
 *    disagree.
 *
 * 2. `enabled()` returns the supplied default when no row exists. The defaults
 *    chosen by every caller reproduce the behaviour the API had before this
 *    module existed, so an install that has never seen the seed behaves exactly
 *    as it did — the module can only ever be additive.
 */
final class AuthConfigService
{
    /** The six methods the platform knows how to gate. */
    public const METHODS = [
        'email_password',
        'mobile_otp',
        'google_login',
        'apple_login',
        'facebook_login',
        'guest_login',
    ];

    /**
     * Public-facing key for each method, and whether it is a social provider
     * exchanged through Firebase.
     */
    private const PUBLIC_KEYS = [
        'email_password' => 'email_password',
        'mobile_otp'     => 'mobile_otp',
        'google_login'   => 'google',
        'apple_login'    => 'apple',
        'facebook_login' => 'facebook',
        'guest_login'    => 'guest',
    ];

    /** @var array<string,int>|null One query per process; ~60 rows. */
    private static ?array $enabledRows = null;

    /**
     * Is this sign-in method switched on?
     *
     * @param string $key     an `integrations`.`integration_key`
     * @param bool   $default what to answer when the row does not exist —
     *                        always today's behaviour, never a guess
     */
    public static function enabled(string $key, bool $default = true): bool
    {
        if (self::$enabledRows === null) {
            self::$enabledRows = [];

            foreach (Database::select('SELECT `integration_key`, `is_enabled` FROM `integrations`') as $row) {
                self::$enabledRows[(string) $row['integration_key']] = (int) $row['is_enabled'];
            }
        }

        return array_key_exists($key, self::$enabledRows)
            ? self::$enabledRows[$key] === 1
            : $default;
    }

    /** Drop the per-process memo after any admin write. */
    public static function flush(): void
    {
        self::$enabledRows = null;
    }

    /**
     * A short hash over everything the public block is built from, so a client
     * can send If-None-Match and be told 304 rather than re-downloading a
     * payload that has not moved.
     *
     * Both tables already carry `updated_at ... ON UPDATE CURRENT_TIMESTAMP`,
     * so nothing extra has to be maintained to keep this honest.
     */
    public static function version(): string
    {
        $row = Database::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM `settings`)                                AS `s_count`,
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `settings`)        AS `s_touched`,
                (SELECT COUNT(*) FROM `integrations`)                            AS `i_count`,
                (SELECT COALESCE(MAX(`updated_at`), "0") FROM `integrations`)    AS `i_touched`'
        ) ?? [];

        return substr(md5((string) json_encode($row)), 0, 16);
    }

    /**
     * The `auth` block of GET /api/v1/app/config.
     *
     * Built from explicit per-key reads, never from Config::settingGroup().
     * settingGroup() ignores `is_public` and hands back values already
     * decrypted, so publishing a whole group is one careless commit away from
     * leaking a credential. Naming every field means a new setting cannot
     * become public by accident.
     *
     * @param  string $platform android | ios | web, from the X-Platform header
     * @return array<string,mixed>
     */
    public static function publicBlock(string $platform): array
    {
        $platform = in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'android';

        $order   = Config::settingArray('auth', 'method_order', [
            'email_password', 'mobile_otp', 'google', 'apple', 'facebook', 'guest',
        ]);
        $methods = self::methods($platform);

        // Sort into the operator's declared order; anything unlisted goes last
        // in catalogue order rather than disappearing.
        $rank = array_flip($order);

        usort($methods, static function (array $a, array $b) use ($rank): int {
            $aRank = $rank[$a['key']] ?? PHP_INT_MAX;
            $bRank = $rank[$b['key']] ?? PHP_INT_MAX;

            return $aRank <=> $bRank;
        });

        foreach ($methods as $index => $_) {
            $methods[$index]['order'] = ($index + 1) * 10;
        }

        // Google, Apple and Facebook all exchange their credential through
        // Firebase, so none of them can complete while the Firebase options
        // are incomplete. Reporting them as on regardless gave every app a
        // button that could only answer "not available". The switch in the
        // panel is untouched; the method comes back the moment Firebase is
        // configured.
        $firebase = self::firebaseBlock($platform);

        if (!$firebase['enabled']) {
            foreach ($methods as $index => $method) {
                if (in_array($method['key'], ['google', 'apple', 'facebook'], true) && $method['enabled']) {
                    $methods[$index]['enabled']            = false;
                    $methods[$index]['unavailable_reason'] = 'firebase_not_configured';
                }
            }
        }

        $socialEnabled = false;

        foreach ($methods as $method) {
            if ($method['enabled'] && in_array($method['key'], ['google', 'apple', 'facebook'], true)) {
                $socialEnabled = true;
                break;
            }
        }

        $siteUrl = rtrim(Config::get('app.url', ''), '/');

        return [
            'version'  => self::version(),
            'platform' => $platform,
            'methods'  => array_values($methods),
            'guest'    => [
                'enabled'           => self::enabled('guest_login', false),
                'browse_routes'     => Config::settingArray('auth', 'guest_browse_routes', []),
                'prompt_on'         => Config::settingArray('auth', 'guest_prompt_on', []),
                'session_days'      => Config::settingInt('auth', 'guest_session_days', 30),
                'upgrade_endpoint'  => '/auth/guest/upgrade',
            ],
            'firebase' => $firebase,
            'signup'   => [
                // Reused from the `app` group, which the features block already
                // publishes — same source, so the two can never disagree.
                'creator_enabled'      => Config::settingBool('app', 'creator_signup_enabled', true),
                'brand_enabled'        => Config::settingBool('app', 'brand_signup_enabled', true),
                'default_role'         => Config::settingString('auth', 'default_signup_role', 'brand'),
                'role_choice_visible'  => Config::settingBool('auth', 'role_choice_visible', true),
                'require_terms_accept' => Config::settingBool('auth', 'require_terms_accept', true),
                'terms_url'            => Config::settingString('auth', 'terms_url') ?: $siteUrl . '/terms',
                'privacy_url'          => Config::settingString('auth', 'privacy_url') ?: $siteUrl . '/privacy',
            ],
            'session' => [
                'access_ttl_seconds'  => Config::settingInt('security', 'jwt_access_ttl', 3600),
                'refresh_ttl_seconds' => Config::settingInt('security', 'jwt_refresh_ttl', 2592000),
            ],
            'ui' => [
                'divider_label'        => Config::settingString('auth', 'social_divider_label', 'OR CONTINUE WITH'),
                'signup_divider_label' => Config::settingString('auth', 'signup_divider_label', 'OR SIGN UP WITH'),
                'show_social_divider'  => $socialEnabled,
            ],
        ];
    }

    /**
     * One entry per method, in catalogue order. `publicBlock()` re-sorts them.
     *
     * @return list<array<string,mixed>>
     */
    private static function methods(string $platform): array
    {
        return [
            [
                'key'                     => self::PUBLIC_KEYS['email_password'],
                'enabled'                 => self::enabled('email_password', true),
                'label'                   => 'Email or phone',
                'identifier_mode'         => Config::settingString('auth', 'identifier_mode', 'email_or_phone'),
                'password_min_length'     => Config::settingInt('security', 'password_min_length', 8),
                'forgot_password_enabled' => Config::settingBool('auth', 'forgot_password_enabled', true),
                'reset_token_ttl_minutes' => Config::settingInt('auth', 'reset_token_ttl_minutes', 60),
            ],
            [
                'key'                   => self::PUBLIC_KEYS['mobile_otp'],
                'enabled'               => self::enabled('mobile_otp', true),
                'label'                 => 'Continue with OTP',
                // Whether a code alone can sign someone in, as opposed to only
                // verifying a number they already own.
                'login_enabled'         => Config::settingBool('auth', 'otp_login_enabled', false),
                'channels'              => Config::settingArray('auth', 'otp_channels', ['sms']),
                'default_country_code'  => Config::settingString('auth', 'otp_default_country_code', '+91'),
                'allowed_country_codes' => Config::settingArray('auth', 'otp_allowed_country_codes', []),
                'phone_digits'          => Config::settingInt('auth', 'otp_phone_digits', 10),
                'otp_length'            => Config::settingInt('security', 'otp_length', 6),
                'otp_ttl_seconds'       => Config::settingInt('security', 'otp_ttl', 300),
                'resend_after_seconds'  => Config::settingInt('auth', 'otp_resend_seconds', 30),
                'max_resends'           => Config::settingInt('auth', 'otp_max_resends', 3),
                'max_attempts'          => Config::settingInt('security', 'max_login_attempts', 5),
            ],
            [
                'key'              => self::PUBLIC_KEYS['google_login'],
                'enabled'          => self::enabled('google_login', true),
                'label'            => 'Google',
                'exchange'         => 'firebase',
                'client_id'        => self::googleClientId($platform),
                // Android's google_sign_in needs the WEB client ID as its
                // serverClientId, which is the single most common thing to get
                // wrong, so it is sent explicitly rather than inferred.
                'server_client_id' => Config::settingString('auth', 'google_web_client_id'),
                'scopes'           => ['email', 'profile'],
            ],
            [
                'key'          => self::PUBLIC_KEYS['apple_login'],
                'enabled'      => self::enabled('apple_login', true),
                'label'        => 'Apple',
                'exchange'     => 'firebase',
                // sign_in_with_apple has no Android implementation, so the
                // client is told where the button belongs rather than being
                // left to guess.
                'platforms'    => ['ios', 'web'],
                'service_id'   => Config::settingString('auth', 'apple_service_id'),
                'redirect_uri' => Config::settingString('auth', 'apple_redirect_uri'),
                'scopes'       => ['email', 'name'],
            ],
            [
                'key'      => self::PUBLIC_KEYS['facebook_login'],
                'enabled'  => self::enabled('facebook_login', true),
                'label'    => 'Facebook',
                'exchange' => 'firebase',
                'app_id'   => Config::settingString('auth', 'facebook_app_id'),
            ],
            [
                'key'     => self::PUBLIC_KEYS['guest_login'],
                'enabled' => self::enabled('guest_login', false),
                'label'   => 'Continue as guest',
            ],
        ];
    }

    private static function googleClientId(string $platform): string
    {
        return match ($platform) {
            'ios' => Config::settingString('auth', 'google_ios_client_id'),
            'web' => Config::settingString('auth', 'google_web_client_id'),
            default => Config::settingString('auth', 'google_android_client_id'),
        };
    }

    /**
     * The client half of the Firebase configuration.
     *
     * The server half — the service account JSON — is never included and is not
     * even in this settings group.
     *
     * @return array<string,mixed>
     */
    private static function firebaseBlock(string $platform): array
    {
        $projectId = Config::settingString('firebase', 'project_id');
        $apiKey    = Config::settingString('firebase', 'web_api_key');

        $appId = match ($platform) {
            'ios' => Config::settingString('auth', 'firebase_app_id_ios'),
            'web' => Config::settingString('auth', 'firebase_app_id_web'),
            default => Config::settingString('auth', 'firebase_app_id_android'),
        };

        return [
            // The app must not attempt Firebase.initializeApp with half a
            // configuration; a clear false is more useful than a crash on boot.
            'enabled'             => $projectId !== '' && $apiKey !== '' && $appId !== '',
            'project_id'          => $projectId,
            'api_key'             => $apiKey,
            'app_id'              => $appId,
            'messaging_sender_id' => Config::settingString('auth', 'firebase_messaging_sender_id'),
            'auth_domain'         => Config::settingString('auth', 'firebase_auth_domain')
                ?: ($projectId !== '' ? $projectId . '.firebaseapp.com' : ''),
            'storage_bucket'      => Config::settingString('firebase', 'storage_bucket'),
        ];
    }
}
