<?php

declare(strict_types=1);

/**
 * Admin shell: collapsible sidebar, sticky topbar, toast host.
 *
 * @var \Zuste\Core\View        $this
 * @var array<string,mixed>     $admin
 * @var list<string>            $permissions
 * @var string                  $pageTitle
 * @var array<string,int>       $badges
 */

$appName  = \Zuste\Core\Config::settingString('general', 'app_name', 'Zusty');
$title    = ($pageTitle ?? 'Dashboard') . ' · ' . $appName . ' Admin';
$badges   = $badges ?? [];
$adminRow = $admin ?? [];

/**
 * Sidebar structure: [label, [items…]] where each item is
 * [route, icon, label, permission, badgeKey].
 *
 * Exactly one item is highlighted: the longest route that prefixes the current
 * path. That keeps "/admin" from matching every page and stops a section
 * overview ("/admin/security") lighting up while a child is open.
 */
$navigation = [
    'Overview' => [
        ['/admin',           'dashboard', 'Dashboard', 'dashboard.view', null],
        ['/admin/analytics', 'chart',     'Analytics', 'analytics.view', null],
    ],
    'Marketplace' => [
        ['/admin/users',      'users',    'Users',      'users.view',      null],
        ['/admin/privacy-requests', 'lock', 'Privacy requests', 'users.view', null],
        ['/admin/creators',   'star',     'Creators',   'creators.view',   'pending_creators'],
        // Both screens exist under /admin/creators/… but are their own
        // destinations; without an entry here an operator has no way to reach
        // either, which is how the change queue would sit unread.
        ['/admin/creator-changes',    'check-circle', 'Profile changes',   'creators.view', 'pending_changes'],
        ['/admin/creators/completion', 'chart',       'Profile completion', 'creators.view', null],
        ['/admin/kyc',        'shield',   'KYC',        'kyc.view',        'pending_kyc'],
        ['/admin/categories', 'grid',     'Categories', 'categories.view', null],
        ['/admin/taxonomies', 'sliders',  'App lists',  'taxonomies.view', null],
        ['/admin/bookings',   'calendar', 'Bookings',   'bookings.view',   'open_disputes'],
        ['/admin/reviews',    'star',     'Reviews',    'reviews.view',    null],
    ],
    'Finance' => [
        ['/admin/payments',    'card',   'Payments',    'payments.view',      null],
        ['/admin/wallets',     'wallet', 'Wallets',     'wallet.view',        null],
        ['/admin/withdrawals', 'wallet', 'Withdrawals', 'wallet.withdrawals', 'pending_withdrawals'],
        ['/admin/settlements', 'chart',  'Settlements', 'payments.view',      null],
    ],
    'Content' => [
        ['/admin/pages',        'file',      'Pages',        'cms.view',           null],
        ['/admin/blogs',        'file',      'Blog',         'cms.blogs',          null],
        ['/admin/banners',      'image',     'Banners',      'cms.banners',        null],
        ['/admin/sections',     'dashboard', 'Home Sections', 'cms.view',          null],
        ['/admin/faqs',         'info',      'FAQs',         'cms.faqs',           null],
        ['/admin/testimonials', 'star',      'Testimonials', 'cms.testimonials',   null],
        ['/admin/onboarding-slides', 'sliders', 'Onboarding', 'cms.view',         null],
        ['/admin/redirects',    'external',  'Redirects',    'cms.view',           null],
        ['/admin/menus',        'menu',      'Menus',        'cms.view',           null],
        ['/admin/sitemap',      'external',  'Sitemap',      'cms.view',           null],
        ['/admin/media',        'image',     'Media',        'cms.media',          null],
    ],
    'Marketing' => [
        ['/admin/marketing',             'megaphone', 'Campaigns',   'marketing.view', null],
        ['/admin/marketing/subscribers', 'inbox',     'Subscribers', 'marketing.view', null],
        ['/admin/coupons',               'card',      'Coupons',     'marketing.view', null],
        ['/admin/popups',                'megaphone', 'Popups',      'marketing.view', null],
    ],
    'Engagement' => [
        ['/admin/notifications', 'megaphone', 'Notifications', 'notifications.view', null],
        ['/admin/tickets',       'headset',   'Support',       'support.view',       'open_tickets'],
        ['/admin/reports',       'flag',      'Reports',       'reports.view',       'open_reports'],
        ['/admin/contact',       'inbox',     'Contact Inbox', 'cms.view',           'new_messages'],
    ],
    'Workflow' => [
        ['/admin/workflow',       'check-circle', 'Approvals',  'workflow.view',   null],
        ['/admin/workflow/rules', 'play',         'Automation', 'workflow.manage', null],
    ],
    'AI' => [
        ['/admin/ai',           'star',     'Overview',   'ai.view', null],
        ['/admin/ai/providers', 'database', 'Providers',  'ai.view', null],
        ['/admin/ai-prompts',   'file',     'Prompts',    'ai.view', null],
        ['/admin/ai/logs',      'chart',    'Usage Logs', 'ai.view', null],
    ],
    'Productivity' => [
        ['/admin/search',        'search',   'Global Search', '',                  null],
        ['/admin/saved-filters', 'filter',   'Saved Filters', '',                  null],
        ['/admin/recycle-bin',   'trash',    'Recycle Bin',   'recyclebin.manage', null],
        ['/admin/revisions',     'refresh',  'Revisions',     'revisions.view',    null],
        ['/admin/import',        'upload',   'Import CSV',    '',                  null],
    ],
    'Developer' => [
        ['/admin/developer',          'database', 'Overview',      'developer.logs',    null],
        ['/admin/developer/api-logs', 'chart',    'API Logs',      'developer.logs',    null],
        ['/admin/developer/queries',  'alert',    'Query Monitor', 'developer.queries', null],
        ['/admin/developer/cache',    'refresh',  'Cache',         'developer.cache',   null],
        ['/admin/developer/tester',   'play',     'API Tester',    'developer.tester',  null],
    ],
    'Access' => [
        ['/admin/admins', 'user-check', 'Admin Users', 'admins.view',  null],
        ['/admin/roles',  'key',        'Roles',       'roles.manage', null],
        ['/admin/logs',   'shield',     'Activity Log', 'logs.activity', null],
    ],
    'Authentication' => [
        ['/admin/login-methods', 'key', 'Login Methods', 'auth.view', null],
    ],
    'Security' => [
        ['/admin/security',               'shield', 'Overview',      'security.logins',  null],
        ['/admin/security/login-history', 'key',    'Login History', 'security.logins',  null],
        ['/admin/security/ip-whitelist',  'filter', 'IP Allow List', 'security.ip',      null],
        ['/admin/security/sessions',      'users',  'Sessions',      'security.sessions', null],
    ],
    'Integrations' => [
        ['/admin/integrations', 'external', 'Integrations Hub', 'integrations.view', null],
    ],
    'System' => [
        ['/admin/settings', 'settings', 'Settings', 'settings.view', null],
        ['/admin/system',   'database', 'System',   'logs.system',   null],
    ],
];

$currentPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Resolve the single active route up front: the longest declared route that the
// current path either equals or sits beneath.
$activeRoute = '';
foreach ($navigation as $navItems) {
    foreach ($navItems as [$navRoute]) {
        $matches = $currentPath === $navRoute
            || str_starts_with($currentPath, rtrim($navRoute, '/') . '/');

        if ($matches && strlen($navRoute) > strlen($activeRoute)) {
            $activeRoute = $navRoute;
        }
    }
}

?><!doctype html>
<!-- data-skin and data-theme are set by theme-engine.js before first paint;
     the attributes here are only the pre-JS default. -->
<html lang="en" data-skin="vibrant" data-theme="light"
      data-voice-search="<?= \Zuste\Core\Config::settingBool('developer', 'voice_search_enabled', true) ? 'on' : 'off' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title><?= $this->e($title) ?></title>

<?php $assetVersion = $this->e(\Zuste\Core\Config::get('app.version', '1.0.0')); ?>

<!-- Blocking, and deliberately first: it reads the stored theme and sets
     the attributes before any stylesheet paints. Deferring it flashes the
     default palette on every navigation — on a dark theme, a white flash. -->
<script src="/admin/assets/js/theme-engine.js?v=<?= $assetVersion ?>"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Order matters. admin.css lays the foundation, themes.css overrides its
     tokens with the active palette, premium.css restyles the components
     using those tokens. -->
<link rel="stylesheet" href="/admin/assets/css/admin.css?v=<?= $assetVersion ?>">
<link rel="stylesheet" href="/admin/assets/css/themes.css?v=<?= $assetVersion ?>">
<link rel="stylesheet" href="/admin/assets/css/premium.css?v=<?= $assetVersion ?>">
<!-- Last, so the editor's chrome inherits the active theme's tokens. -->
<link rel="stylesheet" href="/admin/assets/css/editor.css?v=<?= $assetVersion ?>">
<!-- Last, because the palette overlays everything and its z-index has to
     win against .dialog without !important. -->
<link rel="stylesheet" href="/admin/assets/css/command-palette.css?v=<?= $assetVersion ?>">
<link rel="stylesheet" href="/admin/assets/css/voice-search.css?v=<?= $assetVersion ?>">
<link rel="icon" type="image/png" sizes="64x64" href="/admin/assets/img/favicon.png?v=<?= $assetVersion ?>">
<link rel="apple-touch-icon" href="/admin/assets/img/apple-touch-icon.png?v=<?= $assetVersion ?>">
</head>
<body>

<!-- First focusable element in the document, so a keyboard user can jump the
     ~65 sidebar links that precede <main> on every page (WCAG 2.4.1). -->
<a class="skip-link" href="#main">Skip to content</a>

