<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\ActivityLogger;
use Zuste\Services\AiProviderService;
use Zuste\Services\AiService;
use Zuste\Services\BackupService;
use Zuste\Services\CronRunner;
use Zuste\Services\FirebaseService;
use Zuste\Services\MailService;
use Zuste\Services\NotificationService;
use Zuste\Services\SmsService;

/**
 * Platform settings and system maintenance.
 *
 * Settings are stored in the database rather than in .env so an operator can
 * change commercial terms and integration credentials without a deploy.
 * Values marked `is_encrypted` are transparently encrypted at rest and are
 * never echoed back to the browser.
 */
final class SettingsController extends BaseAdminController
{
    /** Setting groups, in the order they appear as tabs. */
    private const GROUPS = [
        'general'    => ['label' => 'General',      'icon' => 'settings',  'permission' => 'settings.manage'],
        'branding'   => ['label' => 'Branding',     'icon' => 'image',     'permission' => 'settings.manage'],
        'booking'    => ['label' => 'Bookings',     'icon' => 'calendar',  'permission' => 'settings.manage'],
        'commission' => ['label' => 'Fees & tax',   'icon' => 'chart',     'permission' => 'settings.manage'],
        'wallet'     => ['label' => 'Wallet',       'icon' => 'wallet',    'permission' => 'settings.manage'],
        'referral'   => ['label' => 'Referrals',    'icon' => 'users',     'permission' => 'settings.manage'],
        'payment'    => ['label' => 'Payments',     'icon' => 'card',      'permission' => 'payments.gateways'],
        'firebase'   => ['label' => 'Firebase',     'icon' => 'shield',    'permission' => 'settings.manage'],
        'mail'       => ['label' => 'Email',        'icon' => 'inbox',     'permission' => 'settings.manage'],
        'sms'        => ['label' => 'SMS',          'icon' => 'chat',      'permission' => 'settings.manage'],
        'ai'         => ['label' => 'AI',           'icon' => 'star',      'permission' => 'ai.manage'],
        'upload'     => ['label' => 'Uploads',      'icon' => 'upload',    'permission' => 'settings.manage'],
        'app'        => ['label' => 'App releases', 'icon' => 'card',      'permission' => 'settings.manage'],
        'seo'        => ['label' => 'SEO',          'icon' => 'search',    'permission' => 'settings.manage'],
        'social'     => ['label' => 'Social links', 'icon' => 'external',  'permission' => 'settings.manage'],
        'security'   => ['label' => 'Security',     'icon' => 'key',       'permission' => 'settings.security'],
        // Mandatory flags, expiry, retention, consent wording and the blue-tick
        // rule. Gated by kyc.settings, the same permission as the KYC
        // requirements screen, so whoever owns the checklist owns these too.
        'kyc'        => ['label' => 'KYC',          'icon' => 'shield',    'permission' => 'kyc.settings'],
    ];

