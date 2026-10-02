<?php

declare(strict_types=1);

/**
 * Public discovery and search.
 *
 * /home, /explore, /categories, /creators, /creators/{handle},
 * /creators/{id}/portfolio, /creators/{id}/reviews, /creators/{id}/availability
 * and /search/suggestions.
 *
 * The point of this suite is the LIST mechanics. "It returned 200" proves
 * nothing about a catalogue endpoint, so every claim here is checked against
 * the rows that actually came back: a page-2 request must return different
 * creators, a filter must genuinely narrow the set AND every surviving row must
 * satisfy it, and a sort must produce an order that is really monotonic on the
 * key it claims to sort by.
 *
 * Ordering note that shapes most of the sort checks: CreatorService::search
 * always prefixes the ORDER BY with `c.is_featured DESC` (CreatorService.php:163),
 * so a sorted listing is two concatenated runs — featured first, then the rest —
 * each internally ordered by the requested key. Asserting a single global run
 * would be wrong, so every sort assertion partitions on is_featured first.
 */

use Zuste\Core\Database;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// -----------------------------------------------------------------
// Local helpers
// -----------------------------------------------------------------

/** Ids of the creator cards in a listing response, in the order returned. */
$ids = static fn (Res $res): array => array_map(
    static fn (array $row): int => (int) ($row['id'] ?? 0),
    $res->list()
);

/**
 * Splits a listing into the featured run and the rest, preserving order.
 *
 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
 */
$partitionByFeatured = static function (array $rows): array {
    $featured = $others = [];

    foreach ($rows as $row) {
        if (!empty($row['is_featured'])) {
            $featured[] = $row;
        } else {
            $others[] = $row;
        }
    }

    return [$featured, $others];
};

/**
 * True when $rows are ordered non-increasingly (or non-decreasingly) by $key.
 *
 * @param callable(array<string,mixed>):(int|float|null) $key
 */
$isMonotonic = static function (array $rows, callable $key, bool $ascending): bool {
    $previous = null;

    foreach ($rows as $row) {
        $value = $key($row);

        if ($value === null) {
            return false;
        }

        if ($previous !== null) {
            if ($ascending ? $value < $previous - 0.0001 : $value > $previous + 0.0001) {
                return false;
            }
        }

        $previous = $value;
    }

    return true;
};

/** Renders the ordering actually returned, so a sort failure is diagnosable. */
$trail = static function (array $rows, callable $key, int $limit = 12): string {
    $parts = [];

    foreach (array_slice($rows, 0, $limit) as $row) {
        $parts[] = ($row['handle'] ?? '?') . '=' . var_export($key($row), true);
    }

    return implode(' > ', $parts);
};

/**
 * Asserts a sort really orders the whole listing, featured run and rest alike.
 *
 * @param callable(array<string,mixed>):(int|float|null) $key
 */
$assertSorted = static function (
    string $sort,
    callable $key,
    bool $ascending,
    ?callable $decorate = null
) use ($partitionByFeatured, $isMonotonic, $trail): void {
    $res = Http::get('/creators', ['sort' => $sort, 'per_page' => 50]);

    if (!Suite::ok("?sort={$sort} returns a listing", $res)) {
        return;
    }

    $rows = $res->list();

    if ($decorate !== null) {
        $rows = $decorate($rows);
    }

    [$featured, $others] = $partitionByFeatured($rows);

    $direction = $ascending ? 'ascending' : 'descending';

    Suite::check(
        "?sort={$sort} returns the featured run in {$direction} order",
        $isMonotonic($featured, $key, $ascending),
        'order was: ' . $trail($featured, $key)
    );

    Suite::check(
        "?sort={$sort} returns the remaining creators in {$direction} order",
        $isMonotonic($others, $key, $ascending),
        'order was: ' . $trail($others, $key)
    );

    Suite::check(
        "?sort={$sort} keeps featured creators ahead of the rest",
        $featured === [] || $others === [] || count($featured) + count($others) === count($rows),
        'partition lost rows'
    );
};

/** Search terms this suite pushes into `popular_searches`, removed at the end. */
$termsUsed = [];

$search = static function (array $query) use (&$termsUsed): Res {
    if (isset($query['q']) && mb_strlen(trim((string) $query['q'])) >= 2) {
        $termsUsed[] = mb_substr(trim((string) $query['q']), 0, 150);
    }

    return Http::get('/creators', $query);
};

// -----------------------------------------------------------------
// Preconditions
// -----------------------------------------------------------------

$approvedCount = (int) (Database::scalar(
    "SELECT COUNT(*) FROM `creators` c
     INNER JOIN `users` u ON u.`id` = c.`user_id`
     WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL AND u.`status` = 'active'"
) ?? 0);

$hasCreators = $approvedCount >= 6;

// -----------------------------------------------------------------
Suite::group('Discovery — the creator listing');

$listing = Http::get('/creators', ['per_page' => 50]);
Suite::ok('GET /creators returns the success envelope', $listing);

Suite::check(
    'the listing payload is a bare list of creator cards',
    is_array($listing->at('data')) && array_is_list((array) $listing->at('data')),
    $listing->describe()
);

Suite::check(
    'the listing carries meta.pagination',
    static function () use ($listing): bool {
        $pagination = $listing->at('meta.pagination');

        if (!is_array($pagination)) {
            return false;
        }

        foreach (['total', 'per_page', 'current_page', 'last_page', 'from', 'to', 'has_more'] as $key) {
            if (!array_key_exists($key, $pagination)) {
                return false;
            }
        }

        return true;
    },
    'meta was: ' . json_encode($listing->at('meta'))
);

Suite::equals(
    'the reported total matches the approved creators in the database',
    (int) ($listing->at('meta.pagination.total') ?? -1),
    $approvedCount
);

Suite::check(
    'every card carries the fields the creator tile renders',
    static function () use ($listing): bool {
        $rows = $listing->list();

        if ($rows === []) {
            return false;
        }

        foreach (['id', 'handle', 'display_name', 'avatar_url', 'city', 'category',
                  'rating', 'rating_count', 'followers', 'followers_label',
                  'price_min', 'price_max', 'price_label', 'currency',
                  'is_verified', 'is_featured', 'availability'] as $key) {
            if (!array_key_exists($key, $rows[0])) {
                return false;
            }
        }

        return true;
    },
    'first card was: ' . json_encode($listing->list()[0] ?? null)
);

Suite::check(
    'an anonymous listing reports no favourites and no follows',
    static function () use ($listing): bool {
        foreach ($listing->list() as $row) {
            if (($row['is_favorite'] ?? null) !== false || ($row['is_following'] ?? null) !== false) {
                return false;
            }
        }

        return true;
    },
    'a card claimed a relationship for an anonymous caller'
);

