<?php

declare(strict_types=1);

/**
 * Command palette contract.
 *
 *     php scripts/palette_smoke.php
 *
 * The palette builds its rows in JavaScript from what SearchService returns
 * and from icons the layout renders for it to clone. Neither link is visible
 * to PHP, so both can break silently:
 *
 *   - rename a key in a SearchService source and every row loses its subtitle
 *     or its link, with no error anywhere;
 *   - emit an icon name View::iconPaths() does not know and it renders as a
 *     plain circle — the fallback is deliberate, so nothing complains;
 *   - drop the asset tags or the `data-global-search` hook from the layout and
 *     the Ctrl-K hint goes back to doing nothing, which is exactly the state
 *     this whole feature existed to fix.
 *
 * This asserts all three.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Database;
use Zuste\Services\SearchService;

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

echo "\nCommand palette contract\n" . str_repeat('=', 68) . "\n\n";

// -----------------------------------------------------------------
echo "Wiring\n";
// -----------------------------------------------------------------

$layout = (string) file_get_contents($basePath . '/app/Views/admin/layouts/app.php');
$topbar = (string) file_get_contents($basePath . '/app/Views/admin/partials/topbar.php');

check(
    'the layout loads the palette stylesheet',
    str_contains($layout, 'command-palette.css'),
    'without it the palette renders as unstyled text over the page'
);

check(
    'the layout loads the palette script',
    str_contains($layout, 'command-palette.js'),
    'without it Ctrl-K does nothing again'
);

check(
    'the topbar still exposes the search hook',
    str_contains($topbar, 'data-global-search'),
    'the script binds to this attribute; renaming it silently unbinds the palette'
);

check(
    'the topbar still advertises the shortcut',
    str_contains($topbar, 'Ctrl K'),
    'the hint and the binding have to live or die together'
);

foreach (['palette.css' => 'public/admin/assets/css/command-palette.css',
          'palette.js'  => 'public/admin/assets/js/command-palette.js'] as $label => $path) {
    check("{$label} exists on disk", is_file($basePath . '/' . $path), $path);
}

// -----------------------------------------------------------------
echo "\nIcons\n";
// -----------------------------------------------------------------

// View::icon() falls back to `circle` for an unknown name rather than
// throwing, which is right at runtime and useless for catching a typo. The
// reflection here is the only way to see the real key list.
$reflection = new ReflectionMethod(Zuste\Core\View::class, 'iconPaths');
$reflection->setAccessible(true);
$known = array_keys($reflection->invoke(null));

// Every permission slug in the system, so nothing is filtered out by the
// caller's own grants and the whole source list is exercised.
/** @var list<string> $allPermissions */
$allPermissions = Database::column('SELECT `slug` FROM `permissions`');

$searchIcons = [];

foreach (SearchService::availableSources($allPermissions) as $source) {
    if (isset($source['icon'])) {
        $searchIcons[] = (string) $source['icon'];
    }
}

// Plus the icons emitted per row rather than per source.
preg_match_all(
    "/'icon'\s*=>\s*'([a-z0-9-]+)'/",
    (string) file_get_contents($basePath . '/app/Services/SearchService.php'),
    $matches
);

$searchIcons = array_values(array_unique(array_merge($searchIcons, $matches[1] ?? [])));
$missing     = array_values(array_diff($searchIcons, $known));

check(
    'every icon SearchService emits is a real icon',
    $missing === [],
    $missing === []
        ? count($searchIcons) . ' icon(s) checked'
        : 'these would render as a blank circle: ' . implode(', ', $missing)
);

// The sprite the palette clones from.
preg_match('/\[\s*((?:\'[a-z0-9-]+\',?\s*)+)\]\s*as\s*\$paletteIcon/', $layout, $spriteMatch);
preg_match_all("/'([a-z0-9-]+)'/", $spriteMatch[1] ?? '', $spriteIcons);

$sprite = $spriteIcons[1] ?? [];