<div class="app" data-collapsed="false" data-mobile-open="false">

  <div class="scrim" aria-hidden="true"></div>

  <!-- ================= Sidebar ================= -->
  <aside class="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
      <a href="/admin" class="row gap-2" aria-label="<?= $this->e($appName) ?> admin home">
        <div>
          <div class="wordmark">
            <img class="brand-logo brand-logo--light" src="/admin/assets/img/zusty-logo.png?v=<?= $assetVersion ?>" width="138" height="30" alt="<?= $this->e($appName) ?>">
            <img class="brand-logo brand-logo--dark" src="/admin/assets/img/zusty-logo-dark.png?v=<?= $assetVersion ?>" width="138" height="30" alt="" aria-hidden="true">
          </div>
          <div class="wordmark__sub">Admin</div>
        </div>
        <img class="brand-mark" src="/admin/assets/img/zusty-mark.png?v=<?= $assetVersion ?>" width="36" height="36" alt="" aria-hidden="true">
      </a>
    </div>

    <nav class="sidebar__nav">
      <?php foreach ($navigation as $groupLabel => $items): ?>
        <?php
        $visible = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->can((string) $item[3])
        ));

        if ($visible === []) {
            continue;
        }
        ?>
        <div class="sidebar__group">
          <div class="sidebar__label"><?= $this->e($groupLabel) ?></div>
          <?php foreach ($visible as [$route, $icon, $label, $permission, $badgeKey]): ?>
            <?php
            $active     = $route === $activeRoute;
            $badgeCount = $badgeKey !== null ? (int) ($badges[$badgeKey] ?? 0) : 0;
            ?>
            <a href="<?= $this->e($route) ?>"
               class="nav-item<?= $active ? ' nav-item--active' : '' ?>"
               <?= $active ? 'aria-current="page"' : '' ?>>
              <?= $this->icon($icon) ?>
              <span><?= $this->e($label) ?></span>
              <?php if ($badgeCount > 0): ?>
                <span class="nav-item__badge"><?= $badgeCount > 99 ? '99+' : $badgeCount ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
  </aside>

  <!-- ================= Main ================= -->
  <div class="main">

    <?php
    // Premium topbar: breadcrumbs, global search, quick actions,
    // notification centre, theme picker and profile menu.
    $this->partial('admin.partials.topbar', [
        'adminRow'     => $adminRow,
        'badges'       => $badges,
        'pageTitle'    => $pageTitle ?? 'Dashboard',
        'pageSubtitle' => $pageSubtitle ?? '',
        'currentPath'  => $currentPath,
    ]);
    ?>

    <!-- tabindex="-1" so the skip link above can move focus here; without it
         the anchor scrolls but leaves focus where it was and the next Tab
         restarts from the top of the sidebar. -->
    <main id="main" tabindex="-1" class="content fade-in">
      <?= $this->section('content') ?>
    </main>
  </div>
</div>

<div class="toasts" role="status" aria-live="polite"></div>

<?php foreach (\Zuste\Core\Session::toasts() as $toast): ?>
  <span data-flash="<?= $this->e($toast['message']) ?>" data-flash-type="<?= $this->e($toast['type']) ?>" hidden></span>
<?php endforeach; ?>

<script src="/admin/assets/js/admin.js?v=<?= $assetVersion ?>"></script>
<!-- After admin.js: it augments Admin rather than replacing it, and its
     popover and counter bindings expect the base handlers to exist. -->
<!-- Icon sprite for the command palette.
     Icons are server-rendered SVGs, so the palette — which builds its rows in
     JavaScript — has no way to draw one. Rather than duplicate the path data
     into JS, where it would drift from View::iconPaths() the first time an
     icon is edited, the eight icons SearchService can return are rendered once
     here and cloned by outerHTML at build time. Hidden, and aria-hidden on
     each icon already, so it is invisible to assistive technology. -->
<div hidden>
  <?php foreach (['search', 'clock', 'arrow-right', 'alert',
                  'users', 'user-check', 'calendar', 'headset',
                  'grid', 'file', 'card', 'star'] as $paletteIcon): ?>
    <?= str_replace('<svg ', '<svg data-palette-icon="' . $this->e($paletteIcon) . '" ',
                    $this->icon($paletteIcon, 'ic ic--sm')) ?>
  <?php endforeach; ?>
</div>

<script src="/admin/assets/js/premium-ui.js?v=<?= $assetVersion ?>"></script>
<!-- Enhances every textarea[data-editor="rich"] into a WYSIWYG surface. Self
     contained and defensive: if it throws, the plain textarea is restored and
     the form still saves. -->
<script src="/admin/assets/js/editor.js?v=<?= $assetVersion ?>"></script>
<!-- Self-contained: it binds Ctrl/Cmd-K and takes over the topbar search
     field. Independent of Admin, so load order against it does not matter. -->
<script src="/admin/assets/js/command-palette.js?v=<?= $assetVersion ?>"></script>
<script src="/admin/assets/js/voice-search.js?v=<?= $assetVersion ?>"></script>
<?= $this->section('scripts') ?>
</body>
</html>
