<?php

declare(strict_types=1);

/**
 * Backend hygiene for Play review (migration 058, decisions of 2026-10-10).
 *
 *  - SMS is off, so nothing in a creator's profile completion may need a
 *    text message: the mobile-number rules and the Mobile verified badge
 *    are not scored, not listed as missing and not asked for by any feature
 *    gate, and a breakdown stored before that is refilled on the next read.
 *    Switching SMS on brings them back, as optional items.
 *  - A confirmed email is never reported as a missing "Email verified"
 *    badge, whatever the creator's review status: the badge is synced when
 *    the completion is read or rescored, not only by the nightly sweep of
 *    approved creators.
 *  - The request log masks phone numbers and email addresses under any key
 *    that names one (customer_phone, alternateMobile, billing_email …), a
 *    `value` beside `"channel": "email|phone"` (POST /me/contacts/change and
 *    /me/contacts/alternates), a phone-shaped `value` anywhere and an email
 *    address wherever it appears, at any depth, and keeps the keys and the
 *    fields an operator needs.
 *  - Migration 058's data: the mobile rules are optional, no gate asks for
 *    mobile_verified, sms.enabled has its definition, and no stored request
 *    body still holds a number or an address in the clear.
 *
 * SMS "on" is simulated in this process only (the settings cache is patched),
 * so the API server and anything else using the database keep seeing the
 * real setting. The creator is the `it-hygiene-creator` account; only its own
 * stored completion rows are written, and they are recomputed at the end.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Services\ApiLogService;
use Zuste\Services\CreatorBadgeService;
use Zuste\Services\ProfileCompletionService;
use Zuste\Services\SmsService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------

/**
 * Run [$fn] with SMS reported on or off in this process only. The settings
 * cache is loaded, patched and dropped again afterwards; nothing is written.
 */
$bhWithSms = static function (bool $on, callable $fn): mixed {
    $cache = new \ReflectionProperty(Config::class, 'settings');

    Config::flushSettings();
    Config::setting('sms', 'enabled');

    $patched                   = (array) $cache->getValue();
    $patched['sms']['enabled'] = $on ? '1' : '0';
    $cache->setValue(null, $patched);

    try {
        return $fn();
    } finally {
        Config::flushSettings();
    }
};

/** Run [$fn] with one memo of ProfileCompletionService replaced; dropped afterwards. */
$bhWithMemo = static function (string $memo, array $value, callable $fn): mixed {
    ProfileCompletionService::flush();
    (new \ReflectionProperty(ProfileCompletionService::class, $memo))->setValue(null, $value);

    try {
        return $fn();
    } finally {
        ProfileCompletionService::flush();
    }
};

/** "section.rule_key" for each rule or checklist entry. */
$bhKeys = static fn (array $entries): array => array_values(array_map(
    static fn (array $entry): string => $entry['section'] . '.' . $entry['rule_key'],
    $entries
));

/** Every rule in a by-section list, flattened. */
$bhFlatten = static fn (array $bySection): array => array_merge([], ...array_values($bySection));

/** A body as the request log would store it, decoded again. */
$bhRedacted = static fn (array $body): array => (array) json_decode(ApiLogService::redact((string) json_encode($body)), true);

/**
 * The api_logs row a request left, or null. The row is written after the
 * response is sent, so it is polled for briefly.
 *
 * @return array<string,mixed>|null
 */
$bhLogRow = static function (Res $res): ?array {
    $requestId = (string) ($res->header('x-request-id') ?? '');
    $row       = null;

    for ($i = 0; $i < 30 && $requestId !== '' && $row === null; $i++) {
        $row = Database::selectOne(
            'SELECT `status_code`, `request_body` FROM `api_logs` WHERE `request_id` = :r',
            ['r' => $requestId]
        );

        if ($row === null) {
            usleep(100_000);
        }
    }

    return $row;
};

$bhMobileLabel = (string) (CreatorBadgeService::catalogue()['mobile_verified']['label'] ?? 'Mobile verified');

