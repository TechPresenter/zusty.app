<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Helpers\Http;

/**
 * Firebase integration — ID token verification and FCM HTTP v1 delivery.
 *
 * ID token verification is done locally against Google's published public
 * keys (RS256). We do NOT call an "verify token" endpoint per request; that
 * would add ~200ms of latency to every authenticated call and create a hard
 * dependency on Google being reachable during sign-in.
 */
final class FirebaseService
{
    private const PUBLIC_KEYS_URL =
        'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Firebase Authentication admin operations (deleting a user). */
    private const AUTH_SCOPE = 'https://www.googleapis.com/auth/identitytoolkit https://www.googleapis.com/auth/cloud-platform';

    /** @var array<string,string>|null */
    private static ?array $publicKeys = null;

    /**
     * Access tokens by OAuth scope. FCM and Auth need different grants.
     *
     * @var array<string,array{token:string,expires_at:int}>
     */
    private static array $accessTokens = [];

    // -----------------------------------------------------------------
    // ID token verification
    // -----------------------------------------------------------------

    /**
     * Verify a Firebase ID token and return its claims.
     *
     * @return array{uid:string,email:?string,email_verified:bool,phone:?string,name:?string,picture:?string,provider:string}
     * @throws HttpException 401 when the token is not valid for this project.
     */
    public static function verifyIdToken(string $idToken): array
    {
        $projectId = self::projectId();

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw HttpException::unauthorized('Malformed Firebase token.', 'invalid_firebase_token');
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode(self::base64UrlDecode($header64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw HttpException::unauthorized('Unsupported Firebase token header.', 'invalid_firebase_token');
        }

        $certificate = self::publicKeys()[$header['kid']] ?? null;
        if ($certificate === null) {
            // Key rotation: refresh once before giving up.
            self::$publicKeys = null;
            $certificate      = self::publicKeys()[$header['kid']] ?? null;
        }

        if ($certificate === null) {
            throw HttpException::unauthorized('Unknown Firebase signing key.', 'invalid_firebase_token');
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw HttpException::server('Unable to read the Firebase signing certificate.');
        }

        $verified = openssl_verify(
            $header64 . '.' . $payload64,
            self::base64UrlDecode($signature64),
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            throw HttpException::unauthorized('Invalid Firebase token signature.', 'invalid_firebase_token');
        }

        $claims = json_decode(self::base64UrlDecode($payload64), true);
        if (!is_array($claims)) {
            throw HttpException::unauthorized('Malformed Firebase token payload.', 'invalid_firebase_token');
        }

        $now = time();

        if ((int) ($claims['exp'] ?? 0) < $now - 30) {
            throw HttpException::unauthorized('Firebase token has expired.', 'firebase_token_expired');
        }

        if ((int) ($claims['iat'] ?? 0) > $now + 30) {
            throw HttpException::unauthorized('Firebase token issued in the future.', 'invalid_firebase_token');
        }

        if (($claims['aud'] ?? '') !== $projectId) {
            throw HttpException::unauthorized('Firebase token audience mismatch.', 'invalid_firebase_token');
        }

        if (($claims['iss'] ?? '') !== 'https://securetoken.google.com/' . $projectId) {
            throw HttpException::unauthorized('Firebase token issuer mismatch.', 'invalid_firebase_token');
        }

        $uid = (string) ($claims['sub'] ?? '');
        if ($uid === '') {
            throw HttpException::unauthorized('Firebase token has no subject.', 'invalid_firebase_token');
        }

        $firebaseClaims = $claims['firebase'] ?? [];
        $provider       = is_array($firebaseClaims) ? (string) ($firebaseClaims['sign_in_provider'] ?? 'custom') : 'custom';

        return [
            'uid'            => $uid,
            'email'          => isset($claims['email']) ? (string) $claims['email'] : null,
            'email_verified' => (bool) ($claims['email_verified'] ?? false),
            'phone'          => isset($claims['phone_number']) ? (string) $claims['phone_number'] : null,
            'name'           => isset($claims['name']) ? (string) $claims['name'] : null,
            'picture'        => isset($claims['picture']) ? (string) $claims['picture'] : null,
            'provider'       => $provider,
        ];
    }

    /**
     * Google's rotating x509 certificates, cached to disk and honouring the
     * Cache-Control max-age Google returns (typically ~6 hours).
     *
     * @return array<string,string>
     */
    private static function publicKeys(): array
    {
        if (self::$publicKeys !== null) {
            return self::$publicKeys;
        }

        $cacheFile = rtrim((string) Config::get('storage.temp_path'), '/\\') . '/firebase-public-keys.json';

        if (is_readable($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);

            if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) > time() && is_array($cached['keys'] ?? null)) {
                /** @var array<string,string> $keys */
                $keys = $cached['keys'];

                return self::$publicKeys = $keys;
            }
        }

        $response = Http::get(self::PUBLIC_KEYS_URL, [], 15);