check(
    'the layout renders a sprite for the palette',
    $sprite !== [],
    'the palette clones these; with none, every row falls back to a circle'
);

$spriteMissing = array_values(array_diff($sprite, $known));

check(
    'every sprite icon is a real icon',
    $spriteMissing === [],
    $spriteMissing === []
        ? count($sprite) . ' icon(s) in the sprite'
        : 'not in View::iconPaths(): ' . implode(', ', $spriteMissing)
);

$uncovered = array_values(array_diff($searchIcons, $sprite));

check(
    'the sprite covers every icon a result can carry',
    $uncovered === [],
    $uncovered === []
        ? 'no result can fall back to a circle'
        : 'results using these would render a circle: ' . implode(', ', $uncovered)
);

// -----------------------------------------------------------------
echo "\nResult shape\n";
// -----------------------------------------------------------------

// The keys the JavaScript reads off each row. Renaming one in PHP breaks the
// palette with no PHP-side error at all.
$required = ['label', 'sublabel', 'icon', 'url', 'chip', 'tone'];

// Two characters minimum (SearchService::MIN_TERM_LENGTH), and a fragment
// broad enough to hit several sources on any realistic dataset.
$term   = 'an';
$groups = SearchService::search($term, $allPermissions, 3);

check(
    'a search returns groups',
    $groups !== [],
    $groups === []
        ? "nothing matched \"{$term}\" — seed some data, or the sources are all filtered out"
        : count($groups) . ' group(s) for "' . $term . '"'
);

check(
    'a term below the minimum length returns nothing',
    SearchService::search('a', $allPermissions, 3) === [],
    'a one-character search would scan every table for almost every row'
);

if ($groups !== []) {
    foreach (['key', 'label', 'icon', 'index', 'count', 'capped', 'results'] as $key) {
        check(
            "every group carries `{$key}`",
            !array_filter($groups, static fn (array $g): bool => !array_key_exists($key, $g)),
            'the palette reads this when rendering the group header'
        );
    }

    $rows = [];

    foreach ($groups as $group) {
        foreach ($group['results'] as $row) {
            $rows[] = $row;
        }
    }

    check('the groups contain rows', $rows !== [], count($rows) . ' row(s)');

    foreach ($required as $key) {
        $without = array_filter($rows, static fn (array $r): bool => !array_key_exists($key, $r));

        check(
            "every result row carries `{$key}`",
            $without === [],
            $without === [] ? '' : count($without) . ' row(s) missing it'
        );
    }

    // The palette renders `chip chip--{tone}`. A tone with no matching class
    // draws an unstyled chip — visible, wrong, and not an error anywhere.
    $chipVariants = [];

    foreach (glob($basePath . '/public/admin/assets/css/*.css') ?: [] as $stylesheet) {
        preg_match_all('/\.chip--([a-z]+)/', (string) file_get_contents($stylesheet), $found);
        $chipVariants = array_merge($chipVariants, $found[1] ?? []);
    }

    $chipVariants = array_unique($chipVariants);
    $tones        = array_unique(array_map(static fn (array $r): string => (string) ($r['tone'] ?? ''), $rows));
    $unstyled     = array_values(array_diff($tones, $chipVariants));

    check(
        'every tone a result carries has a chip style',
        $unstyled === [],
        $unstyled === []
            ? 'tones in use: ' . implode(', ', $tones)
            : 'no .chip-- class for: ' . implode(', ', $unstyled)
    );

    $badUrls = array_filter(
        $rows,
        static fn (array $r): bool => !str_starts_with((string) ($r['url'] ?? ''), '/admin/')
    );

    check(
        'every row links somewhere inside the admin panel',
        $badUrls === [],
        'a row whose url leaves /admin would navigate the operator out of the panel'
    );
}

echo "\n" . str_repeat('=', 68) . "\n";
printf(
    $failed === 0 ? "  \033[32m%d checks passed.\033[0m\n\n" : "  \033[31m%d passed, {$failed} FAILED.\033[0m\n\n",
    $passed
);

exit($failed === 0 ? 0 : 1);