/** Does any gate reason name the Mobile verified badge? */
$bhAsksForMobile = static fn (array $gates): bool => array_filter(
    $gates,
    static fn (array $gate): bool => str_contains(implode(' ', (array) ($gate['reasons'] ?? [])), $bhMobileLabel)
) !== [];

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$bhActor  = Actors::create('hygiene-creator', 'creator');
$bhUserId = Actors::id('hygiene-creator') ?: (int) (Database::scalar(
    'SELECT `id` FROM `users` WHERE `email` = :e',
    ['e' => Actors::email('hygiene-creator')]
) ?? 0);

$bhCreatorId = $bhUserId > 0
    ? (int) (Database::scalar(
        'SELECT `id` FROM `creators` WHERE `user_id` = :u AND `deleted_at` IS NULL',
        ['u' => $bhUserId]
    ) ?? 0)
    : 0;

ProfileCompletionService::flush();

$bhRules    = ProfileCompletionService::rules();
$bhSmsRules = array_values(array_filter($bhFlatten($bhRules), [ProfileCompletionService::class, 'needsSms']));
$bhSmsKeys  = $bhKeys($bhSmsRules);

// =================================================================
Suite::group('SMS off: migration 058');
// =================================================================

Suite::check(
    'the rules that need a text message are the mobile number, its confirmation and Mobile verified',
    array_diff(['contact.phone', 'contact.phone_verified', 'verification.mobile_badge'], $bhSmsKeys) === [],
    json_encode($bhSmsKeys)
);
Suite::check(
    '…and none of them is mandatory any more',
    array_filter($bhSmsRules, static fn (array $rule): bool => (int) $rule['is_mandatory'] === 1) === [],
    json_encode($bhSmsKeys)
);
Suite::check(
    'the email rules do not need SMS',
    array_intersect(['contact.email', 'contact.email_verified', 'verification.email_badge'], $bhSmsKeys) === []
);
Suite::equals(
    'no feature gate asks for mobile_verified',
    (int) Database::scalar("SELECT COUNT(*) FROM `creator_feature_gates` WHERE `required_badges` LIKE '%mobile_verified%'"),
    0
);
Suite::check(
    'accepting bookings still asks for a confirmed email',
    str_contains((string) Database::scalar("SELECT `required_badges` FROM `creator_feature_gates` WHERE `feature_key` = 'accept_bookings'"), 'email_verified')
);
Suite::check(
    'creator.required_verifications no longer lists mobile_verified',
    !str_contains((string) Config::setting('creator', 'required_verifications', ''), 'mobile_verified'),
    (string) Config::setting('creator', 'required_verifications', '')
);
Suite::equals(
    'sms.enabled keeps its definition',
    Database::selectOne("SELECT `type`, `label`, `description`, `is_public`, `sort_order` FROM `settings` WHERE `group_key` = 'sms' AND `setting_key` = 'enabled'"),
    ['type' => 'boolean', 'label' => 'SMS enabled', 'description' => 'Master switch for SMS delivery.', 'is_public' => 0, 'sort_order' => 1]
);
Suite::equals('SMS delivery is off here', SmsService::enabled(), false);

// =================================================================
$bhCompletionGroup = 'SMS off: profile completion asks for nothing a creator cannot do';
// =================================================================

