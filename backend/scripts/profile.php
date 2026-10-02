<?php

declare(strict_types=1);

/**
 * Query profiler for the read paths the app hits most.
 *
 * Invokes each service in-process and counts the queries it issues, which is
 * the number that matters: an endpoint answering in 100ms today because the
 * tables hold 6,000 rows will answer in 4 seconds at 600,000 if it is doing
 * N+1 work. Latency hides that; a query count does not.
 *
 *     php scripts/profile.php
 *     php scripts/profile.php --slow    # also EXPLAIN the heaviest queries
 *
 * Run against a seeded database or the numbers mean nothing.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

App::boot($basePath);

$explain = in_array('--slow', $argv, true);

echo "\n\033[1mZusty query profile\033[0m\n";
echo str_repeat('=', 76) . "\n";

$rows = (int) (Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `status` = 'approved'") ?? 0);
echo "  against {$rows} approved creators\n\n";

printf("  %-44s %8s %10s %s\n", 'operation', 'queries', 'time', '');
echo '  ' . str_repeat('-', 72) . "\n";

/** @var list<array{name:string,queries:int,ms:float}> */
$results = [];

/**
 * Runs a closure and reports how many queries it issued.
 *
 * @param callable():mixed $operation
 */
function profile(string $name, callable $operation, int $budget): void
{
    global $results;

    $before  = Database::queryCount();
    $started = microtime(true);

    try {
        $operation();
    } catch (\Throwable $e) {
        printf("  %-44s %8s %10s  \033[31m%s\033[0m\n", $name, '-', '-', $e->getMessage());
        return;
    }

    $queries = Database::queryCount() - $before;
    $ms      = (microtime(true) - $started) * 1000;

    // The budget is what this operation SHOULD cost. Exceeding it is the
    // signal — a list endpoint whose cost grows with the page size is doing
    // per-row work that belongs in a join.
    $flag = $queries > $budget ? sprintf("\033[31m%d over budget\033[0m", $queries - $budget) : '';

    printf("  %-44s %8d %8.1fms  %s\n", $name, $queries, $ms, $flag);

    $results[] = ['name' => $name, 'queries' => $queries, 'ms' => $ms, 'budget' => $budget];
}

// -----------------------------------------------------------------
echo "\n\033[1mDiscovery\033[0m\n";

profile('CreatorService::search — 20 creators', static function (): void {
    \Zuste\Services\CreatorService::search(['per_page' => 20]);
}, 3);

profile('CreatorService::search — 50 creators', static function (): void {
    \Zuste\Services\CreatorService::search(['per_page' => 50]);
}, 3);

profile('CreatorService::search — filtered + sorted', static function (): void {
    \Zuste\Services\CreatorService::search([
        'per_page'      => 20,
        'category_slug' => 'lifestyle',
        'min_rating'    => 4,
        'sort'          => 'rating',
    ]);
}, 3);

$handle = (string) (Database::scalar(
    "SELECT `handle` FROM `creators` WHERE `status` = 'approved' LIMIT 1"
) ?? '');

if ($handle !== '') {
    profile('CreatorService::profile — one creator', static function () use ($handle): void {
        \Zuste\Services\CreatorService::profile($handle, null);
    }, 8);
}

// -----------------------------------------------------------------
echo "\n\033[1mHome\033[0m\n";

profile('AppContentService::homeSections — guest', static function (): void {
    \Zuste\Services\AppContentService::homeSections(null, 'brand');
}, 12);

profile('AppContentService::featureFlags', static function (): void {
    \Zuste\Services\AppContentService::featureFlags(null, 'brand', 'android', '1.0.0');
}, 2);

// Budget 4, not 3: a cold read is one version-stamp query (whose subqueries
// count as one statement), one for the taxonomies, one for the terms, and one
// cache write. Warm is 0 — which is the number that matters, since a cold read
// only happens once per cache TTL.
profile('TaxonomyService::all (cold cache)', static function (): void {
    \Zuste\Services\TaxonomyService::flush();
    \Zuste\Services\TaxonomyService::all();
}, 4);

profile('TaxonomyService::all (warm cache)', static function (): void {
    \Zuste\Services\TaxonomyService::all();
}, 1);

// -----------------------------------------------------------------
echo "\n\033[1mTransactional\033[0m\n";

$brandId = (int) (Database::scalar(
    "SELECT `brand_user_id` FROM `bookings` GROUP BY `brand_user_id` ORDER BY COUNT(*) DESC LIMIT 1"
) ?? 0);

