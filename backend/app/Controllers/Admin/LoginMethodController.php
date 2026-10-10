<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Admin\Integration;
use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\AuthConfigService;
use Zuste\Services\AuthService;
use Zuste\Services\IntegrationService;
use Zuste\Services\NotificationService;

/**
 * Authentication & Login.
 *
 * One screen from which every way into the platform is switched on or off,
 * credentialed, tested and watched.
 *
 * Named LoginMethodController rather than AuthController because the admin
 * panel's own sign-in screens already own that name and the `admin.auth.*`
 * view namespace.
 *
 * Deliberately thin on storage: this controller owns no store of its own. It
 * writes provider credentials through IntegrationService (which already
 * encrypts, audits and status-checks them) and policy values back into the
 * settings group each one already belongs to. The alternative — a private
 * `auth_*` copy of every SMS, SMTP and password setting — would drift from the
 * originals the moment somebody edited one from the other screen.
 */
final class LoginMethodController extends BaseAdminController
{
    /**
     * The six methods, in the order the screen presents them.
     *
     * Keys are `integrations`.`integration_key` values, which is the single
     * authority for whether a method is on.
     */
    private const METHODS = [
        'email_password',
        'mobile_otp',
        'google_login',
        'apple_login',
        'facebook_login',
        'guest_login',
    ];

    /**
     * Fields recorded for reference but compiled into the app or registered
     * with the provider. Changing one here changes nothing on a phone already
     * installed, so the screen says so rather than implying otherwise.
     */
    private const BUILD_TIME_FIELDS = [
        'google_login' => ['android_package_name', 'android_sha1', 'android_sha256', 'ios_bundle_id', 'authorised_origins'],
        'apple_login'  => ['bundle_id'],
    ];

    // =================================================================
    // Screen
    // =================================================================

    /** GET /admin/login-methods */
    public function index(Request $request): never
    {
        $this->authorize($request, 'auth.view');

        $methods = [];

        foreach (self::METHODS as $key) {
            $state = IntegrationService::state($key);

            if ($state === null) {
                continue;
            }

            $state['build_time_fields'] = self::BUILD_TIME_FIELDS[$key] ?? [];
            $state['enabled']           = AuthConfigService::enabled($key, $key !== 'guest_login');
            $methods[$key]              = $state;
        }

        $this->view($request, 'admin.login-methods.index', [
            'pageTitle'    => 'Authentication & Login',
            'pageSubtitle' => 'Every way into the platform, in one place',
            'methods'      => $methods,
            'activity'     => $this->activity(),
            'recent'       => $this->recentSignIns(),
            'policy'       => $this->policyValues(),
            'canManage'    => $this->holds($request, 'auth.manage'),
            'canTest'      => $this->holds($request, 'auth.test'),
        ]);
    }

    // =================================================================
    // Mutations
    // =================================================================