if ($bhActor === null || $bhCreatorId <= 0) {
    Suite::skipGroup($bhCompletionGroup, 'could not make the creator: ' . Actors::lastError());
} elseif (SmsService::enabled()) {
    Suite::skipGroup($bhCompletionGroup, 'SMS delivery is switched on here, so the API answers for SMS on');
} else {
    Suite::group($bhCompletionGroup);

    try {
        $bhApplicable = ProfileCompletionService::applicableRules();

        Suite::check(
            'the mobile rules are not scored',
            array_intersect($bhSmsKeys, $bhKeys($bhFlatten($bhApplicable))) === []
        );

        $bhOff = ProfileCompletionService::evaluate($bhCreatorId);

        Suite::check(
            '…or listed as missing',
            array_intersect($bhSmsKeys, $bhKeys($bhOff['missing'])) === [],
            json_encode($bhKeys($bhOff['missing']))
        );
        Suite::equals(
            'the contact section counts only the rules that apply',
            $bhOff['sections']['contact']['rules_total'] ?? null,
            count($bhApplicable['contact'] ?? [])
        );

        // A breakdown stored before 058, as every creator had: the two mobile
        // rules outstanding and mandatory.
        ProfileCompletionService::recompute($bhCreatorId);
        Database::update('creator_profile_completion', [
            'rules_total'       => count($bhRules['contact'] ?? []),
            'percent'           => 50,
            'mandatory_missing' => 2,
            'missing'           => '["phone","phone_verified"]',
        ], ['creator_id' => $bhCreatorId, 'section' => 'contact']);

        $bhRead    = Http::get('/me/creator/completion', [], 'hygiene-creator');
        $bhContact = (array) $bhRead->at('data.completion.sections.contact');

        Suite::ok('the creator reads their completion', $bhRead, 200);
        Suite::check(
            'a breakdown stored before is refilled: no mobile item is outstanding',
            array_intersect(['phone', 'phone_verified'], (array) ($bhContact['missing'] ?? ['?'])) === [],
            json_encode($bhContact)
        );
        Suite::equals('…the section counts the rules that apply', $bhContact['rules_total'] ?? null, count($bhApplicable['contact'] ?? []));
        Suite::equals('…and is reported as applicable', $bhContact['applicable'] ?? null, true);
        Suite::check(
            'no feature gate gives Mobile verified as a reason',
            !$bhAsksForMobile((array) $bhRead->at('data.gates')),
            json_encode($bhRead->at('data.gates'))
        );

        $bhMe = Http::get('/me/creator', [], 'hygiene-creator');
        Suite::check(
            'Mobile verified is not offered as a badge to earn',
            $bhMe->status === 200 && !in_array('mobile_verified', array_column((array) $bhMe->at('data.badges'), 'value'), true),
            $bhMe->describe()
        );

        // An operator who puts the badge back on a gate.
        $bhGates = ProfileCompletionService::gates();

        if (!isset($bhGates['accept_bookings'])) {
            Suite::check('the accept_bookings gate exists', false, 'no active accept_bookings gate');
        } else {
            $bhGates['accept_bookings']['required_badges'] = '["email_verified","mobile_verified"]';

            $bhGateOff = $bhWithSms(false, static fn () => $bhWithMemo(
                'gates',
                $bhGates,
                static fn (): array => ProfileCompletionService::gateStatus($bhCreatorId, 'accept_bookings')
            ));
            $bhGateOn = $bhWithSms(true, static fn () => $bhWithMemo(
                'gates',
                $bhGates,
                static fn (): array => ProfileCompletionService::gateStatus($bhCreatorId, 'accept_bookings')
            ));

            Suite::check('a gate an operator points at mobile_verified does not ask for it while SMS is off', !$bhAsksForMobile([$bhGateOff]), json_encode($bhGateOff['reasons']));
            Suite::check('…and does once SMS is on', $bhAsksForMobile([$bhGateOn]), json_encode($bhGateOn['reasons']));
        }

        // A section whose every rule needs SMS has nothing to finish, so a gate
        // that names it is not held shut by it.
        $bhOnlySms = $bhRules;
        $bhOnlySms['contact'] = array_values(array_filter($bhRules['contact'] ?? [], [ProfileCompletionService::class, 'needsSms']));

        $bhEmptyGate = $bhWithSms(false, static fn () => $bhWithMemo(
            'rules',
            $bhOnlySms,
            static fn (): array => [
                ProfileCompletionService::evaluate($bhCreatorId)['sections']['contact'] ?? [],
                ProfileCompletionService::gateStatus($bhCreatorId, 'accept_bookings'),
            ]
        ));
        $bhContactLabel = (string) ($bhEmptyGate[0]['label'] ?? 'Contact details');

        Suite::equals('a section whose every rule needs SMS is not applicable while SMS is off', $bhEmptyGate[0]['applicable'] ?? null, false);
        Suite::check(
            '…and a gate that names it does not ask for it',
            !str_contains(implode(' ', $bhEmptyGate[1]['reasons']), $bhContactLabel),
            json_encode($bhEmptyGate[1]['reasons'])
        );

        // Switching SMS on brings the rules back, optional.
        $bhOn = $bhWithSms(true, static fn (): array => [
            ProfileCompletionService::applicableRules(),
            ProfileCompletionService::evaluate($bhCreatorId),
            ProfileCompletionService::breakdown($bhCreatorId),
            CreatorBadgeService::ownerOverview($bhCreatorId),
        ]);
        [$bhOnRules, $bhOnEval, $bhOnStored, $bhOnBadges] = $bhOn;

        Suite::check(
            'with SMS on the mobile rules are scored again',
            array_diff($bhSmsKeys, $bhKeys($bhFlatten($bhOnRules))) === []
        );

        $bhOnMobile = array_values(array_filter(
            $bhOnEval['missing'],
            static fn (array $entry): bool => in_array($entry['section'] . '.' . $entry['rule_key'], $bhSmsKeys, true)
        ));

        Suite::check(
            '…an unconfirmed number is listed as optional, never mandatory',
            $bhOnMobile !== [] && array_filter($bhOnMobile, static fn (array $entry): bool => $entry['is_mandatory']) === [],
            json_encode($bhOnMobile)
        );
        Suite::equals(
            '…and the stored breakdown follows on the next read',
            $bhOnStored['sections']['contact']['rules_total'] ?? null,
            count($bhRules['contact'] ?? [])
        );
        Suite::check(
            '…as does the badge list',
            in_array('mobile_verified', array_column($bhOnBadges, 'value'), true)
        );
    } finally {
        ProfileCompletionService::flush();
        Config::flushSettings();

        try {
            ProfileCompletionService::recompute($bhCreatorId);
        } catch (\Throwable) {
            // The next read refills it.
        }
    }
}

