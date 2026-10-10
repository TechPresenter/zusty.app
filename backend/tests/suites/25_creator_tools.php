<?php

declare(strict_types=1);

/**
 * The creator's own editors: packages, portfolio and linked social accounts.
 *
 *   * Each editor reads its own list (GET /me/creator/packages, /portfolio,
 *     /social): hidden packages, items still in review or turned down, and
 *     accounts in the creator's order — none of which the profile carries.
 *   * Validation is the server's: package prices sit inside the booking
 *     bounds, "what's included" is a short list of short lines, portfolio
 *     media URLs must be http(s), a linked account cannot be linked twice or
 *     moved to another network, and `upload` is not a network.
 *   * Ownership: a creator can never edit, hide, delete or reorder another
 *     creator's package, portfolio item or linked account — every attempt is
 *     a 404 and the row is untouched. A brand is refused outright.
 *   * An administrator's section lock refuses the write with its reason.
 *   * The starting price follows the live packages, down to none; a package
 *     priced outside the booking bounds cannot be booked; a network row
 *     cannot carry a name of its own or be linked twice; the portfolio has
 *     a ceiling and its own upload throttle.
 *
 * Everything written belongs to `it-tools-*` accounts and is removed at the
 * end, uploaded files included; the settings and the creator status the
 * suite changes are restored.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Helpers\Money;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$ctA     = Actors::create('tools-creator-a', 'creator');
$ctB     = Actors::create('tools-creator-b', 'creator');
$ctBrand = Actors::create('tools-brand', 'brand');

$ctCreatorId = static function (string $actor): int {
    $userId = Actors::id($actor) ?: (int) (Database::scalar(
        'SELECT `id` FROM `users` WHERE `email` = :email',
        ['email' => Actors::email($actor)]
    ) ?? 0);

    return $userId > 0
        ? (int) (Database::scalar('SELECT `id` FROM `creators` WHERE `user_id` = :u', ['u' => $userId]) ?? 0)
        : 0;
};

$ctAId = $ctA === null ? 0 : $ctCreatorId('tools-creator-a');
$ctBId = $ctB === null ? 0 : $ctCreatorId('tools-creator-b');

/** Removes everything the editors may have written for one creator. */
$ctWipe = static function (int $creatorId): void {
    if ($creatorId <= 0) {
        return;
    }

    // Only a regression would have let the package-bounds check create a
    // booking; if it did, it goes (its conversation first, as in 04).
    $bookingIds = array_map('intval', array_column(
        Database::select('SELECT `id` FROM `bookings` WHERE `creator_id` = :c', ['c' => $creatorId]),
        'id'
    ));

    foreach ($bookingIds as $bookingId) {
        Database::execute('DELETE FROM `conversations` WHERE `booking_id` = :b', ['b' => $bookingId]);
        Database::execute('DELETE FROM `platform_earnings` WHERE `booking_id` = :b', ['b' => $bookingId]);
        Database::execute('DELETE FROM `bookings` WHERE `id` = :b', ['b' => $bookingId]);
    }

    Database::execute('DELETE FROM `creator_packages` WHERE `creator_id` = :c', ['c' => $creatorId]);
    Database::execute('DELETE FROM `creator_portfolio` WHERE `creator_id` = :c', ['c' => $creatorId]);
    Database::execute('DELETE FROM `creator_social_accounts` WHERE `creator_id` = :c', ['c' => $creatorId]);
    Database::execute('DELETE FROM `creator_profile_locks` WHERE `creator_id` = :c', ['c' => $creatorId]);
};

// A run aborted before its cleanup may have left rows behind.
$ctWipe($ctAId);
$ctWipe($ctBId);

$ctModeration = Database::scalar(
    "SELECT `value` FROM `settings` WHERE `group_key` = 'creator' AND `setting_key` = 'moderate_gallery_uploads'"
);

$ctSetModeration = static function (string $value): void {
    Database::execute(
        "UPDATE `settings` SET `value` = :v WHERE `group_key` = 'creator' AND `setting_key` = 'moderate_gallery_uploads'",
        ['v' => $value]
    );
};

// Migration 057. NULL when the row is missing, and then the ceiling check
// is skipped rather than a row invented.
$ctPortfolioCap = Database::scalar(
    "SELECT `value` FROM `settings` WHERE `group_key` = 'creator' AND `setting_key` = 'max_portfolio_items'"
);

