<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Admin\Integration;
use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\Logger;

/**
 * The engine behind the integrations hub.
 *
 * Secrets are held as one encrypted JSON blob per integration, so a new
 * credential field never needs a migration. Where an integration declares a
 * `settings_map`, saving also writes the matching `settings` rows — that is
 * what keeps MailService, SmsService, PaymentService and FirebaseService
 * working unchanged while the hub becomes their front end.
 */
final class IntegrationService
{
    /** Secret-looking keys are never written into a log context. */
    private const REDACT = [
        'password', 'secret', 'token', 'key', 'auth', 'credential', 'private',
        'signature', 'pass', 'pwd', 'authorization', 'json',
    ];

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * The full state of one integration: its declaration, its stored row, and
     * the derived status. Returns null when the key is not in the catalogue.
     *
     * @return array<string,mixed>|null
     */
    public static function state(string $key): ?array
    {
        $definition = Integration::find($key);

        if ($definition === null) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `integrations` WHERE `integration_key` = :k',
            ['k' => $key]
        );

        $credentials = self::decodeCredentials($row['credentials'] ?? null);
        $config      = self::decodeJson($row['config'] ?? null);

        return [
            'key'         => $key,
            'definition'  => $definition,
            'row'         => $row,
            'config'      => $config,
            // Which secret fields hold a value — never the values themselves.
            'has_secret'  => self::secretPresence($key, $credentials),
            'values'      => self::visibleValues($key, $credentials, $config),
            'status'      => self::status($definition, $row, $credentials, $config),
            'webhook_url' => self::webhookUrl($key, $definition),
            'reference_only' => self::isReferenceOnly($definition),
        ];
    }

    /**
     * Does anything on this platform actually read what this card stores?
     *
     * `settings_map` is the only route from an integration's encrypted blob to
     * running code — credentials() is called nowhere outside this class, which
     * is exactly what the class docblock describes. So an integration with
     * fields but no map is a form that saves credentials nothing will ever use,
     * and 36 of them were presenting themselves identically to the ones that
     * do. Deriving the answer rather than flagging each definition by hand
     * means it can never drift: add a settings_map and the warning goes away.
     *
     * @param array<string,mixed> $definition
     */
    public static function isReferenceOnly(array $definition): bool
    {
        return $definition['delegate'] === null
            && $definition['fields'] !== []
            && $definition['settings_map'] === [];
    }

    /**
     * Every integration with its status, for the hub grid.
     *
     * One query for all rows rather than one per integration.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function overview(): array
    {
        $rows = [];

        foreach (Database::select('SELECT * FROM `integrations`') as $row) {
            $rows[$row['integration_key']] = $row;
        }

        $out = [];

        foreach (Integration::all() as $key => $definition) {
            $row         = $rows[$key] ?? null;
            $credentials = self::decodeCredentials($row['credentials'] ?? null);
            $config      = self::decodeJson($row['config'] ?? null);

            $out[$key] = [
                'key'        => $key,
                'definition' => $definition,
                'row'        => $row,
                'status'     => self::status($definition, $row, $credentials, $config),
                'enabled'    => $row !== null && (int) $row['is_enabled'] === 1,
            ];
        }

        return $out;
    }

    /**
     * Derive a status without trusting the stored column, so a row that was
     * saved and then had its credentials cleared reports honestly.
     */
    private static function status(array $definition, ?array $row, array $credentials, array $config): string
    {
        if ($definition['delegate'] !== null) {
            return 'delegated';
        }

        if ($row === null) {
            return 'not_configured';
        }

        if ((int) $row['is_enabled'] !== 1) {
            return self::isConfigured($definition, $credentials, $config) ? 'disabled' : 'not_configured';
        }

        if (!self::isConfigured($definition, $credentials, $config)) {
            return 'not_configured';
        }

        if ($row['last_test_ok'] !== null && (int) $row['last_test_ok'] === 0) {
            return 'error';
        }

        return 'connected';
    }

    /**
     * An integration counts as configured once every required field has a value.
     *
     * This used to return true on the FIRST non-empty field, which contradicted
     * both its own docblock and testConfig() below: Meta Pixel with only a pixel
     * ID showed a green "Connected" pill directly above a connection test saying
     * "still missing: Conversions API access token", and the same leniency let
     * the enable guard in IntegrationController through. The two now apply the
     * same rule — self::requiredFields() — so the pill, the test and the toggle
     * can never disagree.
     */
    private static function isConfigured(array $definition, array $credentials, array $config): bool
    {
        foreach (self::requiredFields($definition) as $field) {
            $name  = $field['name'];
            $value = ($field['secret'] ?? false) ? ($credentials[$name] ?? '') : ($config[$name] ?? '');

            if (trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The fields an integration cannot work without.
     *
     * A switch is always "set" — off is a value. An `optional` field is one the
     * catalogue declares as genuinely not needed: a build-time value recorded
     * for reference, or a token only one flow uses.
     *
     * @param  array<string,mixed> $definition
     * @return list<array<string,mixed>>
     */
    private static function requiredFields(array $definition): array
    {
        $required = [];

        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'switch' || ($field['optional'] ?? false) === true) {
                continue;
            }

            $required[] = $field;
        }

        return $required;
    }

    /**
     * Non-secret values for the form. Secrets are never returned.
     *
     * @return array<string,mixed>
     */
    private static function visibleValues(string $key, array $credentials, array $config): array
    {
        $visible = $config;

        foreach (Integration::secretFields($key) as $secret) {
            unset($visible[$secret]);
        }

        return $visible;
    }

    /** @return array<string,bool> */
    private static function secretPresence(string $key, array $credentials): array
    {
        $presence = [];

        foreach (Integration::secretFields($key) as $secret) {
            $presence[$secret] = trim((string) ($credentials[$secret] ?? '')) !== '';
        }

        return $presence;
    }

    /**
     * Decrypted credentials for an integration. Only call this where the values
     * are actually needed to make a request.
     *
     * @return array<string,string>
     */
    public static function credentials(string $key): array
    {
        $stored = Database::scalar(
            'SELECT `credentials` FROM `integrations` WHERE `integration_key` = :k',
            ['k' => $key]
        );

        return self::decodeCredentials(is_string($stored) ? $stored : null);
    }

    /** @return array<string,string> */
    private static function decodeCredentials(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }

        try {
            $plain = Crypto::decrypt($blob);
        } catch (\Throwable $e) {
            Logger::error('Could not decrypt integration credentials', ['error' => $e->getMessage()]);

            return [];
        }

        if (!is_string($plain)) {
            return [];
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private static function decodeJson(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }

        $decoded = json_decode($blob, true);

        return is_array($decoded) ? $decoded : [];
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Save submitted values.
     *
     * A secret field submitted blank keeps its stored value — the form never
     * round-trips a secret, so "unchanged" has to be expressible.
     *
     * @param array<string,mixed> $submitted
     */
    public static function save(string $key, array $submitted, int $adminId): void
    {
        $definition = Integration::find($key);

        if ($definition === null) {
            throw new \InvalidArgumentException('Unknown integration: ' . $key);
        }

        $existingCredentials = self::credentials($key);
        $existingRow         = Database::selectOne('SELECT * FROM `integrations` WHERE `integration_key` = :k', ['k' => $key]);
        $existingConfig      = self::decodeJson($existingRow['config'] ?? null);

        $credentials = $existingCredentials;
        $config      = $existingConfig;
        $changed     = [];

        foreach ($definition['fields'] as $field) {
            $name     = $field['name'];
            $isSecret = ($field['secret'] ?? false) === true;

            if ($field['type'] === 'switch') {
                $config[$name] = array_key_exists($name, $submitted) ? 1 : 0;
                continue;
            }

            if (!array_key_exists($name, $submitted)) {
                continue;
            }

            $value = trim((string) $submitted[$name]);

            if ($isSecret) {
                // Blank means "keep it"; the caller clears a secret explicitly.
                if ($value === '') {
                    continue;
                }

                $credentials[$name] = $value;
                $changed[]          = $name;
                continue;
            }

            $config[$name] = $value;
        }

        $payload = [
            'integration_key' => $key,
            'credentials'     => $credentials === [] ? null : Crypto::encrypt((string) json_encode($credentials)),
            'config'          => (string) json_encode($config),
            'configured_by'   => $adminId,
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        // Changing a credential invalidates whatever the last test proved.
        if ($changed !== []) {
            $payload['last_test_ok'] = null;
            $payload['last_test_at'] = null;
            $payload['last_error']   = null;
        }

        self::upsert($key, $payload);
        self::mirrorToSettings($definition, $credentials, $config);

        self::log($key, 'config', 'success', 'Configuration saved.', [
            'fields_changed'  => array_keys($submitted),
            'secrets_changed' => count($changed),
        ], $adminId);
    }

    /**
     * Write the values an existing service already reads out of `settings`.
     *
     * Without this, saving SMTP here would leave MailService on the old
     * credentials — the hub would look right and the platform would keep
     * sending with the previous settings.
     */
    private static function mirrorToSettings(array $definition, array $credentials, array $config): void
    {
        foreach ($definition['settings_map'] as $field => [$group, $settingKey]) {
            $isSecret = false;

            foreach ($definition['fields'] as $declared) {
                if ($declared['name'] === $field) {
                    $isSecret = ($declared['secret'] ?? false) === true;
                    break;
                }
            }

            $value = $isSecret ? ($credentials[$field] ?? null) : ($config[$field] ?? null);

            // Blank means two different things depending on the field:
            //
            //   secret     — "leave what is stored alone". The form never
            //                round-trips a secret, so an empty box is the
            //                normal state and must not wipe anything.
            //   non-secret — "clear this". The form always renders the current
            //                value, so a box the operator emptied is a
            //                deliberate instruction.
            //
            // Treating both as "skip" meant clearing an SMTP host in the hub
            // left settings.mail.host pointing at the old server, and the
            // platform kept connecting to it.
            if ($value === null) {
                continue;
            }

            if ($isSecret && trim((string) $value) === '') {
                continue;
            }

            try {
                // Honour the target row's own is_encrypted flag rather than the
                // source field's secret flag. Config::loadSettings() decrypts on
                // the strength of that column, so writing plaintext into a row
                // marked encrypted yields a value nothing can read back.
                $target = Database::selectOne(
                    'SELECT `is_encrypted` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
                    ['g' => $group, 'k' => $settingKey]
                );

                $stored = ($target !== null && (int) $target['is_encrypted'] === 1)
                    ? Crypto::encrypt((string) $value)
                    : (string) $value;

                $affected = Database::execute(
                    'UPDATE `settings` SET `value` = :v, `updated_at` = NOW()
                      WHERE `group_key` = :g AND `setting_key` = :k',
                    ['v' => $stored, 'g' => $group, 'k' => $settingKey]
                );

                if ($affected === 0) {
                    Database::execute(
                        'INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `is_public`, `is_encrypted`, `sort_order`, `updated_at`)
                         VALUES (:g, :k, :v, :t, :l, 0, 0, 99, NOW())
                         ON DUPLICATE KEY UPDATE `value` = :v, `updated_at` = NOW()',
                        [
                            'g' => $group,
                            'k' => $settingKey,
                            'v' => (string) $value,
                            't' => $isSecret ? 'secret' : 'string',
                            'l' => ucwords(str_replace('_', ' ', $settingKey)),
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Logger::error('Could not mirror an integration value into settings', [
                    'group' => $group, 'key' => $settingKey, 'error' => $e->getMessage(),
                ]);
            }
        }

        // The settings cache would otherwise serve the previous value.
        \Zuste\Core\Cache::forgetGroup('settings');
        \Zuste\Core\Cache::forget('settings:all');

        // Config holds its own per-process copy. Without this the mirrored value
        // is invisible until the next request — which breaks "save then test"
        // and "save then read /app/config" inside one round trip.
        Config::flushSettings();
    }

    /** Turn one integration on or off. */
    public static function toggle(string $key, bool $enable, int $adminId): void
    {
        self::upsert($key, [
            'integration_key' => $key,
            'is_enabled'      => $enable ? 1 : 0,
            'status'          => $enable ? 'connected' : 'disabled',
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        self::log($key, $enable ? 'enable' : 'disable', 'success',
            $enable ? 'Integration enabled.' : 'Integration disabled.', [], $adminId);
    }

    /** Remove one stored secret. */
    public static function clearSecret(string $key, string $field, int $adminId): void
    {
        $credentials = self::credentials($key);

        if (!array_key_exists($field, $credentials)) {
            return;
        }

        unset($credentials[$field]);

        self::upsert($key, [
            'integration_key' => $key,
            'credentials'     => $credentials === [] ? null : Crypto::encrypt((string) json_encode($credentials)),
            'is_enabled'      => 0,
            'last_test_ok'    => null,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        // Clear the mirror too.
        //
        // Several integrations mirror a field into a `settings` row that the
        // services actually authenticate with — smtp into mail.password, and so
        // on. Removing the credential here without clearing that row told the
        // operator the secret was gone while the platform carried on
        // authenticating with it: the most dangerous possible outcome for a
        // "remove this credential" button, because it is the one an operator
        // presses when a secret has leaked.
        $definition = Integration::find($key);

        if ($definition !== null && isset($definition['settings_map'][$field])) {
            [$group, $settingKey] = $definition['settings_map'][$field];

            try {
                Database::execute(
                    'UPDATE `settings` SET `value` = :v, `updated_at` = NOW()
                      WHERE `group_key` = :g AND `setting_key` = :k',
                    ['v' => '', 'g' => $group, 'k' => $settingKey]
                );
            } catch (\Throwable $e) {
                Logger::error('Could not clear a mirrored integration secret', [
                    'group' => $group, 'key' => $settingKey, 'error' => $e->getMessage(),
                ]);
            }

            \Zuste\Core\Cache::forgetGroup('settings');
            \Zuste\Core\Cache::forget('settings:all');
            Config::flushSettings();
        }

        self::log($key, 'config', 'warning', 'Secret "' . $field . '" removed; integration switched off.', [], $adminId);
    }

    /** Save webhook settings and rotate the signing secret when asked. */
    public static function saveWebhook(string $key, string $events, bool $rotate, int $adminId): string
    {
        $payload = [
            'integration_key' => $key,
            'webhook_events'  => mb_substr($events, 0, 500),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        $secret = '';

        if ($rotate) {
            $secret                    = bin2hex(random_bytes(24));
            $payload['webhook_secret'] = Crypto::encrypt($secret);
        }

        self::upsert($key, $payload);

        self::log($key, 'webhook', 'success',
            $rotate ? 'Webhook secret rotated.' : 'Webhook settings saved.', ['events' => $events], $adminId);

        return $secret;
    }

    /** Auto-sync schedule. */
    public static function saveSchedule(string $key, bool $auto, int $intervalMinutes, int $adminId): void
    {
        $interval = max(5, min(10080, $intervalMinutes));

        self::upsert($key, [
            'integration_key'   => $key,
            'auto_sync'         => $auto ? 1 : 0,
            'sync_interval_min' => $interval,
            'next_sync_at'      => $auto ? date('Y-m-d H:i:s', time() + $interval * 60) : null,
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        self::log($key, 'config', 'success',
            $auto ? "Auto sync on, every {$interval} minutes." : 'Auto sync off.', [], $adminId);
    }

    /** Insert or update the row for one integration. */
    private static function upsert(string $key, array $payload): void
    {
        $exists = Database::scalar('SELECT `id` FROM `integrations` WHERE `integration_key` = :k', ['k' => $key]);

        if ($exists !== null && $exists !== false) {
            unset($payload['integration_key']);
            Database::update('integrations', $payload, ['integration_key' => $key]);

            return;
        }

        Database::insert('integrations', $payload + ['created_at' => date('Y-m-d H:i:s')]);
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    /**
     * The callback URL this platform exposes for an integration.
     *
     * A URL is only offered when something is listening on it. This used to
     * synthesise `/api/v1/webhooks/{key}` for anything with webhook=true, but
     * routes/api.php registers a receiver for cashfree alone — so nine of the
     * ten URLs the hub told operators to paste into a provider dashboard
     * answered 404, and every delivery from those providers would have been
     * dropped with nothing recorded anywhere.
     */
    public static function webhookUrl(string $key, array $definition): ?string
    {
        if (($definition['webhook'] ?? false) !== true || ($definition['webhook_receiver'] ?? false) !== true) {
            return null;
        }

        $base = rtrim((string) Config::get('app.url', ''), '/');

        if ($base === '') {
            return null;
        }

        return $base . '/api/v1/webhooks/' . $key;
    }

    // -----------------------------------------------------------------
    // Connection test
    // -----------------------------------------------------------------

    /**
     * Run the declared connection test.
     *
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    public static function test(string $key, ?int $adminId = null): array
    {
        $definition = Integration::find($key);

        if ($definition === null) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0, 'message' => 'Unknown integration.'];
        }

        $credentials = self::credentials($key);
        $row         = Database::selectOne('SELECT `config` FROM `integrations` WHERE `integration_key` = :k', ['k' => $key]);
        $config      = self::decodeJson($row['config'] ?? null);
        $values      = $credentials + $config;

        $started = microtime(true);
        $spec    = $definition['test'];

        $result = match ($spec['type']) {
            'http'     => self::testHttp($spec, $values),
            'internal' => self::testInternal((string) $spec['handler'], $values, $key),
            default    => self::testConfig($definition, $credentials, $config),
        };

        $result['duration_ms'] = round((microtime(true) - $started) * 1000, 1);

        self::upsert($key, [
            'integration_key' => $key,
            'last_test_at'    => date('Y-m-d H:i:s'),
            'last_test_ok'    => $result['ok'] ? 1 : 0,
            'last_error'      => $result['ok'] ? null : mb_substr($result['message'], 0, 1000),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        self::log($key, 'test', $result['ok'] ? 'success' : 'failure', $result['message'],
            ['http_status' => $result['status']], $adminId, $result['status'], (int) $result['duration_ms']);

        self::recordUsage($key, 1, $result['ok'] ? 0 : 1, (int) $result['duration_ms']);

        return $result;
    }

    /** @return array{ok:bool,status:int,duration_ms:float,message:string} */
    private static function testConfig(array $definition, array $credentials, array $config): array
    {
        $missing = [];

        // Same list isConfigured() uses, so the status pill above this message
        // and the message itself can never contradict each other.
        foreach (self::requiredFields($definition) as $field) {
            $name  = $field['name'];
            $value = ($field['secret'] ?? false) ? ($credentials[$name] ?? '') : ($config[$name] ?? '');

            if (trim((string) $value) === '') {
                $missing[] = $field['label'];
            }
        }

        if ($definition['fields'] === []) {
            return ['ok' => true, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'Nothing to configure for this integration.'];
        }

        if ($missing !== []) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'Not configured yet — still missing: ' . implode(', ', array_slice($missing, 0, 6)) . '.'];
        }

        return ['ok' => true, 'status' => 0, 'duration_ms' => 0.0,
                'message' => 'All required fields are present. This service has no cheap probe, so send a real test message to confirm the credentials.'];
    }

    /** @return array{ok:bool,status:int,duration_ms:float,message:string} */
    private static function testInternal(string $handler, array $values, string $key = ''): array
    {
        $ok = static fn (string $m): array => ['ok' => true, 'status' => 0, 'duration_ms' => 0.0, 'message' => $m];
        $no = static fn (string $m): array => ['ok' => false, 'status' => 0, 'duration_ms' => 0.0, 'message' => $m];

        return match ($handler) {
            'smtp', 'smtp_gmail' => self::testSmtp($values, $handler === 'smtp_gmail'),

            'firebase' => self::testFirebase($values, $key),

            'image_support' => (extension_loaded('gd') || extension_loaded('imagick'))
                ? $ok('Image support is available (' . (extension_loaded('imagick') ? 'imagick' : 'gd') . ').')
                : $no('Neither the gd nor the imagick extension is loaded, so images cannot be resized.'),

            'google_login'   => self::testGoogleLogin($values),
            'apple_login'    => self::testAppleLogin($values),
            'email_password' => self::testEmailPasswordMethod(),
            'mobile_otp'     => self::testMobileOtpMethod(),
            'guest_login'    => self::testGuestMethod(),

            default => $no('No handler is registered for "' . $handler . '".'),
        };
    }

    /**
     * Prove a Firebase service account, rather than prove that a string is JSON.
     *
     * The previous check accepted anything json_decode() did not return null
     * for — `12345` and `"hello"` both passed — and then claimed the expected
     * fields were present. Worse, the value it green-ticked is mirrored into
     * settings.firebase.service_account_json, which FirebaseService signs push
     * tokens with, so the tick was attached to a credential the platform really
     * uses.
     *
     * What happens now: the JSON must decode to a service-account object with
     * every field the OAuth2 exchange needs, the private key must actually
     * load, and Google must mint a real access token from it. Minting a token
     * has no side effect and is the only thing that proves the key is still
     * active — a revoked or deleted key parses exactly like a live one.
     *
     * @param  array<string,mixed> $values
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testFirebase(array $values, string $key): array
    {
        $no = static fn (string $m, int $s = 0): array => ['ok' => false, 'status' => $s, 'duration_ms' => 0.0, 'message' => $m];

        $json   = trim((string) ($values['service_account_json'] ?? ''));
        $shared = false;

        // Firebase Storage declares only a bucket: it shares the service
        // account configured under Firebase Cloud Messaging, which is mirrored
        // into settings.firebase. Without this fallback its test could never
        // pass, because the screen has no field to paste the JSON into.
        if ($json === '') {
            $json   = trim(Config::settingString('firebase', 'service_account_json'));
            $shared = $json !== '';
        }

        if ($json === '') {
            return $no('No service account JSON is stored. Paste it into the Firebase Cloud Messaging integration — '
                     . 'every Firebase service on this platform shares that one credential.');
        }

        $account = json_decode($json, true);

        if (!is_array($account) || array_is_list($account)) {
            return $no('The service account JSON is not a JSON object. Paste the whole file Firebase gave you, '
                     . 'starting with { and ending with }.');
        }

        $missing = [];

        foreach (['type', 'project_id', 'private_key', 'client_email', 'token_uri'] as $required) {
            if (trim((string) ($account[$required] ?? '')) === '') {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            return $no('That JSON parses, but it is not a service account key — it is missing '
                     . implode(', ', $missing) . '.');
        }

        if ((string) $account['type'] !== 'service_account') {
            return $no('That JSON has "type": "' . (string) $account['type'] . '". A Firebase service account key '
                     . 'has "type": "service_account" — you may have pasted the web app config instead.');
        }

        if (openssl_pkey_get_private((string) $account['private_key']) === false) {
            return $no('The private_key in that JSON could not be loaded. It must be the whole PEM block including '
                     . 'the BEGIN and END lines, with its newlines intact.');
        }

        $declaredProject = trim((string) ($values['project_id'] ?? ''));

        if ($declaredProject !== '' && $declaredProject !== (string) $account['project_id']) {
            return $no('The service account belongs to project "' . (string) $account['project_id']
                     . '", but this integration is set to project "' . $declaredProject . '".');
        }

        // Firebase Storage's own field, checked here because its test is this
        // handler rather than the generic field-presence one.
        if (self::declaresField($key, 'bucket')) {
            $bucket = trim((string) ($values['bucket'] ?? ''));

            if ($bucket === '') {
                return $no('Add the storage bucket first — it is usually '
                         . (string) $account['project_id'] . '.appspot.com or '
                         . (string) $account['project_id'] . '.firebasestorage.app.');
            }

            if (preg_match('/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $bucket) !== 1) {
                return $no('"' . $bucket . '" is not a valid bucket name: lower case letters, digits, dots, '
                         . 'hyphens and underscores only.');
            }
        }

        $assertion = self::googleJwtAssertion(
            (string) $account['client_email'],
            (string) $account['private_key'],
            (string) $account['token_uri'],
            'https://www.googleapis.com/auth/cloud-platform'
        );

        if ($assertion === null) {
            return $no('The private key loaded but could not sign a token request. Google issues an RSA key; '
                     . 'check the JSON has not been re-encoded or truncated.');
        }

        [$status, $body, $transportError] = self::http(
            'POST',
            (string) $account['token_uri'],
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]),
            null
        );

        if ($transportError !== '') {
            return $no('The key is well-formed, but this server could not reach Google (' . $transportError
                     . '). Push and storage calls will fail until outbound HTTPS works.');
        }

        $decoded = is_array($answer = json_decode($body, true)) ? $answer : [];

        if ($status === 200 && trim((string) ($decoded['access_token'] ?? '')) !== '') {
            return ['ok' => true, 'status' => 200, 'duration_ms' => 0.0,
                    'message' => 'Google issued an access token for ' . (string) $account['client_email']
                               . ' on project "' . (string) $account['project_id'] . '" — the key is valid and active.'
                               . ($shared ? ' (Using the service account from Firebase Cloud Messaging.)' : '')];
        }

        $reason = trim((string) ($decoded['error_description'] ?? $decoded['error'] ?? ''));

        return $no('Google refused to issue a token for this service account'
                 . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : '')
                 . '. The key has most likely been deleted or disabled in the Google Cloud console.', $status);
    }

    /** Does an integration declare a field with this name? */
    private static function declaresField(string $key, string $name): bool
    {
        foreach ((Integration::find($key)['fields'] ?? []) as $field) {
            if ($field['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The RS256 JWT a Google service account exchanges for an access token.
     *
     * Simpler than Apple's ES256 equivalent above: RSA signatures need no DER
     * unpacking, so openssl_sign's output goes straight into the JWT.
     */
    private static function googleJwtAssertion(string $clientEmail, string $privateKey, string $tokenUri, string $scope): ?string
    {
        $pkey = openssl_pkey_get_private($privateKey);

        if ($pkey === false) {
            return null;
        }

        $now = time();

        $encode = static fn (array $part): string =>
            rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        $signingInput = $encode(['alg' => 'RS256', 'typ' => 'JWT'])
            . '.' . $encode([
                'iss'   => $clientEmail,
                'scope' => $scope,
                'aud'   => $tokenUri,
                'iat'   => $now,
                'exp'   => $now + 300,
            ]);

        $signature = '';

        if (openssl_sign($signingInput, $signature, $pkey, OPENSSL_ALGO_SHA256) !== true) {
            return null;
        }

        return $signingInput . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * Google publishes no endpoint that will confirm a client ID without a real
     * user sign-in, so this proves what it honestly can: the ID is well-formed,
     * Google's discovery document is reachable from this server, and the
     * Firebase project the token will be verified against is configured.
     *
     * Saying more than that would be a green tick that means nothing.
     *
     * @param  array<string,mixed> $values
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testGoogleLogin(array $values): array
    {
        $webId = trim((string) ($values['web_client_id'] ?? ''));

        if ($webId === '') {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'Add the web client ID first — Android also needs it as the serverClientId.'];
        }

        if (!str_ends_with($webId, '.apps.googleusercontent.com')) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'That does not look like a Google client ID; they end in .apps.googleusercontent.com.'];
        }

        [$status, , $error] = self::http('GET', 'https://accounts.google.com/.well-known/openid-configuration', [], null, null);

        if ($status !== 200) {
            return ['ok' => false, 'status' => $status, 'duration_ms' => 0.0,
                    'message' => 'The client ID is well-formed, but this server could not reach Google '
                               . '(' . ($error !== '' ? $error : 'HTTP ' . $status) . '). '
                               . 'Sign-in verification will fail until outbound HTTPS works.'];
        }

        $project = Config::settingString('firebase', 'project_id');

        if ($project === '') {
            return ['ok' => false, 'status' => 200, 'duration_ms' => 0.0,
                    'message' => 'The client ID is well-formed and Google is reachable, but no Firebase project ID '
                               . 'is set under Settings → Firebase — the server cannot verify the tokens the app will send.'];
        }

        return ['ok' => true, 'status' => 200, 'duration_ms' => 0.0,
                'message' => 'The client ID is well-formed, Google\'s discovery endpoint is reachable and Firebase '
                           . 'project "' . $project . '" is configured. Google cannot confirm a client ID without a real sign-in.'];
    }

    /**
     * Apple, unlike Google, can be proven. Signing a client-secret JWT with the
     * stored .p8 and presenting it with a deliberately invalid refresh token
     * makes Apple answer `invalid_grant` when the credentials are right and
     * `invalid_client` when they are not — with no side effect either way.
     *
     * @param  array<string,mixed> $values
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testAppleLogin(array $values): array
    {
        $serviceId  = trim((string) ($values['service_id'] ?? ''));
        $teamId     = trim((string) ($values['team_id'] ?? ''));
        $keyId      = trim((string) ($values['key_id'] ?? ''));
        $privateKey = trim((string) ($values['private_key'] ?? ''));

        foreach (['Service ID' => $serviceId, 'Team ID' => $teamId, 'Key ID' => $keyId, 'AuthKey .p8' => $privateKey] as $label => $value) {
            if ($value === '') {
                return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                        'message' => 'Add the ' . $label . ' before testing.'];
            }
        }

        $secret = self::appleClientSecret($serviceId, $teamId, $keyId, $privateKey);

        if ($secret === null) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'The AuthKey could not be used to sign. Apple issues an EC P-256 key — check you pasted '
                               . 'the whole .p8 file including the BEGIN and END lines.'];
        }

        [$status, $body, $transportError] = self::http(
            'POST',
            'https://appleid.apple.com/auth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'client_id'     => $serviceId,
                'client_secret' => $secret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => 'zuste-connection-probe',
            ]),
            null
        );

        $decoded = json_decode($body, true);
        $error   = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';

        // `invalid_grant` does NOT discriminate.
        //
        // The probe was written on the assumption that Apple validates the
        // client secret before the refresh token, so invalid_grant would mean
        // "secret good, token bad". It does not: Apple returns invalid_grant
        // for a bad refresh token regardless of the credentials, so entirely
        // fabricated Service/Team/Key IDs produced a green tick.
        //
        // What can honestly be concluded:
        //   invalid_client — the credentials are definitely wrong.
        //   invalid_grant  — the .p8 signs, Apple is reachable, and nothing is
        //                    provably wrong. That is NOT proof of a match, so
        //                    it is not reported as one.
        return match ($error) {
            'invalid_client' => ['ok' => false, 'status' => $status, 'duration_ms' => 0.0,
                'message' => 'Apple rejected these credentials. One of the Service ID, Team ID, Key ID or .p8 '
                           . 'does not belong with the others.'],

            'invalid_grant' => ['ok' => true, 'status' => $status, 'duration_ms' => 0.0,
                'message' => 'The .p8 signs correctly and Apple accepted the request format — nothing is provably '
                           . 'wrong. Apple does not confirm a Service ID, Team ID and Key ID without a real '
                           . 'sign-in, so this is not proof that the three match.'],

            default => ['ok' => false, 'status' => $status, 'duration_ms' => 0.0,
                'message' => 'Apple answered unexpectedly'
                           . ($error !== '' ? ' ("' . $error . '")' : '')
                           . ($transportError !== '' ? ': ' . $transportError : '') . '.'],
        };
    }

    /**
     * Build the ES256 client-secret JWT Apple requires.
     *
     * openssl_sign returns a DER-encoded ECDSA signature; JOSE wants the raw
     * 64-byte R‖S concatenation, so the DER has to be unpacked by hand. That is
     * the only fiddly part, and it is why this is written out rather than
     * pulled from a library.
     */
    private static function appleClientSecret(string $serviceId, string $teamId, string $keyId, string $privateKey): ?string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return null;
        }

        $now = time();

        $encode = static fn (array $part): string =>
            rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        $signingInput = $encode(['alg' => 'ES256', 'kid' => $keyId, 'typ' => 'JWT'])
            . '.' . $encode([
                'iss' => $teamId,
                'iat' => $now,
                'exp' => $now + 300,
                'aud' => 'https://appleid.apple.com',
                'sub' => $serviceId,
            ]);

        $der = '';

        if (openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256) !== true) {
            return null;
        }

        $raw = self::derToJoseSignature($der);

        if ($raw === null) {
            return null;
        }

        return $signingInput . '.' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Convert a DER `SEQUENCE { INTEGER r, INTEGER s }` into the 64-byte
     * fixed-width R‖S form JOSE specifies for ES256.
     */
    private static function derToJoseSignature(string $der): ?string
    {
        $offset = 0;

        $readByte = static function () use ($der, &$offset): ?int {
            if (!isset($der[$offset])) {
                return null;
            }

            return ord($der[$offset++]);
        };

        if ($readByte() !== 0x30) {           // SEQUENCE
            return null;
        }

        $sequenceLength = $readByte();

        if ($sequenceLength === null) {
            return null;
        }

        if ($sequenceLength > 0x80) {          // long form: next N bytes hold the length
            $offset += $sequenceLength - 0x80;
        }

        $readInteger = static function () use ($der, &$offset, $readByte): ?string {
            if ($readByte() !== 0x02) {       // INTEGER
                return null;
            }

            $length = $readByte();

            if ($length === null || $length < 1 || !isset($der[$offset + $length - 1])) {
                return null;
            }

            $value  = substr($der, $offset, $length);
            $offset += $length;

            // DER prefixes a 0x00 when the high bit would read as negative.
            $value = ltrim($value, "\x00");

            return str_pad($value, 32, "\x00", STR_PAD_LEFT);
        };

        $r = $readInteger();
        $s = $readInteger();

        return ($r === null || $s === null) ? null : $r . $s;
    }

    /**
     * Email/password sign-in has no external dependency to probe. What can go
     * wrong is the surrounding configuration, so that is what gets checked.
     *
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testEmailPasswordMethod(): array
    {
        $minLength = Config::settingInt('security', 'password_min_length', 8);
        $notes     = [];

        if ($minLength < 8) {
            $notes[] = 'the minimum password length is ' . $minLength . ', below the recommended 8';
        }

        if (!Config::settingBool('auth', 'forgot_password_enabled', true)) {
            $notes[] = 'password recovery is switched off, so a locked-out user cannot self-serve';
        }

        if (Config::settingString('mail', 'host') === '' && Config::settingString('mail', 'driver', 'smtp') === 'smtp') {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'No SMTP host is configured under Settings → Email, so password-reset messages cannot be delivered.'];
        }

        return ['ok' => true, 'status' => 0, 'duration_ms' => 0.0,
                'message' => 'Email and password sign-in is configured'
                           . ($notes === [] ? '.' : ' — note that ' . implode(', and ', $notes) . '.')];
    }

    /**
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testMobileOtpMethod(): array
    {
        if (!Config::settingBool('sms', 'enabled', false)) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'SMS delivery is switched off under Settings → SMS, so one-time codes are only written to the log.'];
        }

        $provider = Config::settingString('sms', 'provider');
        $apiKey   = Config::settingString('sms', 'api_key');

        if ($provider === '' || $apiKey === '') {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'Set the SMS provider and its API key under Settings → SMS before enabling OTP sign-in.'];
        }

        $length = Config::settingInt('security', 'otp_length', 6);
        $ttl    = Config::settingInt('security', 'otp_ttl', 300);

        return ['ok' => true, 'status' => 0, 'duration_ms' => 0.0,
                'message' => 'SMS is enabled through ' . $provider . ' with a ' . $length . '-digit code valid for '
                           . (int) round($ttl / 60) . ' minutes. Send a real code from Settings → SMS to prove delivery.'];
    }

    /**
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testGuestMethod(): array
    {
        $column = Database::selectOne("SHOW COLUMNS FROM `users` LIKE 'account_type'");
        $type   = (string) ($column['Type'] ?? '');

        if (!str_contains($type, "'guest'")) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'The users table does not accept the guest account type yet — migration 019 has not been applied.'];
        }

        $days = Config::settingInt('auth', 'guest_session_days', 30);

        if ($days < 1 || $days > 365) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'The guest session length (' . $days . ' days) is outside the permitted 1–365.'];
        }

        return ['ok' => true, 'status' => 0, 'duration_ms' => 0.0,
                'message' => 'Guest access is ready: sessions last ' . $days . ' days and upgrade in place without losing anything.'];
    }

    /**
     * Open a real SMTP session and stop before sending anything.
     *
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testSmtp(array $values, bool $gmail): array
    {
        $host = $gmail ? 'smtp.gmail.com' : trim((string) ($values['host'] ?? ''));
        $port = $gmail ? 587 : (int) ($values['port'] ?? 587);

        if ($host === '') {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0, 'message' => 'Set the SMTP host first.'];
        }

        $errno  = 0;
        $errstr = '';
        $prefix = ($port === 465) ? 'ssl://' : '';

        $socket = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10);

        if ($socket === false) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'Could not reach ' . $host . ':' . $port . ' — ' . ($errstr !== '' ? $errstr : 'connection refused') . '.'];
        }

        stream_set_timeout($socket, 10);
        $banner = (string) fgets($socket, 512);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        if (!str_starts_with(trim($banner), '220')) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'Connected, but ' . $host . ' did not greet with 220: ' . trim(mb_substr($banner, 0, 120))];
        }

        return ['ok' => true, 'status' => 0, 'duration_ms' => 0.0,
                'message' => 'Connected to ' . $host . ':' . $port . ' — ' . trim(mb_substr($banner, 0, 90))
                           . '. Send a test email to confirm the credentials.'];
    }

    /**
     * @param  array<string,mixed> $spec
     * @param  array<string,mixed> $values
     * @return array{ok:bool,status:int,duration_ms:float,message:string}
     */
    private static function testHttp(array $spec, array $values): array
    {
        $url = self::interpolate((string) $spec['url'], $values);

        if (isset($spec['base_map'])) {
            $env  = (string) ($values['environment'] ?? 'sandbox');
            $base = (string) ($spec['base_map'][$env] ?? reset($spec['base_map']));
            $url  = str_replace('{base}', $base, $url);
        }

        if (str_contains($url, '{')) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0,
                    'message' => 'Some credentials are still missing, so the request could not be built.'];
        }

        if (preg_match('#^https://#i', $url) !== 1) {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0, 'message' => 'The test endpoint must be https.'];
        }

        $headers = ['Accept: application/json'];

        foreach (($spec['headers'] ?? []) as $header) {
            $headers[] = self::interpolate((string) $header, $values);
        }

        $auth = (string) ($spec['auth'] ?? '');

        if ($auth === 'bearer') {
            $token = (string) ($values[(string) ($spec['token_field'] ?? 'api_key')] ?? '');

            if ($token === '') {
                return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0, 'message' => 'No token stored for this integration yet.'];
            }

            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $basic = null;

        if ($auth === 'basic') {
            $basic = (string) ($values[(string) $spec['user_field']] ?? '') . ':' . (string) ($values[(string) $spec['pass_field']] ?? '');
        }

        $body   = null;
        $method = strtoupper((string) ($spec['method'] ?? 'GET'));

        if (isset($spec['form'])) {
            $form = [];

            foreach ($spec['form'] as $name => $template) {
                $form[$name] = self::interpolate((string) $template, $values);
            }

            $body      = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        [$status, $raw, $error] = self::http($method, $url, $headers, $body, $basic);

        if ($error !== '') {
            return ['ok' => false, 'status' => 0, 'duration_ms' => 0.0, 'message' => $error];
        }

        $accepted = $spec['expect_any'] ?? [(int) ($spec['expect'] ?? 200)];
        $ok       = in_array($status, $accepted, true);

        // Some providers answer 200 with a failure in the body. Reporting that
        // as "refused (HTTP 200)" reads as a contradiction, so the body's own
        // status and message are surfaced instead.
        if ($ok && isset($spec['json_ok'])) {
            $decoded = json_decode($raw, true);

            foreach ($spec['json_ok'] as $field => $expected) {
                if (!is_array($decoded) || ($decoded[$field] ?? null) !== $expected) {
                    $bodyStatus = is_array($decoded) ? (string) ($decoded[$field] ?? 'unknown') : 'unparseable';
                    $bodyError  = is_array($decoded)
                        ? (string) ($decoded['error_message'] ?? $decoded['error']['message'] ?? $decoded['message'] ?? '')
                        : '';

                    return [
                        'ok' => false, 'status' => $status, 'duration_ms' => 0.0,
                        'message' => 'The request reached the provider, but it answered "' . $bodyStatus . '"'
                                   . ($bodyError !== '' ? ' — ' . mb_substr($bodyError, 0, 200) : '')
                                   . '. The credentials are most likely wrong or the API is not enabled for this key.',
                    ];
                }
            }
        }

        // Not every provider signals a refusal with a status code.
        //
        // MSG91 answers HTTP 200 with {"message":"Invalid authkey"}, and
        // reCAPTCHA answers HTTP 200 with an error-codes array. Judged on the
        // status alone, both handed a green tick to any credential at all — and
        // for MSG91 that tick was attached to the key the platform really sends
        // OTPs with. `reject_body` declares the phrases that mean the provider
        // said no in the body, which `json_ok` cannot express because it needs a
        // field that is present and correct on success.
        if ($ok && isset($spec['reject_body']) && self::bodyRejects((array) $spec['reject_body'], $raw)) {
            $snippet = trim(preg_replace('/\s+/', ' ', mb_substr($raw, 0, 200)) ?? '');

            return ['ok' => false, 'status' => $status, 'duration_ms' => 0.0,
                    'message' => (string) ($spec['reject_message'] ?? 'The provider refused the credentials.')
                               . ' It answered HTTP ' . $status . ' with: ' . $snippet];
        }

        if ($ok) {
            // `success_message` exists so an integration whose probe proves less
            // than "the credentials are good" can say what it actually proved,
            // instead of the generic sentence claiming more than it knows.
            $message = (string) ($spec['success_message'] ?? 'The provider accepted the credentials (HTTP ' . $status . ').');

            return ['ok' => true, 'status' => $status, 'duration_ms' => 0.0,
                    'message' => $message . (isset($spec['note']) ? ' ' . $spec['note'] : '')];
        }

        $detail  = '';
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $detail = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? '');
        }

        $reason = match (true) {
            $status === 401, $status === 403 => 'The provider rejected the credentials.',
            $status === 404                  => 'The endpoint was not found — check the ids and base URL.',
            $status === 429                  => 'The provider is rate limiting this key.',
            $status >= 500                   => 'The provider returned a server error.',
            default                          => 'The provider refused the request.',
        };

        return ['ok' => false, 'status' => $status, 'duration_ms' => 0.0,
                'message' => trim($reason . ($detail !== '' ? ' ' . mb_substr($detail, 0, 200) : '') . ' (HTTP ' . $status . ')')];
    }

    /**
     * Does a 2xx body contain one of the declared refusal phrases?
     *
     * Matching on the raw body rather than a decoded field on purpose: the
     * providers that need this put the refusal in different shapes (an
     * error-codes array, a message string), and a phrase check covers both
     * without a per-provider decoder.
     *
     * @param list<string> $phrases
     */
    private static function bodyRejects(array $phrases, string $raw): bool
    {
        $haystack = mb_strtolower($raw);

        foreach ($phrases as $phrase) {
            $needle = mb_strtolower(trim((string) $phrase));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Substitute {field} placeholders from stored values. */
    private static function interpolate(string $template, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            static function (array $m) use ($values): string {
                $value = $values[$m[1]] ?? null;

                return ($value === null || trim((string) $value) === '') ? $m[0] : rawurlencode((string) $value);
            },
            $template
        );
    }

    /**
     * Minimal HTTP client. Redirects are never followed — a provider that
     * 302s elsewhere would otherwise receive the credentials at the target.
     *
     * @param  list<string> $headers
     * @return array{0:int,1:string,2:string}
     */
    private static function http(string $method, string $url, array $headers, ?string $body, ?string $basic): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);

            if ($ch === false) {
                return [0, '', 'Could not initialise the HTTP client.'];
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            if ($basic !== null) {
                curl_setopt($ch, CURLOPT_USERPWD, $basic);
            }

            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                return [0, '', $err !== '' ? $err : 'The request failed before a response arrived.'];
            }

            return [$status, (string) $raw, ''];
        }

        if ($basic !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($basic);
        }

        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $body ?? '',
                'timeout'         => 20,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            return [0, '', 'The request failed before a response arrived.'];
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $raw, ''];
    }

    // -----------------------------------------------------------------
    // Sync
    // -----------------------------------------------------------------

    /**
     * Run a sync for one integration.
     *
     * Only integrations that declare `sync` can be synced. The work itself is
     * a real connection check plus a recorded outcome — a sync that claimed to
     * pull data it cannot reach would be worse than none.
     *
     * @return array{ok:bool,message:string}
     */
    public static function sync(string $key, ?int $adminId = null): array
    {
        $definition = Integration::find($key);

        if ($definition === null || ($definition['sync'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'This integration does not support sync.'];
        }

        self::upsert($key, [
            'integration_key'  => $key,
            'last_sync_status' => 'running',
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $started = microtime(true);
        $probe   = self::test($key, $adminId);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        $interval = (int) (Database::scalar(
            'SELECT `sync_interval_min` FROM `integrations` WHERE `integration_key` = :k',
            ['k' => $key]
        ) ?: 60);

        // A 'config' probe makes no network call — it only looks at which
        // fields are filled in. Reporting "Reached Amazon S3" for it was a
        // claim about traffic that never happened, and it read as success with
        // entirely fabricated credentials. The summary now says what the probe
        // type actually did.
        $reached = ($definition['test']['type'] ?? 'config') !== 'config';

        $summary = match (true) {
            $probe['ok'] && $reached => 'Reached ' . $definition['name'] . ' and refreshed its status.',
            $probe['ok']             => 'Checked the ' . $definition['name'] . ' configuration; every required field is '
                                      . 'present. Nothing was contacted and no data was transferred.',
            $reached                 => 'Could not reach ' . $definition['name'] . ': ' . $probe['message'],
            default                  => $definition['name'] . ' is not ready to sync: ' . $probe['message'],
        };

        self::upsert($key, [
            'integration_key'   => $key,
            'last_sync_at'      => date('Y-m-d H:i:s'),
            'last_sync_status'  => $probe['ok'] ? 'success' : 'failed',
            'last_sync_summary' => mb_substr($summary, 0, 500),
            'next_sync_at'      => date('Y-m-d H:i:s', time() + $interval * 60),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        self::log($key, 'sync', $probe['ok'] ? 'success' : 'failure', $summary, [], $adminId, $probe['status'], $elapsed);

        return ['ok' => $probe['ok'], 'message' => $summary];
    }

    /**
     * Integrations whose auto-sync is due. Called by the cron runner.
     *
     * @return list<string>
     */
    public static function due(): array
    {
        $rows = Database::select(
            'SELECT `integration_key` FROM `integrations`
              WHERE `auto_sync` = 1 AND `is_enabled` = 1
                AND (`next_sync_at` IS NULL OR `next_sync_at` <= NOW())'
        );

        return array_column($rows, 'integration_key');
    }

    // -----------------------------------------------------------------
    // Logging and usage
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $context */
    public static function log(
        string $key,
        string $event,
        string $status,
        string $message,
        array $context = [],
        ?int $adminId = null,
        ?int $httpStatus = null,
        ?int $durationMs = null
    ): void {
        try {
            Database::insert('integration_logs', [
                'integration_key' => $key,
                'event'           => $event,
                'status'          => $status,
                'message'         => mb_substr($message, 0, 500),
                'context'         => $context === [] ? null : (string) json_encode(self::redact($context)),
                'http_status'     => $httpStatus !== null && $httpStatus > 0 ? $httpStatus : null,
                'duration_ms'     => $durationMs,
                // `admin_id` is a foreign key to admins(id), and no admin has
                // id 0. A caller with no signed-in admin — the cron runner —
                // passes 0, which made every insert violate the constraint and
                // lose the row: the audit trail was silently empty for every
                // automated sync. The column is nullable and the view already
                // renders a null author as "system", so anything that is not a
                // real admin id becomes null here.
                'admin_id'        => ($adminId !== null && $adminId > 0) ? $adminId : null,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Could not write an integration log', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Replace anything that looks like a secret before it reaches the log.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function redact(array $context): array
    {
        $clean = [];

        foreach ($context as $name => $value) {
            $lower  = strtolower((string) $name);
            $secret = false;

            foreach (self::REDACT as $needle) {
                if (str_contains($lower, $needle)) {
                    $secret = true;
                    break;
                }
            }

            if ($secret) {
                $clean[$name] = '[REDACTED]';
                continue;
            }

            $clean[$name] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }

    /** @return list<array<string,mixed>> */
    public static function logs(string $key, int $limit = 50): array
    {
        return Database::select(
            'SELECT l.*, a.`name` AS `admin_name`
               FROM `integration_logs` l
               LEFT JOIN `admins` a ON a.`id` = l.`admin_id`
              WHERE l.`integration_key` = :k
              ORDER BY l.`created_at` DESC
              LIMIT ' . max(1, min(500, $limit)),
            ['k' => $key]
        );
    }

    private static function recordUsage(string $key, int $calls, int $failures, int $durationMs): void
    {
        try {
            Database::execute(
                'INSERT INTO `integration_usage_daily`
                    (`integration_key`, `stat_date`, `calls`, `failures`, `avg_duration_ms`)
                 VALUES (:k, CURDATE(), :calls, :failures, :duration)
                 ON DUPLICATE KEY UPDATE
                    `avg_duration_ms` = ((`avg_duration_ms` * `calls`) + :duration) / (`calls` + :calls),
                    `calls`           = `calls` + :calls,
                    `failures`        = `failures` + :failures',
                ['k' => $key, 'calls' => $calls, 'failures' => $failures, 'duration' => $durationMs]
            );
        } catch (\Throwable $e) {
            Logger::error('Could not record integration usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Daily usage for the last N days, gap filled so a chart has no holes.
     *
     * @return list<array{date:string,calls:int,failures:int}>
     */
    public static function usage(string $key, int $days = 30): array
    {
        $rows = [];

        foreach (Database::select(
            'SELECT `stat_date`, `calls`, `failures` FROM `integration_usage_daily`
              WHERE `integration_key` = :k AND `stat_date` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)',
            ['k' => $key, 'days' => $days]
        ) as $row) {
            $rows[$row['stat_date']] = $row;
        }

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime("-{$i} days"));
            $series[] = [
                'date'     => $date,
                'calls'    => (int) ($rows[$date]['calls'] ?? 0),
                'failures' => (int) ($rows[$date]['failures'] ?? 0),
            ];
        }

        return $series;
    }

    /** Counts for the hub header. @return array<string,int> */
    public static function summary(): array
    {
        $counts = ['total' => 0, 'connected' => 0, 'error' => 0, 'disabled' => 0, 'not_configured' => 0, 'delegated' => 0];

        foreach (self::overview() as $entry) {
            $counts['total']++;
            $counts[$entry['status']] = ($counts[$entry['status']] ?? 0) + 1;
        }

        return $counts;
    }
}