    /**
     * Per-setting value rules, keyed by `group.setting_key`.
     *
     * The `type` column only describes the SHAPE of a value (digits, numeric,
     * parseable JSON). It says nothing about the range, so a negative
     * percentage used to save cleanly and invert the live booking quote —
     * platform_fee_percent = -20 made Money::breakdown() pay the creator more
     * than the brand was charged. These rules bound the value itself.
     *
     * `min`/`max` are inclusive and apply to numeric settings; `format` names
     * one of the checks in {@see matchesFormat()}. An empty submission is left
     * alone here, exactly as the type checks already do, so clearing an
     * optional field keeps working.
     *
     * @var array<string,array{min?:float|int,max?:float|int,format?:string}>
     */
    private const VALUE_RULES = [
        // Fees and tax — these feed Money::breakdown() on every booking.
        'commission.platform_fee_percent'       => ['min' => 0, 'max' => 100],
        'commission.creator_commission_percent' => ['min' => 0, 'max' => 100],
        'commission.tax_percent'                => ['min' => 0, 'max' => 100],
        'commission.withdrawal_fee_percent'     => ['min' => 0, 'max' => 100],
        'commission.withdrawal_fee_flat'        => ['min' => 0, 'max' => 1000000],

        // Bookings.
        'booking.request_expiry_hours'          => ['min' => 1, 'max' => 8760],
        'booking.payment_window_hours'          => ['min' => 1, 'max' => 8760],
        'booking.default_delivery_days'         => ['min' => 1, 'max' => 365],
        'booking.auto_complete_days'            => ['min' => 1, 'max' => 365],
        'booking.free_cancellation_days'        => ['min' => 0, 'max' => 365],
        'booking.late_cancellation_fee_percent' => ['min' => 0, 'max' => 100],
        'booking.min_booking_amount'            => ['min' => 0, 'max' => 1000000000],
        'booking.max_booking_amount'            => ['min' => 0, 'max' => 1000000000],
        'booking.max_counter_offers'            => ['min' => 0, 'max' => 20],

        // Wallet.
        'wallet.min_topup'             => ['min' => 0, 'max' => 1000000000],
        'wallet.max_topup'             => ['min' => 0, 'max' => 1000000000],
        'wallet.min_withdrawal'        => ['min' => 0, 'max' => 1000000000],
        'wallet.max_withdrawal'        => ['min' => 0, 'max' => 1000000000],
        'wallet.withdrawal_hold_days'  => ['min' => 0, 'max' => 365],

        // Referrals.
        'referral.referrer_reward' => ['min' => 0, 'max' => 1000000],
        'referral.referred_reward' => ['min' => 0, 'max' => 1000000],
        'referral.share_base_url'  => ['format' => 'url'],

        // Security — a zero TTL or a zero attempt budget locks everybody out.
        'security.jwt_access_ttl'         => ['min' => 1, 'max' => 604800],
        'security.jwt_refresh_ttl'        => ['min' => 1, 'max' => 31536000],
        'security.otp_ttl'                => ['min' => 1, 'max' => 3600],
        'security.otp_length'             => ['min' => 4, 'max' => 10],
        'security.max_login_attempts'     => ['min' => 1, 'max' => 100],
        'security.lockout_minutes'        => ['min' => 1, 'max' => 1440],
        'security.api_rate_limit'         => ['min' => 1, 'max' => 100000],
        'security.api_rate_window'        => ['min' => 1, 'max' => 3600],
        'security.password_min_length'    => ['min' => 6, 'max' => 128],
        'security.api_log_sample_rate'    => ['min' => 0, 'max' => 100],
        'security.slow_query_threshold_ms' => ['min' => 1, 'max' => 60000],

        // Uploads.
        'upload.max_image_mb'       => ['min' => 1, 'max' => 1024],
        'upload.max_video_mb'       => ['min' => 1, 'max' => 4096],
        'upload.max_document_mb'    => ['min' => 1, 'max' => 1024],
        'upload.image_quality'      => ['min' => 1, 'max' => 100],
        'upload.image_max_dimension' => ['min' => 16, 'max' => 10000],

        // KYC — a zero retention window would delete documents the night they
        // were rejected; a zero link lifetime would make every preview fail.
        'kyc.max_upload_mb'                 => ['min' => 1, 'max' => 100],
        'kyc.expiry_months'                 => ['min' => 0, 'max' => 120],
        'kyc.expiry_reminder_days'          => ['min' => 1, 'max' => 365],
        'kyc.auto_approve_min_completeness' => ['min' => 0, 'max' => 100],
        'kyc.download_link_ttl'             => ['min' => 30, 'max' => 3600],
        'kyc.retention_days_rejected'       => ['min' => 7, 'max' => 3650],
        'kyc.retention_days_closed'         => ['min' => 7, 'max' => 3650],
        'kyc.purge_superseded_after_days'   => ['min' => 1, 'max' => 3650],

        // General.
        'general.support_email'    => ['format' => 'email'],
        'general.timezone'         => ['format' => 'timezone'],
        'general.default_currency' => ['format' => 'currency'],
        'general.default_locale'   => ['format' => 'locale'],

        // Branding.
        'branding.primary_color'   => ['format' => 'hex_color'],
        'branding.secondary_color' => ['format' => 'hex_color'],
        'branding.tertiary_color'  => ['format' => 'hex_color'],

        // AI — the allowance and budget are what stop a runaway bill.
        'ai.daily_limit_per_user' => ['min' => 1, 'max' => 10000],
        'ai.monthly_budget'       => ['min' => 0, 'max' => 1000000],

        // Email.
        'mail.port'         => ['min' => 1, 'max' => 65535],
        'mail.from_address' => ['format' => 'email'],

        // App releases — the Flutter force-update check compares these.
        'app.android_min_version'    => ['format' => 'version'],
        'app.android_latest_version' => ['format' => 'version'],
        'app.ios_min_version'        => ['format' => 'version'],
        'app.ios_latest_version'     => ['format' => 'version'],
        'app.android_store_url'      => ['format' => 'url'],
        'app.ios_store_url'          => ['format' => 'url'],

        // SEO and social.
        'seo.canonical_base' => ['format' => 'url'],
        'social.instagram'   => ['format' => 'url'],
        'social.youtube'     => ['format' => 'url'],
        'social.linkedin'    => ['format' => 'url'],
        'social.twitter'     => ['format' => 'url'],
        'social.facebook'    => ['format' => 'url'],
    ];

    /**
     * KYC settings fixed by policy, keyed by `group.setting_key`.
     *
     * The rows stay in the table (nothing that reads them breaks), but the
     * code ignores them and the screen shows them switched off: identity
     * verification is always approved by a person, and the blue tick always
     * needs a KYC approval that is still in date. `value` is what the row is
     * held at (null leaves it as it is) and `reason` is shown under it.
     *
     * @var array<string,array{value:?string,reason:string}>
     */
    private const POLICY_LOCKED = [
        'kyc.auto_approve' => [
            'value'  => '0',
            'reason' => 'Off by policy: every verification is approved by a person on the KYC screen. This switch has no effect and cannot be turned on.',
        ],
        'kyc.auto_approve_min_completeness' => [
            'value'  => null,
            'reason' => 'No effect: automatic approval is off by policy.',
        ],
        'kyc.badge_requires_kyc' => [
            'value'  => '1',
            'reason' => 'Always on by policy: the blue tick can only be granted to an account whose KYC was approved by a reviewer and is still in date. This switch cannot be turned off.',
        ],
    ];

