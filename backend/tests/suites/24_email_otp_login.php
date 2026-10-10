<?php

declare(strict_types=1);

/**
 * Sign-in by a one-time code sent to the account's EMAIL, no SMS anywhere,
 * and the single public mailbox (migration 052, decisions of 2026-10-10).
 *
 * The code really travels: the suite starts tests/fixtures/smtp_sink.php, a
 * one-shot SMTP server on a free local port, points the mail settings at it,
 * and reads the code out of the message the API sent. Nothing is delivered
 * anywhere else. Settings are changed only inside try/finally blocks that put
 * them back; the codes, rate-limit buckets and sign-in history rows this
 * suite writes are removed at the end.
 *
 * Actor: it-eo-login@zuste.test (reused from run to run, like every actor).
 */

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\RateLimiter;
use Zuste\Services\AuthConfigService;
use Zuste\Services\MailService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------

$eoNote = static function (string $text): void {
    echo "  \033[33mnote\033[0m  {$text}\n";
};

/** Settings this suite changed: "group.key" => ['value' => original], or null when the row did not exist. */
$eoSettings = [];

$eoSet = static function (string $group, string $key, string $value) use (&$eoSettings): void {
    $id = $group . '.' . $key;

    if (!array_key_exists($id, $eoSettings)) {
        $row = Database::selectOne(
            'SELECT `value` FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
            ['g' => $group, 'k' => $key]
        );

        $eoSettings[$id] = $row === null ? null : ['value' => $row['value']];
    }

    Database::execute(
        'INSERT INTO `settings` (`group_key`, `setting_key`, `value`) VALUES (:g, :k, :v)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        ['g' => $group, 'k' => $key, 'v' => $value]
    );

    Config::flushSettings();
};

$eoRestore = static function () use (&$eoSettings): void {
    foreach ($eoSettings as $id => $original) {
        [$group, $key] = explode('.', $id, 2);

        if ($original === null) {
            Database::execute(
                'DELETE FROM `settings` WHERE `group_key` = :g AND `setting_key` = :k',
                ['g' => $group, 'k' => $key]
            );
        } else {
            Database::execute(
                'UPDATE `settings` SET `value` = :v WHERE `group_key` = :g AND `setting_key` = :k',
                ['v' => $original['value'], 'g' => $group, 'k' => $key]
            );
        }
    }

    $eoSettings = [];
    Config::flushSettings();
};

/** Every limit a sign-in code for this address can trip, for both loopback spellings. */
$eoClearLimits = static function (string $destination): void {
    $address = strtolower(trim($destination));

    foreach (['login', 'verify_phone'] as $purpose) {
        RateLimiter::clear('otp:cooldown:' . $purpose . ':' . $address);
        RateLimiter::clear('otp:send:' . $purpose . ':' . $address);
    }

    foreach (['127.0.0.1', '::1'] as $ip) {
        RateLimiter::clear('login-otp:' . $address . ':' . $ip);
    }
};

/**
 * One message the sink kept: subject, recipient, and both bodies decoded.
 *
 * @param  array{from:string,to:list<string>,data:string} $mail
 * @return array{subject:string,to:string,plain:string,html:string}
 */
$eoRead = static function (array $mail): array {
    $raw     = (string) $mail['data'];
    $headers = (string) (strstr($raw, "\r\n\r\n", true) ?: '');
    $subject = preg_match('/^Subject:\s*(.+)$/mi', $headers, $m) === 1 ? trim($m[1], " \"\r\n") : '';
    $bodies  = ['plain' => '', 'html' => ''];

    if (preg_match_all(
        '#Content-Type:\s*text/(plain|html)[^\r\n]*\r\nContent-Transfer-Encoding:\s*base64\r\n\r\n([A-Za-z0-9+/=\r\n]+)#i',
        $raw,
        $parts,
        PREG_SET_ORDER
    )) {
        foreach ($parts as $part) {
            $bodies[strtolower($part[1])] = (string) base64_decode((string) preg_replace('/\s+/', '', $part[2]), true);
        }
    }

    return ['subject' => $subject, 'to' => (string) ($mail['to'][0] ?? '')] + $bodies;
};

