<?php

declare(strict_types=1);

/**
 * Public CMS content and app configuration.
 *
 * Covers the endpoints an unauthenticated client hits before it has an account:
 * marketing pages, FAQs, the blog, testimonials, the contact form, the taxonomy
 * bundle, and the two bootstrap payloads (`/app/config`, `/settings`).
 *
 * Two things here are more than smoke tests:
 *
 *  - `/settings` is a data-exposure boundary. The suite reads the `settings`
 *    table directly and asserts that every row NOT flagged `is_public` is
 *    absent from the response, and that every row that IS flagged public is
 *    present. Eyeballing the payload would not catch a single new secret.
 *
 *  - The taxonomy bundle and `/app/config` are conditional-GET endpoints. A
 *    wrong validator is invisible in a 200-only test and shows up in production
 *    as clients pinned to stale config, so the ETag round trip is exercised
 *    both ways: matching validator -> 304, stale validator -> 200.
 *
 * Fixtures: this suite inserts its own `it-` prefixed pages, blogs and popups
 * so that "hidden rows stay hidden" can be asserted against rows it controls,
 * and removes them again at the end. Seeded demo rows are read, never written;
 * the one counter it does bump (FAQ helpfulness) is restored to its original
 * value.
 */

use Zuste\Core\Database;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

/** Removes every row this suite owns. Safe to call twice. */
$cmsCleanup = static function (): void {
    try {
        Database::execute("DELETE FROM `pages` WHERE `slug` LIKE 'it-%'");
        Database::execute("DELETE FROM `blogs` WHERE `slug` LIKE 'it-%'");
        Database::execute("DELETE FROM `popups` WHERE `name` LIKE 'IT %'");
        Database::execute("DELETE FROM `contact_messages` WHERE `email` LIKE 'it-%@zuste.test'");
    } catch (\Throwable) {
        // Reported by the checks that depend on the fixture, not swallowed here.
    }
};

// A previous aborted run must not poison this one.
$cmsCleanup();

$cmsFixturesReady = true;