// =================================================================
$bhBadgeGroup = 'A confirmed email is never a missing badge, before approval too';
// =================================================================

$bhBadgeStatus = static fn (): ?string => ($status = Database::scalar(
    "SELECT `status` FROM `creator_badges` WHERE `creator_id` = :c AND `badge` = 'email_verified'",
    ['c' => $bhCreatorId]
)) === null ? null : (string) $status;

$bhStoredMissing = static fn (string $section): array => (array) json_decode((string) (Database::scalar(
    'SELECT `missing` FROM `creator_profile_completion` WHERE `creator_id` = :c AND `section` = :s',
    ['c' => $bhCreatorId, 's' => $section]
) ?? '[]'), true);

if ($bhActor === null || $bhCreatorId <= 0) {
    Suite::skipGroup($bhBadgeGroup, 'could not make the creator: ' . Actors::lastError());
} else {
    Suite::group($bhBadgeGroup);

    $bhBefore = Database::selectOne(
        'SELECT c.`status`, u.`email_verified_at`
           FROM `creators` c JOIN `users` u ON u.`id` = c.`user_id`
          WHERE c.`id` = :c',
        ['c' => $bhCreatorId]
    ) ?? ['status' => 'draft', 'email_verified_at' => null];

    try {
        // A creator in review, the address confirmed at sign-up, and a
        // breakdown stored while the email badge was still missing.
        // Confirming an address at sign-up does not sync badges, and the
        // nightly badge sweep covers approved creators only.
        Database::update('users', ['email_verified_at' => null], ['id' => $bhUserId]);
        CreatorBadgeService::syncAccountBadges($bhCreatorId);
        ProfileCompletionService::recompute($bhCreatorId);

        Suite::check(
            'set-up: the stored breakdown lists Email verified as missing',
            in_array('email_badge', $bhStoredMissing('verification'), true),
            json_encode($bhStoredMissing('verification'))
        );

        Database::update('creators', ['status' => 'pending_review'], ['id' => $bhCreatorId]);
        Database::update('users', ['email_verified_at' => date('Y-m-d H:i:s')], ['id' => $bhUserId]);

        $bhRead = Http::get('/me/creator/completion', [], 'hygiene-creator');
        $bhVer  = (array) $bhRead->at('data.completion.sections.verification');
        $bhCon  = (array) $bhRead->at('data.completion.sections.contact');

        Suite::ok('the creator in review reads their completion', $bhRead, 200);
        Suite::check(
            'a confirmed email is not reported as a missing Email verified badge',
            !in_array('email_badge', (array) ($bhVer['missing'] ?? ['email_badge']), true),
            json_encode($bhVer)
        );
        Suite::equals('…the badge is awarded on that read', $bhBadgeStatus(), 'active');
        Suite::equals(
            '…and nothing mandatory is left in contact or verification',
            [(int) ($bhCon['mandatory_missing'] ?? -1), (int) ($bhVer['mandatory_missing'] ?? -1)],
            [0, 0]
        );

        // A creator nobody opens: the nightly creators:completion rescoring
        // syncs the badge before it scores.
        Database::update(
            'creator_badges',
            ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')],
            ['creator_id' => $bhCreatorId, 'badge' => 'email_verified']
        );
        ProfileCompletionService::recompute($bhCreatorId);

        Suite::check(
            'rescoring syncs the badge first, so it is not stored as missing',
            $bhBadgeStatus() === 'active' && !in_array('email_badge', $bhStoredMissing('verification'), true),
            json_encode([$bhBadgeStatus(), $bhStoredMissing('verification')])
        );
    } finally {
        Database::update('users', ['email_verified_at' => $bhBefore['email_verified_at']], ['id' => $bhUserId]);
        Database::update('creators', ['status' => (string) $bhBefore['status']], ['id' => $bhCreatorId]);

        try {
            CreatorBadgeService::syncAccountBadges($bhCreatorId);
            ProfileCompletionService::recompute($bhCreatorId);
        } catch (\Throwable) {
            // The next read refills it.
        }
    }
}