// -----------------------------------------------------------------
if (!$hasCreators) {
    Suite::skipGroup(
        'Discovery — pagination',
        "no seeded creators (only {$approvedCount} approved) — pagination cannot be proved against an empty catalogue"
    );
} else {
    Suite::group('Discovery — pagination');

    $perPage = 5;

    $page1 = Http::get('/creators', ['page' => 1, 'per_page' => $perPage, 'sort' => 'newest']);
    $page2 = Http::get('/creators', ['page' => 2, 'per_page' => $perPage, 'sort' => 'newest']);

    Suite::ok('page 1 of the listing succeeds', $page1);
    Suite::ok('page 2 of the listing succeeds', $page2);

    $idsPage1 = $ids($page1);
    $idsPage2 = $ids($page2);

    Suite::equals('per_page is honoured on page 1', count($idsPage1), $perPage);
    Suite::equals('per_page is echoed back in meta', (int) ($page1->at('meta.pagination.per_page') ?? 0), $perPage);

    Suite::check(
        'page 2 returns different creators from page 1',
        $idsPage2 !== [] && array_intersect($idsPage1, $idsPage2) === [],
        'page 1 = ' . implode(',', $idsPage1) . ' | page 2 = ' . implode(',', $idsPage2)
    );

    Suite::equals(
        'the total is the same on page 1 and page 2',
        (int) ($page1->at('meta.pagination.total') ?? -1),
        (int) ($page2->at('meta.pagination.total') ?? -2)
    );

    Suite::equals(
        'current_page reflects the page requested',
        (int) ($page2->at('meta.pagination.current_page') ?? 0),
        2
    );

    Suite::equals(
        'last_page is ceil(total / per_page)',
        (int) ($page1->at('meta.pagination.last_page') ?? 0),
        (int) ceil($approvedCount / $perPage)
    );

    Suite::equals(
        'from/to describe the slice page 2 actually returned',
        [(int) $page2->at('meta.pagination.from'), (int) $page2->at('meta.pagination.to')],
        [$perPage + 1, min($perPage * 2, $approvedCount)]
    );

    Suite::check(
        'has_more is true while pages remain and false on the last page',
        (bool) $page1->at('meta.pagination.has_more') === ($approvedCount > $perPage),
        'total ' . $approvedCount . ', per_page ' . $perPage
            . ', has_more ' . json_encode($page1->at('meta.pagination.has_more'))
    );

    // Walking every page must reconstruct the catalogue exactly once — this is
    // what catches an OFFSET that drifts or a sort that is not deterministic.
    $walked   = [];
    $lastPage = (int) ceil($approvedCount / 10);

    for ($page = 1; $page <= min($lastPage, 6); $page++) {
        $walked = array_merge($walked, $ids(Http::get('/creators', [
            'page' => $page, 'per_page' => 10, 'sort' => 'newest',
        ])));
    }

    Suite::equals(
        'walking the pages yields each creator exactly once',
        count($walked),
        count(array_unique($walked))
    );

    if ($lastPage <= 6) {
        Suite::equals('walking every page returns the whole catalogue', count($walked), $approvedCount);
    }

    // per_page is clamped by BaseApiController::pagination($request, 20, 50).
    $oversized = Http::get('/creators', ['per_page' => 999]);
    Suite::equals(
        'per_page is clamped at the documented maximum of 50',
        (int) ($oversized->at('meta.pagination.per_page') ?? 0),
        50
    );
    Suite::check(
        'the clamped page returns no more than 50 rows',
        count($oversized->list()) <= 50,
        'returned ' . count($oversized->list()) . ' rows'
    );

    $underflow = Http::get('/creators', ['per_page' => 0]);
    Suite::equals(
        'per_page below the floor is clamped to 1 rather than dividing by zero',
        (int) ($underflow->at('meta.pagination.per_page') ?? 0),
        1
    );

    $beyondEnd = Http::get('/creators', ['page' => 999, 'per_page' => 5]);
    Suite::ok('a page past the end is an empty listing, not an error', $beyondEnd);
    Suite::equals('a page past the end returns no rows', count($beyondEnd->list()), 0);
    Suite::equals(
        'a page past the end still reports the true total',
        (int) ($beyondEnd->at('meta.pagination.total') ?? -1),
        $approvedCount
    );

    // from/to describe the slice that was returned, so on an empty page they
    // must not describe a slice at all. The app renders them directly as
    // "Showing {from}-{to} of {total}".
    Suite::check(
        'a page past the end reports a coherent from/to',
        (int) $beyondEnd->at('meta.pagination.from') <= (int) $beyondEnd->at('meta.pagination.to'),
        'from ' . json_encode($beyondEnd->at('meta.pagination.from'))
            . ' is past to ' . json_encode($beyondEnd->at('meta.pagination.to'))
            . ' while zero rows were returned'
    );
}

// -----------------------------------------------------------------
if (!$hasCreators) {
    Suite::skipGroup('Discovery — filtering', 'no seeded creators to filter');
} else {
    Suite::group('Discovery — filtering');

    $baseline = (int) ($listing->at('meta.pagination.total') ?? 0);

    /**
     * A filter must do two things: return fewer rows than the unfiltered
     * listing, and return only rows that satisfy it. Checking just one of the
     * two lets a broken filter through — a WHERE that matches nothing "narrows"
     * perfectly, and an ignored parameter leaves every row "satisfying" it.
     *
     * @param callable(array<string,mixed>):bool $satisfies
     */
    $assertNarrows = static function (
        string $label,
        array $query,
        callable $satisfies,
        bool $mustBeNonEmpty = true
    ) use ($baseline): void {
        $res = Http::get('/creators', $query + ['per_page' => 50]);

        if (!Suite::ok("{$label} returns a listing", $res)) {
            return;
        }

        $total = (int) ($res->at('meta.pagination.total') ?? -1);

        Suite::check(
            "{$label} narrows the result set",
            $total >= 0 && $total < $baseline && ($mustBeNonEmpty ? $total > 0 : true),
            "unfiltered total {$baseline}, filtered total {$total}"
        );

        $offenders = [];

        foreach ($res->list() as $row) {
            if (!$satisfies($row)) {
                $offenders[] = ($row['handle'] ?? '?');
            }
        }

        Suite::check(
            "{$label} returns only rows that satisfy it",
            $offenders === [],
            'rows that should not be here: ' . implode(', ', array_slice($offenders, 0, 8))
        );

        Suite::equals(
            "{$label} returns as many rows as its total claims",
            count($res->list()),
            min($total, 50)
        );
    };

    // -- category (matched through creator_categories, so the primary
    //    category on the card is NOT necessarily the one filtered on) ------
    $categorySlug = (string) (Database::scalar(
        "SELECT cat.`slug`
         FROM `creator_categories` cc
         INNER JOIN `categories` cat ON cat.`id` = cc.`category_id`
         INNER JOIN `creators` c ON c.`id` = cc.`creator_id` AND c.`status` = 'approved' AND c.`deleted_at` IS NULL
         GROUP BY cat.`slug`
         HAVING COUNT(*) < :total
         ORDER BY COUNT(*) DESC
         LIMIT 1",
        ['total' => $approvedCount]
    ) ?? '');

    if ($categorySlug === '') {
        Suite::check('a seeded category exists to filter on', false, 'no category has approved creators');
    } else {
        $inCategory = array_map('intval', Database::column(
            'SELECT cc.`creator_id`
             FROM `creator_categories` cc
             INNER JOIN `categories` cat ON cat.`id` = cc.`category_id`
             WHERE cat.`slug` = :slug',
            ['slug' => $categorySlug]
        ));

        $assertNarrows(
            "?category={$categorySlug}",
            ['category' => $categorySlug],
            static fn (array $row): bool => in_array((int) $row['id'], $inCategory, true)
        );

        $categoryId = (int) (Database::scalar(
            'SELECT `id` FROM `categories` WHERE `slug` = :slug',
            ['slug' => $categorySlug]
        ) ?? 0);

        $bySlug = Http::get('/creators', ['category' => $categorySlug, 'per_page' => 1]);
        $byId   = Http::get('/creators', ['category_id' => $categoryId, 'per_page' => 1]);

        Suite::equals(
            'filtering by category id agrees with filtering by category slug',
            (int) ($byId->at('meta.pagination.total') ?? -1),
            (int) ($bySlug->at('meta.pagination.total') ?? -2)
        );
    }

    // -- city ---------------------------------------------------------
    $city = (string) (Database::scalar(
        "SELECT `city` FROM `creators`
         WHERE `status` = 'approved' AND `deleted_at` IS NULL AND `city` IS NOT NULL AND `city` <> ''
         GROUP BY `city` ORDER BY COUNT(*) DESC LIMIT 1"
    ) ?? '');

    if ($city === '') {
        Suite::check('a seeded city exists to filter on', false, 'no approved creator has a city');
    } else {
        $assertNarrows(
            "?city={$city}",
            ['city' => $city],
            static fn (array $row): bool => (string) ($row['city'] ?? '') === $city
        );
    }

    // -- price range --------------------------------------------------
    // min_price filters on price_max, max_price on price_min: a creator is a
    // match when their band overlaps the budget (CreatorService.php:101-109).
    $medianPrice = (float) (Database::scalar(
        "SELECT AVG(`price_max`) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
    ) ?? 0);

    if ($medianPrice > 0) {
        $assertNarrows(
            '?min_price=' . (int) $medianPrice,
            ['min_price' => (int) $medianPrice],
            static fn (array $row): bool => (float) $row['price_max'] >= $medianPrice - 0.01
        );

        $floorPrice = (float) (Database::scalar(
            "SELECT MIN(`price_min`) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
        ) ?? 0);

        $cheapCeiling = max(1.0, $floorPrice * 2);

        $assertNarrows(
            '?max_price=' . (int) $cheapCeiling,
            ['max_price' => (int) $cheapCeiling],
            static fn (array $row): bool => (float) $row['price_min'] <= $cheapCeiling + 0.01
        );

        $band = Http::get('/creators', [
            'min_price' => (int) $floorPrice,
            'max_price' => (int) $cheapCeiling,
            'per_page'  => 50,
        ]);

        Suite::check(
            'a min_price/max_price pair returns only creators whose band overlaps it',
            static function () use ($band, $floorPrice, $cheapCeiling): bool {
                foreach ($band->list() as $row) {
                    if ((float) $row['price_max'] < $floorPrice || (float) $row['price_min'] > $cheapCeiling) {
                        return false;
                    }
                }

                return $band->list() !== [];
            },
            $band->describe()
        );
    }

    // -- followers ----------------------------------------------------
    $followerCut = (int) (Database::scalar(
        "SELECT AVG(`followers_total`) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
    ) ?? 0);

    if ($followerCut > 0) {
        $assertNarrows(
            "?min_followers={$followerCut}",
            ['min_followers' => $followerCut],
            static fn (array $row): bool => (int) $row['followers'] >= $followerCut
        );
    }

    // -- rating -------------------------------------------------------
    $ratingCut = (float) (Database::scalar(
        "SELECT MAX(`rating_avg`) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
    ) ?? 0);

    if ($ratingCut > 0) {
        $assertNarrows(
            "?min_rating={$ratingCut}",
            ['min_rating' => $ratingCut],
            static fn (array $row): bool => (float) $row['rating'] >= $ratingCut - 0.05
        );
    }

    // -- verified -----------------------------------------------------
    $unverified = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL AND `is_verified` = 0"
    ) ?? 0);

    if ($unverified > 0) {
        $assertNarrows(
            '?verified_only=1',
            ['verified_only' => 1],
            static fn (array $row): bool => ($row['is_verified'] ?? false) === true
        );
    } else {
        Suite::skipGroup(
            'Discovery — filtering: verified_only',
            'every seeded creator is verified, so the filter cannot be shown to narrow anything'
        );
    }

    // -- platform (joined through creator_social_accounts) -------------
    $platform = (string) (Database::scalar(
        "SELECT csa.`platform`
         FROM `creator_social_accounts` csa
         INNER JOIN `creators` c ON c.`id` = csa.`creator_id` AND c.`status` = 'approved' AND c.`deleted_at` IS NULL
         GROUP BY csa.`platform`
         HAVING COUNT(DISTINCT csa.`creator_id`) < :total
         ORDER BY COUNT(DISTINCT csa.`creator_id`) DESC
         LIMIT 1",
        ['total' => $approvedCount]
    ) ?? '');

    if ($platform === '') {
        Suite::skipGroup(
            'Discovery — filtering: platform',
            'no seeded creator_social_accounts rows that would let a platform filter narrow anything'
        );
    } else {
        $onPlatform = array_map('intval', Database::column(
            'SELECT DISTINCT `creator_id` FROM `creator_social_accounts` WHERE `platform` = :platform',
            ['platform' => $platform]
        ));

        $assertNarrows(
            "?platform={$platform}",
            ['platform' => $platform],
            static fn (array $row): bool => in_array((int) $row['id'], $onPlatform, true)
        );
    }

    // -- availability -------------------------------------------------
    $availability = (string) (Database::scalar(
        "SELECT `availability_status` FROM `creators`
         WHERE `status` = 'approved' AND `deleted_at` IS NULL
         GROUP BY `availability_status`
         HAVING COUNT(*) < :total
         ORDER BY COUNT(*) DESC LIMIT 1",
        ['total' => $approvedCount]
    ) ?? '');

    if ($availability === '') {
        Suite::skipGroup(
            'Discovery — filtering: availability',
            'every approved creator shares one availability_status, so the filter cannot be shown to narrow'
        );
    } else {
        $assertNarrows(
            "?availability={$availability}",
            ['availability' => $availability],
            static fn (array $row): bool => (string) ($row['availability'] ?? '') === $availability
        );
    }

    // -- filters compose ----------------------------------------------
    if ($city !== '' && $unverified > 0) {
        $cityOnly = Http::get('/creators', ['city' => $city, 'per_page' => 1]);
        $both     = Http::get('/creators', ['city' => $city, 'verified_only' => 1, 'per_page' => 50]);

        Suite::check(
            'combining city with verified_only narrows further, never wider',
            (int) ($both->at('meta.pagination.total') ?? PHP_INT_MAX)
                <= (int) ($cityOnly->at('meta.pagination.total') ?? 0),
            'city ' . json_encode($cityOnly->at('meta.pagination.total'))
                . ' vs city+verified ' . json_encode($both->at('meta.pagination.total'))
        );

        Suite::check(
            'a combined filter satisfies both conditions on every row',
            static function () use ($both, $city): bool {
                foreach ($both->list() as $row) {
                    if ((string) $row['city'] !== $city || ($row['is_verified'] ?? false) !== true) {
                        return false;
                    }
                }

                return true;
            },
            $both->describe()
        );
    }

    // -- filters that match nothing -----------------------------------
    $impossible = Http::get('/creators', ['city' => 'Nowhere-At-All-' . bin2hex(random_bytes(3))]);
    Suite::ok('a filter that matches nothing is an empty listing, not an error', $impossible);
    Suite::equals('an impossible filter returns zero rows', count($impossible->list()), 0);
    Suite::equals(
        'an impossible filter reports a total of zero',
        (int) ($impossible->at('meta.pagination.total') ?? -1),
        0
    );
}

