<?php

declare(strict_types=1);

/**
 * Voice search wiring.
 *
 *     php scripts/voice_search_smoke.php
 *
 * The microphone is injected by JavaScript into fields the server renders, so
 * nothing in PHP fails when the link breaks — the button simply stops
 * appearing, silently, on some screens and not others. This asserts the three
 * joints that cannot be seen from either side alone:
 *
 *   - the layout loads both assets and publishes the operator's on/off choice;
 *   - every admin search field still carries a selector the script matches;
 *   - the operator can actually turn it off, which matters because in Chrome
 *     the Web Speech API streams captured audio to Google for transcription.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Config;
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

echo "\nVoice search wiring\n" . str_repeat('=', 66) . "\n\n";

// -----------------------------------------------------------------
echo "Assets\n";
// -----------------------------------------------------------------

$layout = (string) file_get_contents($basePath . '/app/Views/admin/layouts/app.php');

check('the layout loads the stylesheet', str_contains($layout, 'voice-search.css'));
check('the layout loads the script', str_contains($layout, 'voice-search.js'));

foreach (['css/voice-search.css', 'js/voice-search.js'] as $asset) {
    check("public/admin/assets/{$asset} exists", is_file($basePath . '/public/admin/assets/' . $asset));
}

// The script must run AFTER the palette, whose search field it decorates
// through the mutation observer — and after admin.js, whose toast it borrows.
$voicePos   = strpos($layout, 'voice-search.js');
$palettePos = strpos($layout, 'command-palette.js');

check(
    'the script loads after the command palette',
    $voicePos !== false && $palettePos !== false && $voicePos > $palettePos,
    'the palette builds its input in JavaScript; loading first would miss it'
);

// -----------------------------------------------------------------
echo "\nThe off switch\n";
// -----------------------------------------------------------------

check(
    'the layout publishes the operator choice to the document',
    str_contains($layout, 'data-voice-search'),
    'the script reads this attribute and creates no DOM at all when it says off'
);

$setting = Database::selectOne(
    "SELECT * FROM `settings` WHERE `group_key` = 'developer' AND `setting_key` = 'voice_search_enabled'"
);

check('the setting exists', $setting !== null, 'so it appears on the settings screen');

check(
    'and its description states where the audio goes',
    $setting !== null && str_contains(strtolower((string) $setting['description']), 'google'),
    'an operator handling KYC documents needs to know this before leaving it on'
);

check(
    'it is readable through the normal helper',
    Config::settingBool('developer', 'voice_search_enabled', false) === ((int) ($setting['value'] ?? 0) === 1),
    'the layout reads it the same way every other setting is read'
);

check(
    'it is not exposed on the public settings endpoint',
    $setting !== null && (int) $setting['is_public'] === 0,
    'an internal panel preference has no business in the app payload'
);

// -----------------------------------------------------------------
echo "\nFields the script can find\n";
// -----------------------------------------------------------------

// The script matches: input[type="search"], input[name="q"], [data-voice].
// A search box that stops matching all three loses its microphone with no
// error anywhere, so the shared partials are asserted by name.
$fields = [
    'the list toolbar'      => 'app/Views/admin/partials/toolbar.php',
    'the topbar search'     => 'app/Views/admin/partials/topbar.php',
    'the global search page' => 'app/Views/admin/productivity/search.php',
    'the media library'     => 'app/Views/admin/media/index.php',
];

foreach ($fields as $label => $path) {
    $markup = (string) @file_get_contents($basePath . '/' . $path);

    $matches = str_contains($markup, 'type="search"')
        || str_contains($markup, 'name="q"')
        || str_contains($markup, 'data-voice');

    check("{$label} carries a matched selector", $matches, $path);
}

// The palette input is built in JavaScript, so its selector lives there.
$palette = (string) file_get_contents($basePath . '/public/admin/assets/js/command-palette.js');

check(
    'the command palette input is matched too',
    str_contains($palette, 'type="search"'),
    'picked up by the mutation observer when the palette opens'
);

// -----------------------------------------------------------------
echo "\nThe script itself\n";
// -----------------------------------------------------------------

$script = (string) file_get_contents($basePath . '/public/admin/assets/js/voice-search.js');

check(
    'it does nothing where the browser has no Speech API',
    str_contains($script, 'if (!Recognition) return'),
    'Firefox does not implement it; a dead microphone is worse than none'
);

check(
    'it honours the off switch before touching the DOM',
    str_contains($script, "getAttribute('data-voice-search') === 'off'"),
    'turning it off must remove the button, not merely disable it'
);

check(
    'the injected button cannot submit the form it sits in',
    str_contains($script, "button.type = 'button'"),
    "a bare <button> inside a search form defaults to type=submit and would fire the search on every click"
);

check(
    'dictation dispatches the same events as typing',
    str_contains($script, "new Event('input'"),
    'auto-submit and every other listener bind to `input`; without this, typed '
        . 'search works and spoken search silently does not'
);

echo "\n" . str_repeat('=', 66) . "\n";
printf(
    $failed === 0 ? "  \033[32m%d checks passed.\033[0m\n\n" : "  \033[31m%d passed, {$failed} FAILED.\033[0m\n\n",
    $passed
);

exit($failed === 0 ? 0 : 1);