    /**
     * Settings that must not cross, as `group` => list of [min key, max key].
     *
     * @var array<string,list<array{0:string,1:string}>>
     */
    private const RANGE_PAIRS = [
        'wallet'  => [['min_topup', 'max_topup'], ['min_withdrawal', 'max_withdrawal']],
        'booking' => [['min_booking_amount', 'max_booking_amount']],
    ];

    /** Tabs the system screen can render. */
    private const SYSTEM_TABS = ['overview', 'jobs', 'backups', 'database', 'logs'];

    /** Currencies the platform can quote in. */
    private const CURRENCIES = [
        'INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'JPY', 'CHF',
        'NZD', 'ZAR', 'MYR', 'THB', 'HKD', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR',
        'LKR', 'NPR', 'BDT', 'PKR', 'IDR', 'PHP', 'VND', 'KRW', 'CNY', 'BRL',
        'MXN', 'RUB', 'TRY', 'SEK', 'NOK', 'DKK', 'PLN',
    ];

    // =================================================================
    // Settings
    // =================================================================

    /** GET /admin/settings */
    public function index(Request $request): never
    {
        $this->authorize($request, 'settings.view');

        $group = $request->string('group', 'general');

        if (!isset(self::GROUPS[$group])) {
            $group = 'general';
        }

        // Read is gated per group, exactly as update() already gates writes.
        //
        // `settings.view` alone used to render every tab, so a role holding it
        // could read the security group in full — JWT TTLs, rate limits,
        // allowed origins, the 2FA policy and the lockout thresholds. None of
        // those are encrypted, so the masking below hid nothing.
        $groupPermission = (string) (self::GROUPS[$group]['permission'] ?? 'settings.manage');

        if ($groupPermission !== 'settings.manage') {
            $this->authorize($request, $groupPermission);
        }

        // Tabs the caller cannot open are not advertised either.
        $visibleGroups = [];

        foreach (self::GROUPS as $key => $meta) {
            $needed = (string) ($meta['permission'] ?? 'settings.manage');

            if ($needed === 'settings.manage' || $this->can($request, $needed)) {
                $visibleGroups[$key] = $meta;
            }
        }

        $rows = Database::select(
            'SELECT * FROM `settings` WHERE `group_key` = :group ORDER BY `sort_order`, `id`',
            ['group' => $group]
        );

        // A secret is never sent to the browser — only whether one is set.
        foreach ($rows as &$row) {
            if ((int) $row['is_encrypted'] === 1) {
                $row['has_value'] = $row['value'] !== null && $row['value'] !== '';
                $row['value']     = '';
            } else {
                $row['has_value'] = true;
            }

            // Shown, but as the fixed value policy holds it at, and disabled.
            $policy = self::POLICY_LOCKED[$group . '.' . $row['setting_key']] ?? null;

            if ($policy !== null) {
                $row['locked']        = true;
                $row['locked_reason'] = $policy['reason'];

                if ($policy['value'] !== null) {
                    $row['value'] = $policy['value'];
                }
            }
        }
        unset($row);

        $this->view($request, 'admin.settings.index', [
            'pageTitle'    => 'Settings',
            'pageSubtitle' => self::GROUPS[$group]['label'],
            'groups'       => $visibleGroups,
            'activeGroup'  => $group,
            'settings'     => $rows,
        ] + ($group === 'ai' ? $this->aiPanel() : []));
    }

    /**
     * POST /admin/settings/ai/provider
     *
     * Choose the provider, key and model the app's AI features use. The
     * provider becomes the enabled default; its key goes to `ai_providers`,
     * the same place the Providers screen keeps it, so there is only ever one.
     */
    public function saveAiProvider(Request $request): never
    {
        $this->authorize($request, 'settings.manage');
        $this->authorize($request, 'ai.manage');

        $data = $this->validate($request, [
            'provider_id' => 'required|integer|exists:ai_providers,id',
            'model'       => 'required|string|max:80',
            'api_key'     => 'nullable|string|max:500',
        ]);

        $provider = AiProviderService::find((int) $data['provider_id']);

        if ($provider === null) {
            $this->back('error', 'That provider no longer exists.', '/admin/settings?group=ai');
        }

        $apiKey = trim((string) ($data['api_key'] ?? ''));

        if ($apiKey === '' && empty($provider['api_key'])) {
            $this->back('error', 'Paste the ' . $provider['name'] . ' API key to use it.', '/admin/settings?group=ai');
        }

        AiProviderService::update((int) $provider['id'], [
            'default_model' => trim((string) $data['model']),
            'is_enabled'    => 1,
            'is_default'    => 1,
        ], $apiKey === '' ? null : $apiKey);

        $this->log(
            $request,
            'ai.provider.configured',
            'ai_provider',
            (int) $provider['id'],
            'Set ' . $provider['name'] . ' as the AI provider.',
            ['model' => trim((string) $data['model']), 'api_key' => $apiKey === '' ? 'unchanged' : '[updated]']
        );

        // A new key is checked straight away, so a typo shows up here rather
        // than as a failed request in somebody's app.
        if ($apiKey !== '') {
            $test = AiProviderService::test((array) AiProviderService::find((int) $provider['id']));

            $this->back(
                $test['ok'] ? 'success' : 'warning',
                $test['ok']
                    ? $provider['name'] . ' is set up and the key works.'
                    : 'Saved, but the test call failed: ' . $test['message'],
                '/admin/settings?group=ai'
            );
        }

        $this->respond($request, $provider['name'] . ' settings saved.', '/admin/settings?group=ai');
    }

