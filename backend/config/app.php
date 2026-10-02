<?php

declare(strict_types=1);

use Zuste\Core\Env;

/**
 * Infrastructure configuration resolved from the environment.
 *
 * Business rules (fees, limits, feature toggles) do NOT live here — they live
 * in the `settings` table so an operator can change them from the admin panel
 * without a deploy. Read those with Config::setting().
 */

$basePath = dirname(__DIR__);

return [
    'app' => [
        'name'           => Env::getString('APP_NAME', 'Zusty'),
        'env'            => Env::getString('APP_ENV', 'production'),
        'debug'          => Env::getBool('APP_DEBUG', false),
        'url'            => rtrim(Env::getString('APP_URL', 'http://localhost'), '/'),
        'api_url'        => rtrim(Env::getString('API_URL', 'http://localhost/api'), '/'),
        'admin_url'      => rtrim(Env::getString('ADMIN_URL', 'http://localhost/admin'), '/'),
        'timezone'       => Env::getString('APP_TIMEZONE', 'Asia/Kolkata'),
        'locale'         => Env::getString('APP_LOCALE', 'en'),
        'base_path'      => $basePath,
        'encryption_key' => Env::getString('APP_ENCRYPTION_KEY'),
        // Addresses (or CIDR blocks) whose X-Forwarded-For we believe.
        //
        // Empty means "trust nothing", which is correct for a server reached
        // directly. Behind nginx on the same host this is the loopback; behind
        // Cloudflare, add their ranges. Setting '*' restores blanket trust and
        // makes every IP-keyed rate limit and allow-list spoofable.
        'trusted_proxies' => Env::getList('TRUSTED_PROXIES'),
        'version'        => '1.0.1',
        'api_version'    => 'v1',
    ],

    'database' => [
        'host'       => Env::getString('DB_HOST', '127.0.0.1'),
        'port'       => Env::getInt('DB_PORT', 3306),
        'name'       => Env::getString('DB_NAME'),
        'user'       => Env::getString('DB_USER'),
        'password'   => Env::getString('DB_PASSWORD'),
        'charset'    => Env::getString('DB_CHARSET', 'utf8mb4'),
        // Every table is utf8mb4_unicode_ci; Database::connection() pins the
        // session to it (see the note there).
        'collation'  => Env::getString('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'socket'     => Env::getString('DB_SOCKET'),
        'persistent' => false,
    ],

    'jwt' => [
        'secret'      => Env::getString('JWT_SECRET'),
        'issuer'      => Env::getString('JWT_ISSUER', 'zustee.com'),
        'audience'    => Env::getString('JWT_AUDIENCE', 'zuste-app'),
        'access_ttl'  => Env::getInt('JWT_ACCESS_TTL', 3600),
        'refresh_ttl' => Env::getInt('JWT_REFRESH_TTL', 2592000),
    ],

    'session' => [
        'name'      => Env::getString('SESSION_NAME', 'zuste_admin_session'),
        'lifetime'  => Env::getInt('SESSION_LIFETIME', 7200),
        'secure'    => Env::getBool('SESSION_SECURE', true),
        'http_only' => true,
        'same_site' => Env::getString('SESSION_SAMESITE', 'Lax'),
        'path'      => '/',
    ],

    'security' => [
        'force_https'      => Env::getBool('SESSION_SECURE', true),
        'allowed_origins'  => Env::getList('CORS_ALLOWED_ORIGINS'),
        'rate_limit'       => 120,
        'rate_window'      => 60,
        'auth_rate_limit'  => 10,
        'auth_rate_window' => 300,
    ],

    'storage' => [
        'driver'      => Env::getString('STORAGE_DRIVER', 'local'),
        'upload_path' => $basePath . '/' . trim(Env::getString('UPLOAD_PATH', 'public/uploads'), '/'),
        'upload_url'  => '/' . trim(Env::getString('UPLOAD_URL', '/uploads'), '/'),
        'base_path'   => $basePath . '/' . trim(Env::getString('STORAGE_PATH', 'storage'), '/'),
        'backup_path' => $basePath . '/' . trim(Env::getString('BACKUP_PATH', 'storage/backups'), '/'),
        'temp_path'   => $basePath . '/storage/tmp',
    ],

    'log' => [
        'channel'   => Env::getString('LOG_CHANNEL', 'daily'),
        'path'      => $basePath . '/storage/logs',
        'min_level' => Env::getBool('APP_DEBUG', false) ? 'debug' : 'info',
    ],

    'firebase' => [
        'project_id'     => Env::getString('FIREBASE_PROJECT_ID'),
        'web_api_key'    => Env::getString('FIREBASE_WEB_API_KEY'),
        'credentials'    => Env::getString('FIREBASE_CREDENTIALS'),
        'storage_bucket' => Env::getString('FIREBASE_STORAGE_BUCKET'),
    ],

    'payment' => [
        'razorpay' => [
            'key_id'         => Env::getString('RAZORPAY_KEY_ID'),
            'key_secret'     => Env::getString('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => Env::getString('RAZORPAY_WEBHOOK_SECRET'),
            'api_base'       => 'https://api.razorpay.com/v1',
        ],
        'stripe' => [
            'publishable_key' => Env::getString('STRIPE_PUBLISHABLE_KEY'),
            'secret_key'      => Env::getString('STRIPE_SECRET_KEY'),
            'webhook_secret'  => Env::getString('STRIPE_WEBHOOK_SECRET'),
            'api_base'        => 'https://api.stripe.com/v1',
        ],
        'cashfree' => [
            'app_id'      => Env::getString('CASHFREE_APP_ID'),
            'secret_key'  => Env::getString('CASHFREE_SECRET_KEY'),
            'mode'        => Env::getString('CASHFREE_MODE', 'sandbox'),
            'return_url'  => Env::getString('CASHFREE_RETURN_URL'),
            // Pinned: Cashfree keys its request/response shape off this header,
            // so the version travels with the code that parses it.
            'api_version' => '2023-08-01',
            'api_base'    => [
                'sandbox'    => 'https://sandbox.cashfree.com/pg',
                'production' => 'https://api.cashfree.com/pg',
            ],
        ],
    ],

    'mail' => [
        'driver'       => Env::getString('MAIL_DRIVER', 'smtp'),
        'host'         => Env::getString('MAIL_HOST'),
        'port'         => Env::getInt('MAIL_PORT', 465),
        'encryption'   => Env::getString('MAIL_ENCRYPTION', 'ssl'),
        'username'     => Env::getString('MAIL_USERNAME'),
        'password'     => Env::getString('MAIL_PASSWORD'),
        'from_address' => Env::getString('MAIL_FROM_ADDRESS', 'no-reply@zustee.com'),
        'from_name'    => Env::getString('MAIL_FROM_NAME', 'Zusty'),
        'timeout'      => 20,
    ],

    'sms' => [
        'enabled'          => Env::getBool('SMS_ENABLED', false),
        'provider'         => Env::getString('SMS_PROVIDER', 'msg91'),
        'api_key'          => Env::getString('SMS_API_KEY'),
        'sender_id'        => Env::getString('SMS_SENDER_ID', 'ZUSTEP'),
        'otp_template_id'  => Env::getString('SMS_OTP_TEMPLATE_ID'),
    ],

    'cron' => [
        'secret' => Env::getString('CRON_SECRET'),
    ],

    'backup' => [
        'retention_days' => Env::getInt('BACKUP_RETENTION_DAYS', 14),
        'mysqldump'      => Env::getString('MYSQLDUMP_PATH', 'mysqldump'),
    ],
];