// =================================================================
Suite::group('Request log: numbers and addresses are masked under any key');
// =================================================================

$bhLogged = json_decode(ApiLogService::redact((string) json_encode([
    'customer_phone'     => '+91 98765 43210',
    'customerEmail'      => 'jane.doe@example.com',
    'phone_country_code' => '+91',
    'amount'             => 1180,
    'billing'            => ['alternateMobile' => 9876543210, 'emails' => ['a.b@x.com'], 'msisdn' => '919876543210'],
    'payload'            => json_encode(['whatsapp' => '9876543210', 'otp' => '123456']),
    'destination'        => 'it-bh@zuste.test',
])), true);

Suite::equals('a phone number keeps its last four digits', $bhLogged['customer_phone'] ?? null, '********3210');
Suite::equals('an email address keeps its first characters and its domain', $bhLogged['customerEmail'] ?? null, 'ja******@example.com');
Suite::equals('…the country code and the amount are kept', [$bhLogged['phone_country_code'] ?? null, $bhLogged['amount'] ?? null], ['+91', 1180]);
Suite::equals('a number sent as a number is masked too', $bhLogged['billing']['alternateMobile'] ?? null, '******3210');
Suite::equals('…as is every address in a list', $bhLogged['billing']['emails'] ?? null, ['a.*@x.com']);
Suite::equals('…and an msisdn', $bhLogged['billing']['msisdn'] ?? null, '********3210');
Suite::equals(
    'JSON inside a field is opened and treated the same',
    json_decode((string) ($bhLogged['payload'] ?? ''), true),
    ['whatsapp' => '******3210', 'otp' => ApiLogService::REDACTED]
);
Suite::equals('where a code was sent is masked', $bhLogged['destination'] ?? null, 'it***@zuste.test');