$ctSetPortfolioCap = static function (string $value): void {
    Database::execute(
        "UPDATE `settings` SET `value` = :v WHERE `group_key` = 'creator' AND `setting_key` = 'max_portfolio_items'",
        ['v' => $value]
    );
};

/** What the suite changes to make creator A bookable, undone at the end. */
$ctCreatorStatus = null;
$ctKycInserted   = false;

/** creators.price_min for creator A, as stored. */
$ctStartingPrice = static fn (int $creatorId): float => (float) (Database::scalar(
    'SELECT `price_min` FROM `creators` WHERE `id` = :id',
    ['id' => $creatorId]
) ?? -1);

$ctPng = sys_get_temp_dir() . '/it-tools-cover.png';
file_put_contents($ctPng, (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    true
));

$ctUploadedUrls = [];

$ctMinPrice = (float) (Database::scalar(
    "SELECT `value` FROM `settings` WHERE `group_key` = 'booking' AND `setting_key` = 'min_booking_amount'"
) ?? 1000);
$ctMinPrice = max(1.0, $ctMinPrice);

$ctPackage = static fn (array $overrides = []): array => $overrides + [
    'title'            => 'IT reel package',
    'description'      => 'One reel with a story reshare.',
    'platform'         => 'instagram',
    'deliverable_type' => 'reel',
    'quantity'         => 1,
    'price'            => $ctMinPrice + 500,
    'turnaround_days'  => 5,
    'revisions'        => 1,
    'includes'         => ['Story reshare', '  Usage rights 30 days  ', ''],
];

if ($ctAId <= 0 || $ctBId <= 0 || $ctBrand === null) {
    Suite::skipGroup('Creator tools', 'could not create the test accounts: ' . Actors::lastError());

    return;
}