    /** POST /admin/settings/ai/clear-key — remove a provider's key and switch it off. */
    public function clearAiKey(Request $request): never
    {
        $this->authorize($request, 'settings.manage');
        $this->authorize($request, 'ai.manage');

        $provider = AiProviderService::find($request->int('provider_id'));

        if ($provider === null || empty($provider['api_key'])) {
            $this->back('info', 'There is no key to remove.', '/admin/settings?group=ai');
        }

        AiProviderService::clearKey((int) $provider['id']);

        $this->log($request, 'ai.provider.key_cleared', 'ai_provider', (int) $provider['id'], 'Removed the ' . $provider['name'] . ' API key.');

        $this->respond($request, 'Key removed. ' . $provider['name'] . ' is switched off.', '/admin/settings?group=ai');
    }

    /** POST /admin/settings */
    public function update(Request $request): never
    {
        $this->authorize($request, 'settings.manage');

        $group = $request->string('group', 'general');

        if (!isset(self::GROUPS[$group])) {
            throw HttpException::unprocessable('Unknown settings group.');
        }

        // The security group is separately gated.
        $this->authorize($request, self::GROUPS[$group]['permission']);

        $definitions = Database::select(
            'SELECT * FROM `settings` WHERE `group_key` = :group',
            ['group' => $group]
        );

        $submitted = $request->array('settings');
        $changed   = [];

        Database::transaction(static function () use ($definitions, $submitted, &$changed, $group): void {
            // Effective value of every key in the group after this save, so a
            // pair like min_topup/max_topup can be compared even when only one
            // side of it was submitted.
            $effective = [];

            foreach ($definitions as $definition) {
                $effective[(string) $definition['setting_key']] = (string) ($definition['value'] ?? '');
            }

            foreach ($definitions as $definition) {
                $key = (string) $definition['setting_key'];

                // Fixed by policy: whatever was posted (a disabled switch is
                // not posted at all, which would read as "off"), the row is
                // held at the policy value and nothing else is written.
                $policy = self::POLICY_LOCKED[$group . '.' . $key] ?? null;

                if ($policy !== null) {
                    if ($policy['value'] !== null && (string) ($definition['value'] ?? '') !== $policy['value']) {
                        Database::update('settings', ['value' => $policy['value']], ['id' => (int) $definition['id']]);
                        $changed[$key] = $policy['value'] . ' (fixed by policy)';
                    }

                    $effective[$key] = $policy['value'] ?? (string) ($definition['value'] ?? '');

                    continue;
                }

                // A boolean that is off is simply absent from the POST body.
                if ($definition['type'] === 'boolean') {
                    $value = isset($submitted[$key]) && in_array((string) $submitted[$key], ['1', 'true', 'on'], true) ? '1' : '0';
                } elseif (!array_key_exists($key, $submitted)) {
                    continue;
                } else {
                    $value = is_array($submitted[$key])
                        ? (string) json_encode(array_values($submitted[$key]), JSON_UNESCAPED_UNICODE)
                        : trim((string) $submitted[$key]);
                }

                // Leaving a secret field blank means "keep the existing value".
                if ((int) $definition['is_encrypted'] === 1) {
                    if ($value === '') {
                        continue;
                    }

                    $stored = Crypto::encrypt($value);
                } else {
                    $stored = $value;
                }

                // Validate the shape declared on the row.
                if ($definition['type'] === 'integer' && $value !== '' && !ctype_digit(ltrim($value, '-'))) {
                    throw \Zuste\Core\ValidationException::forField(
                        'settings.' . $key,
                        (string) ($definition['label'] ?? $key) . ' must be a whole number.'
                    );
                }

                if ($definition['type'] === 'decimal' && $value !== '' && !is_numeric($value)) {
                    throw \Zuste\Core\ValidationException::forField(
                        'settings.' . $key,
                        (string) ($definition['label'] ?? $key) . ' must be a number.'
                    );
                }

                // A JSON-typed setting is always read back with
                // Config::settingArray(), which discards anything that is not
                // an array and silently falls back to the caller's hard-coded
                // default. A syntactically valid scalar such as `"abc"` is
                // therefore just as unusable as a syntax error.
                if ($definition['type'] === 'json' && $value !== '' && !is_array(json_decode($value, true))) {
                    throw \Zuste\Core\ValidationException::forField(
                        'settings.' . $key,
                        (string) ($definition['label'] ?? $key) . ' must be a valid JSON list or object.'
                    );
                }

                // Range and format rules declared for this specific setting.
                self::assertValueAllowed($group, $key, $value, (string) ($definition['label'] ?? $key));

                $effective[$key] = $value;

                if ((string) ($definition['value'] ?? '') === $stored) {
                    continue;
                }

                Database::update('settings', ['value' => $stored], ['id' => (int) $definition['id']]);

                // Never write a secret's value into the audit trail.
                $changed[$key] = (int) $definition['is_encrypted'] === 1 ? '[updated]' : $value;
            }

            // Floors must not exceed their ceilings. Checked on the values the
            // group will hold after this save, so raising only the floor is
            // still caught. Throwing here rolls the whole save back.
            foreach (self::RANGE_PAIRS[$group] ?? [] as [$minKey, $maxKey]) {
                $min = $effective[$minKey] ?? '';
                $max = $effective[$maxKey] ?? '';

                if (!is_numeric($min) || !is_numeric($max) || (float) $min <= (float) $max) {
                    continue;
                }

                throw \Zuste\Core\ValidationException::forField(
                    'settings.' . $minKey,
                    str_replace('_', ' ', $minKey) . ' cannot be greater than ' . str_replace('_', ' ', $maxKey) . '.'
                );
            }
        });

        Config::flushSettings();
        NotificationService::flushTemplates();

        $this->log($request, 'settings.updated', 'settings', null, 'Updated the ' . $group . ' settings.', $changed);

        $this->respond(
            $request,
            $changed === [] ? 'No changes to save.' : count($changed) . ' setting(s) saved.',
            '/admin/settings?group=' . $group
        );
    }