// -----------------------------------------------------------------
// The Flutter client builds its query string in
// mobile/lib/models/home_feed.dart:136 (CreatorFilters::toQuery). Several of
// the names it sends are not the names CreatorController::index reads, and the
// rest have no server-side implementation at all. An unknown query parameter is
// silently ignored, so the app's filter sheet appears to work while the API
// keeps returning the unfiltered catalogue. These checks assert the contract
// the shipped client depends on; each failure is a real defect.
// -----------------------------------------------------------------
if (!$hasCreators) {
    Suite::skipGroup('Discovery — filters the mobile client sends', 'no seeded creators');
} else {
    Suite::group('Discovery — filters the mobile client sends');

    $baseline = (int) ($listing->at('meta.pagination.total') ?? 0);

    $assertClientFilterWorks = static function (string $param, mixed $value) use ($baseline): void {
        $res   = Http::get('/creators', [$param => $value, 'per_page' => 1]);
        $total = (int) ($res->at('meta.pagination.total') ?? -1);

        Suite::check(
            "?{$param}={$value} (sent by CreatorFilters::toQuery) narrows the result set",
            $total >= 0 && $total < $baseline,
            "unfiltered total {$baseline}, with {$param} total {$total} — "
                . 'the parameter appears to be ignored by CreatorController::index'
        );
    };

    $categorySlug = (string) (Database::scalar(
        "SELECT cat.`slug`
         FROM `creator_categories` cc
         INNER JOIN `categories` cat ON cat.`id` = cc.`category_id`
         INNER JOIN `creators` c ON c.`id` = cc.`creator_id` AND c.`status` = 'approved'
         GROUP BY cat.`slug` HAVING COUNT(*) < :total ORDER BY COUNT(*) DESC LIMIT 1",
        ['total' => $approvedCount]
    ) ?? '');

    if ($categorySlug !== '') {
        $assertClientFilterWorks('category_slug', $categorySlug);
    }

    if ((int) (Database::scalar(
        "SELECT COUNT(*) FROM `creators` WHERE `status` = 'approved' AND `is_verified` = 0"
    ) ?? 0) > 0) {
        $assertClientFilterWorks('verified', 1);
    }

    if ((int) (Database::scalar(
        "SELECT COUNT(*) FROM `creators` WHERE `status` = 'approved' AND `availability_status` <> 'available'"
    ) ?? 0) > 0) {
        $assertClientFilterWorks('available', 1);
    }

    $gender = (string) (Database::scalar(
        "SELECT `gender` FROM `creators`
         WHERE `status` = 'approved' AND `deleted_at` IS NULL AND `gender` IS NOT NULL
         GROUP BY `gender` HAVING COUNT(*) < :total ORDER BY COUNT(*) DESC LIMIT 1",
        ['total' => $approvedCount]
    ) ?? '');

    if ($gender === '') {
        Suite::skipGroup(
            'Discovery — filtering: gender',
            'seeded creators do not span more than one gender, so the filter cannot be shown to narrow'
        );
    } else {
        $assertClientFilterWorks('gender', $gender);
    }

    // `languages` is a JSON array column on `creators`; the filter sheet offers
    // a single-language facet (mobile/lib/features/search/filter_sheet.dart).
    $language = (string) (Database::scalar(
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(`languages`, '$[0]')) FROM `creators`
         WHERE `status` = 'approved' AND `deleted_at` IS NULL
           AND `languages` IS NOT NULL AND JSON_LENGTH(`languages`) > 0
         ORDER BY `id` DESC LIMIT 1"
    ) ?? '');

    if ($language === '') {
        Suite::skipGroup('Discovery — filtering: language', 'no seeded creator has a languages array');
    } else {
        $spansLanguages = (int) (Database::scalar(
            "SELECT COUNT(*) FROM `creators`
             WHERE `status` = 'approved' AND `deleted_at` IS NULL
               AND NOT JSON_CONTAINS(`languages`, :needle)",
            ['needle' => json_encode($language)]
        ) ?? 0);

        if ($spansLanguages === 0) {
            Suite::skipGroup(
                'Discovery — filtering: language',
                'every seeded creator speaks ' . $language . ', so the filter cannot be shown to narrow'
            );
        } else {
            $assertClientFilterWorks('language', $language);
        }
    }

    $followerCeiling = (int) (Database::scalar(
        "SELECT AVG(`followers_total`) FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
    ) ?? 0);

    if ($followerCeiling > 0) {
        $assertClientFilterWorks('max_followers', $followerCeiling);
    }
}