$bhSecrets = json_decode(ApiLogService::redact((string) json_encode([
    'password' => 'x', 'newPassword' => 'x', 'refresh-token' => 'x', 'client_secret' => 'x', 'api_key' => 'x',
    'otpCode' => '1', 'verification_code' => '1', 'code' => '1', 'pan' => 'x', 'panNumber' => 'x', 'aadhaar' => 'x',
    'account_number' => 'x', 'bankAccountNo' => 'x', 'ifsc_code' => 'x', 'upi_id' => 'x', 'vpa' => 'x',
    'card_number' => 'x', 'cvv' => 'x',
    // One-word spellings, with no word boundary inside them.
    'cardno' => '4111111111111111', 'cardnum' => 'x', 'cardHolder' => 'x', 'upihandle' => 'x@okicici',
    'upipin' => 'x', 'vpaid' => 'x@ybl', 'payerVpa' => 'x@ybl',
])), true);

Suite::check(
    'secrets are redacted under any spelling of their key',
    is_array($bhSecrets) && array_unique(array_values($bhSecrets)) === [ApiLogService::REDACTED],
    json_encode($bhSecrets)
);

$bhKept = ['reason_code' => 'fees', 'referral_code' => 'ABC123', 'country_code' => '+91', 'company' => 'Acme', 'pricing' => 'keep-me', 'groupId' => 7, 'shipping_address' => 'Street 1'];
Suite::equals(
    'look-alike keys an operator needs are kept',
    json_decode(ApiLogService::redact((string) json_encode($bhKept)), true),
    $bhKept
);

// POST /me/contacts/change and /me/contacts/alternates send the address or
// number under `value`, which names nothing personal.
Suite::equals(
    'a number sent as {"channel": "phone", "value": …} is masked',
    $bhRedacted(['channel' => 'phone', 'value' => '98765 43210'])['value'] ?? null,
    '******3210'
);
Suite::equals(
    '…as is an address sent as {"value": …, "channel": "email"}, with the label kept',
    $bhRedacted(['value' => 'jane.doe@example.com', 'channel' => 'email', 'label' => 'Work']),
    ['value' => 'ja******@example.com', 'channel' => 'email', 'label' => 'Work']
);
Suite::equals(
    '…and a number sent as a JSON number beside "type": "mobile"',
    $bhRedacted(['type' => 'mobile', 'value' => 9876543210])['value'] ?? null,
    '******3210'
);
Suite::equals(
    'a phone-shaped value is masked with no channel beside it',
    $bhRedacted(['value' => '+91 98765 43210'])['value'] ?? null,
    '********3210'
);
Suite::equals(
    'an email address is masked wherever it appears; a file name is not an address',
    $bhRedacted(['note' => 'write to jane.doe@example.com', 'list' => ['p@q.com'], 'logo' => 'logo@2x.png']),
    ['note' => 'write to ja******@example.com', 'list' => ['p*@q.com'], 'logo' => 'logo@2x.png']
);

$bhPlain = ['value' => '2026-10-10', 'to' => '2026-10-12', 'address' => '12 MG Road', 'channel' => 'in_app', 'amount' => 1500];
Suite::equals('a date, a street and an amount beside another channel are kept', $bhRedacted($bhPlain), $bhPlain);

$bhText = ApiLogService::redact('password: hunter2, phone: 9876543210 email="a.b@x.com"');
Suite::check(
    'an unparseable body is scrubbed and masked as text',
    !str_contains($bhText, 'hunter2') && str_contains($bhText, '******3210') && str_contains($bhText, 'a.*@x.com'),
    $bhText
);

$bhXml = ApiLogService::redact('<req><user><mobile>9876543210</mobile><otp>123456</otp></user><city>Pune</city></req>');
Suite::check(
    '…and so is XML',
    !str_contains($bhXml, '9876543210') && !str_contains($bhXml, '123456') && str_contains($bhXml, '<city>Pune</city>'),
    $bhXml
);