    /** POST /admin/settings/test — verify an integration without leaving the panel. */
    public function testIntegration(Request $request): never
    {
        $this->authorize($request, 'settings.manage');

        $target = $request->string('target');

        $result = match ($target) {
            'mail'     => $this->testMail($request),
            'sms'      => $this->testSms($request),
            'firebase' => $this->testFirebase(),
            'database' => $this->testDatabase(),
            'ai'       => $this->testAi($request),
            default    => ['ok' => false, 'message' => 'Unknown integration.'],
        };

        $this->log($request, 'settings.tested', 'settings', null, 'Tested ' . $target . ': ' . ($result['ok'] ? 'ok' : 'failed'));

        if ($request->expectsJson()) {
            Response::json(['success' => $result['ok'], 'message' => $result['message']], $result['ok'] ? 200 : 422);
        }

        $this->back($result['ok'] ? 'success' : 'error', $result['message'], '/admin/settings?group=' . $target);
    }

    // =================================================================
    // System
    // =================================================================

    /** GET /admin/system */
    public function system(Request $request): never
    {
        $this->authorize($request, 'logs.system');

        // The view is an if/elseif chain over these five, so an unrecognised
        // ?tab= used to render the chrome with no content section at all.
        // Coerced back to the default, exactly as index() does with ?group=.
        $tab = $request->string('tab', 'overview');

        if (!in_array($tab, self::SYSTEM_TABS, true)) {
            $tab = 'overview';
        }

        $data = [
            'pageTitle'    => 'System',
            'pageSubtitle' => 'Health, jobs, backups and logs',
            'tab'          => $tab,
            'php'          => [
                'version'            => PHP_VERSION,
                'memory_limit'       => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size'      => (string) ini_get('post_max_size'),
                'extensions'         => array_values(array_filter(
                    ['pdo_mysql', 'openssl', 'mbstring', 'curl', 'gd', 'fileinfo', 'zip', 'intl'],
                    static fn (string $ext): bool => extension_loaded($ext)
                )),
                'missing'            => array_values(array_filter(
                    ['pdo_mysql', 'openssl', 'mbstring', 'curl', 'gd', 'fileinfo'],
                    static fn (string $ext): bool => !extension_loaded($ext)
                )),
            ],
            'database'     => [
                'version' => (string) Database::scalar('SELECT VERSION()'),
                'name'    => (string) Config::get('database.name'),
                'tables'  => BackupService::tableStats(),
            ],
            'storage'      => $this->storageStats(),
            'jobs'         => Database::select('SELECT * FROM `scheduled_jobs` ORDER BY `id`'),
            'backups'      => Database::select('SELECT b.*, a.`name` AS `author` FROM `backups` b LEFT JOIN `admins` a ON a.`id` = b.`created_by` ORDER BY b.`id` DESC LIMIT 20'),
            'queues'       => [
                'email' => $this->queueStats('email_queue'),
                'sms'   => $this->queueStats('sms_queue'),
                'push'  => $this->queueStats('push_queue'),
            ],
        ];

        if ($tab === 'logs') {
            $level    = $request->string('level');
            $clauses  = ['1 = 1'];
            $bindings = [];

            if ($level !== '') {
                $clauses[]         = '`level` = :level';
                $bindings['level'] = $level;
            }

            $where   = ' WHERE ' . implode(' AND ', $clauses);
            $perPage = $request->perPage(50, 200);
            $page    = $request->page();

            $data['logTotal']      = (int) Database::scalar('SELECT COUNT(*) FROM `system_logs`' . $where, $bindings);
            $data['logs']          = Database::select(
                'SELECT * FROM `system_logs`' . $where . ' ORDER BY `id` DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
                $bindings
            );
            $data['logPagination'] = $this->paginationMeta($data['logTotal'], $page, $perPage);
            $data['filters']       = $request->all();
        }

        $this->view($request, 'admin.system.index', $data);
    }

