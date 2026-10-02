<?php

declare(strict_types=1);

/**
 * Admin → app synchronisation audit.
 *
 * WHAT THIS PROVES, AND HOW
 * -------------------------
 * The claim under test is: *an admin panel change reaches the installed apps
 * with no code change and no deploy.* Every check below establishes that in
 * three steps — read the API, make the change the admin panel makes, read the
 * API again — and then reverts and reads a third time, because a value that
 * "stuck" only because nothing was put back is not evidence of anything.
 *
 * ONE API, THREE CLIENTS — WHY THAT IS NOT A SHORTCUT
 * ---------------------------------------------------
 * Android, iOS and Flutter Web are one Dart codebase talking to one REST API
 * over the same `ApiClient`. There is no platform-specific content endpoint and
 * no platform-specific parsing: the payload that this suite reads over HTTP is
 * byte-for-byte the payload all three builds receive. Proving the API reflects
 * an admin write therefore proves it for all three — PROVIDED no client holds a
 * compiled-in value that shadows the server's. That proviso is the real risk,
 * and it is the one thing an HTTP suite cannot see; it is discharged separately
 * by the Dart side (the `const` option tables were removed in favour of
 * `RemoteConfig`, and the response cache is a cache, not a fallback constant).
 *
 * This file therefore deliberately does NOT pretend to drive three platforms.
 * Claiming an Android assertion here when only one HTTP socket was opened would
 * be dishonest. What it does claim, precisely:
 *
 *     the admin write lands in the database, the cache/version machinery the
 *     admin controller runs is run too, and the public REST response changes —
 *     and changes back.
 *
 * MUTATING THE WAY THE ADMIN PANEL MUTATES
 * ----------------------------------------
 * The admin panel is session + CSRF protected HTML, so the suite cannot POST to
 * it with a bearer token. Instead each group performs the SAME write the
 * matching controller performs, INCLUDING its side effects, read out of
 * app/Controllers/Admin:
 *
 *   TaxonomyController::afterWrite ....... TaxonomyService::flush()
 *   SettingsController::update ........... Config::flushSettings()
 *                                          NotificationService::flushTemplates()
 *   ResourceController::afterWrite ....... CreatorService::refreshStatistics()  (categories)
 *                                          NotificationService::flushTemplates() (faqs)
 *   EngagementController::updateTemplate . NotificationService::flushTemplates()
 *   UserController::moderateCreator ...... CreatorService::refreshStatistics($id)
 *   KycController::updateRequirement ..... (no cache — reads are uncached)
 *
 * Skipping a flush would make the test prove something the panel does not do.
 *
 * LEAVING THE DATABASE AS IT WAS FOUND
 * ------------------------------------
 * This runs against a seeded demo database. Every mutation is wrapped in
 * try/finally and reverted from a FULL-ROW snapshot — every column, `updated_at`
 * included. Restoring `updated_at` explicitly beats the column's
 * ON UPDATE CURRENT_TIMESTAMP, so a revert is byte-identical and version stamps
 * derived from MAX(updated_at) return to their baseline value. Rows the suite
 * creates itself (a popup, banners, an article) are hard-deleted.
 *
 * Verified by checksumming every touched table before and after a run: the only
 * row that differs is `users.last_seen_at` for the two `it-` throwaway actors,
 * which moves simply because they authenticated. No seeded demo row changes.
 *
 * THE THREE FAILURES THIS SUITE IS EXPECTED TO REPORT
 * ---------------------------------------------------
 * These are defects in the application, not in the suite. They are asserted
 * rather than omitted, because an audit that quietly skips the broken cases
 * reports a synchronisation guarantee the product does not have:
 *
 *   1. "onboarding slides are editable from the admin panel at all"
 *      `onboarding_slides` has no admin CRUD of any kind.
 *
 *   2. "a popup scoped to one screen is deliverable to the app"
 *      ContentController::appConfig calls AppContentService::popups() without a
 *      placement, so the `placement = 'all' OR placement = :placement` filter
 *      always compares against 'all'. Every placement the admin form offers
 *      except "Every screen" is undeliverable.
 *
 *   3. "a 'saved creators' rail is empty for a viewer who has saved nobody"
 *      AppContentService::creatorsFor() has no `case 'saved'`, so a rail an
 *      admin configured as "the viewer's saved creators" silently falls through
 *      to an unfiltered creator search.
 *
 * When those three are fixed, the whole file should go green with no edits here.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Services\CreatorService;
use Zuste\Services\NotificationService;
use Zuste\Services\TaxonomyService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// -----------------------------------------------------------------
// Shared helpers
// -----------------------------------------------------------------

/** Every column of a row, so a revert can put all of them back. */
$snapshot = static function (string $table, int $id): array {
    $row = Database::selectOne(
        'SELECT * FROM ' . Database::quoteIdentifier($table) . ' WHERE `id` = :id',
        ['id' => $id]
    );

    return $row ?? [];
};

/**
 * Put a snapshot back verbatim.
 *
 * `updated_at` is written explicitly on purpose: an explicit assignment wins
 * over ON UPDATE CURRENT_TIMESTAMP, so the row — and any version stamp computed
 * from MAX(updated_at) — returns to exactly its pre-test value.
 */
$restore = static function (string $table, array $row): void {
    if ($row === [] || !isset($row['id'])) {
        return;
    }

    $id = (int) $row['id'];
    unset($row['id']);

    if ($row === []) {
        return;
    }

    Database::update($table, $row, ['id' => $id]);
};

/** Pluck one column out of a list of rows. */
$pluck = static function (array $rows, string $key): array {
    return array_map(static fn (array $row): mixed => $row[$key] ?? null, $rows);
};

$brand   = Actors::create('brand', 'brand');
$creator = Actors::create('creator', 'creator');

// -----------------------------------------------------------------
Suite::group('Admin sync — taxonomies (option lists)');

$term = Database::selectOne(
    "SELECT tt.* FROM `taxonomy_terms` tt
       INNER JOIN `taxonomies` t ON t.`id` = tt.`taxonomy_id`
      WHERE t.`key` = 'industry' AND tt.`is_active` = 1
      ORDER BY tt.`sort_order`, tt.`id` LIMIT 1"
);