    /** POST /admin/login-methods/{key} — provider credentials. */
    public function save(Request $request): never
    {
        $this->authorize($request, 'auth.manage');

        $key        = $this->methodKey($request);
        $definition = Integration::find($key);

        if ($definition === null || $definition['fields'] === []) {
            $this->back('error', 'That sign-in method stores no credentials here.', '/admin/login-methods');
        }

        $rules = [];

        foreach ($definition['fields'] as $field) {
            if ($field['type'] !== 'switch') {
                $rules[$field['name']] = $field['rules'] ?? 'nullable|string|max:255';
            }
        }

        $data = $this->validate($request, $rules);

        // Switches never appear in the payload when off, so presence is the
        // signal rather than the value.
        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'switch') {
                $data[$field['name']] = $request->has($field['name']) ? '1' : '';
            }
        }

        IntegrationService::save($key, $data, $this->adminId($request));

        AuthConfigService::flush();
        Config::flushSettings();

        $this->log($request, 'auth.method.saved', 'integration', null,
            'Saved the credentials for ' . $this->label($key) . '.');

        $this->respond($request, $this->label($key) . ' saved.', '/admin/login-methods');
    }

    /** POST /admin/login-methods/{key}/toggle */
    public function toggle(Request $request): never
    {
        $this->authorize($request, 'auth.manage');

        $key    = $this->methodKey($request);
        $enable = $request->has('enable');

        IntegrationService::toggle($key, $enable, $this->adminId($request));
        AuthConfigService::flush();

        $revoked = 0;

        // A live access token keeps working until it expires, because checking
        // the method on every authenticated request would cost a query per
        // request and could strand someone who signed in by two providers.
        // Signing them out is therefore an explicit, one-off choice at the
        // moment of disabling rather than a permanent per-request tax.
        if (!$enable && $request->has('revoke_sessions')) {
            $revoked = $this->revokeSessionsFor($key);
        }

        $this->log($request, 'auth.method.toggled', 'integration', null,
            ($enable ? 'Enabled ' : 'Disabled ') . $this->label($key) . '.',
            $revoked > 0 ? ['sessions_revoked' => $revoked] : []);

        $message = $this->label($key) . ($enable ? ' enabled.' : ' disabled.');

        if ($revoked > 0) {
            $message .= ' ' . $revoked . ' active session(s) signed out.';
        } elseif (!$enable) {
            $ttl = Config::settingInt('security', 'jwt_access_ttl', 3600);
            $message .= ' Existing sessions keep working for up to '
                . max(1, (int) round($ttl / 60)) . ' minutes.';
        }

        $this->respond($request, $message, '/admin/login-methods');
    }

    /** POST /admin/login-methods/{key}/secret/{field}/clear */
    public function clearSecret(Request $request): never
    {
        $this->authorize($request, 'auth.manage');

        $key   = $this->methodKey($request);
        $field = (string) $request->routeParam('field', '');

        // Whitelisted against the definition, so this cannot be pointed at an
        // arbitrary column name.
        if (!in_array($field, Integration::secretFields($key), true)) {
            throw HttpException::notFound('That is not a stored secret of this sign-in method.');
        }

        IntegrationService::clearSecret($key, $field, $this->adminId($request));
        AuthConfigService::flush();

        $this->log($request, 'auth.secret_cleared', 'integration', null,
            'Removed the stored ' . str_replace('_', ' ', $field) . ' for ' . $this->label($key) . '.');

        $this->respond($request, 'Stored value removed and the method switched off.', '/admin/login-methods');
    }

    /**
     * POST /admin/login-methods/policy
     *
     * Writes each value back to the settings group that already owns it —
     * `security` for OTP shape and password length, `sms` for delivery, `app`
     * for signup-per-role, `auth` for what has no other home.
     */
    public function savePolicy(Request $request): never
    {
        $this->authorize($request, 'auth.manage');

        $data = $this->validate($request, [
            // auth group
            'otp_login_enabled'        => 'nullable|boolean',
            'otp_default_country_code' => 'nullable|string|max:8|regex:/^\+[1-9][0-9]{0,3}$/',
            'otp_phone_digits'         => 'nullable|integer|min_value:6|max_value:15',
            'otp_resend_seconds'       => 'nullable|integer|min_value:10|max_value:600',
            'otp_max_resends'          => 'nullable|integer|min_value:1|max_value:10',
            'forgot_password_enabled'  => 'nullable|boolean',
            'reset_token_ttl_minutes'  => 'nullable|integer|min_value:5|max_value:1440',
            'identifier_mode'          => 'nullable|in:email,phone,email_or_phone',
            'guest_session_days'       => 'nullable|integer|min_value:1|max_value:365',
            'guest_purge_days'         => 'nullable|integer|min_value:1|max_value:730',
            'guest_max_per_ip_per_day' => 'nullable|integer|min_value:1|max_value:1000',
            'default_signup_role'      => 'nullable|in:brand,creator',
            'role_choice_visible'      => 'nullable|boolean',
            'require_terms_accept'     => 'nullable|boolean',
            'terms_url'                => 'nullable|url|max:255',
            'privacy_url'              => 'nullable|url|max:255',
            'social_divider_label'     => 'nullable|string|max:60',
            'signup_divider_label'     => 'nullable|string|max:60',
            'otp_label'                => 'nullable|string|max:60',
            'method_order'             => 'nullable|string|max:255',
            // firebase client options
            'firebase_app_id_android'     => 'nullable|string|max:191',
            'firebase_app_id_ios'         => 'nullable|string|max:191',
            'firebase_app_id_web'         => 'nullable|string|max:191',
            'firebase_messaging_sender_id' => 'nullable|string|max:64',
            'firebase_auth_domain'        => 'nullable|string|max:191',
            'firebase_android_package_name' => 'nullable|string|max:191',
            'firebase_ios_bundle_id'      => 'nullable|string|max:191',
            // reused groups
            'password_min_length' => 'nullable|integer|min_value:6|max_value:64',
            'otp_length'          => 'nullable|integer|min_value:4|max_value:8',
            'otp_ttl'             => 'nullable|integer|min_value:60|max_value:3600',
            'creator_signup_enabled' => 'nullable|boolean',
            'brand_signup_enabled'   => 'nullable|boolean',
        ]);

        $changed = [];

        // Text and numeric values, each to its real home.
        $plain = [
            'auth' => [
                'otp_default_country_code', 'otp_phone_digits', 'otp_resend_seconds', 'otp_max_resends',
                'reset_token_ttl_minutes', 'identifier_mode',
                'guest_session_days', 'guest_purge_days', 'guest_max_per_ip_per_day',
                'default_signup_role', 'terms_url', 'privacy_url',
                'social_divider_label', 'signup_divider_label', 'otp_label',
                'firebase_app_id_android', 'firebase_app_id_ios', 'firebase_app_id_web',
                'firebase_messaging_sender_id', 'firebase_auth_domain',
                'firebase_android_package_name', 'firebase_ios_bundle_id',
            ],
            'security' => ['password_min_length', 'otp_length', 'otp_ttl'],
        ];

        foreach ($plain as $group => $keys) {
            foreach ($keys as $key) {
                // Validator omits optional keys that were not submitted, so a
                // bare $data[$key] would be an undefined-index notice on every
                // field the form happens not to send.
                if (!array_key_exists($key, $data)) {
                    continue;
                }

                if ($this->putSetting($group, $key, (string) $data[$key])) {
                    $changed[$group . '.' . $key] = (string) $data[$key];
                }
            }
        }

        // Checkboxes: absent means off, so these are read from presence.
        $switches = [
            'auth' => ['otp_login_enabled', 'forgot_password_enabled', 'role_choice_visible', 'require_terms_accept'],
            'app'  => ['creator_signup_enabled', 'brand_signup_enabled'],
        ];

        foreach ($switches as $group => $keys) {
            foreach ($keys as $key) {
                $value = $request->has($key) ? '1' : '0';

                if ($this->putSetting($group, $key, $value)) {
                    $changed[$group . '.' . $key] = $value;
                }
            }
        }

        // The OTP channels arrive as ticked boxes. The form also sends a marker,
        // so a post without it (another form, an older page) leaves them alone
        // rather than reading "nothing ticked".
        if ($request->has('otp_channels_present')) {
            $ticked   = $request->input('otp_channels', []);
            $channels = array_values(array_intersect(
                AuthConfigService::OTP_CHANNELS,
                array_map('strval', is_array($ticked) ? $ticked : [$ticked])
            ));

            if ($this->putSetting('auth', 'otp_channels', (string) json_encode($channels))) {
                $changed['auth.otp_channels'] = implode(',', $channels);
            }
        }

        // The button order arrives as a comma-separated list and is stored as
        // JSON, filtered to keys the apps actually understand.
        if (array_key_exists('method_order', $data)) {
            $known = ['email_password', 'mobile_otp', 'google', 'apple', 'facebook', 'guest'];
            $order = array_values(array_intersect(
                array_map('trim', explode(',', (string) $data['method_order'])),
                $known
            ));

            if ($order !== []) {
                // Anything the operator left out still has to reach the client,
                // or a method would silently vanish rather than move.
                $order = array_merge($order, array_values(array_diff($known, $order)));

                if ($this->putSetting('auth', 'method_order', (string) json_encode($order))) {
                    $changed['auth.method_order'] = implode(',', $order);
                }
            }
        }

        Config::flushSettings();
        AuthConfigService::flush();
        NotificationService::flushTemplates();

        $this->log($request, 'auth.settings.updated', 'settings', null,
            $changed === [] ? 'Saved the authentication settings with no changes.' : 'Updated the authentication settings.',
            $changed);

        $this->respond(
            $request,
            $changed === [] ? 'No changes to save.' : count($changed) . ' setting(s) saved.',
            '/admin/login-methods'
        );
    }

    // =================================================================
    // Connection testing
    // =================================================================

    /** POST /admin/login-methods/{key}/test */
    public function test(Request $request): never
    {
        $this->authorize($request, 'auth.test');

        $key = $this->methodKey($request);

        $result = IntegrationService::test($key, $this->adminId($request));

        $this->log($request, 'auth.method.tested', 'integration', null,
            'Tested ' . $this->label($key) . ': ' . ($result['ok'] ? 'ok' : 'failed') . '.');

        if ($request->expectsJson()) {
            // A failed test is a successful request that reports bad news, so
            // `success` tracks the test outcome rather than the HTTP call —
            // otherwise the panel would show a green tick for a broken provider.
            Response::json(
                ['success' => $result['ok'], 'message' => $result['message'], 'data' => $result],
                $result['ok'] ? 200 : 422
            );
        }

        $this->back(
            $result['ok'] ? 'success' : 'error',
            $result['message'] . ' (' . $result['duration_ms'] . 'ms)',
            '/admin/login-methods'
        );
    }

    // =================================================================
    // Internals
    // =================================================================

    /** Read and whitelist the {key} route parameter. */
    private function methodKey(Request $request): string
    {
        $key = (string) $request->routeParam('key', '');

        if (!in_array($key, self::METHODS, true)) {
            throw HttpException::notFound('Unknown sign-in method: ' . $key);
        }

        return $key;
    }

    private function label(string $key): string
    {
        return (string) (Integration::find($key)['name'] ?? $key);
    }

    private function holds(Request $request, string $permission): bool
    {
        return \Zuste\Services\PermissionService::adminCan($this->adminId($request), $permission);
    }

    /**
     * Sign out everyone who signed in by this provider.
     *
     * Scoped by `auth_providers`, so a user who also has a password keeps no
     * privileged session but can sign straight back in by a method that is
     * still enabled.
     */
    private function revokeSessionsFor(string $key): int
    {
        $provider = match ($key) {
            'google_login'   => 'google.com',
            'apple_login'    => 'apple.com',
            'facebook_login' => 'facebook.com',
            'mobile_otp'     => 'phone',
            'email_password' => 'password',
            'guest_login'    => 'guest',
            default          => null,
        };

        if ($provider === null) {
            return 0;
        }

        $revoked = 0;

        foreach (Database::select(
            'SELECT `id` FROM `users`
              WHERE JSON_CONTAINS(`auth_providers`, :provider) AND `deleted_at` IS NULL',
            ['provider' => (string) json_encode($provider)]
        ) as $row) {
            $revoked += AuthService::revokeAllTokens((int) $row['id'], 'auth_method_disabled') > 0 ? 1 : 0;
        }

        return $revoked;
    }

    /**
     * Write one setting into the group that owns it.
     *
     * Returns whether anything actually changed, so the activity log records
     * real edits rather than every save. Encrypted rows are compared on their
     * plaintext because Crypto uses a fresh IV per call and the ciphertext
     * differs every time even when the value has not.
     */
    private function putSetting(string $group, string $key, string $value): bool
    {
        $row = Database::selectOne(
            'SELECT `id`, `value`, `is_encrypted` FROM `settings`
              WHERE `group_key` = :g AND `setting_key` = :k',
            ['g' => $group, 'k' => $key]
        );

        if ($row === null) {
            Database::insert('settings', [
                'group_key'    => $group,
                'setting_key'  => $key,
                'value'        => $value,
                'type'         => 'string',
                'label'        => ucfirst(str_replace('_', ' ', $key)),
                'is_public'    => 0,
                'is_encrypted' => 0,
                'sort_order'   => 90,
            ]);

            return true;
        }

        $encrypted = (int) $row['is_encrypted'] === 1;

        $current = $encrypted && (string) ($row['value'] ?? '') !== ''
            ? (Crypto::decrypt((string) $row['value']) ?? '')
            : (string) ($row['value'] ?? '');

        if ($current === $value) {
            return false;
        }

        Database::update(
            'settings',
            ['value' => $encrypted && $value !== '' ? Crypto::encrypt($value) : $value],
            ['id' => (int) $row['id']]
        );

        return true;
    }

    /**
     * Everything the policy form renders, read from wherever it actually lives.
     *
     * @return array<string,mixed>
     */
    private function policyValues(): array
    {
        return [
            'otp_login_enabled'        => Config::settingBool('auth', 'otp_login_enabled', false),
            'otp_channels'             => array_values(array_intersect(
                AuthConfigService::OTP_CHANNELS,
                array_map('strval', Config::settingArray('auth', 'otp_channels', ['email']))
            )),
            'otp_label'                => Config::settingString('auth', 'otp_label'),
            'otp_default_country_code' => Config::settingString('auth', 'otp_default_country_code', '+91'),
            'otp_phone_digits'         => Config::settingInt('auth', 'otp_phone_digits', 10),
            'otp_resend_seconds'       => Config::settingInt('auth', 'otp_resend_seconds', 30),
            'otp_max_resends'          => Config::settingInt('auth', 'otp_max_resends', 3),
            'otp_length'               => Config::settingInt('security', 'otp_length', 6),
            'otp_ttl'                  => Config::settingInt('security', 'otp_ttl', 300),
            'sms_enabled'              => Config::settingBool('sms', 'enabled', false),
            'sms_provider'             => Config::settingString('sms', 'provider'),

            'forgot_password_enabled'  => Config::settingBool('auth', 'forgot_password_enabled', true),
            'reset_token_ttl_minutes'  => Config::settingInt('auth', 'reset_token_ttl_minutes', 60),
            'identifier_mode'          => Config::settingString('auth', 'identifier_mode', 'email_or_phone'),
            'password_min_length'      => Config::settingInt('security', 'password_min_length', 8),
            'mail_host'                => Config::settingString('mail', 'host'),

            'guest_session_days'       => Config::settingInt('auth', 'guest_session_days', 30),
            'guest_purge_days'         => Config::settingInt('auth', 'guest_purge_days', 90),
            'guest_max_per_ip_per_day' => Config::settingInt('auth', 'guest_max_per_ip_per_day', 5),
            'guest_browse_routes'      => Config::settingArray('auth', 'guest_browse_routes', []),
            'guest_prompt_on'          => Config::settingArray('auth', 'guest_prompt_on', []),

            'creator_signup_enabled'   => Config::settingBool('app', 'creator_signup_enabled', true),
            'brand_signup_enabled'     => Config::settingBool('app', 'brand_signup_enabled', true),
            'default_signup_role'      => Config::settingString('auth', 'default_signup_role', 'brand'),
            'role_choice_visible'      => Config::settingBool('auth', 'role_choice_visible', true),
            'require_terms_accept'     => Config::settingBool('auth', 'require_terms_accept', true),
            'terms_url'                => Config::settingString('auth', 'terms_url'),
            'privacy_url'              => Config::settingString('auth', 'privacy_url'),

            'social_divider_label'     => Config::settingString('auth', 'social_divider_label', 'OR CONTINUE WITH'),
            'signup_divider_label'     => Config::settingString('auth', 'signup_divider_label', 'OR SIGN UP WITH'),
            'method_order'             => implode(',', Config::settingArray('auth', 'method_order', [])),

            'firebase_project_id'          => Config::settingString('firebase', 'project_id'),
            'firebase_web_api_key'         => Config::settingString('firebase', 'web_api_key'),
            'firebase_storage_bucket'      => Config::settingString('firebase', 'storage_bucket'),
            'firebase_app_id_android'      => Config::settingString('auth', 'firebase_app_id_android'),
            'firebase_app_id_ios'          => Config::settingString('auth', 'firebase_app_id_ios'),
            'firebase_app_id_web'          => Config::settingString('auth', 'firebase_app_id_web'),
            'firebase_messaging_sender_id' => Config::settingString('auth', 'firebase_messaging_sender_id'),
            'firebase_auth_domain'         => Config::settingString('auth', 'firebase_auth_domain'),
            'firebase_android_package_name' => Config::settingString('auth', 'firebase_android_package_name'),
            'firebase_ios_bundle_id'        => Config::settingString('auth', 'firebase_ios_bundle_id'),
        ];
    }

    /**
     * Sign-in counts for the last 24 hours, per method.
     *
     * Read from `login_history`, which already records app-user sign-ins — no
     * second log table and no second screen.
     *
     * @return array<string,array{success:int,failed:int,disabled:int}>
     */
    private function activity(): array
    {
        $out = [];

        foreach (Database::select(
            "SELECT `auth_method`, `outcome`, COUNT(*) AS `n`
               FROM `login_history`
              WHERE `actor_type` = 'user'
                AND `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              GROUP BY `auth_method`, `outcome`"
        ) as $row) {
            $method = (string) $row['auth_method'];
            $out[$method] ??= ['success' => 0, 'failed' => 0, 'disabled' => 0];

            $bucket = match ((string) $row['outcome']) {
                'success'         => 'success',
                'method_disabled' => 'disabled',
                default           => 'failed',
            };

            $out[$method][$bucket] += (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function recentSignIns(): array
    {
        return Database::select(
            "SELECT `identifier`, `outcome`, `auth_method`, `ip_address`, `device_type`, `created_at`
               FROM `login_history`
              WHERE `actor_type` = 'user'
              ORDER BY `id` DESC
              LIMIT 10"
        );
    }
}
