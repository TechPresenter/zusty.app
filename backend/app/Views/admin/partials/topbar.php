<?php

declare(strict_types=1);

/**
 * Premium topbar: breadcrumbs, global search, quick actions, notification
 * centre, theme picker and profile menu.
 *
 * Extracted from the layout so the shell stays readable and so a screen
 * can render its own header variant without duplicating this markup.
 *
 * @var \Zuste\Core\View    $this
 * @var array<string,mixed> $adminRow
 * @var array<string,int>   $badges
 * @var string              $pageTitle
 * @var string              $pageSubtitle
 * @var string              $currentPath
 */

$notificationCount = (int) ($badges['open_tickets'] ?? 0)
    + (int) ($badges['open_reports'] ?? 0)
    + (int) ($badges['new_messages'] ?? 0);

/**
 * Breadcrumbs from the URL.
 *
 * Derived rather than declared per screen: 245 admin routes would each
 * need a trail otherwise, and any that were missed would silently show
 * nothing. The path already describes the hierarchy.
 */
$segments = array_values(array_filter(explode('/', trim($currentPath, '/'))));
$crumbs   = [];
$built    = '';

foreach ($segments as $index => $segment) {
    $built .= '/' . $segment;

    if ($index === 0) {
        // '/admin' is the root crumb, labelled Home.
        $crumbs[] = ['url' => '/admin', 'label' => 'Home'];
        continue;
    }

    // A numeric segment is a record id, not a section — show it as a
    // reference rather than a mangled word.
    $label = ctype_digit($segment)
        ? '#' . $segment
        : ucwords(str_replace('-', ' ', $segment));

    $crumbs[] = ['url' => $built, 'label' => $label];
}
?>