try {
    Database::execute(
        "INSERT INTO `pages` (`slug`, `title`, `subtitle`, `content`, `template`, `is_active`, `show_in_app`, `sort_order`)
         VALUES
           ('it-page-inactive',  'IT Inactive Page',  'fixture', '<p>inactive</p>',  'default', 0, 1, 90),
           ('it-page-web-only',  'IT Web Only Page',  'fixture', '<p>web only</p>',  'default', 1, 0, 91)"
    );

    Database::execute(
        "INSERT INTO `blogs`
            (`category_id`, `slug`, `title`, `excerpt`, `content`, `author_name`, `tags`,
             `reading_minutes`, `views_count`, `status`, `is_featured`, `published_at`)
         VALUES
           (1, 'it-blog-alpha', 'IT Alpha zustequark Article', 'An alpha excerpt.',
            '<p>alpha body</p>', 'IT Author', '[\"it\",\"alpha\"]', 4, 0, 'published', 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
           (2, 'it-blog-beta', 'IT Beta Article', 'A beta excerpt.',
            '<p>beta body</p>', 'IT Author', '[\"it\",\"beta\"]', 6, 0, 'published', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
           (1, 'it-blog-draft', 'IT Draft Article', 'A draft excerpt.',
            '<p>draft body</p>', 'IT Author', '[]', 3, 0, 'draft', 0, NULL),
           (1, 'it-blog-embargoed', 'IT Embargoed Article', 'Not due yet.',
            '<p>embargoed body</p>', 'IT Author', '[]', 3, 0, 'published', 0, DATE_ADD(NOW(), INTERVAL 7 DAY))"
    );
} catch (\Throwable $e) {
    $cmsFixturesReady = false;
    $cmsFixtureError  = $e->getMessage();
}

// -----------------------------------------------------------------
Suite::group('CMS — pages');

$pages = Http::get('/pages');
Suite::ok('GET /pages returns the in-app page list', $pages);

$pageList  = $pages->list('pages');
$pageSlugs = array_column($pageList, 'slug');

Suite::notEmpty('the page list carries content', $pageList, 'no pages returned: ' . $pages->describe());

Suite::check(
    'the page list includes the legal pages the app links to',
    in_array('terms', $pageSlugs, true) && in_array('privacy', $pageSlugs, true),
    'slugs were: ' . implode(', ', $pageSlugs)
);

// The list query selects a summary projection on purpose — shipping every
// page's longtext body in the index would be a real payload regression.
Suite::check(
    'the page list is a summary and omits the page body',
    $pageList !== [] && !array_key_exists('content', $pageList[0]),
    'index row keys: ' . implode(', ', array_keys($pageList[0] ?? []))
);

$terms = Http::get('/pages/terms');
Suite::ok('GET /pages/{slug} resolves a known slug', $terms);
Suite::equals('the resolved page is the one that was asked for', $terms->at('data.page.slug'), 'terms');
Suite::notEmpty('the resolved page carries its body', $terms->at('data.page.content'));

$missingPage = Http::get('/pages/it-no-such-page-exists');
Suite::status('GET /pages/{slug} 404s an unknown slug', $missingPage, 404);
Suite::equals('the 404 carries a machine-readable code', $missingPage->code(), 'not_found');

if (!$cmsFixturesReady) {
    Suite::skipGroup('CMS — page visibility flags', 'fixture pages could not be inserted: ' . ($cmsFixtureError ?? ''));
} else {
    Suite::check(
        'an inactive page is hidden from the list',
        !in_array('it-page-inactive', $pageSlugs, true),
        'it-page-inactive appeared in /pages'
    );

    $inactivePage = Http::get('/pages/it-page-inactive');
    Suite::status('an inactive page 404s when fetched directly', $inactivePage, 404);

    Suite::check(
        'a page with show_in_app = 0 is hidden from the app list',
        !in_array('it-page-web-only', $pageSlugs, true),
        'it-page-web-only appeared in /pages'
    );

    // show_in_app governs the menu, is_active governs readability — the
    // controller filters on both for the list but only is_active for the
    // detail, so a web-only page must still resolve by slug.
    $webOnlyPage = Http::get('/pages/it-page-web-only');
    Suite::ok('a page hidden from the app menu is still readable by slug', $webOnlyPage);
}

// -----------------------------------------------------------------
Suite::group('CMS — FAQs');

$faqs = Http::get('/faqs');
Suite::ok('GET /faqs returns the FAQ list', $faqs);

$faqList = $faqs->at('data.faqs');
Suite::notEmpty('the FAQ list carries content', $faqList, $faqs->describe());

Suite::check(
    'FAQs are also returned grouped by category for the accordion',
    static function () use ($faqs, $faqList): bool {
        $grouped = $faqs->at('data.grouped');

        if (!is_array($grouped) || $grouped === []) {
            return false;
        }

        $flattened = 0;
        foreach ($grouped as $rows) {
            $flattened += is_array($rows) ? count($rows) : 0;
        }

        return $flattened === count((array) $faqList);
    },
    'grouped did not cover every FAQ: ' . $faqs->describe()
);

if (is_array($faqList) && $faqList !== []) {
    $allCategories = array_values(array_unique(array_column($faqList, 'category')));
    $pickCategory  = (string) $allCategories[0];

    $byCategory = Http::get('/faqs', ['category' => $pickCategory]);
    Suite::ok('GET /faqs?category filters', $byCategory);

    $filtered = (array) $byCategory->at('data.faqs');

    Suite::check(
        "?category={$pickCategory} narrows the list",
        count($filtered) > 0 && count($filtered) < count((array) $faqList),
        sprintf('%d of %d rows returned', count($filtered), count((array) $faqList))
    );

    Suite::check(
        '?category returns only that category',
        array_values(array_unique(array_column($filtered, 'category'))) === [$pickCategory],
        'categories present: ' . implode(', ', array_unique(array_column($filtered, 'category')))
    );

    // audience matches `all` OR the requested side, so asking as a creator must
    // drop the brand-only entries and keep the shared ones.
    $forCreators = Http::get('/faqs', ['audience' => 'creators']);
    Suite::ok('GET /faqs?audience filters by side of the marketplace', $forCreators);

    $creatorFaqs = (array) $forCreators->at('data.faqs');
    $audiences   = array_values(array_unique(array_column($creatorFaqs, 'audience')));
    sort($audiences);

    Suite::check(
        '?audience=creators excludes brand-only FAQs',
        !in_array('brands', $audiences, true) && $creatorFaqs !== [],
        'audiences returned: ' . implode(', ', $audiences)
    );

    Suite::check(
        '?audience=creators keeps the FAQs marked for everyone',
        in_array('all', $audiences, true),
        'audiences returned: ' . implode(', ', $audiences)
    );

    // Search over question + answer.
    $searchTerm = 'wallet';
    $search     = Http::get('/faqs', ['q' => $searchTerm]);
    Suite::ok('GET /faqs?q searches', $search);

    $hits = (array) $search->at('data.faqs');

    Suite::check(
        "?q={$searchTerm} returns only rows containing the term",
        static function () use ($hits, $searchTerm): bool {
            if ($hits === []) {
                return false;
            }

            foreach ($hits as $row) {
                $haystack = strtolower((string) $row['question'] . ' ' . (string) $row['answer']);
                if (!str_contains($haystack, $searchTerm)) {
                    return false;
                }
            }

            return true;
        },
        sprintf('%d hits, at least one did not contain "%s"', count($hits), $searchTerm)
    );

    Suite::check(
        "?q={$searchTerm} narrows the list",
        count($hits) < count((array) $faqList),
        sprintf('%d of %d rows returned', count($hits), count((array) $faqList))
    );

    // A bare `%` must be escaped, not treated as a wildcard — otherwise the
    // search box is a way to dump the table.
    $wildcard = Http::get('/faqs', ['q' => '%']);
    Suite::check(
        'a LIKE wildcard in ?q is escaped rather than matching everything',
        count((array) $wildcard->at('data.faqs')) < count((array) $faqList),
        'q=% returned ' . count((array) $wildcard->at('data.faqs')) . ' of ' . count((array) $faqList) . ' rows'
    );

    // -- helpfulness counters -------------------------------------------
    // These are seeded rows, so the original values are read first and put
    // back afterwards. Nothing is left changed.
    $faqId  = (int) $faqList[0]['id'];
    $before = Database::selectOne(
        'SELECT `helpful_yes`, `helpful_no` FROM `faqs` WHERE `id` = :id',
        ['id' => $faqId]
    ) ?? ['helpful_yes' => 0, 'helpful_no' => 0];

    $rateUp = Http::post("/faqs/{$faqId}/helpful", ['helpful' => true]);
    Suite::ok('POST /faqs/{id}/helpful records a positive vote', $rateUp);

    $rateDown = Http::post("/faqs/{$faqId}/helpful", ['helpful' => false]);
    Suite::ok('POST /faqs/{id}/helpful records a negative vote', $rateDown);

    $after = Database::selectOne(
        'SELECT `helpful_yes`, `helpful_no` FROM `faqs` WHERE `id` = :id',
        ['id' => $faqId]
    ) ?? ['helpful_yes' => 0, 'helpful_no' => 0];

    Suite::equals(
        'a positive vote increments helpful_yes exactly once',
        (int) $after['helpful_yes'],
        (int) $before['helpful_yes'] + 1
    );

    Suite::equals(
        'a negative vote increments helpful_no rather than helpful_yes',
        (int) $after['helpful_no'],
        (int) $before['helpful_no'] + 1
    );

    Database::execute(
        'UPDATE `faqs` SET `helpful_yes` = :yes, `helpful_no` = :no WHERE `id` = :id',
        ['yes' => (int) $before['helpful_yes'], 'no' => (int) $before['helpful_no'], 'id' => $faqId]
    );

    $restored = Database::selectOne(
        'SELECT `helpful_yes`, `helpful_no` FROM `faqs` WHERE `id` = :id',
        ['id' => $faqId]
    ) ?? [];

    Suite::check(
        'the seeded FAQ counters are restored after the test',
        (int) ($restored['helpful_yes'] ?? -1) === (int) $before['helpful_yes']
            && (int) ($restored['helpful_no'] ?? -1) === (int) $before['helpful_no'],
        'counters were left at ' . json_encode($restored)
    );

    // Voting on something that does not exist should not report success. The
    // handler runs a bare UPDATE with no existence check, so it currently does.
    $unknownFaqId = (int) (Database::scalar('SELECT COALESCE(MAX(`id`), 0) + 100000 FROM `faqs`') ?? 999999);
    $rateUnknown  = Http::post("/faqs/{$unknownFaqId}/helpful", ['helpful' => true]);
    Suite::status('POST /faqs/{unknown}/helpful reports not found', $rateUnknown, 404);
} else {
    Suite::skipGroup('CMS — FAQ filters and voting', 'no seeded FAQs to filter or vote on');
}

// -----------------------------------------------------------------
Suite::group('CMS — blog');

$blogs = Http::get('/blogs');
Suite::ok('GET /blogs returns the article list', $blogs);

Suite::check(
    '/blogs is paginated with a full meta block',
    static function () use ($blogs): bool {
        $meta = $blogs->at('meta.pagination');

        if (!is_array($meta)) {
            return false;
        }

        foreach (['total', 'per_page', 'current_page', 'last_page', 'has_more'] as $key) {
            if (!array_key_exists($key, $meta)) {
                return false;
            }
        }

        return true;
    },
    'pagination meta was: ' . json_encode($blogs->at('meta.pagination'))
);

$missingBlog = Http::get('/blogs/it-no-such-article');
Suite::status('GET /blogs/{slug} 404s an unknown slug', $missingBlog, 404);
Suite::equals('the article 404 carries a machine-readable code', $missingBlog->code(), 'not_found');

if (!$cmsFixturesReady) {
    Suite::skipGroup('CMS — blog articles', 'fixture articles could not be inserted: ' . ($cmsFixtureError ?? ''));
} else {
    // What the index is *supposed* to show, computed from the table rather
    // than hard-coded, so the check survives new seed data.
    $visibleCount = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `blogs`
          WHERE `status` = 'published' AND `deleted_at` IS NULL
            AND (`published_at` IS NULL OR `published_at` <= NOW())"
    ) ?? 0);

    Suite::equals(
        'the index total counts exactly the publishable articles',
        (int) ($blogs->at('meta.pagination.total') ?? -1),
        $visibleCount
    );

    // Walk the index rather than trusting page one.
    //
    // The fixtures used to be almost the only articles in the table; with the
    // demo content seeded they sit well past the first page, so a membership
    // or ordering check against `$blogs->list()` alone would fail for a reason
    // that has nothing to do with the endpoint.
    $indexSlugs = [];
    $lastPage   = (int) ($blogs->at('meta.pagination.last_page') ?? 1);

    for ($page = 1; $page <= min($lastPage, 10); $page++) {
        $pageRows = Http::get('/blogs', ['page' => $page, 'per_page' => 30]);

        foreach ($pageRows->list() as $row) {
            $indexSlugs[] = (string) ($row['slug'] ?? '');
        }
    }

    Suite::check(
        'a published article appears in the index',
        in_array('it-blog-alpha', $indexSlugs, true),
        'index slugs: ' . implode(', ', $indexSlugs)
    );

    Suite::check(
        'a draft article is not published to the index',
        !in_array('it-blog-draft', $indexSlugs, true),
        'the draft article leaked into /blogs'
    );

    $draft = Http::get('/blogs/it-blog-draft');
    Suite::status('a draft article 404s when fetched by slug', $draft, 404);

    Suite::check(
        'an article dated in the future is held back from the index',
        !in_array('it-blog-embargoed', $indexSlugs, true),
        'the embargoed article leaked into /blogs'
    );

    // The index applies `published_at <= NOW()`; the detail route does not.
    // An embargoed article is therefore readable ahead of its release date by
    // anyone who can guess the slug.
    $embargoed = Http::get('/blogs/it-blog-embargoed');
    Suite::status('an article dated in the future is not readable by slug either', $embargoed, 404);

    // is_featured DESC, then published_at DESC. Beta is newer *and* featured;
    // alpha is neither. Assert the order rather than merely the membership.
    $alphaAt = array_search('it-blog-alpha', $indexSlugs, true);
    $betaAt  = array_search('it-blog-beta', $indexSlugs, true);

    Suite::check(
        'featured articles sort ahead of unfeatured ones',
        is_int($alphaAt) && is_int($betaAt) && $betaAt < $alphaAt,
        sprintf('beta at %s, alpha at %s', json_encode($betaAt), json_encode($alphaAt))
    );

    $viewsBefore = (int) (Database::scalar(
        "SELECT `views_count` FROM `blogs` WHERE `slug` = 'it-blog-alpha'"
    ) ?? 0);

    $alpha = Http::get('/blogs/it-blog-alpha');
    Suite::ok('GET /blogs/{slug} resolves a known slug', $alpha);
    Suite::equals('the resolved article is the one that was asked for', $alpha->at('data.blog.slug'), 'it-blog-alpha');
    Suite::notEmpty('the resolved article carries its body', $alpha->at('data.blog.content'));
    Suite::equals('the article joins its category name', $alpha->at('data.blog.category_name'), 'For Brands');

    Suite::check(
        'article tags are decoded from JSON into a list',
        $alpha->at('data.blog.tags') === ['it', 'alpha'],
        'tags were: ' . json_encode($alpha->at('data.blog.tags'))
    );

    Suite::check(
        'the article carries a related-articles list',
        is_array($alpha->at('data.blog.related')),
        'related was: ' . json_encode($alpha->at('data.blog.related'))
    );

    Suite::check(
        'related articles never include the article itself',
        static function () use ($alpha): bool {
            $related = $alpha->at('data.blog.related');

            return is_array($related) && !in_array('it-blog-alpha', array_column($related, 'slug'), true);
        },
        'the article listed itself as related'
    );

    $viewsAfter = (int) (Database::scalar(
        "SELECT `views_count` FROM `blogs` WHERE `slug` = 'it-blog-alpha'"
    ) ?? 0);

    Suite::equals('reading an article increments its view counter', $viewsAfter, $viewsBefore + 1);

    $byCategory = Http::get('/blogs', ['category' => 'for-creators']);
    Suite::ok('GET /blogs?category filters by category slug', $byCategory);

    $categorySlugs = array_column($byCategory->list(), 'slug');
    Suite::check(
        '?category=for-creators returns the beta article and drops the alpha one',
        in_array('it-blog-beta', $categorySlugs, true) && !in_array('it-blog-alpha', $categorySlugs, true),
        'slugs returned: ' . implode(', ', $categorySlugs)
    );

    $search = Http::get('/blogs', ['q' => 'zustequark']);
    Suite::ok('GET /blogs?q searches titles and excerpts', $search);
    Suite::check(
        '?q matches only the article containing the term',
        array_column($search->list(), 'slug') === ['it-blog-alpha'],
        'slugs returned: ' . implode(', ', array_column($search->list(), 'slug'))
    );

    $paged = Http::get('/blogs', ['per_page' => 1]);
    Suite::ok('GET /blogs honours per_page', $paged);
    Suite::equals('per_page=1 returns a single article', count($paged->list()), 1);
    Suite::equals(
        'per_page=1 reports more pages to come',
        $paged->at('meta.pagination.has_more'),
        $visibleCount > 1
    );

    $capped = Http::get('/blogs', ['per_page' => 999]);
    Suite::check(
        'an oversized per_page is capped rather than honoured',
        (int) ($capped->at('meta.pagination.per_page') ?? 0) <= 30,
        'per_page came back as ' . json_encode($capped->at('meta.pagination.per_page'))
    );
}