if ($term === null) {
    Suite::skipGroup(
        'Admin sync — taxonomies (option lists)',
        'the `industry` taxonomy has no active terms to relabel'
    );
} else {
    $termSnapshot = $snapshot('taxonomy_terms', (int) $term['id']);
    $termValue    = (string) $term['value'];
    $newLabel     = 'IT Relabelled Industry';

    try {
        $before        = Http::get('/app/taxonomies');
        $baselineEtag  = (string) $before->header('etag');
        $baselineStamp = (string) $before->at('data.version');

        Suite::ok('GET /app/taxonomies returns the bundle', $before);
        Suite::notEmpty('the bundle carries a version stamp', $baselineStamp);
        Suite::notEmpty('the bundle is served with an ETag', $baselineEtag);

        $labelFor = static function (\Zuste\Tests\Res $res, string $value): ?string {
            foreach ($res->at('data.taxonomies.industry') ?? [] as $row) {
                if (($row['value'] ?? null) === $value) {
                    return (string) ($row['label'] ?? '');
                }
            }

            return null;
        };

        Suite::equals(
            'the seeded label is what the app currently renders',
            $labelFor($before, $termValue),
            (string) $term['label']
        );

        // --- the admin write: relabel a term, then flush the bundle ------
        Database::update('taxonomy_terms', ['label' => $newLabel], ['id' => (int) $term['id']]);
        TaxonomyService::flush();

        $after = Http::get('/app/taxonomies');
        Suite::ok('GET /app/taxonomies still succeeds after the edit', $after);
        Suite::equals(
            'the relabelled term reaches the app with no deploy',
            $labelFor($after, $termValue),
            $newLabel
        );

        $editedStamp = (string) $after->at('data.version');
        $editedEtag  = (string) $after->header('etag');

        Suite::check(
            'the version stamp moved, so a cached client knows to refetch',
            $editedStamp !== '' && $editedStamp !== $baselineStamp,
            "stamp was {$baselineStamp}, is now {$editedStamp}"
        );
        Suite::check(
            'the ETag moved with it',
            $editedEtag !== '' && $editedEtag !== $baselineEtag,
            "etag was {$baselineEtag}, is now {$editedEtag}"
        );

        // --- conditional requests ---------------------------------------
        $stale = Http::get('/app/taxonomies', [], null, ['If-None-Match' => $baselineEtag]);
        Suite::status('a client holding the pre-edit ETag is served a fresh 200', $stale, 200);
        Suite::equals(
            'and that fresh body carries the new label',
            $labelFor($stale, $termValue),
            $newLabel
        );

        $fresh = Http::get('/app/taxonomies', [], null, ['If-None-Match' => $editedEtag]);
        Suite::status('a client holding the current ETag is answered 304', $fresh, 304);
        Suite::check(
            'the 304 carries no body',
            trim($fresh->body) === '' || $fresh->body === '[]',
            'expected an empty 304 body, got: ' . $fresh->describe()
        );

        $weak = Http::get('/app/taxonomies', [], null, ['If-None-Match' => 'W/' . $editedEtag]);
        Suite::status('a weak validator is honoured too', $weak, 304);

        // The single-list endpoint reads the same cached bundle.
        $single = Http::get('/app/taxonomies/industry');
        Suite::ok('GET /app/taxonomies/{key} returns one list', $single);
        Suite::check(
            'the single-list endpoint reflects the edit as well',
            in_array($newLabel, $pluck($single->at('data.terms') ?? [], 'label'), true),
            'labels were: ' . json_encode($pluck($single->at('data.terms') ?? [], 'label'))
        );
    } finally {
        $restore('taxonomy_terms', $termSnapshot);
        TaxonomyService::flush();
    }

    $reverted = Http::get('/app/taxonomies');

    Suite::equals(
        'reverting the label reaches the app as well',
        (static function (\Zuste\Tests\Res $res, string $value): ?string {
            foreach ($res->at('data.taxonomies.industry') ?? [] as $row) {
                if (($row['value'] ?? null) === $value) {
                    return (string) ($row['label'] ?? '');
                }
            }
            return null;
        })($reverted, $termValue),
        (string) $term['label']
    );
    Suite::equals(
        'and the version stamp returns to its pre-test value',
        (string) $reverted->at('data.version'),
        (string) $baselineStamp
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — settings');

$publicSetting = Database::selectOne(
    "SELECT * FROM `settings`
      WHERE `group_key` = 'general' AND `setting_key` = 'app_tagline' AND `is_public` = 1"
);

$privateSetting = Database::selectOne(
    "SELECT * FROM `settings`
      WHERE `group_key` = 'security' AND `setting_key` = 'jwt_access_ttl' AND `is_public` = 0"
);

if ($publicSetting === null) {
    Suite::skipGroup('Admin sync — settings', 'the public setting general.app_tagline is not seeded');
} else {
    $publicSnapshot  = $snapshot('settings', (int) $publicSetting['id']);
    $privateSnapshot = $privateSetting === null ? [] : $snapshot('settings', (int) $privateSetting['id']);
    $newTagline      = 'IT Tagline — changed from the admin panel';

    try {
        $before = Http::get('/settings');
        Suite::ok('GET /settings returns the public subset', $before);
        Suite::equals(
            'the seeded tagline is what the app currently shows',
            $before->at('data.settings.general.app_tagline'),
            (string) $publicSetting['value']
        );

        // --- the admin write --------------------------------------------
        Database::update('settings', ['value' => $newTagline], ['id' => (int) $publicSetting['id']]);
        Config::flushSettings();
        NotificationService::flushTemplates();

        $after = Http::get('/settings');
        Suite::equals(
            'flipping a public setting reaches the app with no deploy',
            $after->at('data.settings.general.app_tagline'),
            $newTagline
        );

        // /app/config reads the same setting through Config::settingString.
        $config = Http::get('/app/config');
        Suite::equals(
            'the same change is visible in the bootstrap payload',
            $config->at('data.app.tagline'),
            $newTagline
        );

        // --- a non-public setting must never appear ----------------------
        if ($privateSetting === null) {
            Suite::check(
                'a non-public setting was available to test against',
                false,
                'security.jwt_access_ttl is not seeded, so the leak check could not run'
            );
        } else {
            Suite::notEmpty(
                'the security group IS present in /settings (so absence is meaningful)',
                $after->at('data.settings.security.password_min_length')
            );
            Suite::check(
                'a non-public setting stays absent from /settings',
                $after->at('data.settings.security.jwt_access_ttl') === null,
                'jwt_access_ttl leaked: ' . json_encode($after->at('data.settings.security'))
            );

            // Touching it must not promote it either.
            Database::update(
                'settings',
                ['value' => (string) (((int) $privateSetting['value']) + 1)],
                ['id' => (int) $privateSetting['id']]
            );
            Config::flushSettings();

            $touched = Http::get('/settings');
            Suite::check(
                'editing a non-public setting still does not publish it',
                $touched->at('data.settings.security.jwt_access_ttl') === null,
                'jwt_access_ttl leaked after an edit: ' . json_encode($touched->at('data.settings.security'))
            );
        }
    } finally {
        $restore('settings', $publicSnapshot);
        $restore('settings', $privateSnapshot);
        Config::flushSettings();
        NotificationService::flushTemplates();
    }

    Suite::equals(
        'reverting the setting reaches the app too',
        Http::get('/settings')->at('data.settings.general.app_tagline'),
        (string) $publicSetting['value']
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — feature flags');

$flag = Database::selectOne(
    "SELECT * FROM `feature_flags`
      WHERE `audience` = 'all' AND `rollout_percent` = 100
        AND (`platforms` IS NULL OR `platforms` = '')
        AND (`min_app_version` IS NULL OR `min_app_version` = '')
      ORDER BY `id` LIMIT 1"
);

if ($flag === null) {
    Suite::skipGroup(
        'Admin sync — feature flags',
        'no unconditional flag is seeded, so a toggle could not be isolated from audience/rollout gating'
    );
} else {
    $flagSnapshot = $snapshot('feature_flags', (int) $flag['id']);
    $flagKey      = (string) $flag['flag_key'];
    $wasEnabled   = (int) $flag['is_enabled'] === 1;

    try {
        $before = Http::get('/app/config');
        Suite::ok('GET /app/config returns the bootstrap payload', $before);
        Suite::equals(
            "the flag {$flagKey} currently evaluates to its seeded state",
            $before->at('data.features.' . $flagKey),
            $wasEnabled
        );

        $baselineEtag = (string) $before->header('etag');

        // --- the admin write ---------------------------------------------
        Database::update('feature_flags', ['is_enabled' => $wasEnabled ? 0 : 1], ['id' => (int) $flag['id']]);

        $after = Http::get('/app/config');
        Suite::equals(
            'toggling a feature flag reaches the app with no deploy',
            $after->at('data.features.' . $flagKey),
            !$wasEnabled
        );
        Suite::check(
            'the /app/config ETag moved, so a revalidating client refetches',
            (string) $after->header('etag') !== '' && (string) $after->header('etag') !== $baselineEtag,
            'etag did not change: ' . $baselineEtag
        );

        // A flag scoped to the other side of the marketplace must not leak.
        //
        // Asserting this against a flag that happens to be switched off proves
        // nothing — it would read false whether audience gating worked or not.
        // So the flag is fully switched ON first, and only then is the brand
        // expected to be denied it.
        $creatorOnly = Database::selectOne(
            "SELECT * FROM `feature_flags`
              WHERE `audience` = 'creators'
                AND (`platforms` IS NULL OR `platforms` = '')
                AND (`min_app_version` IS NULL OR `min_app_version` = '')
              LIMIT 1"
        );

        if ($creatorOnly === null || $brand === null || $creator === null) {
            Suite::check(
                'a creators-only flag and both sessions were available for the audience check',
                false,
                'audience gating could not be isolated: no unconditional creators-only flag, or a missing session'
            );
        } else {
            $creatorOnlySnapshot = $snapshot('feature_flags', (int) $creatorOnly['id']);
            $creatorOnlyKey      = (string) $creatorOnly['flag_key'];

            try {
                Database::update(
                    'feature_flags',
                    ['is_enabled' => 1, 'rollout_percent' => 100],
                    ['id' => (int) $creatorOnly['id']]
                );

                Suite::equals(
                    'a fully enabled creators-only flag IS delivered to a creator',
                    Http::get('/app/config', [], 'creator')->at('data.features.' . $creatorOnlyKey),
                    true
                );
                Suite::equals(
                    'and is still withheld from a brand — audience gating, not the switch',
                    Http::get('/app/config', [], 'brand')->at('data.features.' . $creatorOnlyKey),
                    false
                );
            } finally {
                $restore('feature_flags', $creatorOnlySnapshot);
            }
        }
    } finally {
        $restore('feature_flags', $flagSnapshot);
    }

    Suite::equals(
        'reverting the flag reaches the app too',
        Http::get('/app/config')->at('data.features.' . $flagKey),
        $wasEnabled
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — popups');

$popupId = 0;

try {
    $before       = Http::get('/app/config');
    $baselineEtag = (string) $before->header('etag');
    $baselineIds  = $pluck($before->at('data.popups') ?? [], 'id');

    Suite::check(
        'GET /app/config exposes a popups list',
        is_array($before->at('data.popups')),
        $before->describe()
    );

    // --- the admin write: create a popup ---------------------------------
    $popupId = Database::insert('popups', [
        'name'             => 'IT Admin Sync Popup',
        'type'             => 'modal',
        'title'            => 'IT Popup Headline',
        'content'          => 'Added by the admin-sync audit.',
        'placement'        => 'all',
        'audience'         => 'all',
        'trigger_type'     => 'immediate',
        'frequency'        => 'once_per_session',
        'is_dismissible'   => 1,
        'is_active'        => 1,
        'priority'         => 500,
        'background_color' => '#B80049',
        'text_color'       => '#FFFFFF',
        'cta_action'       => 'none',
        'created_at'       => date('Y-m-d H:i:s'),
    ]);

    Suite::notEmpty('the popup row was created', $popupId);

    $after    = Http::get('/app/config');
    $afterIds = $pluck($after->at('data.popups') ?? [], 'id');

    Suite::check(
        'a popup added in the admin panel reaches the app with no deploy',
        in_array($popupId, $afterIds, true),
        'popups were: ' . json_encode($afterIds)
    );
    Suite::check(
        'adding a popup busts the /app/config ETag',
        (string) $after->header('etag') !== '' && (string) $after->header('etag') !== $baselineEtag,
        'etag did not change: ' . $baselineEtag
    );

    $delivered = [];
    foreach ($after->at('data.popups') ?? [] as $row) {
        if ((int) ($row['id'] ?? 0) === $popupId) {
            $delivered = $row;
        }
    }

    Suite::equals('the delivered popup carries the admin copy', $delivered['title'] ?? null, 'IT Popup Headline');
    Suite::equals('and the admin trigger settings', $delivered['trigger'] ?? null, 'immediate');

    // --- a popup scoped to one screen --------------------------------------
    //
    // The admin form offers Home / Search / Creator profile / Bookings /
    // Wallet / Checkout as placements. AppContentService::popups() filters on
    // `placement = 'all' OR placement = :placement`, but ContentController
    // calls it without a placement, so :placement is always the default 'all'
    // and nothing screen-scoped can ever match. /app/config exposes no
    // placement parameter either, so the client cannot ask for one.
    Database::update('popups', ['placement' => 'home'], ['id' => $popupId]);

    $scoped = Http::get('/app/config', ['placement' => 'home']);

    Suite::check(
        'a popup scoped to one screen is deliverable to the app',
        in_array($popupId, $pluck($scoped->at('data.popups') ?? [], 'id'), true),
        'a popup with placement=home was not delivered by any /app/config call — '
        . 'every placement other than "all" is undeliverable'
    );

    Database::update('popups', ['placement' => 'all'], ['id' => $popupId]);

    // --- deactivating it in the panel removes it -------------------------
    Database::update('popups', ['is_active' => 0], ['id' => $popupId]);

    $deactivated = Http::get('/app/config');
    Suite::check(
        'deactivating the popup removes it from the app',
        !in_array($popupId, $pluck($deactivated->at('data.popups') ?? [], 'id'), true),
        'the deactivated popup was still delivered'
    );

    // --- the panel's delete is a soft delete -----------------------------
    Database::update('popups', ['is_active' => 1, 'deleted_at' => date('Y-m-d H:i:s')], ['id' => $popupId]);

    Suite::check(
        'a soft-deleted popup is not delivered even while active',
        !in_array($popupId, $pluck(Http::get('/app/config')->at('data.popups') ?? [], 'id'), true),
        'a deleted popup was still delivered'
    );
} finally {
    if ($popupId > 0) {
        Database::delete('popups', ['id' => $popupId]);
    }
}

$restored = Http::get('/app/config');
Suite::equals(
    'removing the popup leaves the app exactly where it started',
    $pluck($restored->at('data.popups') ?? [], 'id'),
    $baselineIds ?? []
);

// -----------------------------------------------------------------
Suite::group('Admin sync — banners');

if ($brand === null) {
    Suite::skipGroup('Admin sync — banners', 'no brand session: ' . Actors::lastError());
} else {
    /** @var list<int> $bannerIds */
    $bannerIds = [];

    try {
        $before        = Http::get('/home', [], 'brand');
        Suite::ok('GET /home returns the home payload', $before);
        $baselineTitles = $pluck($before->at('data.banners') ?? [], 'title');

        $now = date('Y-m-d H:i:s');

        // Three banners that differ only in the two fields the app must honour.
        $heroForBrands = Database::insert('banners', [
            'title'      => 'IT Hero For Brands',
            'subtitle'   => 'Added by the admin-sync audit.',
            'image_url'  => 'https://cdn.zuste.test/it-hero-brands.png',
            'position'   => 'home_hero',
            'audience'   => 'brands',
            'is_active'  => 1,
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        $bannerIds[] = $heroForBrands;

        $midForBrands = Database::insert('banners', [
            'title'      => 'IT Mid For Brands',
            'image_url'  => 'https://cdn.zuste.test/it-mid-brands.png',
            'position'   => 'home_mid',
            'audience'   => 'brands',
            'is_active'  => 1,
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        $bannerIds[] = $midForBrands;

        $heroForCreators = Database::insert('banners', [
            'title'      => 'IT Hero For Creators',
            'image_url'  => 'https://cdn.zuste.test/it-hero-creators.png',
            'position'   => 'home_hero',
            'audience'   => 'creators',
            'is_active'  => 1,
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        $bannerIds[] = $heroForCreators;

        $after       = Http::get('/home', [], 'brand');
        $afterTitles = $pluck($after->at('data.banners') ?? [], 'title');

        Suite::check(
            'a banner added in the admin panel reaches the app with no deploy',
            in_array('IT Hero For Brands', $afterTitles, true),
            'banners were: ' . json_encode($afterTitles)
        );
        Suite::check(
            'the hero rail respects `position` — a home_mid banner stays out',
            !in_array('IT Mid For Brands', $afterTitles, true),
            'a home_mid banner surfaced in the hero rail'
        );
        Suite::check(
            'the hero rail respects `audience` — a creators banner stays out for a brand',
            !in_array('IT Hero For Creators', $afterTitles, true),
            'a creators-only banner was shown to a brand'
        );

        $anonTitles = $pluck(Http::get('/home')->at('data.banners') ?? [], 'title');
        Suite::check(
            'and a brands-only banner stays out for a signed-out visitor',
            !in_array('IT Hero For Brands', $anonTitles, true),
            'a brands-only banner was shown to a guest'
        );

        // The configured banner_carousel rail must see it too.
        $carousel = null;
        foreach ($after->at('data.sections') ?? [] as $section) {
            if (($section['type'] ?? '') === 'banner_carousel') {
                $carousel = $section;
            }
        }

        if ($carousel === null) {
            Suite::check(
                'the configured banner_carousel rail was found on /home',
                false,
                'no banner_carousel section is active, so its items could not be checked'
            );
        } else {
            Suite::check(
                'the configured banner rail picks the new banner up as well',
                in_array('IT Hero For Brands', $pluck($carousel['items'] ?? [], 'title'), true),
                'rail items were: ' . json_encode($pluck($carousel['items'] ?? [], 'title'))
            );
        }

        // --- deactivate ---------------------------------------------------
        Database::update('banners', ['is_active' => 0], ['id' => $heroForBrands]);

        Suite::check(
            'deactivating a banner removes it from the app',
            !in_array(
                'IT Hero For Brands',
                $pluck(Http::get('/home', [], 'brand')->at('data.banners') ?? [], 'title'),
                true
            ),
            'the deactivated banner was still served'
        );

        // --- scheduling ---------------------------------------------------
        Database::update('banners', [
            'is_active' => 1,
            'starts_at' => date('Y-m-d H:i:s', time() + 86400),
        ], ['id' => $heroForBrands]);

        Suite::check(
            'a banner scheduled to start tomorrow is not served today',
            !in_array(
                'IT Hero For Brands',
                $pluck(Http::get('/home', [], 'brand')->at('data.banners') ?? [], 'title'),
                true
            ),
            'a future-dated banner was served'
        );
    } finally {
        foreach ($bannerIds as $id) {
            Database::delete('banners', ['id' => $id]);
        }
    }

    Suite::equals(
        'removing the banners leaves /home exactly where it started',
        $pluck(Http::get('/home', [], 'brand')->at('data.banners') ?? [], 'title'),
        $baselineTitles ?? []
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — homepage sections');

$sections = Database::select(
    'SELECT * FROM `homepage_sections` WHERE `is_active` = 1 ORDER BY `sort_order`, `id`'
);

if ($brand === null) {
    Suite::skipGroup('Admin sync — homepage sections', 'no brand session: ' . Actors::lastError());
} elseif (count($sections) < 2) {
    Suite::skipGroup(
        'Admin sync — homepage sections',
        'fewer than two active homepage_sections rows are seeded, so a reorder proves nothing'
    );
} else {
    $first = $sections[0];
    $last  = $sections[count($sections) - 1];

    $firstSnapshot = $snapshot('homepage_sections', (int) $first['id']);
    $lastSnapshot  = $snapshot('homepage_sections', (int) $last['id']);

    try {
        $before         = Http::get('/home', [], 'brand');
        $baselineTitles = $pluck($before->at('data.sections') ?? [], 'title');

        Suite::notEmpty('GET /home returns the configured rails', $baselineTitles);
        Suite::equals(
            'the first rail is the lowest sort_order the admin panel shows',
            $baselineTitles[0] ?? null,
            (string) $first['title']
        );

        // --- reorder: send the last rail to the top -----------------------
        Database::update(
            'homepage_sections',
            ['sort_order' => ((int) $first['sort_order']) - 1],
            ['id' => (int) $last['id']]
        );

        $reordered = $pluck(Http::get('/home', [], 'brand')->at('data.sections') ?? [], 'title');

        Suite::equals(
            'reordering rails in the admin panel reorders the app Home with no deploy',
            $reordered[0] ?? null,
            (string) $last['title']
        );
        Suite::check(
            'the rail that used to be first is still present, just lower',
            in_array((string) $first['title'], $reordered, true),
            'sections were: ' . json_encode($reordered)
        );

        $restore('homepage_sections', $lastSnapshot);

        Suite::equals(
            'reverting the order reaches the app too',
            $pluck(Http::get('/home', [], 'brand')->at('data.sections') ?? [], 'title'),
            $baselineTitles
        );

        // --- deactivate a rail --------------------------------------------
        Database::update('homepage_sections', ['is_active' => 0], ['id' => (int) $first['id']]);

        $withoutFirst = $pluck(Http::get('/home', [], 'brand')->at('data.sections') ?? [], 'title');

        Suite::check(
            'deactivating a rail removes it from the app Home',
            !in_array((string) $first['title'], $withoutFirst, true),
            'sections were: ' . json_encode($withoutFirst)
        );
        Suite::equals(
            'and only that rail went',
            count($withoutFirst),
            count($baselineTitles) - 1
        );

        // --- retitle -------------------------------------------------------
        Database::update(
            'homepage_sections',
            ['is_active' => 1, 'title' => 'IT Renamed Rail'],
            ['id' => (int) $first['id']]
        );

        Suite::check(
            'retitling a rail reaches the app',
            in_array(
                'IT Renamed Rail',
                $pluck(Http::get('/home', [], 'brand')->at('data.sections') ?? [], 'title'),
                true
            ),
            'the renamed rail did not appear'
        );

        // --- the "saved creators" data source ---------------------------------
        //
        // The admin form offers "The viewer's saved creators" as a data source.
        // AppContentService::creatorsFor() has no `case 'saved'`, so it falls
        // through to an unfiltered CreatorService::search — an admin who picks
        // this gets a generic creator list instead, with no indication that the
        // setting did nothing.
        $favourites = (int) (Database::scalar(
            'SELECT COUNT(*) FROM `favorites` WHERE `user_id` = :id',
            ['id' => Actors::id('brand')]
        ) ?? 0);

        if ($favourites > 0) {
            Suite::check(
                'the brand actor had no saved creators, so the "saved" rail could be isolated',
                false,
                "the brand actor has {$favourites} favourite(s) left by an earlier suite, "
                . 'so an unfiltered rail is indistinguishable from a correct one here'
            );
        } else {
            Database::update(
                'homepage_sections',
                ['section_type' => 'creator_carousel', 'data_source' => 'saved', 'source_value' => null],
                ['id' => (int) $first['id']]
            );

            $savedRail = null;
            foreach (Http::get('/home', [], 'brand')->at('data.sections') ?? [] as $section) {
                if ((int) ($section['id'] ?? 0) === (int) $first['id']) {
                    $savedRail = $section;
                }
            }

            Suite::check(
                'a "saved creators" rail is empty for a viewer who has saved nobody',
                $savedRail === null || ($savedRail['items'] ?? []) === [],
                'the rail returned ' . count($savedRail['items'] ?? []) . ' creators for a viewer with no '
                . 'favourites — `saved` is not implemented in AppContentService::creatorsFor(), so it '
                . 'silently renders a generic search instead'
            );
        }

        $restore('homepage_sections', $firstSnapshot);

        // --- audience -------------------------------------------------------
        Database::update('homepage_sections', ['audience' => 'creators', 'title' => 'IT Renamed Rail'], ['id' => (int) $first['id']]);

        Suite::check(
            'a rail scoped to creators is not sent to a brand',
            !in_array(
                'IT Renamed Rail',
                $pluck(Http::get('/home', [], 'brand')->at('data.sections') ?? [], 'title'),
                true
            ),
            'a creators-only rail was sent to a brand'
        );
    } finally {
        $restore('homepage_sections', $firstSnapshot);
        $restore('homepage_sections', $lastSnapshot);
    }

    Suite::equals(
        'the rails are back exactly as they were',
        $pluck(Http::get('/home', [], 'brand')->at('data.sections') ?? [], 'title'),
        $baselineTitles ?? []
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — categories');

$category = Database::selectOne(
    'SELECT * FROM `categories`
      WHERE `is_active` = 1 AND `is_featured` = 1 AND `deleted_at` IS NULL
      ORDER BY `sort_order`, `id` LIMIT 1'
);

if ($category === null) {
    Suite::skipGroup('Admin sync — categories', 'no active featured category is seeded');
} else {
    $categorySnapshot = $snapshot('categories', (int) $category['id']);
    $categoryId       = (int) $category['id'];
    $renamed          = 'IT Renamed Category';

    try {
        $before = Http::get('/categories');
        Suite::ok('GET /categories returns the discovery list', $before);

        $nameFor = static function (\Zuste\Tests\Res $res, int $id): ?string {
            foreach ($res->list('categories') as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return (string) ($row['name'] ?? '');
                }
            }

            return null;
        };

        Suite::equals(
            'the seeded name is what the app currently renders',
            $nameFor($before, $categoryId),
            (string) $category['name']
        );

        // --- the admin write: rename, then recount (ResourceController) ----
        Database::update('categories', ['name' => $renamed], ['id' => $categoryId]);
        CreatorService::refreshStatistics();

        Suite::equals(
            'renaming a category reaches the app with no deploy',
            $nameFor(Http::get('/categories'), $categoryId),
            $renamed
        );

        if ($brand !== null) {
            Suite::check(
                'and the Home category strip picks the new name up too',
                in_array($renamed, $pluck(Http::get('/home', [], 'brand')->at('data.categories') ?? [], 'name'), true),
                'the renamed category did not reach /home'
            );
        }

        // --- deactivate -----------------------------------------------------
        Database::update('categories', ['is_active' => 0], ['id' => $categoryId]);
        CreatorService::refreshStatistics();

        Suite::check(
            'deactivating a category removes it from discovery',
            $nameFor(Http::get('/categories'), $categoryId) === null,
            'the deactivated category was still listed'
        );

        // --- un-feature ------------------------------------------------------
        Database::update('categories', ['is_active' => 1, 'is_featured' => 0], ['id' => $categoryId]);
        CreatorService::refreshStatistics();

        $home = $brand === null ? Http::get('/home') : Http::get('/home', [], 'brand');

        Suite::check(
            'un-featuring a category drops it from Home but keeps it in /categories',
            !in_array($categoryId, array_map('intval', $pluck($home->at('data.categories') ?? [], 'id')), true)
                && $nameFor(Http::get('/categories'), $categoryId) !== null,
            'un-feature did not behave as the admin panel describes it'
        );
    } finally {
        $restore('categories', $categorySnapshot);
        CreatorService::refreshStatistics();
    }

    Suite::equals(
        'reverting the category reaches the app too',
        (static function (\Zuste\Tests\Res $res, int $id): ?string {
            foreach ($res->list('categories') as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return (string) ($row['name'] ?? '');
                }
            }
            return null;
        })(Http::get('/categories'), $categoryId),
        (string) $category['name']
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — CMS pages');

$page = Database::selectOne(
    "SELECT * FROM `pages` WHERE `is_active` = 1 AND `show_in_app` = 1 ORDER BY `sort_order`, `id` LIMIT 1"
);

if ($page === null) {
    Suite::skipGroup('Admin sync — CMS pages', 'no in-app page is seeded');
} else {
    $pageSnapshot = $snapshot('pages', (int) $page['id']);
    $slug         = (string) $page['slug'];

    try {
        $before = Http::get('/pages/' . $slug);
        Suite::ok('GET /pages/{slug} returns the page', $before);
        Suite::equals(
            'the seeded title is what the app currently renders',
            $before->at('data.page.title'),
            (string) $page['title']
        );

        Database::update('pages', [
            'title'   => 'IT Renamed Page',
            'content' => '<p>Rewritten by the admin-sync audit.</p>',
        ], ['id' => (int) $page['id']]);

        $after = Http::get('/pages/' . $slug);
        Suite::equals('editing a page reaches the app with no deploy', $after->at('data.page.title'), 'IT Renamed Page');
        Suite::check(
            'the body copy came through as well',
            str_contains((string) $after->at('data.page.content'), 'admin-sync audit'),
            'content was: ' . mb_substr((string) $after->at('data.page.content'), 0, 120)
        );
        Suite::check(
            'and the page index shows the new title',
            in_array('IT Renamed Page', $pluck(Http::get('/pages')->list('pages'), 'title'), true),
            'the renamed page did not appear in /pages'
        );

        // --- unpublish -------------------------------------------------------
        Database::update('pages', ['is_active' => 0], ['id' => (int) $page['id']]);

        Suite::status('unpublishing a page makes it 404 in the app', Http::get('/pages/' . $slug), 404);
        Suite::check(
            'and it leaves the page index',
            !in_array($slug, $pluck(Http::get('/pages')->list('pages'), 'slug'), true),
            'an unpublished page was still listed'
        );

        // --- hide from the app but keep it on the website ---------------------
        Database::update('pages', ['is_active' => 1, 'show_in_app' => 0], ['id' => (int) $page['id']]);

        Suite::check(
            'clearing "show in app" hides it from the index but keeps it reachable by slug',
            !in_array($slug, $pluck(Http::get('/pages')->list('pages'), 'slug'), true)
                && Http::get('/pages/' . $slug)->status === 200,
            'show_in_app did not behave as the admin panel describes it'
        );
    } finally {
        $restore('pages', $pageSnapshot);
    }

    Suite::equals(
        'reverting the page reaches the app too',
        Http::get('/pages/' . $slug)->at('data.page.title'),
        (string) $page['title']
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — FAQs');

$faq = Database::selectOne('SELECT * FROM `faqs` WHERE `is_active` = 1 ORDER BY `sort_order`, `id` LIMIT 1');

if ($faq === null) {
    Suite::skipGroup('Admin sync — FAQs', 'no active FAQ is seeded');
} else {
    $faqSnapshot = $snapshot('faqs', (int) $faq['id']);
    $faqId       = (int) $faq['id'];

    $questionFor = static function (\Zuste\Tests\Res $res, int $id): ?string {
        foreach ($res->list('faqs') as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return (string) ($row['question'] ?? '');
            }
        }

        return null;
    };

    try {
        $before = Http::get('/faqs');
        Suite::ok('GET /faqs returns the help content', $before);
        Suite::equals(
            'the seeded question is what the app currently renders',
            $questionFor($before, $faqId),
            (string) $faq['question']
        );

        Database::update('faqs', [
            'question' => 'IT Edited question?',
            'answer'   => 'Edited by the admin-sync audit.',
        ], ['id' => $faqId]);
        NotificationService::flushTemplates();

        $after = Http::get('/faqs');
        Suite::equals('editing an FAQ reaches the app with no deploy', $questionFor($after, $faqId), 'IT Edited question?');

        // --- move it to another category -------------------------------------
        $otherCategory = (string) $faq['category'] === 'general' ? 'wallet' : 'general';
        Database::update('faqs', ['category' => $otherCategory], ['id' => $faqId]);

        Suite::check(
            'the category filter follows the admin change',
            $questionFor(Http::get('/faqs', ['category' => $otherCategory]), $faqId) !== null
                && $questionFor(Http::get('/faqs', ['category' => (string) $faq['category']]), $faqId) === null,
            'the FAQ did not move between categories'
        );

        // --- deactivate ---------------------------------------------------------
        Database::update('faqs', ['is_active' => 0], ['id' => $faqId]);

        Suite::check(
            'deactivating an FAQ removes it from the app',
            $questionFor(Http::get('/faqs'), $faqId) === null,
            'the deactivated FAQ was still served'
        );
        Suite::status(
            'and it can no longer be voted on',
            Http::post('/faqs/' . $faqId . '/helpful', ['helpful' => true]),
            404
        );
    } finally {
        $restore('faqs', $faqSnapshot);
        NotificationService::flushTemplates();
    }

    Suite::equals(
        'reverting the FAQ reaches the app too',
        $questionFor(Http::get('/faqs'), $faqId),
        (string) $faq['question']
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — blog articles');

$blogBaseline = (int) (Database::scalar('SELECT COUNT(*) FROM `blogs`') ?? 0);
$blogId       = 0;

try {
    $slug = 'it-admin-sync-article';

    Database::execute('DELETE FROM `blogs` WHERE `slug` = :slug', ['slug' => $slug]);

    $blogId = Database::insert('blogs', [
        'slug'            => $slug,
        'title'           => 'IT Admin Sync Article',
        'excerpt'         => 'Published by the admin-sync audit.',
        'content'         => '<p>Published by the admin-sync audit.</p>',
        'author_name'     => 'IT Admin',
        'status'          => 'published',
        'published_at'    => date('Y-m-d H:i:s'),
        'reading_minutes' => 3,
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    Suite::notEmpty('the article row was created', $blogId);

    // The index orders `is_featured DESC, published_at DESC` and pages at 10.
    // There are already ten featured articles, so a new one can never reach
    // page 1 — asking whether it is there tested the seed data, not the
    // publish. Worse, the two absence checks below then passed for a slug that
    // was never in the list to begin with. Scoping by the title makes all
    // three assert what they claim to.
    $blogIndex = static fn (): array => $pluck(
        Http::get('/blogs', ['q' => 'IT Admin Sync Article'])->list(),
        'slug'
    );

    $index = Http::get('/blogs');
    Suite::ok('GET /blogs returns the article index', $index);
    Suite::check(
        'publishing an article reaches the app with no deploy',
        in_array($slug, $blogIndex(), true),
        'the index returned: ' . json_encode($blogIndex())
    );

    $detail = Http::get('/blogs/' . $slug);
    Suite::ok('and the article is readable by slug', $detail);
    Suite::equals('with the admin copy', $detail->at('data.blog.title'), 'IT Admin Sync Article');

    // --- unpublish -----------------------------------------------------------
    Database::update('blogs', ['status' => 'draft'], ['id' => $blogId]);

    Suite::check(
        'unpublishing an article removes it from the index',
        !in_array($slug, $blogIndex(), true),
        'a draft article was still listed'
    );
    Suite::status('and makes it 404 by slug', Http::get('/blogs/' . $slug), 404);

    // --- schedule ------------------------------------------------------------
    Database::update('blogs', [
        'status'       => 'published',
        'published_at' => date('Y-m-d H:i:s', time() + 86400),
    ], ['id' => $blogId]);

    Suite::check(
        'an article scheduled for tomorrow is embargoed in both the index and by slug',
        !in_array($slug, $blogIndex(), true)
            && Http::get('/blogs/' . $slug)->status === 404,
        'a future-dated article leaked'
    );
} finally {
    if ($blogId > 0) {
        Database::delete('blogs', ['id' => $blogId]);
    }
}

Suite::equals(
    'the suite left no article rows behind',
    (int) (Database::scalar('SELECT COUNT(*) FROM `blogs`') ?? 0),
    $blogBaseline
);

// -----------------------------------------------------------------
Suite::group('Admin sync — testimonials');

$testimonial = Database::selectOne(
    'SELECT * FROM `testimonials` WHERE `is_active` = 1 ORDER BY `is_featured` DESC, `sort_order`, `id` LIMIT 1'
);

if ($testimonial === null) {
    Suite::skipGroup('Admin sync — testimonials', 'no active testimonial is seeded');
} else {
    $testimonialSnapshot = $snapshot('testimonials', (int) $testimonial['id']);
    $originalAuthor      = (string) $testimonial['author_name'];

    try {
        $before = Http::get('/testimonials');
        Suite::ok('GET /testimonials returns the social proof', $before);
        Suite::check(
            'the seeded author is what the app currently renders',
            in_array($originalAuthor, $pluck($before->list('testimonials'), 'author_name'), true),
            'authors were: ' . json_encode($pluck($before->list('testimonials'), 'author_name'))
        );

        Database::update('testimonials', [
            'author_name' => 'IT Edited Author',
            'content'     => 'Rewritten by the admin-sync audit so the change is unmistakable.',
        ], ['id' => (int) $testimonial['id']]);

        $after = Http::get('/testimonials');
        Suite::check(
            'editing a testimonial reaches the app with no deploy',
            in_array('IT Edited Author', $pluck($after->list('testimonials'), 'author_name'), true),
            'authors were: ' . json_encode($pluck($after->list('testimonials'), 'author_name'))
        );

        Database::update('testimonials', ['is_active' => 0], ['id' => (int) $testimonial['id']]);

        Suite::check(
            'deactivating a testimonial removes it from the app',
            !in_array('IT Edited Author', $pluck(Http::get('/testimonials')->list('testimonials'), 'author_name'), true),
            'the deactivated testimonial was still served'
        );
    } finally {
        $restore('testimonials', $testimonialSnapshot);
    }

    Suite::check(
        'reverting the testimonial reaches the app too',
        in_array($originalAuthor, $pluck(Http::get('/testimonials')->list('testimonials'), 'author_name'), true),
        'the original testimonial did not come back'
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — onboarding slides');

// Every other group in this file mirrors a real admin controller. This one
// cannot: `onboarding_slides` is read by GET /app/onboarding but has no
// Resource definition, no entry in the routes/admin.php resource whitelist and
// no controller of its own — so the rows below are edited with raw SQL because
// raw SQL is the ONLY way to edit them. The checks that follow prove the API
// serves whatever the table says; they do not prove an admin can change it,
// because an admin cannot. That gap is asserted first.
$slidesResource = null;

foreach (\Zuste\Admin\Resource::registry() as $key => $definition) {
    if ($definition->table === 'onboarding_slides') {
        $slidesResource = $key;
    }
}

Suite::check(
    'onboarding slides are editable from the admin panel at all',
    $slidesResource !== null,
    'no admin resource, route or controller manages `onboarding_slides` — GET /app/onboarding '
    . 'serves the table but nothing in app/Controllers/Admin can write to it, so the slides are '
    . 'only changeable with direct SQL'
);

$slide = Database::selectOne(
    'SELECT * FROM `onboarding_slides` WHERE `is_active` = 1 ORDER BY `sort_order`, `id` LIMIT 1'
);

if ($slide === null) {
    Suite::skipGroup('Admin sync — onboarding slides', 'no active onboarding slide is seeded');
} else {
    $slideSnapshot = $snapshot('onboarding_slides', (int) $slide['id']);
    $slideId       = (int) $slide['id'];

    $titleFor = static function (\Zuste\Tests\Res $res, int $id): ?string {
        foreach ($res->list('slides') as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return (string) ($row['title'] ?? '');
            }
        }

        return null;
    };

    try {
        $before = Http::get('/app/onboarding');
        Suite::ok('GET /app/onboarding returns the intro slides', $before);
        Suite::equals(
            'the seeded slide copy is what the app currently renders',
            $titleFor($before, $slideId),
            (string) $slide['title']
        );

        Database::update('onboarding_slides', [
            'title'    => 'IT Edited Slide',
            'subtitle' => 'Rewritten by the admin-sync audit.',
        ], ['id' => $slideId]);

        Suite::equals(
            'editing a slide reaches the app with no deploy',
            $titleFor(Http::get('/app/onboarding'), $slideId),
            'IT Edited Slide'
        );

        // --- audience -------------------------------------------------------
        Database::update('onboarding_slides', ['audience' => 'creators'], ['id' => $slideId]);

        Suite::check(
            'a creators-only slide is not served on the brand path',
            $titleFor(Http::get('/app/onboarding', ['audience' => 'brands']), $slideId) === null
                && $titleFor(Http::get('/app/onboarding', ['audience' => 'creators']), $slideId) !== null,
            'the slide audience filter did not apply'
        );

        // --- deactivate -------------------------------------------------------
        Database::update('onboarding_slides', ['audience' => 'all', 'is_active' => 0], ['id' => $slideId]);

        Suite::check(
            'deactivating a slide removes it from the app',
            $titleFor(Http::get('/app/onboarding'), $slideId) === null,
            'the deactivated slide was still served'
        );
    } finally {
        $restore('onboarding_slides', $slideSnapshot);
    }

    Suite::equals(
        'reverting the slide reaches the app too',
        $titleFor(Http::get('/app/onboarding'), $slideId),
        (string) $slide['title']
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — KYC requirements');

$requirement = Database::selectOne(
    "SELECT * FROM `kyc_requirements`
      WHERE `kind` = 'creator' AND `is_active` = 1 AND `is_required` = 1
      ORDER BY `sort_order`, `id` LIMIT 1"
);

if ($requirement === null) {
    Suite::skipGroup('Admin sync — KYC requirements', 'no active mandatory creator requirement is seeded');
} else {
    $requirementSnapshot = $snapshot('kyc_requirements', (int) $requirement['id']);
    $slot                = (string) $requirement['slot'];

    $rowFor = static function (\Zuste\Tests\Res $res, string $slot): ?array {
        foreach ($res->list('requirements') as $row) {
            if (($row['slot'] ?? null) === $slot) {
                return $row;
            }
        }

        return null;
    };

    try {
        $before = Http::get('/kyc/requirements', ['kind' => 'creator']);
        Suite::ok('GET /kyc/requirements returns the public rulebook', $before);
        Suite::equals('the slot is currently mandatory', $rowFor($before, $slot)['is_required'] ?? null, true);

        // --- make it optional ------------------------------------------------
        Database::update('kyc_requirements', ['is_required' => 0], ['id' => (int) $requirement['id']]);

        $after = Http::get('/kyc/requirements', ['kind' => 'creator']);
        Suite::equals(
            'making a document optional reaches the app with no deploy',
            $rowFor($after, $slot)['is_required'] ?? null,
            false
        );

        // --- relabel and resize ------------------------------------------------
        Database::update('kyc_requirements', [
            'label'       => 'IT Renamed Requirement',
            'max_size_mb' => 7,
        ], ['id' => (int) $requirement['id']]);

        $relabelled = $rowFor(Http::get('/kyc/requirements', ['kind' => 'creator']), $slot);
        Suite::equals('the new label reaches the app', $relabelled['label'] ?? null, 'IT Renamed Requirement');
        Suite::equals('and the new upload limit', $relabelled['max_size_mb'] ?? null, 7);

        // --- deactivate ----------------------------------------------------------
        Database::update('kyc_requirements', ['is_active' => 0], ['id' => (int) $requirement['id']]);

        Suite::check(
            'deactivating a requirement removes the slot entirely',
            $rowFor(Http::get('/kyc/requirements', ['kind' => 'creator']), $slot) === null,
            'the deactivated slot was still published'
        );
        Suite::check(
            'and the brand rulebook was never touched',
            count(Http::get('/kyc/requirements', ['kind' => 'brand'])->list('requirements')) > 0,
            'the brand requirements list came back empty'
        );
    } finally {
        $restore('kyc_requirements', $requirementSnapshot);
    }

    $reverted = $rowFor(Http::get('/kyc/requirements', ['kind' => 'creator']), $slot);
    Suite::equals('reverting the requirement reaches the app too', $reverted['is_required'] ?? null, true);
    Suite::equals('with its original label', $reverted['label'] ?? null, (string) $requirement['label']);
}

// -----------------------------------------------------------------
Suite::group('Admin sync — notification templates');

$template = Database::selectOne("SELECT * FROM `notification_templates` WHERE `code` = 'message.new'");

if ($brand === null || $creator === null) {
    Suite::skipGroup(
        'Admin sync — notification templates',
        'both a brand and a creator session are needed to make a real notification: ' . Actors::lastError()
    );
} elseif ($template === null) {
    Suite::skipGroup('Admin sync — notification templates', 'the message.new template is not seeded');
} else {
    $templateSnapshot = $snapshot('notification_templates', (int) $template['id']);

    $maxConversation = (int) (Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `conversations`') ?? 0);
    $maxMessage      = (int) (Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `messages`') ?? 0);
    $maxNotification = (int) (Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `notifications`') ?? 0);

    // An earlier suite may already have opened this pair's direct thread. If so
    // the rows survive the cleanup below (their ids are not "new"), so their
    // counters — last_message_at, unread_count — are snapshotted and put back.
    $existingThread = Database::selectOne(
        "SELECT c.* FROM `conversations` c
           INNER JOIN `conversation_participants` p1 ON p1.`conversation_id` = c.`id` AND p1.`user_id` = :a
           INNER JOIN `conversation_participants` p2 ON p2.`conversation_id` = c.`id` AND p2.`user_id` = :b
          WHERE c.`type` = 'direct' LIMIT 1",
        ['a' => Actors::id('brand'), 'b' => Actors::id('creator')]
    );

    $existingParticipants = $existingThread === null ? [] : Database::select(
        'SELECT * FROM `conversation_participants` WHERE `conversation_id` = :id',
        ['id' => (int) $existingThread['id']]
    );

    try {
        $start = Http::post('/conversations/start', ['user_id' => Actors::id('creator')], 'brand');
        Suite::ok('a brand can open a conversation with a creator', $start);

        $conversationId = (int) ($start->at('data.conversation_id') ?? 0);

        if ($conversationId === 0) {
            Suite::check('the conversation fixture was created', false, $start->describe());
        } else {
            $sent = Http::post(
                "/conversations/{$conversationId}/messages",
                ['body' => 'IT admin-sync probe, before the template edit.'],
                'brand'
            );
            Suite::ok('the brand can send a message', $sent, [200, 201]);

            $inbox = Http::get('/notifications', ['category' => 'message'], 'creator');
            Suite::ok('the creator can read their notifications', $inbox);

            $latest = $inbox->list()[0] ?? ($inbox->list('notifications')[0] ?? []);

            Suite::equals(
                'the notification uses the seeded template copy',
                $latest['title'] ?? null,
                (string) $template['title']
            );

            // --- the admin write ------------------------------------------
            Database::update('notification_templates', [
                'title' => 'IT Edited Notification Title',
            ], ['id' => (int) $template['id']]);
            NotificationService::flushTemplates();

            Http::post(
                "/conversations/{$conversationId}/messages",
                ['body' => 'IT admin-sync probe, after the template edit.'],
                'brand'
            );

            $afterInbox  = Http::get('/notifications', ['category' => 'message'], 'creator');
            $afterLatest = $afterInbox->list()[0] ?? ($afterInbox->list('notifications')[0] ?? []);

            Suite::equals(
                'editing the template changes the copy of the next notification sent',
                $afterLatest['title'] ?? null,
                'IT Edited Notification Title'
            );

            // --- deactivating a template stops the notification -------------
            Database::update('notification_templates', ['is_active' => 0], ['id' => (int) $template['id']]);
            NotificationService::flushTemplates();

            $countBefore = (int) (Database::scalar(
                "SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `type` = 'message.new'",
                ['id' => Actors::id('creator')]
            ) ?? 0);

            Http::post(
                "/conversations/{$conversationId}/messages",
                ['body' => 'IT admin-sync probe, template disabled.'],
                'brand'
            );

            $countAfter = (int) (Database::scalar(
                "SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `type` = 'message.new'",
                ['id' => Actors::id('creator')]
            ) ?? 0);

            Suite::equals('disabling a template stops the notification being written', $countAfter, $countBefore);
        }
    } finally {
        $restore('notification_templates', $templateSnapshot);
        NotificationService::flushTemplates();

        // Remove only what this group created.
        foreach ([
            ['message_receipts', '`message_id` > :id', $maxMessage],
            ['notifications', '`id` > :id', $maxNotification],
            ['messages', '`id` > :id', $maxMessage],
            ['conversation_participants', '`conversation_id` > :id', $maxConversation],
            ['conversations', '`id` > :id', $maxConversation],
        ] as [$table, $where, $marker]) {
            try {
                Database::execute(
                    'DELETE FROM ' . Database::quoteIdentifier($table) . ' WHERE ' . $where,
                    ['id' => $marker]
                );
            } catch (\Throwable) {
                // A schema without that table, or a cascade already handled it.
            }
        }

        // Put a pre-existing thread's counters back where they were.
        if ($existingThread !== null) {
            $restore('conversations', $existingThread);

            foreach ($existingParticipants as $participant) {
                Database::update(
                    'conversation_participants',
                    array_diff_key($participant, array_flip(['conversation_id', 'user_id'])),
                    [
                        'conversation_id' => (int) $participant['conversation_id'],
                        'user_id'         => (int) $participant['user_id'],
                    ]
                );
            }
        }
    }

    Suite::equals(
        'the template copy is back to the seeded value',
        (string) (Database::scalar(
            'SELECT `title` FROM `notification_templates` WHERE `id` = :id',
            ['id' => (int) $template['id']]
        ) ?? ''),
        (string) $template['title']
    );
    Suite::equals(
        'and the suite left no conversation rows behind',
        (int) (Database::scalar('SELECT COUNT(*) FROM `conversations` WHERE `id` > :id', ['id' => $maxConversation]) ?? 0),
        0
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — navigation menus');

// The admin menu builder wrote 92 rows that nothing ever read: no API path
// selected from `menu_items`, so an operator could reorder the app drawer all
// day and no installed app would notice. These checks exist to make sure that
// cannot regress quietly — they assert the whole loop, admin write through to
// what the app is served, including the ETag that decides whether the app even
// looks at the new payload.

$drawerMenu = Database::selectOne(
    "SELECT * FROM `menus` WHERE `location` = 'app_drawer' AND `is_active` = 1 ORDER BY `id` LIMIT 1"
);

if ($drawerMenu === null) {
    Suite::skipGroup('Admin sync — navigation menus', 'no active app_drawer menu is seeded');
} else {
    $menuId    = (int) $drawerMenu['id'];
    $menuItems = Database::select(
        'SELECT * FROM `menu_items` WHERE `menu_id` = :id ORDER BY `sort_order`',
        ['id' => $menuId]
    );

    Suite::notEmpty('the app drawer menu has items', count($menuItems));

    // A seed with no idempotency guard grew this table by seven rows on every
    // install. Migration 027's unique key is what stops that, so the duplicate
    // count is asserted rather than assumed.
    $duplicates = (int) (Database::scalar(
        'SELECT COUNT(*) FROM (
             SELECT 1 FROM `menu_items`
              GROUP BY `menu_id`, COALESCE(`parent_id`, 0), `label`
             HAVING COUNT(*) > 1
         ) d'
    ) ?? 0);

    Suite::equals('no menu item is duplicated within its level', $duplicates, 0);

    $config = Http::get('/app/config');
    Suite::ok('GET /app/config carries the menus block', $config);

    $drawer = $config->at('data.menus.app_drawer');

    Suite::check(
        'the admin-built drawer reaches the app',
        is_array($drawer) && $drawer !== [],
        'menus block was: ' . json_encode($config->at('data.menus'))
    );

    if (is_array($drawer) && $drawer !== []) {
        Suite::check(
            'every entry carries what the client needs to render it',
            !array_filter(
                $drawer,
                static fn (array $row): bool => ($row['label'] ?? '') === ''
                    || !array_key_exists('link_type', $row)
                    || !array_key_exists('children', $row)
            ),
            'an entry arrived without a label, a link type or a children array'
        );

        // Web-only locations must not be in a mobile payload.
        Suite::check(
            'server-rendered locations are not shipped to the app',
            !array_intersect(
                ['web_header', 'web_footer', 'admin'],
                array_keys((array) $config->at('data.menus'))
            ),
            'locations were: ' . implode(', ', array_keys((array) $config->at('data.menus')))
        );
    }

    // --- the audience boundary ----------------------------------------------
    $brandOnly = Database::selectOne(
        "SELECT * FROM `menu_items` WHERE `menu_id` = :id AND `audience` = 'brands' LIMIT 1",
        ['id' => $menuId]
    );

    if ($brandOnly !== null) {
        $anonLabels = array_map(
            static fn (array $row): string => (string) ($row['label'] ?? ''),
            (array) ($config->at('data.menus.app_drawer') ?? [])
        );

        Suite::check(
            'a brand-only entry is withheld from a signed-out caller',
            !in_array((string) $brandOnly['label'], $anonLabels, true),
            'anonymous callers were offered: ' . implode(', ', $anonLabels)
        );

        if ($brand !== null) {
            $asBrand = Http::get('/app/config', [], 'brand');
            $brandLabels = array_map(
                static fn (array $row): string => (string) ($row['label'] ?? ''),
                (array) ($asBrand->at('data.menus.app_drawer') ?? [])
            );

            Suite::check(
                'and offered to a signed-in brand',
                in_array((string) $brandOnly['label'], $brandLabels, true),
                'the brand was offered: ' . implode(', ', $brandLabels)
            );
        }
    }

    // --- an edit has to change the validator ---------------------------------
    $firstItem = $menuItems[0] ?? null;

    if ($firstItem !== null) {
        $itemSnapshot = $snapshot('menu_items', (int) $firstItem['id']);
        $beforeEtag   = $config->header('etag');

        Database::update(
            'menu_items',
            ['label' => 'IT Renamed Entry'],
            ['id' => (int) $firstItem['id']]
        );

        $after = Http::get('/app/config');

        Suite::check(
            'renaming an item changes the ETag',
            $after->header('etag') !== null && $after->header('etag') !== $beforeEtag,
            'the validator did not move, so every revalidating client keeps the old menu'
        );

        $renamed = array_map(
            static fn (array $row): string => (string) ($row['label'] ?? ''),
            (array) ($after->at('data.menus.app_drawer') ?? [])
        );

        Suite::check(
            'and the new label reaches the app',
            in_array('IT Renamed Entry', $renamed, true),
            'labels were: ' . implode(', ', $renamed)
        );

        // A client holding the old validator must be sent the new body, not a
        // 304 — that is the whole point of stamping menu_items into the ETag.
        $stale = Http::get('/app/config', [], null, ['If-None-Match' => (string) $beforeEtag]);
        Suite::check(
            'a client on the old validator is not answered 304',
            $stale->status !== 304,
            'the app would have kept serving the previous menu'
        );

        $restore('menu_items', $itemSnapshot);

        $deactivated = $snapshot('menu_items', (int) $firstItem['id']);
        Database::update('menu_items', ['is_active' => 0], ['id' => (int) $firstItem['id']]);

        $hidden = array_map(
            static fn (array $row): string => (string) ($row['label'] ?? ''),
            (array) (Http::get('/app/config')->at('data.menus.app_drawer') ?? [])
        );

        Suite::check(
            'deactivating an item removes it from the app',
            !in_array((string) $itemSnapshot['label'], $hidden, true),
            'a deactivated entry was still served'
        );

        $restore('menu_items', $deactivated);
    }

    // --- deactivating the whole menu -----------------------------------------
    $menuSnapshot = $snapshot('menus', $menuId);
    Database::update('menus', ['is_active' => 0], ['id' => $menuId]);

    Suite::check(
        'deactivating the menu removes the whole location',
        (Http::get('/app/config')->at('data.menus.app_drawer') ?? []) === [],
        'an inactive menu was still served'
    );

    $restore('menus', $menuSnapshot);

    Suite::check(
        'and reactivating brings it back',
        (Http::get('/app/config')->at('data.menus.app_drawer') ?? []) !== [],
        'the menu did not come back after the restore'
    );
}

// -----------------------------------------------------------------
Suite::group('Admin sync — creator moderation');

$targetCreator = Database::selectOne(
    "SELECT c.* FROM `creators` c
       INNER JOIN `users` u ON u.`id` = c.`user_id`
      WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL AND u.`status` = 'active'
      ORDER BY c.`id` LIMIT 1"
);

if ($targetCreator === null) {
    Suite::skipGroup('Admin sync — creator moderation', 'no approved creator is seeded to suspend');
} else {
    $creatorSnapshot = $snapshot('creators', (int) $targetCreator['id']);
    $handle          = (string) $targetCreator['handle'];
    $creatorId       = (int) $targetCreator['id'];

    $maxView = (int) (Database::scalar('SELECT COALESCE(MAX(`id`), 0) FROM `creator_profile_views`') ?? 0);

    try {
        $before = Http::get('/creators/' . $handle, [], 'brand');
        Suite::ok('an approved creator is publicly readable', $before);

        $listed = Http::get('/creators', ['q' => $handle, 'per_page' => 50], 'brand');
        Suite::check(
            'and appears in discovery',
            in_array($creatorId, array_map('intval', $pluck($listed->list(), 'id')), true),
            'search for the handle returned: ' . json_encode($pluck($listed->list(), 'id'))
        );

        // --- the admin write: suspend, then recount (UserController) --------
        Database::update('creators', [
            'status'           => 'suspended',
            'rejection_reason' => 'IT admin-sync audit',
            'approved_by'      => null,
            'approved_at'      => null,
        ], ['id' => $creatorId]);
        CreatorService::refreshStatistics($creatorId);

        Suite::status(
            'suspending a creator removes them from the public profile route',
            Http::get('/creators/' . $handle, [], 'brand'),
            404
        );

        $afterList = Http::get('/creators', ['q' => $handle, 'per_page' => 50], 'brand');
        Suite::check(
            'and from discovery',
            !in_array($creatorId, array_map('intval', $pluck($afterList->list(), 'id')), true),
            'a suspended creator was still discoverable'
        );

        Suite::check(
            'and from the Home rails',
            !in_array(
                $creatorId,
                array_map('intval', $pluck(Http::get('/home', [], 'brand')->at('data.top_creators') ?? [], 'id')),
                true
            ),
            'a suspended creator was still on the Home rail'
        );
    } finally {
        $restore('creators', $creatorSnapshot);
        CreatorService::refreshStatistics($creatorId);

        try {
            Database::execute(
                'DELETE FROM `creator_profile_views` WHERE `id` > :id',
                ['id' => $maxView]
            );
        } catch (\Throwable) {
            // Nothing to clean up.
        }
    }

    Suite::ok('reinstating the creator restores public access', Http::get('/creators/' . $handle, [], 'brand'));
    Suite::equals(
        'the creator row is byte-identical to how it was found',
        Database::selectOne('SELECT * FROM `creators` WHERE `id` = :id', ['id' => $creatorId]),
        $creatorSnapshot
    );
}