<header class="topbar">

  <button class="btn btn--ghost btn--icon" data-toggle-sidebar aria-label="Toggle navigation">
    <?= $this->icon('menu') ?>
  </button>

  <!-- ---------- Title and breadcrumbs ---------- -->
  <div class="flex-1" style="min-width:0">
    <?php if (count($crumbs) > 1): ?>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <?php foreach ($crumbs as $i => $crumb): ?>
          <?php if ($i > 0): ?><span class="breadcrumbs__sep" aria-hidden="true">/</span><?php endif; ?>
          <?php if ($i === count($crumbs) - 1): ?>
            <span class="breadcrumbs__current" aria-current="page"><?= $this->e($crumb['label']) ?></span>
          <?php else: ?>
            <a href="<?= $this->e($crumb['url']) ?>"><?= $this->e($crumb['label']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <h1 class="h2 mb-0 truncate"><?= $this->e($pageTitle ?? 'Dashboard') ?></h1>

    <?php if (!empty($pageSubtitle)): ?>
      <p class="tiny muted mb-0 truncate"><?= $this->e($pageSubtitle) ?></p>
    <?php endif; ?>
  </div>

  <!-- ---------- Global search ---------- -->
  <form class="searchbar hidden-md" method="get" action="/admin/search" role="search">
    <span class="searchbar__icon"><?= $this->icon('search', 'ic ic--sm') ?></span>
    <input class="input" type="search" name="q" placeholder="Search everything…"
           aria-label="Search" autocomplete="off" data-global-search>
    <kbd class="searchbar__kbd">Ctrl K</kbd>
  </form>

  <!-- ---------- Quick actions ---------- -->
  <div style="position:relative">
    <button class="btn btn--ghost btn--icon" data-popover="#pop-quick"
            aria-label="Quick actions" aria-expanded="false" title="Quick actions">
      <?= $this->icon('plus') ?>
    </button>

    <div class="popover" id="pop-quick" hidden style="right:0">
      <?php
      $quick = [
          ['/admin/users',        'users',     'Users',         'users.view'],
          ['/admin/creators',     'star',      'Creators',      'creators.view'],
          ['/admin/bookings',     'calendar',  'Bookings',      'bookings.view'],
          ['/admin/kyc',          'shield',    'KYC queue',     'kyc.view'],
          ['/admin/withdrawals',  'wallet',    'Withdrawals',   'wallet.withdrawals'],
          ['/admin/notifications','megaphone', 'Send a notice', 'notifications.send'],
      ];
      ?>
      <?php foreach ($quick as [$url, $icon, $label, $permission]): ?>
        <?php if ($this->can($permission)): ?>
          <a class="popover__item" href="<?= $this->e($url) ?>">
            <?= $this->icon($icon, 'ic ic--sm') ?><?= $this->e($label) ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ---------- Theme picker ---------- -->
  <div style="position:relative">
    <button class="btn btn--ghost btn--icon" data-popover="#pop-theme"
            aria-label="Appearance" aria-expanded="false" title="Appearance">
      <?= $this->icon('settings') ?>
    </button>

    <!-- Filled in by premium-ui.js from the theme registry, so adding a
         theme never means editing this file. -->
    <div class="popover" id="pop-theme" data-theme-picker hidden
         style="right:0;min-width:19rem"></div>
  </div>

  <!-- ---------- Notification centre ---------- -->
  <?php if ($this->can('notifications.view')): ?>
    <div style="position:relative">
      <button class="btn btn--ghost btn--icon" data-popover="#pop-bell"
              aria-label="Notifications" aria-expanded="false">
        <?= $this->icon('bell') ?>
        <?php if ($notificationCount > 0): ?>
          <span class="nav-item__badge" style="position:absolute;top:2px;right:2px;margin:0">
            <?= $notificationCount > 99 ? '99+' : $notificationCount ?>
          </span>
        <?php endif; ?>
      </button>

      <div class="popover" id="pop-bell" hidden style="right:0;min-width:19rem">
        <div class="row row--between" style="padding:.5rem .625rem">
          <strong class="small">Needs attention</strong>
          <?php if ($notificationCount === 0): ?>
            <span class="chip chip--success tiny">All clear</span>
          <?php endif; ?>
        </div>

        <div class="popover__divider"></div>

        <?php
        // Only the queues an operator has to act on. A feed of everything
        // that happened is Activity Log; this is a to-do list.
        $queues = [
            ['open_tickets',        '/admin/tickets',     'headset',      'Support tickets',   'support.view'],
            ['open_reports',        '/admin/reports',     'flag',         'User reports',      'reports.view'],
            ['pending_kyc',         '/admin/kyc',         'shield',       'KYC to review',     'kyc.view'],
            ['pending_creators',    '/admin/creators',    'star',         'Creators to approve','creators.view'],
            ['pending_withdrawals', '/admin/withdrawals', 'wallet',       'Payouts to approve','wallet.withdrawals'],
            ['open_disputes',       '/admin/bookings',    'alert',        'Open disputes',     'bookings.view'],
            ['new_messages',        '/admin/contact',     'inbox',        'Contact messages',  'cms.view'],
        ];

        $shown = 0;
        ?>

        <?php foreach ($queues as [$key, $url, $icon, $label, $permission]): ?>
          <?php
          $count = (int) ($badges[$key] ?? 0);

          if ($count === 0 || !$this->can($permission)) {
              continue;
          }

          $shown++;
          ?>
          <a class="popover__item" href="<?= $this->e($url) ?>">
            <?= $this->icon($icon, 'ic ic--sm') ?>
            <span class="flex-1"><?= $this->e($label) ?></span>
            <span class="chip chip--warning tiny"><?= $count > 99 ? '99+' : $count ?></span>
          </a>
        <?php endforeach; ?>

        <?php if ($shown === 0): ?>
          <div class="empty" style="padding:1.25rem .75rem">
            <div class="empty__icon"><?= $this->icon('check-circle') ?></div>
            <p class="small muted mb-0">Nothing is waiting on you.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ---------- Profile ---------- -->
  <div style="position:relative;padding-left:.5rem;border-left:1px solid var(--outline-variant)">
    <button class="row gap-2" data-popover="#pop-profile" aria-expanded="false"
            aria-label="Account menu"
            style="background:none;border:none;cursor:pointer;padding:.25rem;border-radius:var(--r-md)">
      <?php if (!empty($adminRow['avatar'])): ?>
        <img class="avatar avatar--sm" src="<?= $this->e($adminRow['avatar']) ?>" alt="">
      <?php else: ?>
        <div class="avatar avatar--sm avatar--fallback">
          <?= $this->e($this->initials((string) ($adminRow['name'] ?? 'A'))) ?>
        </div>
      <?php endif; ?>

      <span class="hidden-sm" style="line-height:1.25;text-align:left">
        <span class="small" style="font-weight:600;display:block"><?= $this->e($adminRow['name'] ?? 'Admin') ?></span>
        <span class="tiny faint"><?= $this->e($adminRow['role_name'] ?? '') ?></span>
      </span>
    </button>

    <div class="popover" id="pop-profile" hidden style="right:0">
      <div style="padding:.5rem .625rem">
        <div class="small" style="font-weight:650"><?= $this->e($adminRow['name'] ?? 'Admin') ?></div>
        <div class="tiny muted"><?= $this->e($adminRow['email'] ?? '') ?></div>
      </div>

      <div class="popover__divider"></div>

      <a class="popover__item" href="/admin/account">
        <?= $this->icon('user-check', 'ic ic--sm') ?>My account
      </a>
      <a class="popover__item" href="/admin/account/password">
        <?= $this->icon('key', 'ic ic--sm') ?>Change password
      </a>
      <a class="popover__item" href="/admin/security/sessions">
        <?= $this->icon('users', 'ic ic--sm') ?>Active sessions
      </a>

      <div class="popover__divider"></div>

      <form method="post" action="/admin/logout">
        <?= $this->csrf() ?>
        <button type="submit" class="popover__item text-error" style="width:100%">
          <?= $this->icon('external', 'ic ic--sm') ?>Sign out
        </button>
      </form>
    </div>
  </div>
</header>