/** The one public mailbox, and anything that looks like another one at our domains. */
$eoOtherMailboxes = static function (string $text): array {
    preg_match_all('/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]+@(?:zusty\.app|zustee\.com)(?![A-Za-z0-9_-]|\.[A-Za-z0-9_-])/i', $text, $m);

    return array_values(array_unique(array_filter(
        array_map('strtolower', $m[0]),
        static fn (string $mailbox): bool => $mailbox !== 'support@zusty.app'
    )));
};

// -----------------------------------------------------------------
// Actor
// -----------------------------------------------------------------

$eoActor = Actors::create('eo-login', 'brand');
$eoId    = Actors::id('eo-login');
$eoEmail = strtolower(Actors::email('eo-login'));
$eoRow   = $eoId > 0
    ? (Database::selectOne('SELECT `phone`, `phone_country_code` FROM `users` WHERE `id` = :id', ['id' => $eoId]) ?? [])
    : [];
$eoPhone = (string) ($eoRow['phone_country_code'] ?? '+91') . (string) ($eoRow['phone'] ?? '');

$eoNobody = 'it-eo-nobody-' . bin2hex(random_bytes(4)) . '@zuste.test';

$eoHistoryMark = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `login_history`');

// =================================================================
Suite::group('Email codes: what the apps are told');
// =================================================================

$eoConfig = Http::get('/app/config');
$eoOtp    = null;

foreach ((array) $eoConfig->at('data.auth.methods') as $eoMethod) {
    if (is_array($eoMethod) && ($eoMethod['key'] ?? '') === 'mobile_otp') {
        $eoOtp = $eoMethod;
    }
}

Suite::check('the config carries the one-time code method', is_array($eoOtp), $eoConfig->describe());
Suite::equals('…which sends codes by email only', $eoOtp['channels'] ?? null, ['email']);
Suite::check('…signs an account in with one', ($eoOtp['login_enabled'] ?? false) === true && ($eoOtp['enabled'] ?? false) === true);
Suite::equals('…and is labelled for email', $eoOtp['label'] ?? null, 'Log in with email code');
Suite::equals('SMS delivery is switched off', Config::settingBool('sms', 'enabled', true), false);

try {
    // SMS listed but switched off is still not offered.
    $eoSet('auth', 'otp_channels', '["email","sms"]');
    $eoSet('sms', 'enabled', '0');

    Suite::equals('a listed channel that cannot deliver (SMS while off) is never offered', AuthConfigService::otpChannels(), ['email']);
    Suite::check('…though phone sign-in then counts as offered', AuthConfigService::phoneSignInAllowed());

    $eoSet('auth', 'otp_channels', '["email"]');
    Suite::check('with email alone there is no phone sign-in at all', !AuthConfigService::phoneSignInAllowed());
} finally {
    $eoRestore();
}

// =================================================================
Suite::group('Email codes: sign in end to end');
// =================================================================

$eoState = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zuste-smtp-sink-' . getmypid() . '.json';
@unlink($eoState);
@unlink($eoState . '.mail');