    /** POST /admin/system/jobs/{job}/run */
    public function runJob(Request $request): never
    {
        $this->authorize($request, 'system.jobs');

        $job = (string) $request->routeParam('job', '');

        if (!CronRunner::exists($job)) {
            throw HttpException::notFound('Unknown job.');
        }

        $started = microtime(true);
        $result  = CronRunner::run($job);
        $ms      = (int) round((microtime(true) - $started) * 1000);

        $this->log($request, 'system.job_run', 'job', null, 'Ran ' . $job . ' manually (' . $ms . 'ms).', $result);

        $this->respond($request, $job . ' completed in ' . $ms . 'ms.', '/admin/system?tab=jobs', $result);
    }

    /** POST /admin/system/jobs/{job}/toggle */
    public function toggleJob(Request $request): never
    {
        $this->authorize($request, 'system.jobs');

        $job = (string) $request->routeParam('job', '');

        // Same guard runJob() applies. Without it a mistyped key matched zero
        // rows, the follow-up SELECT returned NULL, and the operator was told
        // the job had been disabled — with an activity_logs row agreeing.
        if (!CronRunner::exists($job)) {
            throw HttpException::notFound('Unknown job.');
        }

        Database::execute(
            'UPDATE `scheduled_jobs` SET `is_enabled` = 1 - `is_enabled` WHERE `job_key` = :key',
            ['key' => $job]
        );

        $enabled = (int) Database::scalar('SELECT `is_enabled` FROM `scheduled_jobs` WHERE `job_key` = :key', ['key' => $job]);

        $this->log($request, 'system.job_toggled', 'job', null, $job . ' ' . ($enabled ? 'enabled' : 'disabled') . '.');

        $this->respond($request, $job . ' ' . ($enabled ? 'enabled' : 'disabled') . '.', '/admin/system?tab=jobs');
    }

    /** POST /admin/system/backup */
    public function createBackup(Request $request): never
    {
        $this->authorize($request, 'system.backup');

        $backup = BackupService::createDatabaseBackup('manual', $this->adminId($request));

        $this->log($request, 'system.backup_created', 'backup', $backup['id'], 'Created ' . $backup['file']);

        $this->respond(
            $request,
            'Backup created: ' . $backup['file'] . ' (' . $this->formatBytes($backup['size']) . ').',
            '/admin/system?tab=backups'
        );
    }

    /** GET /admin/system/backup/{id}/download */
    public function downloadBackup(Request $request): never
    {
        $this->authorize($request, 'system.backup');

        $backup = Database::selectOne('SELECT * FROM `backups` WHERE `id` = :id', ['id' => $request->routeParamInt('id')]);

        if ($backup === null || !is_readable((string) $backup['path'])) {
            throw HttpException::notFound('Backup file not found.');
        }

        $this->log($request, 'system.backup_downloaded', 'backup', (int) $backup['id'], 'Downloaded ' . (string) $backup['file_name']);

        Response::download(
            (string) file_get_contents((string) $backup['path']),
            (string) $backup['file_name'],
            str_ends_with((string) $backup['file_name'], '.gz') ? 'application/gzip' : 'application/sql'
        );
    }

    /** POST /admin/system/backup/{id}/restore */
    public function restoreBackup(Request $request): never
    {
        $this->authorize($request, 'system.restore');

        $data = $this->validate($request, ['confirmation' => 'required|string']);

        $backupId = $request->routeParamInt('id');

        $result = BackupService::restore(
            $backupId,
            (string) $data['confirmation'],
            $this->adminId($request)
        );

        // Must be written AFTER the restore: the restore replaces every table,
        // so an audit row inserted before it would be overwritten.
        $this->log(
            $request,
            'system.restored',
            'backup',
            $backupId,
            'Restored the database from backup #' . $backupId . ' (' . $result['statements_executed'] . ' statements).',
            ['statements' => $result['statements_executed'], 'safety_backup' => $result['safety_backup']]
        );

        $this->respond(
            $request,
            'Database restored (' . $result['statements_executed'] . ' statements). A safety snapshot was taken first: ' . $result['safety_backup'],
            '/admin/system?tab=backups'
        );
    }

    /** POST /admin/system/optimize */
    public function optimizeDatabase(Request $request): never
    {
        $this->authorize($request, 'system.optimize');

        $results = BackupService::optimizeDatabase();
        $failed  = array_filter($results, static fn (string $r): bool => $r !== 'ok');

        $this->log($request, 'system.optimized', 'database', null, count($results) . ' tables optimised.');

        $this->respond(
            $request,
            $failed === []
                ? count($results) . ' tables optimised.'
                : count($results) - count($failed) . ' optimised, ' . count($failed) . ' failed.',
            '/admin/system?tab=database'
        );
    }

    /** POST /admin/system/logs/clear */
    public function clearLogs(Request $request): never
    {
        $this->authorize($request, 'logs.system');

        $level = $request->string('level');

        if ($level !== '' && in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)) {
            $deleted = Database::execute('DELETE FROM `system_logs` WHERE `level` = :level', ['level' => $level]);
        } else {
            $deleted = Database::execute("DELETE FROM `system_logs` WHERE `level` IN ('debug','info','notice')");
        }

        $this->log($request, 'system.logs_cleared', 'logs', null, $deleted . ' log entries removed.');