        if (!Http::successful($response) || !is_array($response['json'])) {
            // Fall back to a stale cache rather than locking everyone out.
            if (isset($cached['keys']) && is_array($cached['keys'])) {
                Logger::warning('Using stale Firebase public keys — refresh failed.');

                /** @var array<string,string> $keys */
                $keys = $cached['keys'];

                return self::$publicKeys = $keys;
            }

            throw HttpException::server('Unable to fetch Firebase signing keys.');
        }

        /** @var array<string,string> $keys */
        $keys = $response['json'];

        $directory = dirname($cacheFile);
        if (is_dir($directory) || @mkdir($directory, 0750, true)) {
            @file_put_contents(
                $cacheFile,
                (string) json_encode(['keys' => $keys, 'expires_at' => time() + 3600]),
                LOCK_EX
            );
        }

        return self::$publicKeys = $keys;
    }

    // -----------------------------------------------------------------
    // Cloud Messaging (HTTP v1)
    // -----------------------------------------------------------------

    /**
     * Send a push notification to a single device token.
     *
     * @param  array<string,string> $data
     * @return array{ok:bool,error:string|null,invalid_token:bool}
     */
    public static function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = [],
        ?string $imageUrl = null
    ): array {
        $message = [
            'token'        => $token,
            'notification' => array_filter([
                'title' => $title,
                'body'  => $body,
                'image' => $imageUrl,
            ]),
            // Cast to object so an empty payload serialises as `{}` (a map) and
            // not `[]` (a list) — FCM HTTP v1 rejects a list for `data`.
            'data'    => (object) array_map('strval', $data),
            'android' => [
                'priority'     => 'high',
                'notification' => [
                    'channel_id'  => 'zuste_default',
                    'sound'       => 'default',
                    'color'       => '#B80049',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ],
            'apns' => [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => ['sound' => 'default', 'badge' => 1, 'content-available' => 1]],
            ],
        ];

        return self::dispatch($message);
    }

    /**
     * Send to a topic (used for broadcasts).
     *
     * @param array<string,string> $data
     */
    public static function sendToTopic(string $topic, string $title, string $body, array $data = [], ?string $imageUrl = null): array
    {
        return self::dispatch([
            'topic'        => preg_replace('/[^a-zA-Z0-9_.~%-]/', '', $topic) ?: 'all',
            'notification' => array_filter(['title' => $title, 'body' => $body, 'image' => $imageUrl]),
            'data'         => (object) array_map('strval', $data),
            'android'      => ['priority' => 'high'],
        ]);
    }

    /**
     * Fan out to every registered device of a user, pruning dead tokens.
     *
     * `error` carries the last provider failure so the caller can record why
     * nothing was delivered — without it a push job died with last_error NULL
     * and an operator could not tell a missing credential from a dead token.
     *
     * @param  array<string,string> $data
     * @return array{sent:int,failed:int,error:string|null,devices:int}
     */
    public static function sendToUser(int $userId, string $title, string $body, array $data = [], ?string $imageUrl = null): array
    {
        /** @var list<string> $tokens */
        $tokens = Database::column(
            'SELECT `fcm_token` FROM `user_devices`
             WHERE `user_id` = :user_id AND `push_enabled` = 1 AND `fcm_token` IS NOT NULL AND `fcm_token` <> ""',
            ['user_id' => $userId]
        );

        $sent   = 0;
        $failed = 0;
        $error  = null;

        foreach ($tokens as $token) {
            $result = self::sendToToken($token, $title, $body, $data, $imageUrl);

            if ($result['ok']) {
                $sent++;
                continue;
            }

            $failed++;
            $error = $result['error'] ?? $error;

            // Google tells us when a token is permanently dead — remove it so
            // we stop paying for calls that can never succeed.
            if ($result['invalid_token']) {
                Database::execute(
                    'DELETE FROM `user_devices` WHERE `fcm_token` = :token',
                    ['token' => $token]
                );
            }
        }

        return [
            'sent'    => $sent,
            'failed'  => $failed,
            'error'   => $error,
            'devices' => count($tokens),
        ];
    }

    /**
     * @param  array<string,mixed> $message
     * @return array{ok:bool,error:string|null,invalid_token:bool}
     */
    private static function dispatch(array $message): array
    {
        if (!Config::settingBool('firebase', 'fcm_enabled', true)) {
            return ['ok' => false, 'error' => 'FCM is disabled in settings.', 'invalid_token' => false];
        }

        try {
            $accessToken = self::accessToken();
        } catch (\Throwable $e) {
            Logger::error('Unable to mint an FCM access token', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage(), 'invalid_token' => false];
        }

        $response = Http::post(
            'https://fcm.googleapis.com/v1/projects/' . self::projectId() . '/messages:send',
            ['message' => $message],
            ['Authorization' => 'Bearer ' . $accessToken, 'Content-Type' => 'application/json'],
            20
        );

        if (Http::successful($response)) {
            return ['ok' => true, 'error' => null, 'invalid_token' => false];
        }

        $errorStatus = (string) ($response['json']['error']['status'] ?? '');
        $errorMessage = (string) ($response['json']['error']['message'] ?? ($response['error'] ?? 'Unknown FCM error'));

        return [
            'ok'            => false,
            'error'         => $errorMessage,
            'invalid_token' => in_array($errorStatus, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED'], true)
                               || $response['status'] === 404,
        ];
    }

    /**
     * Delete a user from Firebase Authentication.
     *
     * Used when an account is deleted. Google, Apple and phone sign-ins leave
     * a copy of the email, name, photo and phone in Firebase Auth, and
     * erasing the account means erasing that copy too. A user Firebase no
     * longer has counts as deleted.
     *
     * @return bool whether Firebase no longer has the user
     */
    public static function deleteAuthUser(string $uid): bool
    {
        $uid = trim($uid);

        if ($uid === '') {
            return true;
        }

        $response = Http::post(
            'https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode(self::projectId()) . '/accounts:delete',
            ['localId' => $uid],
            ['Authorization' => 'Bearer ' . self::accessToken(self::AUTH_SCOPE), 'Content-Type' => 'application/json'],
            20
        );

        if (Http::successful($response)) {
            return true;
        }

        $message = (string) ($response['json']['error']['message'] ?? ($response['error'] ?? 'Unknown error'));

        if (str_contains($message, 'USER_NOT_FOUND')) {
            return true;
        }

        Logger::warning('Firebase Auth user deletion failed', ['status' => $response['status'] ?? 0, 'error' => $message]);

        return false;
    }

    /**
     * OAuth2 access token for the service account, using a self-signed JWT
     * grant. Cached in-process per scope until shortly before it expires.
     */
    private static function accessToken(string $scope = self::FCM_SCOPE): string
    {
        $cached = self::$accessTokens[$scope] ?? null;

        if ($cached !== null && $cached['expires_at'] > time() + 60) {
            return $cached['token'];
        }

        $credentials = self::serviceAccount();

        $now    = time();
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64UrlEncode((string) json_encode([
            'iss'   => $credentials['client_email'],
            'scope' => $scope,
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if ($privateKey === false) {
            throw new \RuntimeException('The Firebase service account private key could not be read.');
        }

        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign the Firebase service account assertion.');
        }

        $assertion = $header . '.' . $claims . '.' . self::base64UrlEncode($signature);

        $response = Http::post(
            self::TOKEN_ENDPOINT,
            [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            20
        );

        if (!Http::successful($response) || empty($response['json']['access_token'])) {
            throw new \RuntimeException('Firebase rejected the service account assertion.');
        }

        self::$accessTokens[$scope] = [
            'token'      => (string) $response['json']['access_token'],
            'expires_at' => $now + (int) ($response['json']['expires_in'] ?? 3600),
        ];

        return self::$accessTokens[$scope]['token'];
    }

    /**
     * Load the service account, preferring the DB setting (editable from the
     * admin panel) and falling back to the file path in .env.
     *
     * @return array{client_email:string,private_key:string,project_id:string}
     */
    private static function serviceAccount(): array
    {
        $json = Config::settingString('firebase', 'service_account_json');

        if ($json === '') {
            $path = (string) Config::get('firebase.credentials', '');

            if ($path !== '') {
                $resolved = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1
                    ? $path
                    : rtrim((string) Config::get('app.base_path'), '/\\') . '/' . ltrim($path, '/');

                if (is_readable($resolved)) {
                    $json = (string) file_get_contents($resolved);
                }
            }
        }

        $credentials = json_decode($json, true);

        if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new \RuntimeException(
                'Firebase service account credentials are missing. Set them in Admin → Settings → Firebase.'
            );
        }

        return [
            'client_email' => (string) $credentials['client_email'],
            'private_key'  => (string) $credentials['private_key'],
            'project_id'   => (string) ($credentials['project_id'] ?? self::projectId()),
        ];
    }

    private static function projectId(): string
    {
        $projectId = Config::settingString('firebase', 'project_id');

        if ($projectId === '') {
            $projectId = (string) Config::get('firebase.project_id', '');
        }

        if ($projectId === '') {
            // 503, not 500, and no operator instructions.
            //
            // This is reached from POST /auth/firebase, which unauthenticated
            // app clients call. The old message handed them an admin-panel
            // navigation path — telling a stranger where to go to configure the
            // platform — and a 500 told the client the request was malformed
            // rather than that the provider is unavailable.
            //
            // The operator still gets the detail: it goes to the log, where it
            // belongs, not into an HTTP body.
            Logger::error('Firebase sign-in attempted while unconfigured', [
                'hint' => 'Set the project id under Admin → Settings → Firebase.',
            ]);

            throw new HttpException(
                'Sign-in with this provider is temporarily unavailable. Please try another method.',
                503,
                'provider_unavailable'
            );
        }

        return $projectId;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded  = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