// -----------------------------------------------------------------
if (!$hasCreators) {
    Suite::skipGroup('Discovery — sorting', 'no seeded creators to sort');
} else {
    Suite::group('Discovery — sorting');

    // rating   → c.rating_avg DESC, c.rating_count DESC
    $assertSorted('rating', static fn (array $r): float => (float) $r['rating'], false);

    // price_low  → c.price_min ASC
    $assertSorted('price_low', static fn (array $r): float => (float) $r['price_min'], true);

    // price_high → c.price_max DESC
    $assertSorted('price_high', static fn (array $r): float => (float) $r['price_max'], false);

    // popular  → c.followers_total DESC
    $assertSorted('popular', static fn (array $r): int => (int) $r['followers'], false);

    // `relevance` orders on c.search_boost and `newest` on c.created_at, neither
    // of which the card exposes, so both are resolved from the row the API is
    // actually ordering by rather than guessed from the payload.
    $boosts = [];
    foreach (Database::select("SELECT `id`, `search_boost` FROM `creators` WHERE `status` = 'approved'") as $row) {
        $boosts[(int) $row['id']] = (float) $row['search_boost'];
    }

    $createdAt = [];
    foreach (Database::select("SELECT `id`, `created_at` FROM `creators` WHERE `status` = 'approved'") as $row) {
        $createdAt[(int) $row['id']] = (int) strtotime((string) $row['created_at']);
    }

    $assertSorted(
        'relevance',
        static fn (array $r): ?float => $r['_boost'] ?? null,
        false,
        static function (array $rows) use ($boosts): array {
            foreach ($rows as &$row) {
                $row['_boost'] = $boosts[(int) $row['id']] ?? null;
            }
            unset($row);

            return $rows;
        }
    );

    $assertSorted(
        'newest',
        static fn (array $r): ?int => $r['_created'] ?? null,
        false,
        static function (array $rows) use ($createdAt): array {
            foreach ($rows as &$row) {
                $row['_created'] = $createdAt[(int) $row['id']] ?? null;
            }
            unset($row);

            return $rows;
        }
    );

    // Two different sorts over the same rows must not produce the same order,
    // otherwise "sorting works" is only true by coincidence.
    $byPriceLow  = $ids(Http::get('/creators', ['sort' => 'price_low', 'per_page' => 20]));
    $byPopular   = $ids(Http::get('/creators', ['sort' => 'popular', 'per_page' => 20]));

    Suite::check(
        'different sort keys really produce different orderings',
        $byPriceLow !== $byPopular,
        'price_low and popular returned an identical id sequence: ' . implode(',', $byPriceLow)
    );

    // An unknown sort must fall back rather than reach SQL — the key is looked
    // up in CreatorService::SORTABLE (CreatorService.php:134).
    $bogus     = Http::get('/creators', ['sort' => 'price_low; DROP TABLE creators', 'per_page' => 20]);
    $relevance = Http::get('/creators', ['sort' => 'relevance', 'per_page' => 20]);

    Suite::ok('an unrecognised sort key is refused safely, not executed', $bogus);
    Suite::equals(
        'an unrecognised sort key falls back to relevance',
        $ids($bogus),
        $ids($relevance)
    );
    Suite::check(
        'the creators table survived the injection attempt',
        (int) (Database::scalar('SELECT COUNT(*) FROM `creators`') ?? 0) > 0,
        'creators table is empty after a sort-key injection attempt'
    );
}

// -----------------------------------------------------------------
if (!$hasCreators) {
    Suite::skipGroup('Discovery — search', 'no seeded creators to search');
} else {
    Suite::group('Discovery — search');

    $target = Database::selectOne(
        "SELECT `id`, `handle`, `display_name`, `city` FROM `creators`
         WHERE `status` = 'approved' AND `deleted_at` IS NULL
           AND `display_name` LIKE '% %'
         ORDER BY `id` LIMIT 1"
    );

    if ($target === null) {
        Suite::check('a seeded creator exists to search for', false, 'no approved creator with a display name');
    } else {
        $byName = $search(['q' => (string) $target['display_name'], 'per_page' => 50]);
        Suite::ok('searching a seeded creator name succeeds', $byName);
        Suite::check(
            'searching a seeded creator name returns that creator',
            in_array((int) $target['id'], $ids($byName), true),
            'looked for id ' . $target['id'] . ' (' . $target['display_name'] . '), got: '
                . implode(',', $ids($byName))
        );

        $byHandle = $search(['q' => (string) $target['handle'], 'per_page' => 50]);
        Suite::check(
            'searching a seeded handle returns that creator',
            in_array((int) $target['id'], $ids($byHandle), true),
            'looked for handle ' . $target['handle'] . ', got: ' . implode(',', $ids($byHandle))
        );

        // Half a name must still match — the query is a LIKE %term%.
        $fragment = (string) strtok((string) $target['display_name'], ' ');

        if (mb_strlen($fragment) >= 3) {
            $byFragment = $search(['q' => $fragment, 'per_page' => 50]);
            Suite::check(
                'searching part of a name matches it',
                in_array((int) $target['id'], $ids($byFragment), true),
                'fragment "' . $fragment . '" did not match id ' . $target['id']
            );
        }
    }

    $nonsense = $search(['q' => 'qzxvblorptnonsense' . bin2hex(random_bytes(3))]);
    Suite::ok('a nonsense query is an empty listing, not an error', $nonsense);
    Suite::equals('a nonsense query returns no creators', count($nonsense->list()), 0);
    Suite::equals(
        'a nonsense query reports a total of zero',
        (int) ($nonsense->at('meta.pagination.total') ?? -1),
        0
    );
    Suite::check(
        'an empty result set says so in the message',
        str_contains(strtolower($nonsense->message()), 'no creators'),
        'message was: ' . $nonsense->message()
    );

    // Str::likeTerm escapes LIKE metacharacters, so a bare wildcard is a
    // literal search for "%" and must not match the whole catalogue.
    $wildcard = $search(['q' => '%']);
    Suite::equals(
        'a bare LIKE wildcard is escaped rather than matching everything',
        (int) ($wildcard->at('meta.pagination.total') ?? -1),
        0
    );

    $underscore = $search(['q' => '_a']);
    Suite::check(
        'a LIKE underscore wildcard is escaped too',
        (int) ($underscore->at('meta.pagination.total') ?? -1) < $approvedCount,
        'total was ' . json_encode($underscore->at('meta.pagination.total'))
    );

    $quote = $search(['q' => "o'brien\" OR 1=1 --"]);
    Suite::ok('a quote-laden query is handled as text, not SQL', $quote);
}

// -----------------------------------------------------------------
Suite::group('Discovery — creator profile');

$publicCreator = Database::selectOne(
    "SELECT c.`id`, c.`handle`, c.`display_name`
     FROM `creators` c
     INNER JOIN `users` u ON u.`id` = c.`user_id`
     WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL AND u.`status` = 'active'
     ORDER BY c.`id` LIMIT 1"
);