// -----------------------------------------------------------------
Suite::group('CMS — testimonials');

$testimonials = Http::get('/testimonials');
Suite::ok('GET /testimonials returns the list', $testimonials);

$quotes = $testimonials->list('testimonials');
Suite::notEmpty('the testimonial list carries content', $quotes, $testimonials->describe());

Suite::check(
    'every testimonial carries an author and a quote',
    static function () use ($quotes): bool {
        if ($quotes === []) {
            return false;
        }

        foreach ($quotes as $quote) {
            if (trim((string) ($quote['author_name'] ?? '')) === '' || trim((string) ($quote['content'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    },
    'a testimonial was missing its author or body'
);

Suite::check(
    'testimonial ratings are within the 1–5 scale',
    static function () use ($quotes): bool {
        foreach ($quotes as $quote) {
            $rating = (int) ($quote['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) {
                return false;
            }
        }

        return $quotes !== [];
    },
    'ratings returned: ' . implode(', ', array_column($quotes, 'rating'))
);

Suite::equals(
    'the endpoint returns exactly the active testimonials (capped at 12)',
    count($quotes),
    min(12, (int) (Database::scalar('SELECT COUNT(*) FROM `testimonials` WHERE `is_active` = 1') ?? 0))
);

// -----------------------------------------------------------------
Suite::group('CMS — contact form');

// There is no read side to this resource — messages go to the admin panel and
// nowhere else. Confirm the route really is write-only.
$readContact = Http::get('/contact');
Suite::status('GET /contact is not a readable endpoint', $readContact, [404, 405]);

$emptyContact = Http::post('/contact', []);
Suite::status('an empty contact submission is rejected', $emptyContact, 422);
Suite::equals('the rejection is a validation failure', $emptyContact->code(), 'validation_failed');

Suite::check(
    'the validation failure names every missing field',
    static function () use ($emptyContact): bool {
        $errors = $emptyContact->at('errors') ?? $emptyContact->at('data.errors');

        if (!is_array($errors)) {
            return false;
        }

        foreach (['email', 'subject', 'message'] as $field) {
            if (!array_key_exists($field, $errors)) {
                return false;
            }
        }

        return true;
    },
    'errors were: ' . json_encode($emptyContact->at('errors'))
);

$badEmail = Http::post('/contact', [
    'name'    => 'IT Contact',
    'email'   => 'not-an-email',
    'subject' => 'Integration test',
    'message' => 'This message is comfortably longer than ten characters.',
]);
Suite::status('a malformed email is rejected', $badEmail, 422);

$shortMessage = Http::post('/contact', [
    'name'    => 'IT Contact',
    'email'   => 'it-contact@zuste.test',
    'subject' => 'Integration test',
    'message' => 'too short',
]);
Suite::status('a message under the minimum length is rejected', $shortMessage, 422);

$badPhone = Http::post('/contact', [
    'name'    => 'IT Contact',
    'email'   => 'it-contact@zuste.test',
    'phone'   => '12',
    'subject' => 'Integration test',
    'message' => 'This message is comfortably longer than ten characters.',
]);
Suite::status('a malformed phone number is rejected', $badPhone, 422);

$overlongSubject = Http::post('/contact', [
    'name'    => 'IT Contact',
    'email'   => 'it-contact@zuste.test',
    'subject' => str_repeat('A', 400),
    'message' => 'This message is comfortably longer than ten characters.',
]);
Suite::status('a subject beyond the column width is rejected, not truncated', $overlongSubject, 422);

$goodContact = Http::post('/contact', [
    'name'    => 'IT Contact',
    'email'   => 'it-contact@zuste.test',
    'phone'   => '9876543210',
    'subject' => 'Integration test enquiry',
    'message' => 'This message is comfortably longer than ten characters.',
]);
Suite::ok('a well-formed contact submission is accepted', $goodContact, 201);

$stored = Database::selectOne(
    "SELECT * FROM `contact_messages` WHERE `email` = 'it-contact@zuste.test' ORDER BY `id` DESC LIMIT 1"
);

Suite::check('the submission is persisted', $stored !== null, 'no contact_messages row was written');

if ($stored !== null) {
    Suite::equals('the stored subject matches what was sent', (string) $stored['subject'], 'Integration test enquiry');
    Suite::equals('the submission is tagged as coming from the app', (string) $stored['source'], 'app');
    Suite::equals('a new submission starts unread', (string) $stored['status'], 'new');
    Suite::notEmpty('the submission records the sender IP for abuse handling', $stored['ip_address']);
}

// -- injection / XSS -------------------------------------------------
// Two payloads in one submission: a script tag and a statement terminator with
// a DROP. The contract is "reject it, or store it verbatim and never execute
// it" — what must not happen is partial escaping that silently corrupts the
// message, or the SQL reaching the engine.
$xssPayload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
$sqlPayload = 'IT injection"); DROP TABLE `contact_messages`; -- and some filler text';

$contactRowsBefore = (int) (Database::scalar('SELECT COUNT(*) FROM `contact_messages`') ?? 0);

$hostile = Http::post('/contact', [
    'name'    => 'IT Hostile',
    'email'   => 'it-hostile@zuste.test',
    'subject' => $xssPayload,
    'message' => $sqlPayload,
]);

Suite::status('a hostile contact payload is either rejected or accepted, never a 500', $hostile, [201, 422]);

Suite::check(
    'the injection payload did not reach the SQL engine',
    static function (): bool {
        // If the DROP had executed the table would be gone and this throws.
        return Database::scalar('SELECT COUNT(*) FROM `contact_messages`') !== null;
    },
    'the contact_messages table is no longer queryable'
);

if ($hostile->status === 201) {
    $hostileRow = Database::selectOne(
        "SELECT * FROM `contact_messages` WHERE `email` = 'it-hostile@zuste.test' ORDER BY `id` DESC LIMIT 1"
    );

    Suite::check('the hostile submission was stored as one row', $hostileRow !== null, 'no row was written');

    Suite::equals(
        'exactly one row was added — the payload did not run extra statements',
        (int) (Database::scalar('SELECT COUNT(*) FROM `contact_messages`') ?? 0),
        $contactRowsBefore + 1
    );

    if ($hostileRow !== null) {
        Suite::equals(
            'the script payload is stored verbatim, as inert text',
            (string) $hostileRow['subject'],
            $xssPayload
        );

        Suite::equals(
            'the SQL payload is stored verbatim, as inert text',
            (string) $hostileRow['message'],
            $sqlPayload
        );
    }

    Suite::check(
        'the API response does not echo the script payload back to the caller',
        !str_contains($hostile->body, '<script>'),
        'the response body reflected the payload: ' . $hostile->describe()
    );
} else {
    Suite::check(
        'a rejected hostile payload wrote nothing',
        (int) (Database::scalar('SELECT COUNT(*) FROM `contact_messages`') ?? 0) === $contactRowsBefore,
        'a row was written despite the rejection'
    );
}

// -----------------------------------------------------------------
Suite::group('App config — /app/config');

$config = Http::get('/app/config');
Suite::ok('GET /app/config responds without a token', $config);

Suite::check(
    '/app/config carries every block the client boots from',
    static function () use ($config): bool {
        $data = $config->data();

        foreach (['app', 'branding', 'update', 'maintenance', 'features', 'auth', 'popups', 'commission', 'booking'] as $block) {
            if (!array_key_exists($block, $data)) {
                return false;
            }
        }

        return true;
    },
    'blocks present: ' . implode(', ', array_keys($config->data()))
);

$features = $config->at('data.features');

Suite::check(
    'the feature block is a map of booleans',
    static function () use ($features): bool {
        if (!is_array($features) || $features === []) {
            return false;
        }

        foreach ($features as $value) {
            if (!is_bool($value)) {
                return false;
            }
        }

        return true;
    },
    'features were: ' . json_encode($features)
);

$flagKeys = Database::column('SELECT `flag_key` FROM `feature_flags`');

if ($flagKeys === []) {
    Suite::skipGroup('App config — feature flag evaluation', 'no rows in feature_flags');
} else {
    Suite::check(
        'every row in feature_flags is evaluated into the response',
        static function () use ($features, $flagKeys): bool {
            foreach ($flagKeys as $key) {
                if (!array_key_exists((string) $key, (array) $features)) {
                    return false;
                }
            }

            return true;
        },
        'missing: ' . implode(', ', array_diff(array_map('strval', $flagKeys), array_keys((array) $features)))
    );

    // A disabled flag must never evaluate true, whatever its audience says.
    $disabled = Database::column('SELECT `flag_key` FROM `feature_flags` WHERE `is_enabled` = 0');

    Suite::check(
        'a disabled feature flag never reaches the client as on',
        static function () use ($features, $disabled): bool {
            foreach ($disabled as $key) {
                if (((array) $features)[(string) $key] ?? false) {
                    return false;
                }
            }

            return true;
        },
        'a flag with is_enabled = 0 came back true'
    );

    // Audience targeting is the whole point of the table, so prove it moves.
    $brandOnly = Database::scalar(
        "SELECT `flag_key` FROM `feature_flags`
          WHERE `is_enabled` = 1 AND `audience` = 'brands' AND `rollout_percent` >= 100 LIMIT 1"
    );

    if ($brandOnly === null) {
        Suite::skipGroup(
            'App config — flag audience targeting',
            'no fully rolled-out brand-only flag to test audience narrowing with'
        );
    } else {
        $brand   = Actors::create('brand', 'brand');
        $creator = Actors::create('creator', 'creator');

        if ($brand === null || $creator === null) {
            // Not a backend defect — the login throttle bites when this suite
            // is run on its own several times in a row. Recorded, never a
            // silent pass.
            Suite::skipGroup(
                'App config — flag audience targeting',
                'need both a brand and a creator session to compare: ' . Actors::lastError()
            );
        } else {
            $brandConfig = Http::get('/app/config', [], 'brand');
            Suite::ok('GET /app/config with a brand token succeeds', $brandConfig);

            Suite::equals(
                "a brand-audience flag ({$brandOnly}) is on for a brand caller",
                $brandConfig->at('data.features.' . $brandOnly),
                true
            );

            $creatorConfig = Http::get('/app/config', [], 'creator');
            Suite::ok('GET /app/config with a creator token succeeds', $creatorConfig);

            // AppContentService evaluates `audience` against the caller's
            // account type, so a brands-only flag must be off here. It will
            // only be off if the route actually attaches the caller.
            Suite::check(
                "a brand-audience flag ({$brandOnly}) is off for a creator caller",
                $creatorConfig->at('data.features.' . $brandOnly) === false,
                'the flag came back '
                    . json_encode($creatorConfig->at('data.features.' . $brandOnly))
                    . ' — /app/config is routed without `auth.optional`, so the bearer token is'
                    . ' never resolved and every caller is evaluated as an anonymous brand'
            );
        }
    }
}

Suite::check(
    '/app/config exposes popups as a list',
    is_array($config->at('data.popups')),
    'popups were: ' . json_encode($config->at('data.popups'))
);

// -- secrets --------------------------------------------------------
// /app/config is unauthenticated. Anything in it is public by definition, so
// assert the credential-bearing settings are not in the payload.
Suite::check(
    '/app/config does not carry any private credential',
    static function () use ($config): bool {
        $secrets = Database::select(
            "SELECT `value` FROM `settings`
              WHERE `is_public` = 0 AND `is_encrypted` = 1 AND `value` IS NOT NULL AND `value` <> ''"
        );

        foreach ($secrets as $secret) {
            if (str_contains($config->body, (string) $secret['value'])) {
                return false;
            }
        }

        // Key names as well as values — a leaked key name means a leaked block.
        foreach (['razorpay_key_secret', 'stripe_secret_key', 'cashfree_secret_key', 'service_account_json', 'smtp_password'] as $name) {
            if (str_contains($config->body, $name)) {
                return false;
            }
        }

        return true;
    },
    'a private credential appeared in the /app/config payload'
);

// -----------------------------------------------------------------
Suite::group('App config — /app/config conditional GET');

$configEtag = $config->header('etag');
Suite::notEmpty('/app/config returns an ETag', $configEtag, 'no ETag header on /app/config');

if (is_string($configEtag) && $configEtag !== '') {
    $configFresh = Http::get('/app/config', [], null, ['If-None-Match' => $configEtag]);
    Suite::status('/app/config revalidates to 304 for a current ETag', $configFresh, 304);
    Suite::equals('the 304 body is empty', trim($configFresh->body), '');

    $configStale = Http::get('/app/config', [], null, ['If-None-Match' => '"stale-validator-000"']);
    Suite::status('/app/config returns 200 for a stale ETag', $configStale, 200);

    Suite::check(
        '/app/config sets a revalidate-every-time cache policy',
        str_contains(strtolower((string) $config->header('cache-control')), 'must-revalidate'),
        'Cache-Control was: ' . json_encode($config->header('cache-control'))
    );
}

// -----------------------------------------------------------------
Suite::group('App config — popups');

$popupFixtures = true;

try {
    Database::execute(
        "INSERT INTO `popups` (`name`, `type`, `title`, `content`, `placement`, `audience`, `is_active`, `priority`, `starts_at`, `ends_at`)
         VALUES
           ('IT Popup Everyone', 'modal', 'IT everyone', 'shown to all', 'all', 'all', 1, 50, NULL, NULL),
           ('IT Popup Creators', 'modal', 'IT creators', 'creators only', 'all', 'creators', 1, 40, NULL, NULL),
           ('IT Popup Brands', 'modal', 'IT brands', 'brands only', 'all', 'brands', 1, 45, NULL, NULL),
           ('IT Popup Expired', 'modal', 'IT expired', 'already over', 'all', 'all', 1, 60, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
           ('IT Popup Disabled', 'modal', 'IT disabled', 'switched off', 'all', 'all', 0, 70, NULL, NULL)"
    );
} catch (\Throwable $e) {
    $popupFixtures = false;
    $popupError    = $e->getMessage();
}

if (!$popupFixtures) {
    Suite::skipGroup('App config — popups', 'fixture popups could not be inserted: ' . ($popupError ?? ''));
} else {
    $withPopups = Http::get('/app/config');
    $popupList  = (array) $withPopups->at('data.popups');
    $titles     = array_column($popupList, 'title');

    Suite::check(
        'an active popup for everyone reaches an anonymous caller',
        in_array('IT everyone', $titles, true),
        'popup titles: ' . implode(', ', $titles)
    );

    Suite::check(
        'an expired popup is not served',
        !in_array('IT expired', $titles, true),
        'the expired popup was served'
    );

    Suite::check(
        'a deactivated popup is not served',
        !in_array('IT disabled', $titles, true),
        'the disabled popup was served'
    );

    Suite::check(
        'a creator-audience popup is withheld from a non-creator caller',
        !in_array('IT creators', $titles, true),
        'the creator-only popup was served to an anonymous caller'
    );

    Suite::check(
        'a brand-audience popup is withheld from a signed-out caller',
        !in_array('IT brands', $titles, true),
        'a brand-targeted popup reached someone who is not signed in at all — '
            . 'popup titles: ' . implode(', ', $titles)
    );

    if (Actors::get('creator') !== null) {
        $creatorPopups = array_column((array) Http::get('/app/config', [], 'creator')->at('data.popups'), 'title');

        Suite::check(
            'a creator-audience popup does reach a creator',
            in_array('IT creators', $creatorPopups, true),
            'creator saw: ' . implode(', ', $creatorPopups)
                . ' — /app/config is routed without `auth.optional`, so the caller is'
                . ' always evaluated as an anonymous brand and creator-targeted'
                . ' popups are undeliverable'
        );
    } else {
        Suite::skipGroup('App config — creator popup targeting', 'no creator session available');
    }

    Suite::check(
        'popups are ordered by descending priority',
        static function () use ($popupList): bool {
            $priorities = [];

            foreach ($popupList as $popup) {
                // The presenter drops `priority`, so compare via the ids the
                // API did return against the order the table defines.
                $priorities[] = (int) $popup['id'];
            }

            // `$popupList` came from an ANONYMOUS request, so the expected set
            // is what a signed-out visitor may see: `all` and `guests`.
            //
            // This used to say `IN ('all', 'brands')`, which asserted a real
            // defect rather than the intended behaviour: callers pass 'brand'
            // as the default account type for an unauthenticated request, and
            // audienceMatches() compared that string without also testing that
            // anyone was signed in — so every brand-targeted popup, flag and
            // menu entry was served to logged-out visitors.
            $expected = array_map('intval', Database::column(
                "SELECT `id` FROM `popups`
                  WHERE `is_active` = 1 AND `deleted_at` IS NULL
                    AND (`starts_at` IS NULL OR `starts_at` <= NOW())
                    AND (`ends_at` IS NULL OR `ends_at` >= NOW())
                    AND `audience` IN ('all', 'guests')
                  ORDER BY `priority` DESC, `id`"
            ));

            return $priorities === $expected;
        },
        'popup order did not match priority DESC'
    );

    // The /app/config validator is derived from the `settings` and
    // `integrations` tables only. Popups, feature flags and homepage sections
    // are all in the payload but none of them move the tag, so a client that
    // revalidates keeps being told nothing changed.
    $beforeInsert = Http::get('/app/config');
    $staleTag     = (string) $beforeInsert->header('etag');

    Database::execute(
        "INSERT INTO `popups` (`name`, `type`, `title`, `content`, `placement`, `audience`, `is_active`, `priority`)
         VALUES ('IT Popup Late', 'modal', 'IT late arrival', 'added after the tag', 'all', 'all', 1, 10)"
    );

    $afterInsert = Http::get('/app/config', [], null, ['If-None-Match' => $staleTag]);

    Suite::status(
        'adding a popup invalidates the /app/config ETag',
        $afterInsert,
        200
    );

    Suite::check(
        'a revalidating client sees a newly added popup',
        static function () use ($afterInsert): bool {
            if ($afterInsert->status !== 200) {
                return false;
            }

            return in_array('IT late arrival', array_column((array) $afterInsert->at('data.popups'), 'title'), true);
        },
        'the client was told 304 and would keep serving the stale config'
    );
}

// -----------------------------------------------------------------
Suite::group('App config — /settings exposure boundary');

$settings = Http::get('/settings');
Suite::ok('GET /settings responds without a token', $settings);

$exposed = $settings->at('data.settings');
Suite::check('settings are grouped by group_key', is_array($exposed) && $exposed !== [], $settings->describe());

/** Is `group.key` present in the published settings map? */
$settingExposed = static function (string $group, string $key) use ($exposed): bool {
    $groupMap = is_array($exposed) ? ($exposed[$group] ?? null) : null;

    return is_array($groupMap) && array_key_exists($key, $groupMap);
};

/** The published value of `group.key`, or null when it is absent. */
$settingValue = static function (string $group, string $key) use ($exposed): mixed {
    $groupMap = is_array($exposed) ? ($exposed[$group] ?? null) : null;

    return is_array($groupMap) ? ($groupMap[$key] ?? null) : null;
};

$privateRows = Database::select(
    'SELECT `group_key`, `setting_key` FROM `settings` WHERE `is_public` = 0 ORDER BY `group_key`, `setting_key`'
);

if ($privateRows === []) {
    Suite::skipGroup('App config — private setting leakage', 'every settings row is flagged is_public');
} else {
    // The real check: query the table for the keys that are NOT public and
    // prove each one is absent from the response. Nesting matters — `enabled`
    // is public under `wallet` and private under `sms`, so the group has to be
    // part of the lookup or the check would report a false pass.
    $leaked = [];

    foreach ($privateRows as $row) {
        if ($settingExposed((string) $row['group_key'], (string) $row['setting_key'])) {
            $leaked[] = $row['group_key'] . '.' . $row['setting_key'];
        }
    }

    Suite::equals(
        sprintf('none of the %d non-public settings appear in /settings', count($privateRows)),
        $leaked,
        []
    );

    // Pick one named private key and assert it individually, so a regression
    // report names something concrete rather than a count.
    $namedPrivate = Database::selectOne(
        "SELECT `group_key`, `setting_key` FROM `settings`
          WHERE `is_public` = 0 AND `group_key` = 'security' ORDER BY `setting_key` LIMIT 1"
    ) ?? $privateRows[0];

    $namedGroup = (string) $namedPrivate['group_key'];
    $namedKey   = (string) $namedPrivate['setting_key'];

    Suite::check(
        "the private setting {$namedGroup}.{$namedKey} is absent from /settings",
        !$settingExposed($namedGroup, $namedKey),
        'the key was present in the response with value ' . json_encode($settingValue($namedGroup, $namedKey))
    );

    Suite::check(
        'no private setting value appears anywhere in the raw /settings body',
        static function () use ($settings): bool {
            $rows = Database::select(
                "SELECT `group_key`, `setting_key`, `value` FROM `settings`
                  WHERE `is_public` = 0 AND `value` IS NOT NULL AND LENGTH(`value`) >= 12"
            );

            foreach ($rows as $row) {
                // Short or numeric values collide with legitimate public data,
                // so only distinctive strings are searched for.
                $value = (string) $row['value'];

                if (is_numeric($value) || in_array(strtolower($value), ['true', 'false'], true)) {
                    continue;
                }

                if (str_contains($settings->body, $value)) {
                    return false;
                }
            }

            return true;
        },
        'a non-public setting value was found in the /settings body'
    );
}

$publicRows = Database::select(
    'SELECT `group_key`, `setting_key`, `type` FROM `settings` WHERE `is_public` = 1 AND `is_encrypted` = 0'
);

if ($publicRows === []) {
    Suite::skipGroup('App config — public setting completeness', 'no public, unencrypted settings rows');
} else {
    $missing = [];

    foreach ($publicRows as $row) {
        if (!$settingExposed((string) $row['group_key'], (string) $row['setting_key'])) {
            $missing[] = $row['group_key'] . '.' . $row['setting_key'];
        }
    }

    Suite::equals(
        sprintf('all %d public settings are exposed', count($publicRows)),
        $missing,
        []
    );
}

$encryptedLeaks = [];

foreach (Database::select('SELECT `group_key`, `setting_key` FROM `settings` WHERE `is_encrypted` = 1') as $row) {
    if ($settingExposed((string) $row['group_key'], (string) $row['setting_key'])) {
        $encryptedLeaks[] = $row['group_key'] . '.' . $row['setting_key'];
    }
}

Suite::equals(
    'an encrypted setting is never served, even when flagged public',
    $encryptedLeaks,
    []
);

$miscast = [];

foreach (Database::select(
    "SELECT `group_key`, `setting_key`, `type` FROM `settings`
      WHERE `is_public` = 1 AND `is_encrypted` = 0 AND `type` IN ('boolean', 'integer', 'decimal')"
) as $row) {
    $value = $settingValue((string) $row['group_key'], (string) $row['setting_key']);

    if ($value === null) {
        continue;
    }

    $ok = match ((string) $row['type']) {
        'boolean' => is_bool($value),
        'integer' => is_int($value),
        'decimal' => is_float($value) || is_int($value),
        default   => true,
    };

    if (!$ok) {
        $miscast[] = $row['group_key'] . '.' . $row['setting_key'] . ' (' . $row['type'] . ' => ' . gettype($value) . ')';
    }
}

Suite::equals(
    'typed settings are cast, not returned as raw strings',
    $miscast,
    []
);

// -----------------------------------------------------------------
Suite::group('App config — taxonomy bundle');

$taxonomies = Http::get('/app/taxonomies');
Suite::ok('GET /app/taxonomies returns the bundle', $taxonomies);

Suite::check(
    'the bundle carries a version stamp and the taxonomy map',
    is_string($taxonomies->at('data.version')) && is_array($taxonomies->at('data.taxonomies')),
    $taxonomies->describe()
);

Suite::check(
    'the bundle covers every active taxonomy that has terms',
    static function () use ($taxonomies): bool {
        $expected = Database::column(
            'SELECT DISTINCT t.`key`
               FROM `taxonomies` t
               INNER JOIN `taxonomy_terms` tt ON tt.`taxonomy_id` = t.`id`
              WHERE t.`is_active` = 1 AND tt.`is_active` = 1'
        );

        $returned = array_keys((array) $taxonomies->at('data.taxonomies'));

        return $expected !== [] && array_diff(array_map('strval', $expected), $returned) === [];
    },
    'bundle keys: ' . implode(', ', array_keys((array) $taxonomies->at('data.taxonomies')))
);

Suite::check(
    'each term carries a value and a label the client can render',
    static function () use ($taxonomies): bool {
        $bundle = (array) $taxonomies->at('data.taxonomies');

        if ($bundle === []) {
            return false;
        }

        foreach ($bundle as $terms) {
            foreach ((array) $terms as $term) {
                if (!isset($term['value'], $term['label']) || (string) $term['label'] === '') {
                    return false;
                }
            }
        }

        return true;
    },
    'a term was missing value/label'
);

Suite::check(
    'the bundle carries the numeric discovery bounds the filters need',
    static function () use ($taxonomies): bool {
        $discovery = $taxonomies->at('data.discovery');

        return is_array($discovery)
            && array_key_exists('max_budget', $discovery)
            && array_key_exists('max_followers', $discovery)
            && array_key_exists('default_page_size', $discovery);
    },
    'discovery block was: ' . json_encode($taxonomies->at('data.discovery'))
);

$taxonomyEtag = $taxonomies->header('etag');
Suite::notEmpty('the taxonomy bundle returns an ETag', $taxonomyEtag, 'no ETag header on /app/taxonomies');

Suite::check(
    'the ETag is the bundle version, so an admin edit moves it',
    is_string($taxonomyEtag) && trim($taxonomyEtag, '"') === (string) $taxonomies->at('data.version'),
    sprintf('etag %s vs version %s', json_encode($taxonomyEtag), json_encode($taxonomies->at('data.version')))
);

if (is_string($taxonomyEtag) && $taxonomyEtag !== '') {
    $revalidated = Http::get('/app/taxonomies', [], null, ['If-None-Match' => $taxonomyEtag]);
    Suite::status('re-requesting with a matching If-None-Match returns 304', $revalidated, 304);
    Suite::equals('the 304 carries no body', trim($revalidated->body), '');
    Suite::equals('the 304 repeats the validator', $revalidated->header('etag'), $taxonomyEtag);

    $weak = Http::get('/app/taxonomies', [], null, ['If-None-Match' => 'W/' . $taxonomyEtag]);
    Suite::status('a weak validator prefix still matches', $weak, 304);

    $staleTaxonomy = Http::get('/app/taxonomies', [], null, ['If-None-Match' => '"0000000000000000"']);
    Suite::status('a stale If-None-Match returns 200 with the bundle', $staleTaxonomy, 200);
    Suite::ok('the 200 after a stale validator carries the bundle', $staleTaxonomy);
} else {
    Suite::skipGroup('App config — taxonomy conditional GET', 'no ETag was issued to revalidate with');
}

// -----------------------------------------------------------------
Suite::group('App config — single taxonomy and onboarding');

$cityKey = (string) (Database::scalar(
    "SELECT t.`key` FROM `taxonomies` t
      INNER JOIN `taxonomy_terms` tt ON tt.`taxonomy_id` = t.`id`
     WHERE t.`is_active` = 1 AND tt.`is_active` = 1
     GROUP BY t.`key` ORDER BY COUNT(*) DESC LIMIT 1"
) ?? 'city');

$single = Http::get('/app/taxonomies/' . $cityKey);
Suite::ok("GET /app/taxonomies/{$cityKey} returns one list", $single);
Suite::equals('the response echoes the requested key', $single->at('data.key'), $cityKey);

Suite::check(
    'the single list matches the bundle for the same key',
    static function () use ($single, $taxonomies, $cityKey): bool {
        $fromBundle = ((array) $taxonomies->at('data.taxonomies'))[$cityKey] ?? null;
        $fromSingle = $single->at('data.terms');

        return is_array($fromBundle) && $fromBundle === $fromSingle;
    },
    'the single-key response diverged from the bundle'
);

Suite::equals(
    'the count field matches the number of terms returned',
    $single->at('data.count'),
    count((array) $single->at('data.terms'))
);

// An unknown key is a valid request for an empty list, not an error: the route
// regexp already rejects anything that is not a lowercase key.
$unknownKey = Http::get('/app/taxonomies/no_such_list');
Suite::ok('an unknown taxonomy key returns an empty list rather than an error', $unknownKey);
Suite::equals('the empty list is genuinely empty', $unknownKey->at('data.terms'), []);

$badKey = Http::get('/app/taxonomies/NotAKey123');
Suite::status('a key that does not match the route pattern is not routed here', $badKey, [404, 405]);

$onboarding = Http::get('/app/onboarding');
Suite::ok('GET /app/onboarding returns the intro slides', $onboarding);

$slides = $onboarding->list('slides');
Suite::notEmpty('the onboarding deck carries slides', $slides, $onboarding->describe());

Suite::equals(
    'the deck matches the active slides for the default audience',
    count($slides),
    (int) (Database::scalar(
        "SELECT COUNT(*) FROM `onboarding_slides` WHERE `is_active` = 1 AND `audience` = 'all'"
    ) ?? 0)
);

Suite::check(
    'every slide carries a title',
    static function () use ($slides): bool {
        foreach ($slides as $slide) {
            if (trim((string) ($slide['title'] ?? '')) === '') {
                return false;
            }
        }

        return $slides !== [];
    },
    'a slide was missing its title'
);

$creatorSlides = Http::get('/app/onboarding', ['audience' => 'creators']);
Suite::ok('GET /app/onboarding?audience narrows the deck', $creatorSlides);

Suite::equals(
    'the creator deck matches the rows targeted at creators',
    count($creatorSlides->list('slides')),
    (int) (Database::scalar(
        "SELECT COUNT(*) FROM `onboarding_slides`
          WHERE `is_active` = 1 AND (`audience` = 'all' OR `audience` = 'creators')"
    ) ?? 0)
);

// An audience the enum does not know must fall back to `all`, not error.
$junkAudience = Http::get('/app/onboarding', ['audience' => 'martians']);
Suite::ok('an unrecognised audience falls back to the default deck', $junkAudience);
Suite::equals(
    'the fallback deck is the default one',
    count($junkAudience->list('slides')),
    count($slides)
);

// -----------------------------------------------------------------
// Teardown
// -----------------------------------------------------------------

Suite::group('CMS — cleanup');

$cmsCleanup();

Suite::equals(
    'every fixture page, article, popup and contact message is removed',
    [
        'pages'    => (int) (Database::scalar("SELECT COUNT(*) FROM `pages` WHERE `slug` LIKE 'it-%'") ?? -1),
        'blogs'    => (int) (Database::scalar("SELECT COUNT(*) FROM `blogs` WHERE `slug` LIKE 'it-%'") ?? -1),
        'popups'   => (int) (Database::scalar("SELECT COUNT(*) FROM `popups` WHERE `name` LIKE 'IT %'") ?? -1),
        'contacts' => (int) (Database::scalar("SELECT COUNT(*) FROM `contact_messages` WHERE `email` LIKE 'it-%@zuste.test'") ?? -1),
    ],
    ['pages' => 0, 'blogs' => 0, 'popups' => 0, 'contacts' => 0]
);
