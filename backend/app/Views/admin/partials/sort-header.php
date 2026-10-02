<?php

declare(strict_types=1);

/**
 * One sortable column header.
 *
 * Renders the whole `<th>` because `aria-sort` belongs on the cell, not on the
 * link inside it, and because the arrow, the class and the next-direction rule
 * have to stay identical on every list screen.
 *
 * The caller passes the *normalised* sort the controller actually used — not
 * the raw query string — otherwise a first page load, where `sort_by` is absent
 * from the URL, would render every column as unsorted while the rows are in
 * fact ordered by the default column.
 *
 * @var \Zuste\Core\View $this
 * @var string $key       Column key sent as `sort_by`
 * @var string $label     Visible header text
 * @var string $current   Normalised current `sort_by`
 * @var string $dir       Normalised current `sort_dir` ("ASC" or "DESC")
 * @var string $class     Extra classes for the `<th>` (e.g. "text-right")
 * @var string $style     Inline style for the `<th>` (e.g. "min-width:170px")
 * @var string $firstDir  Direction to use when this column is not the sorted one
 */

$sortKey     = (string) $key;
$sortCurrent = (string) ($current ?? '');
$sortDir     = strtoupper((string) ($dir ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$sortFirst   = strtoupper((string) ($firstDir ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

$isCurrent = $sortCurrent === $sortKey;
$nextDir   = $isCurrent ? ($sortDir === 'ASC' ? 'DESC' : 'ASC') : $sortFirst;

$thClass = trim(((string) ($class ?? '')) . ' is-sortable');

?>
<th class="<?= $this->e($thClass) ?>"<?= ($style ?? '') === '' ? '' : ' style="' . $this->e((string) $style) . '"' ?>
    aria-sort="<?= $isCurrent ? ($sortDir === 'ASC' ? 'ascending' : 'descending') : 'none' ?>">
  <a href="<?= $this->queryUrl(['sort_by' => $sortKey, 'sort_dir' => $nextDir]) ?>"
     style="display:inline-flex;align-items:center;gap:4px">
    <?= $this->e((string) $label) ?>
    <?php if ($isCurrent): ?>
      <?= $this->icon($sortDir === 'ASC' ? 'arrow-up' : 'arrow-down', 'ic ic--sm') ?>
    <?php endif; ?>
  </a>
</th>
