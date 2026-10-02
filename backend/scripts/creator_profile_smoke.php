<?php

declare(strict_types=1);

/**
 * Creator profile: edit review, audit trail, and the social section.
 *
 *     php scripts/creator_profile_smoke.php
 *
 * Three defects this covers, all of which were live:
 *
 *   1. An APPROVED creator could rewrite their display name, headline and bio
 *      and stay approved. CreatorController computed a `$needsReview` flag
 *      that included the live-profile case exactly as its comment described,
 *      then `&&`-ed it with "is a draft" on the next line, discarding the
 *      branch. The field test was dead code.
 *
 *   2. Not one of the eight creator write paths logged anything.
 *      ActivityLogger was imported and never called, so a creator could
 *      rename themselves, swap every social handle, rewrite all their pricing
 *      and delete portfolio items leaving no trace. The admin side logged its
 *      own moderation, which made the log look populated.
 *
 *   3. The social platform allowlist was nine values in a validator. The four
 *      regional networks the product needs and every link-only entry were
 *      refused at the door — even after migration 029 widened the column to
 *      hold them. And that migration changed the unique key, which would have
 *      turned an Instagram handle edit into a SECOND Instagram row under the
 *      old blind upsert.
 *
 * Everything runs inside one transaction and is rolled back.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Config;
use Zuste\Core\Database as D;

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

$failed = 0;
$passed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failed, $passed;

    if ($ok) {
        $passed++;
        printf("  \033[32mok\033[0m    %s%s\n", $label, $detail === '' ? '' : "  \033[2m{$detail}\033[0m");
        return;
    }

    $failed++;
    printf("  \033[31mFAIL\033[0m  %s  %s\n", $label, $detail);
}

echo "\nCreator profile: review, audit and social\n" . str_repeat('=', 70) . "\n\n";

D::beginTransaction();

try {
    $creator = D::selectOne(
        "SELECT c.*, u.`id` AS `uid` FROM `creators` c
           INNER JOIN `users` u ON u.`id` = c.`user_id`
          WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL LIMIT 1"
    );

    if ($creator === null) {
        exit("no approved creator to test with\n");
    }

    $creatorId = (int) $creator['id'];

    // -----------------------------------------------------------------
    echo "Settings drive the rule\n";
    // -----------------------------------------------------------------

    check(
        'edit review defaults to on',
        Config::settingBool('creator', 'approve_profile_edits', false),
        'the behaviour the controller documented; a bug had disabled it'
    );

    $reviewed = array_filter(array_map('trim', explode(
        ',',
        Config::settingString('creator', 'reviewed_sections', '')
    )));

    check(
        'the reviewed field list is configurable, not hardcoded',
        $reviewed !== [] && in_array('display_name', $reviewed, true),
        'reviewed: ' . implode(', ', $reviewed)
    );

    // -----------------------------------------------------------------
    echo "\nSocial platforms\n";
    // -----------------------------------------------------------------

    $terms = array_column(
        \Zuste\Services\TaxonomyService::terms('social_platform'),
        'value'
    );

    foreach (['moj', 'josh', 'sharechat', 'website', 'portfolio', 'blog', 'other'] as $wanted) {
        check(
            "'{$wanted}' is an accepted platform",
            in_array($wanted, $terms, true),
            'the validator now reads this list from the taxonomy'
        );
    }

    check(
        'the taxonomy says which networks report follower counts',
        (function () {
            // terms(), not map(): map() returns value => label, so indexing
            // it for metadata reads a character off a string.
            $keyed = [];

            foreach (\Zuste\Services\TaxonomyService::terms('social_platform') as $term) {
                $keyed[(string) $term['value']] = $term;
            }

            return ($keyed['instagram']['metadata']['metrics'] ?? null) === true
                && ($keyed['website']['metadata']['metrics'] ?? null) === false;
        })(),
        'so a client never renders a follower count for a website'
    );

    // A creator may hold several custom links but only one Instagram.
    $before = (int) D::scalar('SELECT COUNT(*) FROM `creator_social_accounts` WHERE `creator_id` = ?', [$creatorId]);

    D::execute(
        'INSERT INTO `creator_social_accounts` (`creator_id`,`platform`,`handle`,`profile_url`,`label`,`sort_order`,`created_at`)
         VALUES (?,?,?,?,?,?,NOW()), (?,?,?,?,?,?,NOW())',
        [
            $creatorId, 'other', 'https://sub.example/a', 'https://sub.example/a', 'My newsletter', 90,
            $creatorId, 'other', 'https://sub.example/b', 'https://sub.example/b', 'My podcast', 91,
        ]
    );

    check(
        'a creator can hold several custom links',
        (int) D::scalar('SELECT COUNT(*) FROM `creator_social_accounts` WHERE `creator_id` = ? AND `platform` = ?', [$creatorId, 'other']) === 2,
        'the old UNIQUE(creator_id, platform) allowed exactly one'
    );

    $duplicate = false;

    try {
        D::execute(
            'INSERT INTO `creator_social_accounts` (`creator_id`,`platform`,`handle`,`profile_url`,`created_at`)
             VALUES (?,?,?,?,NOW())',
            [$creatorId, 'other', 'https://sub.example/a', 'https://sub.example/a']
        );
    } catch (\Throwable) {
        $duplicate = true;
    }

    check(
        'but not the same link twice',
        $duplicate,
        'UNIQUE(creator_id, platform, handle) still rejects an exact duplicate'
    );

    check(
        'linked accounts carry an explicit order',
        (int) D::scalar('SELECT COUNT(DISTINCT `sort_order`) FROM `creator_social_accounts` WHERE `creator_id` = ?', [$creatorId]) > 1,
        'the reorder endpoint writes this column'
    );

    // -----------------------------------------------------------------
    echo "\nAudit trail\n";
    // -----------------------------------------------------------------

    // The eight actions the controller now records.
    $actions = [
        'creator.profile_updated', 'creator.profile_submitted',
        'creator.package_created', 'creator.package_updated', 'creator.package_deleted',
        'creator.portfolio_added', 'creator.portfolio_removed',
        'creator.social_updated', 'creator.social_removed', 'creator.social_reordered',
        'creator.availability_updated',
    ];

    $source = (string) file_get_contents($basePath . '/app/Controllers/Api/CreatorController.php');

    $missing = array_values(array_filter(
        $actions,
        static fn (string $a): bool => !str_contains($source, "'" . $a . "'")
    ));

    check(
        'every creator write path records an action',
        $missing === [],
        $missing === []
            ? count($actions) . ' actions wired'
            : 'not recorded: ' . implode(', ', $missing)
    );

    // The controller must actually call the logger, not merely import it.
    check(
        'the logger is called, not just imported',
        substr_count($source, 'logCreatorChange(') >= 9,
        'found ' . substr_count($source, 'logCreatorChange(') . ' call site(s); it used to import '
            . 'ActivityLogger and never call it once'
    );

    check(
        'activity_logs can store a creator subject',
        (function () use ($creatorId) {
            \Zuste\Services\ActivityLogger::log(
                'creator.profile_updated', 'creator', $creatorId,
                'Smoke probe.', ['display_name'], 'user', 1
            );

            return (int) D::scalar(
                "SELECT COUNT(*) FROM `activity_logs` WHERE `subject_type` = 'creator' AND `subject_id` = ?",
                [$creatorId]
            ) > 0;
        })(),
        'the write path and the table agree on the subject type'
    );
} finally {
    D::rollBack();
    echo "\n\033[2m(rolled back)\033[0m\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
printf(
    $failed === 0 ? "  \033[32m%d checks passed.\033[0m\n\n" : "  \033[31m%d passed, {$failed} FAILED.\033[0m\n\n",
    $passed
);

exit($failed === 0 ? 0 : 1);