try {
    // -----------------------------------------------------------------
    Suite::group('Creator tools — packages');

    $list = Http::get('/me/creator/packages', [], 'tools-creator-a');
    Suite::ok('the editor reads its own list', $list, 200);
    Suite::equals('it starts empty', $list->list('packages'), []);
    Suite::equals('and carries the booking floor as the lowest price', (float) $list->at('data.limits.min_price'), $ctMinPrice);
    Suite::equals('and the includes ceiling', $list->at('data.limits.max_includes'), 10);

    $created = Http::post('/me/creator/packages', $ctPackage(), 'tools-creator-a');
    Suite::ok('a creator adds a package', $created, 201);
    $packageId = (int) $created->at('data.package.id');
    Suite::check('the answer is the editor shape, not the raw row', $created->at('data.package.is_active') === true
        && $created->at('data.package.includes') === ['Story reshare', 'Usage rights 30 days']
        && is_string($created->at('data.package.price_formatted')), $created->describe());
    Suite::equals('the starting price follows the live package', $ctStartingPrice($ctAId), (float) ($ctMinPrice + 500));

    $bad = Http::post('/me/creator/packages', $ctPackage(['title' => '', 'price' => $ctMinPrice - 1]), 'tools-creator-a');
    Suite::status('a missing title and a price under the floor are refused', $bad, 422);
    Suite::check('field by field', $bad->at('errors.title') !== null && $bad->at('errors.price') !== null, $bad->describe());

    $tooMany = Http::post('/me/creator/packages', $ctPackage(['includes' => array_fill(0, 11, 'Line')]), 'tools-creator-a');
    Suite::check('more than ten included lines are refused', $tooMany->status === 422 && $tooMany->at('errors.includes') !== null, $tooMany->describe());

    $nested = Http::post('/me/creator/packages', $ctPackage(['includes' => [['a' => 'b']]]), 'tools-creator-a');
    Suite::check('an included "line" that is not text is refused', $nested->status === 422 && $nested->at('errors.includes') !== null, $nested->describe());

    $badPlatform = Http::post('/me/creator/packages', $ctPackage(['platform' => 'myspace']), 'tools-creator-a');
    Suite::check('a platform outside the taxonomy is refused', $badPlatform->status === 422 && $badPlatform->at('errors.platform') !== null, $badPlatform->describe());

    $hidden = Http::put('/me/creator/packages/' . $packageId, ['is_active' => false], 'tools-creator-a');
    Suite::ok('a creator hides a package', $hidden, 200);
    Suite::equals('it reads hidden', $hidden->at('data.package.is_active'), false);

    $own = Http::get('/me/creator/packages', [], 'tools-creator-a');
    Suite::equals('a hidden package stays in the editor', array_column($own->list('packages'), 'is_active'), [false]);

    $profile = Http::get('/me/creator', [], 'tools-creator-a');
    Suite::equals('and leaves the profile brands see', array_column((array) $profile->at('data.creator.packages'), 'id'), []);
    // It used to keep the last price, so "Starting at" quoted a price no
    // package offered.
    Suite::equals('hiding the only package clears the starting price', $ctStartingPrice($ctAId), 0.0);
    Suite::equals('so the profile quotes none', (float) $profile->at('data.creator.price_min'), 0.0);

    $shown = Http::put('/me/creator/packages/' . $packageId, ['is_active' => true], 'tools-creator-a');
    Suite::equals('showing it again works', $shown->at('data.package.is_active'), true);
    Suite::equals('and brings its price back', $ctStartingPrice($ctAId), (float) ($ctMinPrice + 500));

    $nullActive = Http::put('/me/creator/packages/' . $packageId, ['is_active' => null, 'title' => 'IT reel package v2'], 'tools-creator-a');
    Suite::check('an explicit null is_active does not hide it', $nullActive->at('data.package.is_active') === true
        && $nullActive->at('data.package.title') === 'IT reel package v2', $nullActive->describe());

    $cleared = Http::put('/me/creator/packages/' . $packageId, ['description' => null, 'includes' => []], 'tools-creator-a');
    Suite::check('an emptied description and includes list are cleared, not ignored',
        $cleared->at('data.package.description') === null && $cleared->at('data.package.includes') === [], $cleared->describe());

    $tooDear = Http::put('/me/creator/packages/' . $packageId, ['price' => 999999999], 'tools-creator-a');
    Suite::check('a price over the booking ceiling is refused', $tooDear->status === 422 && $tooDear->at('errors.price') !== null, $tooDear->describe());

    // ---- Ownership -------------------------------------------------
    $before = Database::selectOne('SELECT `title`, `price`, `is_active`, `deleted_at` FROM `creator_packages` WHERE `id` = :id', ['id' => $packageId]);

    Suite::status("another creator cannot edit it", Http::put('/me/creator/packages/' . $packageId, ['title' => 'Hijacked', 'price' => $ctMinPrice], 'tools-creator-b'), 404);
    Suite::status("or hide it", Http::put('/me/creator/packages/' . $packageId, ['is_active' => false], 'tools-creator-b'), 404);
    Suite::status("or delete it", Http::delete('/me/creator/packages/' . $packageId, null, 'tools-creator-b'), 404);
    Suite::equals('and the row is untouched',
        Database::selectOne('SELECT `title`, `price`, `is_active`, `deleted_at` FROM `creator_packages` WHERE `id` = :id', ['id' => $packageId]),
        $before);
    Suite::equals("another creator's list does not show it", Http::get('/me/creator/packages', [], 'tools-creator-b')->list('packages'), []);

    Suite::status('a brand cannot read the editor', Http::get('/me/creator/packages', [], 'tools-brand'), 403);
    Suite::status('or add a package', Http::post('/me/creator/packages', $ctPackage(), 'tools-brand'), 403);

    // ---- Section lock ----------------------------------------------
    Database::insert('creator_profile_locks', [
        'creator_id' => $ctAId,
        'section'    => 'pricing',
        'reason'     => 'IT pricing review',
        'locked_at'  => date('Y-m-d H:i:s'),
    ]);

    $locked = Http::post('/me/creator/packages', $ctPackage(), 'tools-creator-a');
    Suite::check('a locked pricing section refuses the write, with its reason',
        $locked->status === 403 && str_contains($locked->message(), 'IT pricing review'), $locked->describe());
    Suite::equals('and the editor says it is locked', Http::get('/me/creator/packages', [], 'tools-creator-a')->at('data.locked'), true);

    Database::execute("DELETE FROM `creator_profile_locks` WHERE `creator_id` = :c AND `section` = 'pricing'", ['c' => $ctAId]);

    // ---- A package priced under the booking minimum -----------------
    // One saved before the editor enforced the bounds, or before an
    // operator raised the minimum — written directly, since the API now
    // refuses it. BookingService checked the bounds against the amount the
    // brand TYPED and then swapped in the package price, so it was bookable.
    $ctCreatorStatus = (string) Database::scalar('SELECT `status` FROM `creators` WHERE `id` = :id', ['id' => $ctAId]);
    Database::update('creators', ['status' => 'approved', 'availability_status' => 'available'], ['id' => $ctAId]);

    $ctBrandUserId = Actors::id('tools-brand');

    if ($ctBrandUserId > 0 && Database::scalar(
        "SELECT 1 FROM `kyc_profiles` WHERE `user_id` = :u AND `kind` = 'brand'",
        ['u' => $ctBrandUserId]
    ) === null) {
        // KYC approval has no public API; the throwaway brand is approved
        // directly, exactly as 04_bookings does, and the row removed below.
        Database::insert('kyc_profiles', [
            'user_id'              => $ctBrandUserId,
            'kind'                 => 'brand',
            'status'               => 'approved',
            'reference'            => 'KYC-IT-TOOLS-' . $ctBrandUserId,
            'submitted_at'         => date('Y-m-d H:i:s'),
            'approved_at'          => date('Y-m-d H:i:s'),
            'submission_count'     => 1,
            'completeness'         => 100,
            'is_identity_verified' => 1,
            'created_at'           => date('Y-m-d H:i:s'),
        ]);
        $ctKycInserted = true;
    }

    $ctCheapId = Database::insert('creator_packages', [
        'creator_id'       => $ctAId,
        'title'            => 'IT legacy cheap package',
        'platform'         => 'instagram',
        'deliverable_type' => 'post',
        'quantity'         => 1,
        'price'            => max(0.0, $ctMinPrice - 1),
        'currency'         => Money::defaultCurrency(),
        'turnaround_days'  => 3,
        'revisions'        => 1,
        'is_active'        => 1,
        'created_at'       => date('Y-m-d H:i:s'),
    ]);

    $cheap = Http::post('/bookings', [
        'creator_id'       => $ctAId,
        'title'            => 'IT tools legacy package booking',
        'platform'         => 'instagram',
        'deliverable_type' => 'post',
        'amount'           => $ctMinPrice + 1000,
        'package_id'       => $ctCheapId,
    ], 'tools-brand');
    Suite::check('a package priced under the booking minimum cannot be booked, whatever amount is typed',
        $cheap->status === 422 && str_contains($cheap->message(), 'package is priced under'), $cheap->describe());
    Suite::equals('and no booking was made', (int) Database::scalar(
        'SELECT COUNT(*) FROM `bookings` WHERE `creator_id` = :c',
        ['c' => $ctAId]
    ), 0);

    Database::execute('DELETE FROM `creator_packages` WHERE `id` = :id', ['id' => $ctCheapId]);
    Database::update('creators', ['status' => $ctCreatorStatus], ['id' => $ctAId]);
    $ctCreatorStatus = null;

    Suite::ok('the owner deletes it', Http::delete('/me/creator/packages/' . $packageId, null, 'tools-creator-a'), 200);
    Suite::status('a second delete is a 404', Http::delete('/me/creator/packages/' . $packageId, null, 'tools-creator-a'), 404);
    Suite::equals('and it is gone from the editor', Http::get('/me/creator/packages', [], 'tools-creator-a')->list('packages'), []);
    Suite::equals('deleting the last package clears the starting price', $ctStartingPrice($ctAId), 0.0);

    // -----------------------------------------------------------------
    Suite::group('Creator tools — portfolio');

    $ctSetModeration('1');

    $upload = Http::upload('/me/creator/portfolio', ['media' => $ctPng], [
        'media_kind'   => 'reel',
        'title'        => 'IT cover',
        'caption'      => 'Behind the scenes',
        'external_url' => 'https://www.instagram.com/reel/it-tools/',
    ], 'tools-creator-a');
    Suite::ok('a reel is added with its cover image', $upload, 201);
    $itemId = (int) $upload->at('data.item.id');
    $ctUploadedUrls[] = (string) $upload->at('data.item.media_url');

    Suite::check('the image was stored as an image, under the reel tab',
        $upload->at('data.item.media_kind') === 'reel' && str_contains((string) $upload->at('data.item.media_url'), '/portfolio/'),
        $upload->describe());
    Suite::equals('its shape is read from the upload', $upload->at('data.item.aspect_ratio'), '1:1');
    Suite::equals('the link records where it came from', $upload->at('data.item.source_platform'), 'instagram');
    Suite::equals('with moderation on it waits for review', $upload->at('data.item.moderation_status'), 'pending');
    Suite::check('and the creator is told so', str_contains($upload->message(), 'reviewed'), $upload->message());
    Suite::equals('adding has its own upload throttle', $upload->header('X-RateLimit-Limit'), '30');

    $mine = Http::get('/me/creator/portfolio', [], 'tools-creator-a');
    Suite::ok('the editor reads its own list', $mine, 200);
    Suite::equals('a pending item is listed for its owner', array_column($mine->list(), 'id'), [$itemId]);
    Suite::equals('paginated', $mine->at('meta.pagination.total'), 1);
    Suite::check('but not on the profile', !in_array($itemId, array_column((array) Http::get('/me/creator', [], 'tools-creator-a')->at('data.creator.recent_portfolio'), 'id'), true));

    $script = Http::post('/me/creator/portfolio', ['media_kind' => 'post', 'media_url' => 'javascript:alert(1)'], 'tools-creator-a');
    Suite::check('a media URL that is not http(s) is refused', $script->status === 422 && $script->at('errors.media_url') !== null, $script->describe());

    $noMedia = Http::post('/me/creator/portfolio', ['media_kind' => 'post', 'title' => 'Nothing attached'], 'tools-creator-a');
    Suite::status('an item with no image and no URL is refused', $noMedia, 422);

    $badKind = Http::post('/me/creator/portfolio', ['media_kind' => 'hologram', 'media_url' => 'https://example.com/a.jpg'], 'tools-creator-a');
    Suite::check('a kind outside the taxonomy is refused', $badKind->status === 422 && $badKind->at('errors.media_kind') !== null, $badKind->describe());

    $ctSetModeration('0');

    $linked = Http::post('/me/creator/portfolio', [
        'media_kind' => 'post',
        'media_url'  => 'https://example.com/it-tools/photo.jpg',
    ], 'tools-creator-a');
    Suite::ok('a pre-uploaded https URL is accepted', $linked, 201);
    Suite::equals('with moderation off it is approved at once', $linked->at('data.item.moderation_status'), 'approved');
    $linkedId = (int) $linked->at('data.item.id');

    $reason = (string) (Database::scalar(
        "SELECT tt.`value` FROM `taxonomy_terms` tt JOIN `taxonomies` t ON t.`id` = tt.`taxonomy_id`
          WHERE t.`key` = 'moderation_reason' ORDER BY tt.`sort_order` LIMIT 1"
    ) ?? '');

    if ($reason !== '') {
        Database::update('creator_portfolio', [
            'moderation_status' => 'rejected',
            'moderation_reason' => $reason,
            'moderation_note'   => 'IT internal note',
        ], ['id' => $linkedId]);

        $rows = Http::get('/me/creator/portfolio', [], 'tools-creator-a')->list();
        $row  = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $linkedId))[0] ?? [];

        Suite::check('a turned-down item says why, by the reason\'s label',
            ($row['moderation_status'] ?? '') === 'rejected' && is_string($row['reason_label'] ?? null) && $row['reason_label'] !== '',
            json_encode($row));
        Suite::check('and never carries the moderator\'s own note', !array_key_exists('moderation_note', $row)
            && !str_contains(json_encode($rows), 'IT internal note'));
    }

    // ---- Ownership -------------------------------------------------
    Suite::status("another creator cannot delete it", Http::delete('/me/creator/portfolio/' . $itemId, null, 'tools-creator-b'), 404);
    Suite::check('and it is still there', Database::scalar(
        'SELECT 1 FROM `creator_portfolio` WHERE `id` = :id AND `deleted_at` IS NULL',
        ['id' => $itemId]
    ) !== null);
    Suite::equals("another creator's list does not show it", Http::get('/me/creator/portfolio', [], 'tools-creator-b')->list(), []);
    Suite::status('a brand cannot read the editor', Http::get('/me/creator/portfolio', [], 'tools-brand'), 403);

    Suite::ok('the owner deletes it', Http::delete('/me/creator/portfolio/' . $itemId, null, 'tools-creator-a'), 200);
    Suite::status('a second delete is a 404', Http::delete('/me/creator/portfolio/' . $itemId, null, 'tools-creator-a'), 404);

    // ---- Ceiling (creator.max_portfolio_items, migration 057) -------
    if ($ctPortfolioCap !== null) {
        $remaining = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_portfolio` WHERE `creator_id` = :c AND `deleted_at` IS NULL',
            ['c' => $ctAId]
        );
        $ctSetPortfolioCap((string) max(1, $remaining));

        $full = Http::post('/me/creator/portfolio', [
            'media_kind' => 'post',
            'media_url'  => 'https://example.com/it-tools/one-too-many.jpg',
        ], 'tools-creator-a');
        Suite::check('past the ceiling an item is refused before anything is stored',
            $full->status === 422 && $full->code() === 'portfolio_full', $full->describe());
        Suite::equals('the editor reports the ceiling', (int) Http::get('/me/creator/portfolio', [], 'tools-creator-a')->at('meta.max_items'), max(1, $remaining));
        Suite::equals('and nothing was added', (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_portfolio` WHERE `creator_id` = :c AND `deleted_at` IS NULL',
            ['c' => $ctAId]
        ), $remaining);

        $ctSetPortfolioCap((string) $ctPortfolioCap);
    } else {
        Suite::skipGroup('Creator tools — portfolio ceiling', 'migration 057 has not been applied');
    }

    // -----------------------------------------------------------------
    Suite::group('Creator tools — social accounts');

    $social = Http::get('/me/creator/social', [], 'tools-creator-a');
    Suite::ok('the editor reads its own list', $social, 200);
    Suite::equals('it starts empty', $social->list('social_accounts'), []);
    Suite::equals('and says how many may be linked', $social->at('data.max_accounts'), Config::settingInt('creator', 'max_social_accounts', 20));

    $insta = Http::put('/me/creator/social', [
        'platform'        => 'instagram',
        'handle'          => '@it.tools.a',
        'profile_url'     => 'https://instagram.com/it.tools.a',
        'followers'       => 12000,
        'engagement_rate' => 3.4,
    ], 'tools-creator-a');
    Suite::ok('a creator links Instagram', $insta, 200);
    $instaRow = $insta->list('social_accounts')[0] ?? [];
    $instaId  = (int) ($instaRow['id'] ?? 0);
    Suite::check('stored without the @, labelled from the taxonomy',
        ($instaRow['handle'] ?? '') === 'it.tools.a' && ($instaRow['platform_label'] ?? '') === 'Instagram' && ($instaRow['has_metrics'] ?? false) === true,
        json_encode($instaRow));

    // "Link an account" for a network already linked used to overwrite that
    // row — handle, figures and verified mark — and answer "saved".
    Database::update('creator_social_accounts', ['is_verified' => 1], ['id' => $instaId]);
    $second = Http::put('/me/creator/social', [
        'platform'    => 'instagram',
        'handle'      => 'it.tools.other',
        'profile_url' => 'https://instagram.com/it.tools.other',
        'followers'   => 5,
    ], 'tools-creator-a');
    Suite::check('a second Instagram is refused, pointing at the one to edit',
        $second->status === 422 && str_contains((string) json_encode($second->at('errors.platform')), 'Edit it instead'),
        $second->describe());
    $instaNow = Database::selectOne(
        'SELECT `handle`, `followers`, `is_verified` FROM `creator_social_accounts` WHERE `id` = :id',
        ['id' => $instaId]
    ) ?? [];
    Suite::check('and the linked one keeps its handle, figures and verified mark',
        ($instaNow['handle'] ?? '') === 'it.tools.a' && (int) ($instaNow['followers'] ?? 0) === 12000 && (int) ($instaNow['is_verified'] ?? 0) === 1,
        json_encode($instaNow));
    Database::update('creator_social_accounts', ['is_verified' => 0], ['id' => $instaId]);

    // A label renamed the network wherever the account is shown.
    $dressed = Http::put('/me/creator/social', [
        'id'          => $instaId,
        'platform'    => 'instagram',
        'handle'      => 'it.tools.a',
        'profile_url' => 'https://instagram.com/it.tools.a',
        'label'       => 'Instagram - Verified by Zusty',
        'followers'   => 12000,
    ], 'tools-creator-a');
    $dressedRow = array_values(array_filter(
        $dressed->list('social_accounts'),
        static fn (array $r): bool => (int) $r['id'] === $instaId
    ))[0] ?? [];
    Suite::check('a network keeps its own name, whatever label is sent',
        $dressed->status === 200 && ($dressedRow['platform_label'] ?? '') === 'Instagram'
            && Database::scalar('SELECT `label` FROM `creator_social_accounts` WHERE `id` = :id', ['id' => $instaId]) === null,
        $dressed->describe() . ' ' . json_encode($dressedRow));

    // Figures on a website row were summed into the follower total.
    $site = ['platform' => 'website', 'handle' => 'it-tools.example.com', 'profile_url' => 'https://it-tools.example.com', 'followers' => 999999];
    Suite::ok('and a website', Http::put('/me/creator/social', $site, 'tools-creator-a'), 200);
    Suite::equals('which carries no follower figure', (int) Database::scalar(
        "SELECT `followers` FROM `creator_social_accounts` WHERE `creator_id` = :c AND `platform` = 'website'",
        ['c' => $ctAId]
    ), 0);
    $again = Http::put('/me/creator/social', $site, 'tools-creator-a');
    Suite::check('the same link twice is refused, not a 500', $again->status === 422 && $again->at('errors.handle') !== null, $again->describe());

    // A custom link (a term with custom_label) is shown under its own name,
    // so it needs one.
    $ctCustomTerm = (string) (Database::scalar(
        "SELECT tt.`value` FROM `taxonomy_terms` tt JOIN `taxonomies` t ON t.`id` = tt.`taxonomy_id`
          WHERE t.`key` = 'social_platform' AND tt.`is_active` = 1 AND tt.`metadata` LIKE '%\"custom_label\":true%'
          ORDER BY tt.`sort_order` LIMIT 1"
    ) ?? '');

    if ($ctCustomTerm !== '') {
        $custom   = ['platform' => $ctCustomTerm, 'handle' => 'it-tools.example.com/news', 'profile_url' => 'https://it-tools.example.com/news'];
        $unnamed  = Http::put('/me/creator/social', $custom, 'tools-creator-a');
        Suite::check('a custom link needs a name', $unnamed->status === 422 && $unnamed->at('errors.label') !== null, $unnamed->describe());

        $named    = Http::put('/me/creator/social', $custom + ['label' => 'IT newsletter'], 'tools-creator-a');
        $namedRow = array_values(array_filter(
            $named->list('social_accounts'),
            static fn (array $r): bool => ($r['platform'] ?? '') === $ctCustomTerm
        ))[0] ?? [];
        Suite::check('and is shown under it', $named->status === 200 && ($namedRow['platform_label'] ?? '') === 'IT newsletter',
            $named->describe());
    }

    $notNetwork = Http::put('/me/creator/social', ['platform' => 'upload', 'handle' => 'x', 'profile_url' => 'https://example.com'], 'tools-creator-a');
    Suite::check('`upload` is not a network', $notNetwork->status === 422 && $notNetwork->at('errors.platform') !== null, $notNetwork->describe());

    $moved = Http::put('/me/creator/social', [
        'id' => $instaId, 'platform' => 'youtube', 'handle' => 'it.tools.a', 'profile_url' => 'https://youtube.com/@it.tools.a',
    ], 'tools-creator-a');
    Suite::check('a link cannot move to another network', $moved->status === 422 && $moved->at('errors.platform') !== null, $moved->describe());

    Database::update('creator_social_accounts', ['is_verified' => 1], ['id' => $instaId]);
    $renamed = Http::put('/me/creator/social', [
        'id' => $instaId, 'platform' => 'instagram', 'handle' => 'it.tools.renamed', 'profile_url' => 'https://instagram.com/it.tools.renamed',
    ], 'tools-creator-a');
    Suite::ok('a creator renames a linked account', $renamed, 200);
    Suite::equals('which drops a verified mark that belonged to the old handle',
        (int) Database::scalar('SELECT `is_verified` FROM `creator_social_accounts` WHERE `id` = :id', ['id' => $instaId]), 0);

    // ---- Ownership -------------------------------------------------
    $snapshot = Database::select('SELECT `id`, `handle`, `profile_url`, `sort_order` FROM `creator_social_accounts` WHERE `creator_id` = :c ORDER BY `id`', ['c' => $ctAId]);
    $aIds     = array_map('intval', array_column($snapshot, 'id'));

    Suite::status("another creator cannot edit it", Http::put('/me/creator/social', [
        'id' => $instaId, 'platform' => 'instagram', 'handle' => 'hijacked', 'profile_url' => 'https://instagram.com/hijacked',
    ], 'tools-creator-b'), 404);
    Suite::status("or unlink it", Http::delete('/me/creator/social/' . $instaId, null, 'tools-creator-b'), 404);
    $foreignOrder = Http::put('/me/creator/social/order', ['ids' => array_reverse($aIds)], 'tools-creator-b');
    Suite::check("and reordering someone else's ids touches nothing of theirs",
        $foreignOrder->status === 200 && $foreignOrder->list('social_accounts') === [],
        $foreignOrder->describe());
    Suite::equals('the rows are untouched',
        Database::select('SELECT `id`, `handle`, `profile_url`, `sort_order` FROM `creator_social_accounts` WHERE `creator_id` = :c ORDER BY `id`', ['c' => $ctAId]),
        $snapshot);
    Suite::status('a brand cannot read the editor', Http::get('/me/creator/social', [], 'tools-brand'), 403);

    $reordered = Http::put('/me/creator/social/order', ['ids' => array_reverse($aIds)], 'tools-creator-a');
    Suite::ok('the owner reorders', $reordered, 200);
    Suite::equals('in the order sent', array_map('intval', array_column($reordered->list('social_accounts'), 'id')), array_reverse($aIds));

    $unlinked = Http::delete('/me/creator/social/' . $instaId, null, 'tools-creator-a');
    Suite::ok('the owner unlinks one', $unlinked, 200);
    Suite::check('and it is gone from the list', !in_array($instaId, array_map('intval', array_column($unlinked->list('social_accounts'), 'id')), true));
} finally {
    // -----------------------------------------------------------------
    Suite::group('Creator tools — cleanup');

    if ($ctModeration !== null) {
        $ctSetModeration((string) $ctModeration);
    }

    if ($ctPortfolioCap !== null) {
        $ctSetPortfolioCap((string) $ctPortfolioCap);
    }

    // Set only while the booking check ran; an abort in between leaves it.
    if ($ctCreatorStatus !== null) {
        Database::update('creators', ['status' => $ctCreatorStatus], ['id' => $ctAId]);
    }

    if ($ctKycInserted) {
        Database::execute(
            "DELETE FROM `kyc_profiles` WHERE `user_id` = :u AND `kind` = 'brand' AND `reference` LIKE 'KYC-IT-TOOLS-%'",
            ['u' => Actors::id('tools-brand')]
        );
    }

    $uploadRoot = rtrim((string) Config::get('storage.upload_path'), '/\\');

    foreach (array_filter($ctUploadedUrls) as $url) {
        $media = Database::selectOne('SELECT `id`, `path` FROM `media` WHERE `url` = :url', ['url' => $url]);

        if ($media === null) {
            continue;
        }

        if ($uploadRoot !== '' && is_file($uploadRoot . '/' . (string) $media['path'])) {
            @unlink($uploadRoot . '/' . (string) $media['path']);
        }

        Database::execute('DELETE FROM `media` WHERE `id` = :id', ['id' => (int) $media['id']]);
    }

    $ctWipe($ctAId);
    $ctWipe($ctBId);
    @unlink($ctPng);

    Suite::check('the editors left nothing behind', (int) Database::scalar(
        'SELECT (SELECT COUNT(*) FROM `creator_packages` WHERE `creator_id` IN (:a, :b))
              + (SELECT COUNT(*) FROM `creator_portfolio` WHERE `creator_id` IN (:a2, :b2))
              + (SELECT COUNT(*) FROM `creator_social_accounts` WHERE `creator_id` IN (:a3, :b3))',
        ['a' => $ctAId, 'b' => $ctBId, 'a2' => $ctAId, 'b2' => $ctBId, 'a3' => $ctAId, 'b3' => $ctBId]
    ) === 0);
}