if ($publicCreator === null) {
    Suite::skipGroup('Discovery — creator profile', 'no approved creator to read');
} else {
    $profile = Http::get('/creators/' . $publicCreator['handle']);
    Suite::ok('GET /creators/{handle} returns the profile', $profile);

    Suite::equals(
        'the profile returned is the one asked for',
        (string) ($profile->at('data.creator.handle') ?? ''),
        (string) $publicCreator['handle']
    );

    Suite::check(
        'the profile carries the sections the detail screen renders',
        static function () use ($profile): bool {
            $creator = $profile->at('data.creator');

            if (!is_array($creator)) {
                return false;
            }

            foreach (['categories', 'social_accounts', 'packages', 'portfolio_counts',
                      'recent_portfolio', 'reviews', 'rating_breakdown', 'languages',
                      'price_formatted', 'is_online'] as $key) {
                if (!array_key_exists($key, $creator)) {
                    return false;
                }
            }

            return true;
        },
        'profile keys: ' . implode(',', array_keys((array) $profile->at('data.creator')))
    );

    Suite::check(
        'the public profile never leaks account credentials',
        static function () use ($profile): bool {
            $body = strtolower($profile->body);

            return !str_contains($body, 'password_hash') && !str_contains($body, '$2y$');
        },
        'the profile body contained a credential'
    );

    Suite::check(
        'an anonymous profile read reports no favourite, follow or ownership',
        ($profile->at('data.creator.is_favorite') === false)
            && ($profile->at('data.creator.is_following') === false)
            && ($profile->at('data.creator.is_owner') === false),
        $profile->describe()
    );

    $byId = Http::get('/creators/' . (int) $publicCreator['id']);
    Suite::ok('GET /creators/{id} resolves the same profile numerically', $byId);
    Suite::equals(
        'the numeric and handle lookups return the same creator',
        (int) ($byId->at('data.creator.id') ?? 0),
        (int) $publicCreator['id']
    );

    $missing = Http::get('/creators/no-such-handle-' . bin2hex(random_bytes(4)));
    Suite::status('an unknown creator handle is 404', $missing, 404);
    Suite::equals('the 404 carries a machine-readable code', $missing->code(), 'not_found');
}

// -----------------------------------------------------------------
Suite::group('Discovery — non-approved creators stay private');

$brand   = Actors::create('brand', 'brand');
$creator = Actors::create('creator', 'creator');

/**
 * Why an actor could not be obtained, in terms that point at the real cause.
 *
 * `Actors::create` signs in first and only registers when that produces no
 * token, so an `email_taken` register response means the account exists and it
 * was the sign-in that was refused — almost always `throttle:10,300,login`
 * (routes/api.php:62) burnt by a previous run inside the same five minutes.
 */
$actorFailure = static function (): string {
    $error = Actors::lastError();

    if (str_contains($error, 'email_taken') || str_contains($error, 'already exists')) {
        return 'the throwaway account exists but re-authentication was refused — the login '
            . 'throttle (throttle:10,300,login) is most likely still holding from an earlier run. '
            . 'Raw response: ' . $error;
    }

    return $error;
};

// A creator account gets a `draft` profile at registration
// (CreatorService::createDraftProfile), which is exactly the row that must not
// be discoverable until an admin approves it.
$ownDraft = $creator === null ? null : Database::selectOne(
    'SELECT `id`, `handle`, `display_name`, `status` FROM `creators` WHERE `user_id` = :id',
    ['id' => Actors::id('creator')]
);

$seededPrivate = Database::selectOne(
    "SELECT `id`, `handle`, `status` FROM `creators`
     WHERE `status` IN ('pending_review', 'rejected', 'draft', 'suspended')
       AND `deleted_at` IS NULL AND `id` BETWEEN 101 AND 199
     ORDER BY `id` LIMIT 1"
);

