<?php

declare(strict_types=1);

/**
 * Notification template coverage.
 *
 *     php scripts/notification_coverage.php
 *
 * Two failure modes, both silent, both previously present:
 *
 *   - a code the application SENDS with no template behind it, so the
 *     notification is dropped and the user is simply never told. All seven
 *     `campaign.*` codes were in this state, and system_logs had recorded
 *     40 drops;
 *   - a template that EXISTS, is active, is editable by an admin, and is
 *     never sent by anything — `booking.requested` sat like this from the
 *     start, so a creator was never told a booking request had arrived.
 *
 * Neither shows up in a test that only checks HTTP status codes, which is why
 * this exists as its own check.
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

// -----------------------------------------------------------------
// Every literal code the application sends
// -----------------------------------------------------------------

/** @var array<string,list<string>> code => files that send it */
$sent = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($basePath . '/app', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());

    // Anchor on the call site, then take the first quoted code after it.
    //
    // One regex cannot do this well. Matching `send(\s*[^,]+,\s*'code'` runs
    // from a `public function send(Request $request)` declaration into the
    // `authorize($request, 'marketing.send')` call below it and reports a
    // PERMISSION slug as a missing template. Tightening the first argument to
    // `[^,()]+` then rejects the real calls, whose first argument looks like
    // `(int) $booking['brand_user_id']`.
    //
    // Scanning a bounded window after each anchor handles both: the window is
    // short enough not to reach the next statement, and imposes no shape on
    // the arguments.
    $offset = 0;

    while (($anchor = strpos($source, 'NotificationService::send', $offset)) !== false) {
        $offset = $anchor + 25;
        $window = substr($source, $anchor, 220);

        if (preg_match("/'([a-z_]+\\.[a-z_]+)'/", $window, $found) === 1) {
            $sent[$found[1]][] = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }
}

/**
 * Codes built at runtime, e.g. `'campaign.' . $status`.
 *
 * A regex cannot resolve these, so the variants are listed explicitly. Adding
 * a new branch to one of those switches means adding it here too — which is
 * the point: the omission becomes visible instead of silent.
 */
$dynamic = [
    'campaign.shortlisted' => 'BrandCampaignService::decide()',
    'campaign.hired'       => 'BrandCampaignService::decide()',
    'campaign.declined'    => 'BrandCampaignService::decide()',
    'campaign.cancelled'   => 'BrandCampaignService::notifyApplicants()',
    'campaign.completed'   => 'BrandCampaignService::notifyApplicants()',
];

foreach ($dynamic as $code => $origin) {
    $sent[$code][] = $origin . ' (built at runtime)';
}

// -----------------------------------------------------------------
// Every template that exists
// -----------------------------------------------------------------

$templates = [];

foreach (Database::select('SELECT `code`, `is_active` FROM `notification_templates`') as $row) {
    $templates[(string) $row['code']] = (int) $row['is_active'] === 1;
}

$missing  = array_values(array_diff(array_keys($sent), array_keys($templates)));
$inactive = array_values(array_filter(
    array_keys($sent),
    static fn (string $code): bool => isset($templates[$code]) && $templates[$code] === false
));
$unsent   = array_values(array_diff(array_keys($templates), array_keys($sent)));

echo "\nNotification template coverage\n" . str_repeat('=', 64) . "\n\n";
printf("  codes sent: %d      templates: %d\n\n", count($sent), count($templates));

$failed = 0;

// -----------------------------------------------------------------
if ($missing === []) {
    echo "  \033[32mOK\033[0m    every code the app sends has a template\n";
} else {
    $failed++;
    echo "  \033[31mFAIL\033[0m  codes sent with NO template — these are dropped silently:\n";

    foreach ($missing as $code) {
        printf("          %-28s sent from %s\n", $code, implode(', ', array_unique($sent[$code])));
    }
}

if ($inactive === []) {
    echo "  \033[32mOK\033[0m    no code is sent against a deactivated template\n";
} else {
    $failed++;
    echo "  \033[31mFAIL\033[0m  templates deactivated while still being sent:\n";

    foreach ($inactive as $code) {
        printf("          %s\n", $code);
    }
}

// A template nobody sends is dead copy an admin can edit to no effect. Not a
// failure — some are genuinely reserved — but it should never be a surprise.
if ($unsent === []) {
    echo "  \033[32mOK\033[0m    every template is reachable\n";
} else {
    printf("  \033[33mNOTE\033[0m  %d template(s) exist that nothing sends:\n", count($unsent));

    foreach ($unsent as $code) {
        printf("          %s\n", $code);
    }
}

// -----------------------------------------------------------------
// Recorded drops — the ground truth
// -----------------------------------------------------------------

try {
    $drops = (int) (Database::scalar(
        "SELECT COUNT(*) FROM `system_logs`
          WHERE `message` LIKE '%notification template%'
             OR `message` LIKE '%Unknown notification%'"
    ) ?? 0);

    printf("\n  system_logs records %d historic template drop(s).\n", $drops);
} catch (\Throwable) {
    // system_logs may be pruned.
}

echo "\n" . str_repeat('=', 64) . "\n";
echo $failed === 0
    ? "  \033[32mCoverage is complete.\033[0m\n\n"
    : "  \033[31m{$failed} coverage problem(s).\033[0m\n\n";

exit($failed === 0 ? 0 : 1);
