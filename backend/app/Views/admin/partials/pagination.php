<?php

declare(strict_types=1);

/**
 * Pagination control.
 *
 * @var \Zuste\Core\View $this
 * @var array{total:int,page:int,per_page:int,last_page:int,from:int,to:int} $pagination
 */

$page  = (int) ($pagination['page'] ?? 1);
$last  = (int) ($pagination['last_page'] ?? 1);
$total = (int) ($pagination['total'] ?? 0);

if ($total === 0) {
    return;
}

// Window of page numbers around the current page, with ellipses.
$window = 2;
$pages  = [];

for ($i = 1; $i <= $last; $i++) {
    if ($i === 1 || $i === $last || abs($i - $page) <= $window) {
        $pages[] = $i;
    } elseif (end($pages) !== '…') {
        $pages[] = '…';
    }
}

?>
<nav class="pagination" aria-label="Pagination">
  <span class="pagination__info">
    Showing <strong><?= $this->number($pagination['from'] ?? 0) ?></strong>–<strong><?= $this->number($pagination['to'] ?? 0) ?></strong>
    of <strong><?= $this->number($total) ?></strong>
  </span>

  <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
     href="<?= $this->queryUrl(['page' => max(1, $page - 1)]) ?>"
     aria-label="Previous page" <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>
    <?= $this->icon('chevron-left', 'ic ic--sm') ?>
  </a>

  <?php foreach ($pages as $entry): ?>
    <?php if ($entry === '…'): ?>
      <span class="pagination__item is-disabled" aria-hidden="true">…</span>
    <?php else: ?>
      <a class="pagination__item<?= $entry === $page ? ' is-active' : '' ?>"
         href="<?= $this->queryUrl(['page' => $entry]) ?>"
         <?= $entry === $page ? 'aria-current="page"' : '' ?>><?= (int) $entry ?></a>
    <?php endif; ?>
  <?php endforeach; ?>

  <a class="pagination__item<?= $page >= $last ? ' is-disabled' : '' ?>"
     href="<?= $this->queryUrl(['page' => min($last, $page + 1)]) ?>"
     aria-label="Next page" <?= $page >= $last ? 'aria-disabled="true"' : '' ?>>
    <?= $this->icon('chevron-right', 'ic ic--sm') ?>
  </a>
</nav>