if ($eoActor === null || $eoId <= 0) {
    Suite::skipGroup('Email codes: sign in end to end', 'could not create eo-login: ' . Actors::lastError());
} elseif (!AuthConfigService::enabled('mobile_otp', true)) {
    Suite::skipGroup('Email codes: sign in end to end', 'the one-time code method (mobile_otp) is switched off');
} else {
    $eoSink = proc_open(
        [PHP_BINARY, __DIR__ . '/../fixtures/smtp_sink.php', $eoState, '2', '90'],
        [0 => ['pipe', 'r'], 1 => ['file', $eoState . '.out', 'a'], 2 => ['file', $eoState . '.out', 'a']],
        $eoPipes,
        dirname(__DIR__, 2)
    );

    $eoPort = 0;

    for ($i = 0; $i < 50 && is_resource($eoSink); $i++) {
        $eoPort = (int) (json_decode((string) @file_get_contents($eoState), true)['port'] ?? 0);

        if ($eoPort > 0) {
            break;
        }

        usleep(100_000);
    }

    /** @return list<array{from:string,to:list<string>,data:string}> */
    $eoMail = static function () use ($eoState): array {
        $lines = is_file($eoState . '.mail') ? file($eoState . '.mail', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), $lines ?: []), 'is_array'));
    };

    try {
        Suite::check('the SMTP sink is listening', $eoPort > 0, 'no port in ' . $eoState);

        $eoSet('mail', 'driver', 'smtp');
        $eoSet('mail', 'host', '127.0.0.1');
        $eoSet('mail', 'port', (string) $eoPort);
        $eoSet('mail', 'encryption', 'none');
        $eoSet('auth', 'otp_login_enabled', '1');
        $eoSet('auth', 'otp_channels', '["email"]');
        $eoSet('sms', 'enabled', '0');

        $eoClearLimits($eoEmail);
        $eoClearLimits($eoNobody);
        $eoClearLimits($eoPhone);
        Database::execute('DELETE FROM `otp_verifications` WHERE `destination` IN (:a, :b)', ['a' => $eoEmail, 'b' => $eoNobody]);

        $eoSmsMark = (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `sms_queue`');

        // Typed with stray spaces and capitals: stored, and found, as the
        // account stores its address.
        $eoSend = Http::post('/auth/otp/send', ['destination' => '  ' . strtoupper($eoEmail) . ' ', 'channel' => 'email', 'purpose' => 'login']);
        Suite::ok('a code is sent to the account\'s email', $eoSend, 200);
        Suite::equals('…on the email channel', $eoSend->at('data.channel'), 'email');
        Suite::check('…with when to ask again', (int) ($eoSend->at('data.resend_in') ?? 0) > 0, $eoSend->describe());

        $eoCodeRow = Database::selectOne(
            "SELECT * FROM `otp_verifications` WHERE `destination` = :d AND `purpose` = 'login' ORDER BY `id` DESC LIMIT 1",
            ['d' => $eoEmail]
        ) ?? [];
        Suite::equals('…stored against the address in lower case', (string) ($eoCodeRow['destination'] ?? ''), $eoEmail);
        Suite::equals('…for the account it belongs to', (int) ($eoCodeRow['user_id'] ?? 0), $eoId);
        Suite::equals('…by email', (string) ($eoCodeRow['channel'] ?? ''), 'email');

        $eoMails = $eoMail();
        $eoLetter = $eoMails === [] ? null : $eoRead($eoMails[0]);
        Suite::check('…and an email really left', $eoLetter !== null, 'the sink kept nothing');

        $eoLength = Config::settingInt('security', 'otp_length', 6);
        $eoCode   = '';

        if ($eoLetter !== null) {
            Suite::equals('…to the account\'s address', strtolower($eoLetter['to']), $eoEmail);
            Suite::equals('…titled as a sign-in code', $eoLetter['subject'], 'Your Zusty sign-in code');

            preg_match('/(?<!\d)(\d{' . $eoLength . '})(?!\d)/', $eoLetter['plain'], $m);
            $eoCode = (string) ($m[1] ?? '');

            Suite::check('…carrying the code', $eoCode !== '' && str_contains($eoLetter['html'], $eoCode), mb_substr($eoLetter['plain'], 0, 300));
            Suite::check('…saying what it is for', str_contains($eoLetter['plain'], 'Use this code to sign in to Zusty'), mb_substr($eoLetter['plain'], 0, 300));
            // The same email goes to an address with no account (the send
            // never says which), so it must not claim there is one.
            Suite::check(
                '…in words that hold whether or not there is an account',
                !str_contains($eoLetter['plain'], 'your Zusty account')
                    && !str_contains($eoLetter['plain'], 'because you have an account')
                    && str_contains($eoLetter['plain'], 'have an account, or'),
                mb_substr($eoLetter['plain'], 0, 600)
            );
            Suite::check(
                '…and naming no mailbox but support@zusty.app',
                str_contains($eoLetter['html'], 'support@zusty.app') && $eoOtherMailboxes($eoLetter['html'] . ' ' . $eoLetter['plain']) === [],
                implode(', ', $eoOtherMailboxes($eoLetter['html'] . ' ' . $eoLetter['plain']))
            );
            Suite::check(
                '…the stored code is the one emailed',
                Crypto::secureEquals((string) ($eoCodeRow['code_hash'] ?? ''), Crypto::hashToken($eoCode))
            );
        }

        Suite::equals('no text message was queued', (int) Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `sms_queue`'), $eoSmsMark);

        $eoWrong = Http::post('/auth/otp/verify', [
            'destination' => $eoEmail,
            'code'        => $eoCode === str_repeat('1', $eoLength) ? str_repeat('2', $eoLength) : str_repeat('1', $eoLength),
            'purpose'     => 'login',
        ]);
        Suite::status('a wrong code is refused (422)', $eoWrong, 422);
        Suite::equals('…as otp_invalid', $eoWrong->code(), 'otp_invalid');
        Suite::check('…with no session', $eoWrong->at('data.tokens.access_token') === null);

        if ($eoCode !== '') {
            $eoIn = Http::post('/auth/otp/verify', ['destination' => strtoupper($eoEmail), 'code' => $eoCode, 'purpose' => 'login']);
            Suite::ok('the emailed code signs in', $eoIn, 200);
            Suite::equals('…as the account the address belongs to', (int) ($eoIn->at('data.user.id') ?? 0), $eoId);
            Suite::notEmpty('…with a session', $eoIn->at('data.tokens.access_token'));
            Suite::notEmpty('…and a refresh token', $eoIn->at('data.tokens.refresh_token'));
            Suite::check(
                '…recorded as an email-code sign-in',
                Database::scalar(
                    "SELECT 1 FROM `login_history`
                      WHERE `id` > :m AND `actor_id` = :u AND `auth_method` = 'email_otp' AND `outcome` = 'success' LIMIT 1",
                    ['m' => $eoHistoryMark, 'u' => $eoId]
                ) !== null
            );

            $eoAgain = Http::post('/auth/otp/verify', ['destination' => $eoEmail, 'code' => $eoCode, 'purpose' => 'login']);
            Suite::status('the same code does not work twice (422)', $eoAgain, 422);
            Suite::equals('…as otp_used', $eoAgain->code(), 'otp_used');
        }

        // An address nobody uses: the send looks exactly the same, so it
        // reveals nothing; only the code's owner learns there is no account.
        Suite::clearRateLimits();
        $eoUsersBefore = (int) Database::scalar('SELECT COUNT(*) FROM `users`');
        $eoStranger    = Http::post('/auth/otp/send', ['destination' => $eoNobody, 'channel' => 'email', 'purpose' => 'login']);
        Suite::ok('a code goes to an address with no account too', $eoStranger, 200);
        Suite::equals('…answered exactly like one with an account', [$eoStranger->message(), array_keys($eoStranger->data())], [$eoSend->message(), array_keys($eoSend->data())]);

        $eoStrangerMail = $eoMail();
        $eoStrangerCode = '';

        if (count($eoStrangerMail) >= 2) {
            preg_match('/(?<!\d)(\d{' . $eoLength . '})(?!\d)/', $eoRead($eoStrangerMail[1])['plain'], $m);
            $eoStrangerCode = (string) ($m[1] ?? '');
        }

        Suite::check('…and it arrives', $eoStrangerCode !== '', 'the sink kept ' . count($eoStrangerMail) . ' message(s)');

        if ($eoStrangerCode !== '') {
            $eoNone = Http::post('/auth/otp/verify', ['destination' => $eoNobody, 'code' => $eoStrangerCode, 'purpose' => 'login']);
            Suite::status('its right code is told there is no account (404)', $eoNone, 404);
            Suite::equals('…as account_not_found', $eoNone->code(), 'account_not_found');
            Suite::check('…in words about an email address', str_contains($eoNone->message(), 'email address'), $eoNone->message());
            Suite::check('…with no session', $eoNone->at('data.tokens.access_token') === null);
        }

        Suite::equals('no account was created', (int) Database::scalar('SELECT COUNT(*) FROM `users`'), $eoUsersBefore);
        Suite::check(
            '…for that address',
            Database::scalar('SELECT 1 FROM `users` WHERE `email` = :e', ['e' => $eoNobody]) === null
        );
    } finally {
        $eoRestore();

        if (is_resource($eoSink)) {
            proc_terminate($eoSink);
            proc_close($eoSink);
        }

        foreach ([$eoState, $eoState . '.mail', $eoState . '.out'] as $eoFile) {
            @unlink($eoFile);
        }

        Database::execute('DELETE FROM `otp_verifications` WHERE `destination` IN (:a, :b)', ['a' => $eoEmail, 'b' => $eoNobody]);
        $eoClearLimits($eoEmail);
        $eoClearLimits($eoNobody);
    }
}

// =================================================================
Suite::group('Email codes: no SMS, no phone sign-in');
// =================================================================

if ($eoActor === null || $eoId <= 0 || empty($eoRow['phone'])) {
    Suite::skipGroup('Email codes: no SMS, no phone sign-in', $eoId <= 0 ? 'could not create eo-login' : 'eo-login has no phone');
} else {
    Suite::clearRateLimits();

    try {
        $eoSet('auth', 'otp_login_enabled', '1');
        $eoSet('auth', 'otp_channels', '["email"]');
        $eoSet('sms', 'enabled', '0');
        $eoClearLimits($eoPhone);

        $eoPhoneCodes = static fn (): int => (int) Database::scalar(
            'SELECT COUNT(*) FROM `otp_verifications` WHERE `destination` = :d',
            ['d' => $eoPhone]
        );
        $eoBefore = $eoPhoneCodes();

        $eoSmsLogin = Http::post('/auth/otp/send', ['destination' => $eoPhone, 'channel' => 'sms', 'purpose' => 'login']);
        Suite::status('a sign-in code by SMS is refused (422)', $eoSmsLogin, 422);
        Suite::equals('…as channel_not_allowed', $eoSmsLogin->code(), 'channel_not_allowed');

        $eoSmsVerify = Http::post('/auth/otp/send', ['destination' => $eoPhone, 'channel' => 'sms', 'purpose' => 'verify_phone']);
        Suite::status('so is any other code by SMS while SMS is off (422)', $eoSmsVerify, 422);
        Suite::equals('…as channel_not_allowed, said before anything is sent', $eoSmsVerify->code(), 'channel_not_allowed');
        Suite::equals('…and no code is written for the number', $eoPhoneCodes(), $eoBefore);

        // A login code for the number, as if one had been issued before the
        // switch to email: it no longer signs anyone in.
        $eoPlain = (string) random_int(100000, 999999);
        Database::insert('otp_verifications', [
            'user_id'     => $eoId,
            'channel'     => 'sms',
            'destination' => $eoPhone,
            'purpose'     => 'login',
            'code_hash'   => Crypto::hashToken($eoPlain),
            'expires_at'  => date('Y-m-d H:i:s', time() + 300),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $eoPhoneIn = Http::post('/auth/otp/verify', ['destination' => $eoPhone, 'code' => $eoPlain, 'purpose' => 'login']);
        Suite::status('a code for a phone number signs nobody in (403)', $eoPhoneIn, 403);
        Suite::equals('…as auth_method_disabled', $eoPhoneIn->code(), 'auth_method_disabled');
        Suite::check('…with no session', $eoPhoneIn->at('data.tokens.access_token') === null);
    } finally {
        $eoRestore();
        Database::execute('DELETE FROM `otp_verifications` WHERE `destination` = :d', ['d' => $eoPhone]);
        $eoClearLimits($eoPhone);
    }
}

// =================================================================
Suite::group('052: one public mailbox, no published address');
// =================================================================

$eoMigrated = Database::scalar("SELECT 1 FROM `migrations` WHERE `version` = '052'") !== null;

if (!$eoMigrated) {
    Suite::skipGroup('052: one public mailbox, no published address', 'migration 052 is not applied');
} else {
    $eoStray = static function (string $label, array $rows, string $id) use ($eoOtherMailboxes): array {
        $found = [];

        foreach ($rows as $row) {
            $others = $eoOtherMailboxes(implode(' ', array_map('strval', array_diff_key($row, [$id => true]))));

            if ($others !== []) {
                $found[] = $label . ' ' . $row[$id] . ': ' . implode(', ', $others);
            }
        }

        return $found;
    };

    Suite::equals('no CMS page names a mailbox but support@zusty.app', $eoStray(
        'page',
        Database::select('SELECT `slug`, `title`, `subtitle`, `content`, `meta_title`, `meta_description`, `meta_keywords` FROM `pages`'),
        'slug'
    ), []);
    Suite::equals('…nor any FAQ', $eoStray('faq', Database::select('SELECT `id`, `question`, `answer` FROM `faqs`'), 'id'), []);
    Suite::equals('…nor any notification template', $eoStray(
        'template',
        Database::select('SELECT `code`, `title`, `body`, `email_subject`, `email_body`, `sms_body` FROM `notification_templates`'),
        'code'
    ), []);
    Suite::equals('…nor any popup', $eoStray(
        'popup',
        Database::select('SELECT `id`, `title`, `content`, `cta_value` FROM `popups` WHERE `deleted_at` IS NULL'),
        'id'
    ), []);
    Suite::equals('…nor any blog post', $eoStray(
        'blog',
        Database::select('SELECT `id`, `title`, `excerpt`, `content` FROM `blogs` WHERE `deleted_at` IS NULL'),
        'id'
    ), []);

    // The mail group is the SMTP login and sender, which 052 leaves alone.
    Suite::equals('…nor any setting outside the SMTP login and sender', $eoStray(
        'setting',
        array_map(
            static fn (array $row): array => ['key' => $row['group_key'] . '.' . $row['setting_key'], 'value' => $row['value']],
            Database::select("SELECT `group_key`, `setting_key`, `value` FROM `settings` WHERE `is_encrypted` = 0 AND `group_key` <> 'mail'")
        ),
        'key'
    ), []);
    Suite::equals('the support address is support@zusty.app', MailService::supportAddress(), 'support@zusty.app');

    $eoContact = Http::get('/pages/contact');
    $eoBody    = (string) $eoContact->at('data.page.content');
    Suite::ok('the contact page is served', $eoContact, 200);
    Suite::check('…with no registered office', stripos($eoBody, 'Registered office') === false && stripos($eoBody, 'Registered address') === false, $eoBody);
    Suite::check('…naming support@zusty.app', str_contains($eoBody, 'support@zusty.app'), $eoBody);
    Suite::equals('…and no other mailbox', $eoOtherMailboxes($eoBody), []);

    Suite::equals('no company address is published', Config::settingString('general', 'company_address', ''), '');
    Suite::check(
        'the company name still is',
        trim(Config::settingString('general', 'company_name', '')) !== '',
        'general.company_name is empty'
    );
}

// -----------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------

Database::execute(
    "DELETE FROM `login_history` WHERE `id` > :m AND (`identifier` LIKE 'it-eo-%' OR `actor_id` = :u)",
    ['m' => $eoHistoryMark, 'u' => $eoId]
);