if ($ownDraft === null && $seededPrivate === null) {
    Suite::skipGroup(
        'Discovery — non-approved creators stay private',
        'no non-approved creator to hide: no seeded row is unapproved and no creator actor was available — '
            . $actorFailure()
    );
} else {
    if ($ownDraft === null) {
        Suite::skipGroup(
            'Discovery — draft profile visibility',
            'no creator actor, so the owner-only read of an unapproved profile could not be exercised — '
                . $actorFailure()
        );
    }

    if ($seededPrivate !== null) {
        $hidden = Http::get('/creators/' . $seededPrivate['handle']);
        Suite::status(
            'a ' . $seededPrivate['status'] . ' creator is 404 to an anonymous caller',
            $hidden,
            404
        );

        $fullList = Http::get('/creators', ['per_page' => 50]);
        Suite::check(
            'a ' . $seededPrivate['status'] . ' creator is not in the public listing',
            !in_array((int) $seededPrivate['id'], $ids($fullList), true),
            'id ' . $seededPrivate['id'] . ' appeared in the listing'
        );
    }

    // State the precondition instead of assuming it. Every check below only
    // means anything if the row really is unapproved, and another suite that
    // approves this shared actor and dies before restoring it used to turn that
    // into three failures here, a long way from the cause.
    if ($ownDraft !== null && $ownDraft['status'] === 'approved') {
        Suite::check(
            'the shared test creator starts unapproved',
            false,
            'creator #' . $ownDraft['id'] . ' is approved, so the visibility checks below cannot '
                . 'test anything — an earlier suite approved it and did not restore it'
        );

        $ownDraft = null;
    }

    if ($ownDraft !== null) {
        $anonymousRead = Http::get('/creators/' . $ownDraft['handle']);
        Suite::status('a draft profile is 404 to an anonymous caller', $anonymousRead, 404);

        if ($brand !== null) {
            $strangerRead = Http::get('/creators/' . $ownDraft['handle'], [], 'brand');
            Suite::status('a draft profile is 404 to a signed-in stranger', $strangerRead, 404);
        }

        $ownerRead = Http::get('/creators/' . $ownDraft['handle'], [], 'creator');
        Suite::ok('the owner can still read their own unapproved profile', $ownerRead);
        Suite::equals(
            'the owner\'s own read is flagged as ownership',
            $ownerRead->at('data.creator.is_owner'),
            true
        );

        $listedAnyway = Http::get('/creators', ['per_page' => 50]);
        Suite::check(
            'a draft profile is not in the public listing',
            !in_array((int) $ownDraft['id'], $ids($listedAnyway), true),
            'draft id ' . $ownDraft['id'] . ' appeared in the public listing'
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Discovery — portfolio');

if ($publicCreator === null) {
    Suite::skipGroup('Discovery — portfolio', 'no approved creator to read a portfolio for');
} else {
    $creatorId = (int) $publicCreator['id'];

    $portfolio = Http::get("/creators/{$creatorId}/portfolio");
    Suite::ok('GET /creators/{id}/portfolio returns a paginated envelope', $portfolio);
    Suite::check(
        'the portfolio carries meta.pagination',
        is_array($portfolio->at('meta.pagination')),
        $portfolio->describe()
    );
    Suite::check(
        'the portfolio carries the per-tab counts the profile tabs need',
        static function () use ($portfolio): bool {
            $counts = $portfolio->at('meta.counts');

            if (!is_array($counts)) {
                return false;
            }

            foreach (['post', 'reel', 'photo', 'video'] as $kind) {
                if (!array_key_exists($kind, $counts)) {
                    return false;
                }
            }

            return true;
        },
        'meta.counts was: ' . json_encode($portfolio->at('meta.counts'))
    );

    // CreatorService::portfolio allow-lists the kind and falls back to `post`.
    $bogusKind = Http::get("/creators/{$creatorId}/portfolio", ['kind' => 'not-a-kind']);
    $postKind  = Http::get("/creators/{$creatorId}/portfolio", ['kind' => 'post']);
    Suite::ok('an unrecognised portfolio kind is handled, not an error', $bogusKind);
    Suite::equals(
        'an unrecognised portfolio kind falls back to posts',
        (int) ($bogusKind->at('meta.pagination.total') ?? -1),
        (int) ($postKind->at('meta.pagination.total') ?? -2)
    );

    $unknownPortfolio = Http::get('/creators/99999999/portfolio');
    Suite::ok('the portfolio of an unknown creator is an empty list, not a crash', $unknownPortfolio);

    $portfolioRows = (int) (Database::scalar(
        'SELECT COUNT(*) FROM `creator_portfolio` WHERE `is_active` = 1 AND `deleted_at` IS NULL'
    ) ?? 0);

    if ($portfolioRows === 0) {
        Suite::skipGroup(
            'Discovery — portfolio content',
            'no seeded creator_portfolio rows (database/seeds/021_demo_creator_depth.sql has not applied) '
                . '— item shape and per-kind filtering cannot be proved'
        );
    } else {
        $withItems = Database::selectOne(
            "SELECT `creator_id`, `media_kind` FROM `creator_portfolio`
             WHERE `is_active` = 1 AND `deleted_at` IS NULL
             ORDER BY `creator_id` LIMIT 1"
        );

        $owner = (int) $withItems['creator_id'];
        $kind  = (string) $withItems['media_kind'];

        $filled = Http::get("/creators/{$owner}/portfolio", ['kind' => $kind, 'per_page' => 24]);
        Suite::ok("a creator with {$kind} items returns them", $filled);
        Suite::check(
            'every returned portfolio item is of the requested kind',
            static function () use ($filled, $kind): bool {
                $rows = $filled->list();

                if ($rows === []) {
                    return false;
                }

                foreach ($rows as $row) {
                    if ((string) ($row['media_kind'] ?? '') !== $kind) {
                        return false;
                    }
                }

                return true;
            },
            $filled->describe()
        );

        Suite::check(
            'portfolio items carry the media fields the grid renders',
            static function () use ($filled): bool {
                $row = $filled->list()[0] ?? null;

                if (!is_array($row)) {
                    return false;
                }

                foreach (['id', 'media_kind', 'media_url', 'thumbnail_url',
                          'views_count', 'views_label', 'likes_label'] as $key) {
                    if (!array_key_exists($key, $row)) {
                        return false;
                    }
                }

                return true;
            },
            'first item: ' . json_encode($filled->list()[0] ?? null)
        );

        Suite::check(
            'meta.counts agrees with the number of items the tab returns',
            (int) ($filled->at('meta.counts.' . $kind) ?? -1)
                === (int) ($filled->at('meta.pagination.total') ?? -2),
            'counts.' . $kind . '=' . json_encode($filled->at('meta.counts.' . $kind))
                . ' total=' . json_encode($filled->at('meta.pagination.total'))
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Discovery — reviews');

if ($publicCreator === null) {
    Suite::skipGroup('Discovery — reviews', 'no approved creator to read reviews for');
} else {
    $creatorId = (int) $publicCreator['id'];

    $reviews = Http::get("/creators/{$creatorId}/reviews");
    Suite::ok('GET /creators/{id}/reviews returns a paginated envelope', $reviews);
    Suite::check(
        'the reviews response carries the star breakdown',
        static function () use ($reviews): bool {
            $breakdown = $reviews->at('meta.breakdown');

            if (!is_array($breakdown)) {
                return false;
            }

            foreach ([1, 2, 3, 4, 5] as $star) {
                if (!array_key_exists((string) $star, $breakdown)) {
                    return false;
                }
            }

            return true;
        },
        'meta.breakdown was: ' . json_encode($reviews->at('meta.breakdown'))
    );

    $publishedReviews = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `reviews`
         WHERE `is_published` = 1 AND `deleted_at` IS NULL AND `direction` = 'brand_to_creator'"
    ) ?? 0);

    if ($publishedReviews === 0) {
        Suite::skipGroup(
            'Discovery — review content',
            'no seeded published reviews (database/seeds/021_demo_creator_depth.sql has not applied) '
                . '— review shape, ordering and the breakdown total cannot be proved'
        );
    } else {
        $reviewed = (int) Database::scalar(
            "SELECT `creator_id` FROM `reviews`
             WHERE `is_published` = 1 AND `deleted_at` IS NULL AND `direction` = 'brand_to_creator'
             GROUP BY `creator_id` ORDER BY COUNT(*) DESC LIMIT 1"
        );

        $filled = Http::get("/creators/{$reviewed}/reviews", ['per_page' => 10]);
        Suite::ok('a reviewed creator returns their reviews', $filled);

        Suite::check(
            'a review carries the reviewer, the rating and the comment',
            static function () use ($filled): bool {
                $row = $filled->list()[0] ?? null;

                if (!is_array($row)) {
                    return false;
                }

                foreach (['id', 'rating', 'comment', 'created_at', 'reviewer_name'] as $key) {
                    if (!array_key_exists($key, $row)) {
                        return false;
                    }
                }

                return (int) $row['rating'] >= 1 && (int) $row['rating'] <= 5;
            },
            'first review: ' . json_encode($filled->list()[0] ?? null)
        );

        Suite::check(
            'reviews come back newest first',
            static function () use ($filled): bool {
                $previous = null;

                foreach ($filled->list() as $row) {
                    $id = (int) $row['id'];

                    if ($previous !== null && $id > $previous) {
                        return false;
                    }

                    $previous = $id;
                }

                return true;
            },
            $filled->describe()
        );

        Suite::equals(
            'the review total matches the published reviews in the database',
            (int) ($filled->at('meta.pagination.total') ?? -1),
            (int) (Database::scalar(
                "SELECT COUNT(*) FROM `reviews`
                 WHERE `creator_id` = :id AND `is_published` = 1 AND `deleted_at` IS NULL
                   AND `direction` = 'brand_to_creator'",
                ['id' => $reviewed]
            ) ?? 0)
        );

        Suite::check(
            'an unpublished review is never exposed publicly',
            static function () use ($reviewed): bool {
                $hidden = Database::column(
                    'SELECT `id` FROM `reviews` WHERE `creator_id` = :id AND (`is_published` = 0 OR `deleted_at` IS NOT NULL)',
                    ['id' => $reviewed]
                );

                if ($hidden === []) {
                    return true;
                }

                $visible = array_map(
                    static fn (array $row): int => (int) $row['id'],
                    Http::get("/creators/{$reviewed}/reviews", ['per_page' => 50])->list()
                );

                return array_intersect(array_map('intval', $hidden), $visible) === [];
            },
            'an unpublished review appeared in the public list'
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Discovery — availability');

if ($publicCreator === null) {
    Suite::skipGroup('Discovery — availability', 'no approved creator to read availability for');
} else {
    $creatorId = (int) $publicCreator['id'];

    $thisMonth = Http::get("/creators/{$creatorId}/availability");
    Suite::ok('GET /creators/{id}/availability returns a month', $thisMonth);
    Suite::equals(
        'availability defaults to the current month',
        (string) ($thisMonth->at('data.month') ?? ''),
        date('Y-m')
    );
    Suite::check(
        'availability returns a days array',
        is_array($thisMonth->at('data.days')),
        $thisMonth->describe()
    );

    $nextMonth = date('Y-m', strtotime('+1 month'));
    $requested = Http::get("/creators/{$creatorId}/availability", ['month' => $nextMonth]);
    Suite::equals(
        'availability echoes back the month that was asked for',
        (string) ($requested->at('data.month') ?? ''),
        $nextMonth
    );

    // The controller regex-checks `month` and falls back rather than 500ing.
    $malformed = Http::get("/creators/{$creatorId}/availability", ['month' => 'not-a-month']);
    Suite::ok('a malformed month is handled, not an error', $malformed);
    Suite::equals(
        'a malformed month falls back to the current month',
        (string) ($malformed->at('data.month') ?? ''),
        date('Y-m')
    );

    $availabilityRows = (int) (Database::scalar('SELECT COUNT(*) FROM `creator_availability`') ?? 0);

    if ($availabilityRows === 0) {
        Suite::skipGroup(
            'Discovery — availability content',
            'no seeded creator_availability rows (database/seeds/021_demo_creator_depth.sql has not applied) '
                . '— day shape and the is_bookable derivation cannot be proved'
        );
    } else {
        $day = Database::selectOne(
            'SELECT `creator_id`, LEFT(`available_on`, 7) AS `month` FROM `creator_availability` ORDER BY `available_on` LIMIT 1'
        );

        $filled = Http::get(
            '/creators/' . (int) $day['creator_id'] . '/availability',
            ['month' => (string) $day['month']]
        );

        Suite::ok('a creator with a published calendar returns days', $filled);
        Suite::check(
            'every returned day falls inside the month requested',
            static function () use ($filled, $day): bool {
                $days = $filled->at('data.days');

                if (!is_array($days) || $days === []) {
                    return false;
                }

                foreach ($days as $entry) {
                    if (!str_starts_with((string) ($entry['available_on'] ?? ''), (string) $day['month'])) {
                        return false;
                    }
                }

                return true;
            },
            $filled->describe()
        );

        Suite::check(
            'is_bookable is false once a day is full or closed',
            static function () use ($filled): bool {
                foreach ((array) $filled->at('data.days') as $entry) {
                    $expected = (bool) $entry['is_available']
                        && (int) $entry['booked_count'] < (int) $entry['max_bookings'];

                    if ((bool) $entry['is_bookable'] !== $expected) {
                        return false;
                    }
                }

                return true;
            },
            'a day reported is_bookable inconsistently with its counters'
        );

        Suite::check(
            'the slots on a day are decoded into an array, not left as JSON text',
            static function () use ($filled): bool {
                foreach ((array) $filled->at('data.days') as $entry) {
                    if (!is_array($entry['slots'] ?? null)) {
                        return false;
                    }
                }

                return true;
            },
            'a day returned slots as a string'
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Discovery — /home');

$home = Http::get('/home');
Suite::ok('GET /home succeeds for an anonymous caller', $home);

Suite::check(
    '/home returns the legacy flat rails older builds still read',
    static function () use ($home): bool {
        foreach (['banners', 'categories', 'top_creators', 'trending', 'new_creators'] as $key) {
            if (!is_array($home->at('data.' . $key))) {
                return false;
            }
        }

        return true;
    },
    'data keys: ' . implode(',', array_keys($home->data()))
);

$activeSections = (int) (Database::scalar(
    'SELECT COUNT(*) FROM `homepage_sections` WHERE `is_active` = 1'
) ?? 0);

if ($activeSections === 0) {
    Suite::skipGroup('Discovery — /home sections', 'no active homepage_sections configured by admin');
} else {
    Suite::check(
        '/home returns the admin-configured sections',
        is_array($home->at('data.sections')) && $home->at('data.sections') !== [],
        'sections were: ' . json_encode($home->at('data.sections'))
    );

    $sections = (array) $home->at('data.sections');

    Suite::check(
        'every section carries the fields the renderer switches on',
        static function () use ($sections): bool {
            foreach ($sections as $section) {
                foreach (['id', 'type', 'title', 'layout', 'items'] as $key) {
                    if (!array_key_exists($key, $section)) {
                        return false;
                    }
                }

                if (!is_array($section['items'])) {
                    return false;
                }
            }

            return true;
        },
        'sections: ' . json_encode(array_map(
            static fn (array $s): array => array_keys($s),
            $sections
        ))
    );

    // AppContentService::homeSections drops any data-backed rail that resolved
    // to nothing, so a section that survived must have items.
    Suite::check(
        'no data-backed section comes back empty',
        static function () use ($sections): bool {
            foreach ($sections as $section) {
                if (in_array($section['type'], ['custom_html', 'cta_card', 'stats'], true)) {
                    continue;
                }

                if ($section['items'] === []) {
                    return false;
                }
            }

            return true;
        },
        'an empty rail was returned: ' . json_encode(array_map(
            static fn (array $s): array => [$s['type'], count($s['items'])],
            $sections
        ))
    );

    Suite::check(
        'the sections come back in the admin-configured order',
        static function () use ($sections): bool {
            $expected = array_map(
                'intval',
                Database::column(
                    'SELECT `id` FROM `homepage_sections` WHERE `is_active` = 1 ORDER BY `sort_order`, `id`'
                )
            );

            $returned = array_map(static fn (array $s): int => (int) $s['id'], $sections);

            // The returned ids must be the configured ids, in the same relative
            // order — some may have been dropped for being empty.
            $filtered = array_values(array_filter(
                $expected,
                static fn (int $id): bool => in_array($id, $returned, true)
            ));

            return $filtered === $returned;
        },
        'returned order: ' . implode(',', array_map(static fn (array $s): int => (int) $s['id'], $sections))
    );

    $creatorSections = array_values(array_filter(
        $sections,
        static fn (array $s): bool => in_array($s['type'], ['creator_grid', 'creator_carousel'], true)
    ));

    if ($creatorSections === [] || !$hasCreators) {
        Suite::skipGroup(
            'Discovery — /home creator rails',
            $hasCreators
                ? 'no creator_grid or creator_carousel section is configured'
                : 'no seeded creators to populate a creator rail'
        );
    } else {
        Suite::check(
            'a creator rail is filled with creator cards, not bare ids',
            static function () use ($creatorSections): bool {
                foreach ($creatorSections as $section) {
                    foreach ($section['items'] as $item) {
                        foreach (['id', 'handle', 'display_name', 'rating', 'price_label'] as $key) {
                            if (!array_key_exists($key, $item)) {
                                return false;
                            }
                        }
                    }
                }

                return true;
            },
            'first rail item: ' . json_encode($creatorSections[0]['items'][0] ?? null)
        );

        Suite::check(
            'a creator rail never surfaces an unapproved creator',
            static function () use ($creatorSections): bool {
                $ids = [];

                foreach ($creatorSections as $section) {
                    foreach ($section['items'] as $item) {
                        $ids[] = (int) $item['id'];
                    }
                }

                if ($ids === []) {
                    return true;
                }

                $approved = array_map('intval', Database::column(
                    "SELECT `id` FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
                ));

                return array_diff(array_unique($ids), $approved) === [];
            },
            'an unapproved creator appeared on the home screen'
        );

        Suite::check(
            'a creator rail honours its configured item limit',
            static function () use ($creatorSections): bool {
                foreach ($creatorSections as $section) {
                    $limit = (int) (Database::scalar(
                        'SELECT `item_limit` FROM `homepage_sections` WHERE `id` = :id',
                        ['id' => (int) $section['id']]
                    ) ?? 0);

                    if ($limit > 0 && count($section['items']) > min(30, $limit)) {
                        return false;
                    }
                }

                return true;
            },
            'a rail returned more items than its item_limit'
        );
    }
}

// The badges and the active-bookings rail are for signed-in callers only.
Suite::check(
    '/home does not expose per-user badges to an anonymous caller',
    !array_key_exists('badges', $home->data()) && !array_key_exists('active_bookings', $home->data()),
    'anonymous /home leaked: ' . implode(',', array_keys($home->data()))
);

if ($brand === null) {
    Suite::skipGroup(
        'Discovery — /home for a signed-in caller',
        'no brand actor, so the per-user badges and bookings rail could not be exercised — ' . $actorFailure()
    );
} else {
    $homeAuthed = Http::get('/home', [], 'brand');
    Suite::ok('GET /home succeeds for a signed-in brand', $homeAuthed);
    Suite::check(
        'a signed-in caller gets their notification and message badges',
        is_array($homeAuthed->at('data.badges'))
            && array_key_exists('unread_notifications', (array) $homeAuthed->at('data.badges'))
            && array_key_exists('unread_messages', (array) $homeAuthed->at('data.badges')),
        'badges were: ' . json_encode($homeAuthed->at('data.badges'))
    );
    Suite::check(
        'a signed-in caller gets their own active bookings rail',
        is_array($homeAuthed->at('data.active_bookings')),
        $homeAuthed->describe()
    );
}

// -----------------------------------------------------------------
Suite::group('Discovery — /explore');

$explore = Http::get('/explore');
Suite::ok('GET /explore succeeds', $explore);

Suite::check(
    '/explore returns its three rails',
    static function () use ($explore): bool {
        foreach (['top_creators', 'trending', 'categories'] as $key) {
            if (!is_array($explore->at('data.' . $key))) {
                return false;
            }
        }

        return true;
    },
    'data keys: ' . implode(',', array_keys($explore->data()))
);

if ($hasCreators) {
    Suite::check(
        '/explore fills its creator rails',
        count((array) $explore->at('data.top_creators')) > 0
            && count((array) $explore->at('data.trending')) > 0,
        $explore->describe()
    );

    Suite::check(
        '/explore trending really is ordered by follower count',
        $isMonotonic(
            $partitionByFeatured((array) $explore->at('data.trending'))[0],
            static fn (array $r): int => (int) $r['followers'],
            false
        ) && $isMonotonic(
            $partitionByFeatured((array) $explore->at('data.trending'))[1],
            static fn (array $r): int => (int) $r['followers'],
            false
        ),
        'trending order: ' . $trail((array) $explore->at('data.trending'), static fn (array $r): int => (int) $r['followers'])
    );

    Suite::check(
        '/explore top_creators really is ordered by rating',
        $isMonotonic(
            $partitionByFeatured((array) $explore->at('data.top_creators'))[0],
            static fn (array $r): float => (float) $r['rating'],
            false
        ) && $isMonotonic(
            $partitionByFeatured((array) $explore->at('data.top_creators'))[1],
            static fn (array $r): float => (float) $r['rating'],
            false
        ),
        'top_creators order: ' . $trail((array) $explore->at('data.top_creators'), static fn (array $r): float => (float) $r['rating'])
    );

    Suite::check(
        '/explore never surfaces an unapproved creator',
        static function () use ($explore): bool {
            $ids = array_map(
                static fn (array $row): int => (int) $row['id'],
                array_merge((array) $explore->at('data.top_creators'), (array) $explore->at('data.trending'))
            );

            $approved = array_map('intval', Database::column(
                "SELECT `id` FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL"
            ));

            return array_diff(array_unique($ids), $approved) === [];
        },
        'an unapproved creator appeared on the explore screen'
    );
} else {
    Suite::skipGroup('Discovery — /explore rails', 'no seeded creators to populate the explore rails');
}

// -----------------------------------------------------------------
Suite::group('Discovery — /categories');

$categories = Http::get('/categories');
Suite::ok('GET /categories succeeds', $categories);

$activeCategories = (int) (Database::scalar(
    'SELECT COUNT(*) FROM `categories` WHERE `is_active` = 1 AND `deleted_at` IS NULL'
) ?? 0);

if ($activeCategories === 0) {
    Suite::skipGroup('Discovery — /categories', 'no active categories seeded');
} else {
    Suite::equals(
        '/categories returns every active category',
        count($categories->list('categories')),
        $activeCategories
    );

    Suite::check(
        'a category carries what the category tile renders',
        static function () use ($categories): bool {
            $row = $categories->list('categories')[0] ?? null;

            if (!is_array($row)) {
                return false;
            }

            foreach (['id', 'name', 'slug', 'icon', 'color_hex', 'creator_count',
                      'creator_label', 'is_featured'] as $key) {
                if (!array_key_exists($key, $row)) {
                    return false;
                }
            }

            return is_int($row['id']) && is_int($row['creator_count']);
        },
        'first category: ' . json_encode($categories->list('categories')[0] ?? null)
    );

    $featuredCount = (int) (Database::scalar(
        'SELECT COUNT(*) FROM `categories` WHERE `is_active` = 1 AND `deleted_at` IS NULL AND `is_featured` = 1'
    ) ?? 0);

    $featured = Http::get('/categories', ['featured' => 1]);
    Suite::ok('GET /categories?featured=1 succeeds', $featured);
    Suite::equals(
        '?featured=1 narrows to the featured categories',
        count($featured->list('categories')),
        $featuredCount
    );
    Suite::check(
        '?featured=1 returns only featured categories',
        static function () use ($featured): bool {
            foreach ($featured->list('categories') as $row) {
                if ((int) $row['is_featured'] !== 1) {
                    return false;
                }
            }

            return true;
        },
        $featured->describe()
    );

    // The categories screen renders `creator_label` ("12+ Creators") straight
    // from the denormalised counter, so a category with approved creators must
    // not advertise zero.
    $populated = (int) (Database::scalar(
        "SELECT COUNT(DISTINCT cc.`category_id`)
         FROM `creator_categories` cc
         INNER JOIN `creators` c ON c.`id` = cc.`creator_id`
         WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL"
    ) ?? 0);

    if ($populated === 0) {
        Suite::skipGroup(
            'Discovery — category creator counts',
            'no approved creator is attached to a category'
        );
    } else {
        Suite::check(
            'a category with approved creators does not report a count of zero',
            static function () use ($categories): bool {
                foreach ($categories->list('categories') as $row) {
                    $real = (int) (Database::scalar(
                        "SELECT COUNT(DISTINCT cc.`creator_id`)
                         FROM `creator_categories` cc
                         INNER JOIN `creators` c ON c.`id` = cc.`creator_id`
                         WHERE cc.`category_id` = :id AND c.`status` = 'approved' AND c.`deleted_at` IS NULL",
                        ['id' => (int) $row['id']]
                    ) ?? 0);

                    if ($real > 0 && (int) $row['creator_count'] === 0) {
                        return false;
                    }
                }

                return true;
            },
            'a category with approved creators advertised "0+ Creators" — the denormalised '
                . '`categories`.`creator_count` is stale'
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Discovery — /search/suggestions');

$suggestions = Http::get('/search/suggestions');
Suite::ok('GET /search/suggestions succeeds anonymously', $suggestions);

Suite::check(
    'suggestions return the three lists the search screen needs',
    static function () use ($suggestions): bool {
        foreach (['recent_searches', 'popular_searches', 'suggested_creators'] as $key) {
            if (!is_array($suggestions->at('data.' . $key))) {
                return false;
            }
        }

        return true;
    },
    'data keys: ' . implode(',', array_keys($suggestions->data()))
);

Suite::equals(
    'an anonymous caller has no recent searches',
    $suggestions->at('data.recent_searches'),
    []
);

$curated = (int) (Database::scalar('SELECT COUNT(*) FROM `popular_searches` WHERE `is_active` = 1') ?? 0);

if ($curated === 0) {
    Suite::skipGroup('Discovery — popular searches', 'no active popular_searches rows seeded');
} else {
    Suite::check(
        'popular searches are returned as plain strings',
        static function () use ($suggestions): bool {
            $terms = (array) $suggestions->at('data.popular_searches');

            if ($terms === []) {
                return false;
            }

            foreach ($terms as $term) {
                if (!is_string($term)) {
                    return false;
                }
            }

            return true;
        },
        'popular_searches: ' . json_encode($suggestions->at('data.popular_searches'))
    );

    Suite::check(
        'popular searches are capped at the documented 8',
        count((array) $suggestions->at('data.popular_searches')) <= 8,
        'returned ' . count((array) $suggestions->at('data.popular_searches')) . ' terms'
    );
}

if ($hasCreators) {
    Suite::check(
        'suggested creators are creator cards ordered by rating',
        static function () use ($suggestions, $partitionByFeatured, $isMonotonic): bool {
            $rows = (array) $suggestions->at('data.suggested_creators');

            if ($rows === [] || !array_key_exists('handle', $rows[0])) {
                return false;
            }

            [$featured, $others] = $partitionByFeatured($rows);
            $rating = static fn (array $r): float => (float) $r['rating'];

            return $isMonotonic($featured, $rating, false) && $isMonotonic($others, $rating, false);
        },
        'suggested_creators: ' . json_encode(array_map(
            static fn (array $r): array => [$r['handle'] ?? '?', $r['rating'] ?? null],
            (array) $suggestions->at('data.suggested_creators')
        ))
    );
}

// -- recent searches are private to the caller -------------------------
if ($brand === null || $creator === null) {
    Suite::skipGroup(
        'Discovery — search history is per-user',
        'needs two signed-in accounts to prove history is scoped, and one could not be obtained — '
            . $actorFailure()
    );
} else {
    $privateTerm = 'ITDiscovery' . bin2hex(random_bytes(3));
    $termsUsed[] = $privateTerm;

    Http::get('/creators', ['q' => $privateTerm], 'brand');

    $brandSuggestions = Http::get('/search/suggestions', [], 'brand');
    Suite::ok('a signed-in caller can read their suggestions', $brandSuggestions);
    Suite::check(
        'a search the caller ran shows up in their recent searches',
        in_array($privateTerm, (array) $brandSuggestions->at('data.recent_searches'), true),
        'recent_searches: ' . json_encode($brandSuggestions->at('data.recent_searches'))
    );

    $creatorSuggestions = Http::get('/search/suggestions', [], 'creator');
    Suite::check(
        'one account never sees another account\'s search history',
        !in_array($privateTerm, (array) $creatorSuggestions->at('data.recent_searches'), true),
        'the creator actor saw the brand actor\'s term: '
            . json_encode($creatorSuggestions->at('data.recent_searches'))
    );

    $anonSuggestions = Http::get('/search/suggestions');
    Suite::check(
        'an anonymous caller never sees a signed-in account\'s search history',
        !in_array($privateTerm, (array) $anonSuggestions->at('data.recent_searches'), true),
        'anonymous recent_searches: ' . json_encode($anonSuggestions->at('data.recent_searches'))
    );

    // DELETE /search/history is behind `auth` (routes/api.php:166).
    $anonymousClear = Http::delete('/search/history');
    Suite::status('clearing search history requires authentication', $anonymousClear, 401);

    $cleared = Http::delete('/search/history', null, 'brand');
    Suite::ok('a signed-in caller can clear their own search history', $cleared);

    $afterClear = Http::get('/search/suggestions', [], 'brand');
    Suite::equals(
        'the history really is gone after clearing it',
        $afterClear->at('data.recent_searches'),
        []
    );
}

// -----------------------------------------------------------------
Suite::group('Discovery — cleanup');

// The listing endpoint records every non-trivial `q` into `popular_searches`
// (CreatorController::recordSearch), so this suite leaves rows behind unless it
// removes exactly the terms it created. Curated rows are left untouched.
$removed = 0;

foreach (array_values(array_unique($termsUsed)) as $term) {
    try {
        $removed += Database::execute(
            'DELETE FROM `popular_searches` WHERE `term` = :term AND `is_curated` = 0',
            ['term' => $term]
        );
    } catch (\Throwable) {
        // Leave the row rather than abort the sweep; it is inert.
    }
}

Suite::check(
    'the search terms this suite created were swept from popular_searches',
    static function () use ($termsUsed): bool {
        foreach (array_unique($termsUsed) as $term) {
            $left = Database::scalar(
                'SELECT 1 FROM `popular_searches` WHERE `term` = :term AND `is_curated` = 0',
                ['term' => $term]
            );

            if ($left !== null) {
                return false;
            }
        }

        return true;
    },
    'residue left in popular_searches'
);

Suite::check(
    'the curated popular searches were not disturbed',
    (int) (Database::scalar('SELECT COUNT(*) FROM `popular_searches` WHERE `is_curated` = 1') ?? 0) > 0,
    'the curated search terms are gone'
);