if ($brandId > 0) {
    // The booking list is assembled in BookingController rather than a service,
    // so this profiles the same query shape it issues.
    profile('bookings list — 20 for one brand', static function () use ($brandId): void {
        $rows = Database::select(
            'SELECT b.*, c.`display_name`, c.`handle`, u.`avatar_url`
               FROM `bookings` b
               INNER JOIN `creators` c ON c.`id` = b.`creator_id`
               INNER JOIN `users` u ON u.`id` = c.`user_id`
              WHERE b.`brand_user_id` = :id AND b.`deleted_at` IS NULL
              ORDER BY b.`created_at` DESC LIMIT 20',
            ['id' => $brandId]
        );

        // Touch each row the way a presenter would, so an N+1 in the
        // presentation layer would show up here rather than hiding.
        foreach ($rows as $row) {
            \Zuste\Services\BookingService::find((int) $row['id']);
        }
    }, 25);
}

$campaignId = (int) (Database::scalar('SELECT `id` FROM `campaigns` LIMIT 1') ?? 0);

if ($campaignId > 0) {
    profile('BrandCampaignService::applications', static function () use ($campaignId): void {
        \Zuste\Services\BrandCampaignService::applications($campaignId);
    }, 2);
}

// -----------------------------------------------------------------
echo "\n\033[1mIndex coverage on the hot paths\033[0m\n\n";

// A full scan on a table this size is invisible; at 100x it is an outage.
$probes = [
    'creators listing' => "SELECT c.`id` FROM `creators` c
                            INNER JOIN `users` u ON u.`id` = c.`user_id`
                            WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL
                              AND u.`status` = 'active'
                            ORDER BY c.`is_featured` DESC, c.`rating_avg` DESC LIMIT 20",

    'creators by category' => "SELECT c.`id` FROM `creators` c
                                INNER JOIN `creator_categories` cc ON cc.`creator_id` = c.`id`
                                WHERE cc.`category_id` = 1 AND c.`status` = 'approved' LIMIT 20",

    'bookings for a brand' => "SELECT `id` FROM `bookings`
                                WHERE `brand_user_id` = {$brandId} AND `deleted_at` IS NULL
                                ORDER BY `created_at` DESC LIMIT 20",

    'unread notifications' => "SELECT COUNT(*) FROM `notifications`
                                WHERE `user_id` = {$brandId} AND `read_at` IS NULL",

    'wallet ledger' => "SELECT `id` FROM `wallet_transactions`
                         WHERE `user_id` = {$brandId} ORDER BY `id` DESC LIMIT 20",

    'conversation messages' => "SELECT `id` FROM `messages`
                                 WHERE `conversation_id` = 801 ORDER BY `id` DESC LIMIT 30",
];

foreach ($probes as $label => $sql) {
    try {
        $plan = Database::select('EXPLAIN ' . $sql);

        $worst = null;

        foreach ($plan as $step) {
            $type = strtoupper((string) ($step['type'] ?? ''));

            // ALL means a full table scan; index-only scans are nearly as bad
            // on a large table when they are not covering.
            if (in_array($type, ['ALL', 'INDEX'], true)) {
                $worst = $step;
                break;
            }
        }

        if ($worst === null) {
            printf("  \033[32mOK\033[0m    %-30s\n", $label);
        } else {
            printf(
                "  \033[33mSCAN\033[0m  %-30s table=%s type=%s rows=%s key=%s\n",
                $label,
                $worst['table'] ?? '?',
                $worst['type'] ?? '?',
                $worst['rows'] ?? '?',
                $worst['key'] ?? 'NONE'
            );
        }

        if ($explain) {
            foreach ($plan as $step) {
                printf(
                    "          %-22s type=%-8s key=%-24s rows=%-8s %s\n",
                    $step['table'] ?? '?',
                    $step['type'] ?? '?',
                    $step['key'] ?? '-',
                    $step['rows'] ?? '?',
                    $step['Extra'] ?? ''
                );
            }
        }
    } catch (\Throwable $e) {
        printf("  \033[31mERR\033[0m   %-30s %s\n", $label, $e->getMessage());
    }
}

// -----------------------------------------------------------------
echo "\n" . str_repeat('=', 76) . "\n";

$over = array_values(array_filter($results, static fn (array $r): bool => $r['queries'] > $r['budget']));

if ($over === []) {
    echo "  \033[32mEvery profiled operation is inside its query budget.\033[0m\n\n";
    exit(0);
}

echo "  \033[33mOver budget:\033[0m\n";

foreach ($over as $r) {
    printf("    %-44s %d queries (budget %d)\n", $r['name'], $r['queries'], $r['budget']);
}

echo "\n";
exit(1);