        $this->respond($request, number_format($deleted) . ' log entries cleared.', '/admin/system?tab=logs');
    }

    // =================================================================
    // Integration tests
    // =================================================================

    /** @return array{ok:bool,message:string} */
    private function testMail(Request $request): array
    {
        $to = $request->string('email') ?: (string) $this->admin($request)['email'];

        // Name the blocker instead of blaming the credentials. MailService
        // throws before it opens a socket when the SMTP host is blank, and the
        // caller only ever saw 'the mail server rejected the message'.
        $driver = Config::settingString('mail', 'driver', (string) Config::get('mail.driver', 'smtp'));

        if ($driver === 'smtp') {
            $host = Config::settingString('mail', 'host') ?: (string) Config::get('mail.host', '');

            if ($host === '') {
                return ['ok' => false, 'message' => 'No SMTP host is configured. Set the host in Settings → Email before sending a test.'];
            }
        }

        $sent = MailService::sendNow(
            $to,
            (string) $this->admin($request)['name'],
            'Zusty mail configuration test',
            MailService::renderDefault(
                'Your mail configuration works',
                'This test message was sent from the Zusty admin panel. If you are reading it, outbound email is configured correctly.',
                (string) $this->admin($request)['name']
            )
        );

        return [
            'ok'      => $sent,
            'message' => $sent
                ? 'Test email sent to ' . $to . '.'
                : 'The mail server rejected the message. Check the host, port, encryption and credentials.',
        ];
    }

    /** @return array{ok:bool,message:string} */
    private function testSms(Request $request): array
    {
        $to = $request->string('phone');

        if ($to === '') {
            return ['ok' => false, 'message' => 'Enter a phone number to send the test to.'];
        }

        // SmsService::sendNow() returns false at its first guard when SMS is
        // switched off, before any provider is contacted — reporting that as a
        // rejected API key sent operators hunting a working credential.
        if (!Config::settingBool('sms', 'enabled', false) && !(bool) Config::get('sms.enabled', false)) {
            return ['ok' => false, 'message' => 'SMS is switched off in Settings → SMS. Enable it before sending a test.'];
        }

        if (Config::settingString('sms', 'api_key') === '' && (string) Config::get('sms.api_key', '') === '') {
            return [
                'ok'      => false,
                'message' => 'No API key is stored for the '
                    . (Config::settingString('sms', 'provider', 'msg91'))
                    . ' provider. Save one in Settings → SMS before sending a test.',
            ];
        }

        $sent = SmsService::sendNow($to, 'Zusty SMS configuration test. If you received this, SMS is working.');

        return [
            'ok'      => $sent,
            'message' => $sent ? 'Test SMS sent to ' . $to . '.' : 'The SMS provider rejected the message. Check the API key and sender id.',
        ];
    }

    /** @return array{ok:bool,message:string} */
    private function testFirebase(): array
    {
        try {
            // Minting an access token exercises the service account end to end.
            $result = FirebaseService::sendToTopic(
                'zuste-diagnostics',
                'Zusty configuration test',
                'Firebase Cloud Messaging is configured correctly.'
            );

            return [
                'ok'      => $result['ok'],
                'message' => $result['ok']
                    ? 'Firebase accepted the test message.'
                    : 'Firebase rejected the request: ' . (string) ($result['error'] ?? 'unknown error'),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Firebase test failed: ' . $e->getMessage()];
        }
    }

    /** @return array{ok:bool,message:string} */
    /**
     * Send the smallest real request to the provider the app would use.
     *
     * @return array{ok:bool,message:string}
     */
    private function testAi(Request $request): array
    {
        $this->authorize($request, 'ai.manage');

        $provider = AiProviderService::active();

        if ($provider === null) {
            return ['ok' => false, 'message' => 'Save a provider and its API key first.'];
        }

        $result = AiProviderService::test($provider);

        return [
            'ok'      => $result['ok'],
            'message' => $result['ok']
                ? $provider['name'] . ' answered in ' . (int) $result['duration_ms'] . ' ms using ' . $result['model'] . '.'
                : $provider['name'] . ': ' . $result['message'],
        ];
    }

    /**
     * Data for the provider card on the AI tab. A key is reported only as
     * present or not — its value, even encrypted, never reaches the page.
     *
     * @return array<string,mixed>
     */
    private function aiPanel(): array
    {
        $providers = array_values(array_filter(
            AiProviderService::all(),
            static fn (array $p): bool => isset(AiProviderService::ADAPTERS[(string) $p['adapter']])
        ));

        $currentId = 0;

        foreach ($providers as $provider) {
            if ((int) $provider['is_default'] === 1) {
                $currentId = (int) $provider['id'];
                break;
            }
        }

        $models = [];

        foreach ($providers as $provider) {
            $models[(int) $provider['id']] = array_map(
                static fn (array $m): array => ['code' => (string) $m['model_code'], 'label' => (string) $m['label']],
                AiProviderService::models((int) $provider['id'])
            );
        }

        $active = AiProviderService::active();

        return [
            'aiProviders'  => array_map(static fn (array $p): array => [
                'id'            => (int) $p['id'],
                'name'          => (string) $p['name'],
                'has_key'       => !empty($p['api_key']),
                'default_model' => (string) ($p['default_model'] ?? ''),
                'is_enabled'    => (int) $p['is_enabled'] === 1,
                'verified_at'   => $p['verified_at'],
                'last_error'    => $p['last_error'],
            ], $providers),
            'aiCurrentId'  => $currentId ?: (int) ($active['id'] ?? 0),
            'aiModels'     => $models,
            'aiActiveName' => $active === null ? null : (string) $active['name'],
            'aiFeatures'   => AiService::appFeatures(),
            'aiMonthSpend' => AiService::monthSpend(),
            'aiCallsToday' => (int) Database::scalar(
                'SELECT COUNT(*) FROM `ai_logs` WHERE `created_at` >= :today',
                ['today' => date('Y-m-d 00:00:00')]
            ),
        ];
    }

    private function testDatabase(): array
    {
        try {
            $started = microtime(true);
            Database::scalar('SELECT 1');
            $ms = round((microtime(true) - $started) * 1000, 2);

            return ['ok' => true, 'message' => 'Database responded in ' . $ms . 'ms.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Database check failed: ' . $e->getMessage()];
        }
    }

    // =================================================================
    // Value rules
    // =================================================================

    /**
     * Enforce the range/format rule declared for a setting, if it has one.
     *
     * @throws \Zuste\Core\ValidationException when the value is out of range or
     *                                         malformed for its key.
     */
    private static function assertValueAllowed(string $group, string $key, string $value, string $label): void
    {
        $rule = self::VALUE_RULES[$group . '.' . $key] ?? null;

        // An empty submission is left to the existing "keep/clear" behaviour.
        if ($rule === null || $value === '') {
            return;
        }

        if (isset($rule['min']) || isset($rule['max'])) {
            if (!is_numeric($value)) {
                throw \Zuste\Core\ValidationException::forField('settings.' . $key, $label . ' must be a number.');
            }

            $number = (float) $value;
            $min    = isset($rule['min']) ? (float) $rule['min'] : null;
            $max    = isset($rule['max']) ? (float) $rule['max'] : null;

            if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
                throw \Zuste\Core\ValidationException::forField(
                    'settings.' . $key,
                    $label . ' must be between ' . self::formatBound($min ?? 0.0) . ' and ' . self::formatBound($max ?? $number) . '.'
                );
            }
        }

        $format = (string) ($rule['format'] ?? '');

        if ($format !== '' && !self::matchesFormat($format, $value)) {
            throw \Zuste\Core\ValidationException::forField(
                'settings.' . $key,
                $label . ' ' . self::formatHint($format)
            );
        }
    }

    /** Does [$value] satisfy the named format? */
    private static function matchesFormat(string $format, string $value): bool
    {
        return match ($format) {
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'       => filter_var($value, FILTER_VALIDATE_URL) !== false
                           && preg_match('~^https?://~i', $value) === 1,
            'timezone'  => in_array($value, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true),
            'currency'  => in_array(strtoupper($value), self::CURRENCIES, true),
            'locale'    => preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $value) === 1,
            'hex_color' => preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value) === 1,
            'version'   => preg_match('/^\d{1,5}(?:\.\d{1,5}){0,3}$/', $value) === 1,
            default     => true,
        };
    }

    private static function formatHint(string $format): string
    {
        return match ($format) {
            'email'     => 'must be a valid email address.',
            'url'       => 'must be a full URL starting with http:// or https://.',
            'timezone'  => 'must be a valid IANA timezone, for example Asia/Kolkata.',
            'currency'  => 'must be a supported 3-letter currency code, for example INR.',
            'locale'    => 'must be a language code such as en or en-IN.',
            'hex_color' => 'must be a hex colour such as #00b815.',
            'version'   => 'must be a version number such as 1.0.0.',
            default     => 'is not in the expected format.',
        };
    }

    private static function formatBound(float $bound): string
    {
        return $bound == (int) $bound ? (string) (int) $bound : rtrim(rtrim(number_format($bound, 2, '.', ''), '0'), '.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array<string,mixed> */
    private function storageStats(): array
    {
        $uploadPath = (string) Config::get('storage.upload_path');
        $logPath    = (string) Config::get('log.path');

        return [
            'upload_path'     => $uploadPath,
            'upload_writable' => is_writable($uploadPath),
            'upload_size'     => $this->directorySize($uploadPath),
            'log_path'        => $logPath,
            'log_writable'    => is_writable($logPath),
            'log_size'        => $this->directorySize($logPath),
            'backup_size'     => $this->directorySize((string) Config::get('storage.backup_path')),
            'free_space'      => (float) (@disk_free_space((string) Config::get('app.base_path')) ?: 0),
        ];
    }

    private function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $bytes = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (\Throwable) {
            return $bytes;
        }

        return $bytes;
    }

    /** @return array<string,int> */
    private function queueStats(string $table): array
    {
        $row = Database::selectOne(
            "SELECT
                SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) AS `pending`,
                SUM(CASE WHEN `status` = 'sent'    THEN 1 ELSE 0 END) AS `sent`,
                SUM(CASE WHEN `status` = 'failed'  THEN 1 ELSE 0 END) AS `failed`
             FROM " . Database::quoteIdentifier($table)
        ) ?? [];

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'sent'    => (int) ($row['sent'] ?? 0),
            'failed'  => (int) ($row['failed'] ?? 0),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1) . ' ' . $units[$index];
    }
}