// End to end: failed requests keep no number or address in the log.
if (!Config::settingBool('developer', 'api_logging', true)) {
    Suite::check('failed requests are logged with numbers and addresses masked', true, 'API logging is switched off here');
} else {
    $bhPay = Http::post('/payments/booking/999999999', ['gateway' => 'cashfree', 'customer_phone' => '98765 12345']);
    $bhRow = $bhLogRow($bhPay);

    Suite::check(
        'a failed checkout is logged with customer_phone masked',
        $bhRow !== null && (int) $bhRow['status_code'] >= 400
            && str_contains((string) $bhRow['request_body'], '"customer_phone":"******2345"')
            && !str_contains((string) $bhRow['request_body'], '98765 12345'),
        $bhRow === null ? 'no api_logs row: ' . $bhPay->describe() : (string) $bhRow['request_body']
    );

    // Refused by validation (a label and a reason code one character too
    // long), so the outcome does not depend on the contact settings and
    // nothing is changed on the account.
    if ($bhActor === null) {
        Suite::check('the contacts endpoints log a masked value', false, 'could not make the creator: ' . Actors::lastError());
    } else {
        $bhAlt    = Http::post('/me/contacts/alternates', [
            'channel' => 'phone',
            'value'   => '98765 12345',
            'label'   => str_repeat('x', 61),
        ], 'hygiene-creator');
        $bhAltRow = $bhLogRow($bhAlt);

        Suite::check(
            'a refused POST /me/contacts/alternates is logged with the number masked',
            $bhAltRow !== null && (int) $bhAltRow['status_code'] >= 400
                && str_contains((string) $bhAltRow['request_body'], '"value":"******2345"')
                && !str_contains((string) $bhAltRow['request_body'], '98765 12345'),
            $bhAltRow === null ? 'no api_logs row: ' . $bhAlt->describe() : (string) $bhAltRow['request_body']
        );

        $bhChange    = Http::post('/me/contacts/change', [
            'channel'     => 'email',
            'value'       => 'it-bh-moved@zuste.test',
            'reason_code' => str_repeat('x', 61),
        ], 'hygiene-creator');
        $bhChangeRow = $bhLogRow($bhChange);

        Suite::check(
            'a refused POST /me/contacts/change is logged with the address masked',
            $bhChangeRow !== null && (int) $bhChangeRow['status_code'] >= 400
                && str_contains((string) $bhChangeRow['request_body'], '"value":"it*********@zuste.test"')
                && !str_contains((string) $bhChangeRow['request_body'], 'it-bh-moved@'),
            $bhChangeRow === null ? 'no api_logs row: ' . $bhChange->describe() : (string) $bhChangeRow['request_body']
        );
    }
}

// What migration 058 masks in stored bodies, and what the code above
// writes: none of it may be left in the clear.
$bhClear = [
    'no stored request body holds a phone number in the clear' => <<<'SQL'
        SELECT COUNT(*) FROM `api_logs`
         WHERE `request_body` REGEXP '(?i)"(?:[a-z0-9_.-]*(?:phone|mobile|msisdn|whatsapp)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*"?\\+?[0-9][0-9 ().-]{2,}[0-9]{4}'
        SQL,
    '…or an email address under a key that names one' => <<<'SQL'
        SELECT COUNT(*) FROM `api_logs`
         WHERE `request_body` REGEXP '(?i)"(?:[a-z0-9_.-]*(?:email|phone|mobile)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*"[^"@*\\\\]+@'
        SQL,
    '…or a phone number under value, new_value, old_value or contact' => <<<'SQL'
        SELECT COUNT(*) FROM `api_logs`
         WHERE `request_body` REGEXP '(?i)"(?:value|new_value|old_value|contact)"\\s*:\\s*"\\+?[0-9][0-9 ().-]{2,}[0-9]{4}"'
            OR `request_body` REGEXP '(?i)"(?:value|new_value|old_value|contact)"\\s*:\\s*[0-9]{7,15}\\s*[,}\\]]'
        SQL,
    '…or an email address anywhere' => <<<'SQL'
        SELECT COUNT(*) FROM `api_logs`
         WHERE `request_body` REGEXP '(?i)[A-Za-z0-9._%+-]@[A-Za-z0-9-]+(?:\\.[A-Za-z0-9-]+)*\\.(?!(?:png|jpe?g|gif|webp|svg|pdf|mp4|mov|heic|avif)(?![A-Za-z]))[A-Za-z]{2,}'
        SQL,
];

foreach ($bhClear as $bhName => $bhSql) {
    Suite::equals($bhName, (int) Database::scalar($bhSql), 0);
}
