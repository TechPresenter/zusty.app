<?php

declare(strict_types=1);

/**
 * List-screen toolbar: search box, select filters, date range and export.
 *
 * @var \Zuste\Core\View $this
 * @var string $action        Form target (usually the current path)
 * @var array<string,mixed> $filters  Current query values
 * @var list<array{name:string,label:string,options:array<string,string>}> $selects
 * @var bool  $showDateRange
 * @var bool  $showExport
 * @var string $searchPlaceholder
 */

$selects           = $selects ?? [];
$filters           = $filters ?? [];
$showDateRange     = $showDateRange ?? false;
$showExport        = $showExport ?? false;
$searchPlaceholder = $searchPlaceholder ?? 'Search…';
$action            = $action ?? strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

$hasActiveFilter = false;
foreach ($filters as $key => $value) {
    if (!in_array($key, ['page', 'per_page', 'sort_by', 'sort_dir'], true) && $value !== '' && $value !== null) {
        $hasActiveFilter = true;
        break;
    }
}

?>
<form class="toolbar" method="get" action="<?= $this->e($action) ?>">

  <div class="toolbar__search">
    <div class="input-group">
      <span class="input-group__icon"><?= $this->icon('search', 'ic ic--sm') ?></span>
      <input class="input" type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>"
             placeholder="<?= $this->e($searchPlaceholder) ?>" data-auto-submit="500" aria-label="Search">
    </div>
  </div>

  <?php foreach ($selects as $select): ?>
    <select class="select" name="<?= $this->e($select['name']) ?>" data-auto-submit
            aria-label="<?= $this->e($select['label']) ?>" style="max-width:200px">
      <?php foreach ($select['options'] as $value => $label): ?>
        <option value="<?= $this->e($value) ?>"
          <?= (string) ($filters[$select['name']] ?? '') === (string) $value ? 'selected' : '' ?>>
          <?= $this->e($label) ?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php endforeach; ?>

  <?php if ($showDateRange): ?>
    <input class="input" type="date" name="from" value="<?= $this->e($filters['from'] ?? '') ?>"
           data-auto-submit aria-label="From date" style="max-width:160px">
    <input class="input" type="date" name="to" value="<?= $this->e($filters['to'] ?? '') ?>"
           data-auto-submit aria-label="To date" style="max-width:160px">
  <?php endif; ?>

  <div class="toolbar__spacer"></div>

  <?php if ($hasActiveFilter): ?>
    <a href="<?= $this->e($action) ?>" class="btn btn--ghost btn--sm">
      <?= $this->icon('x', 'ic ic--sm') ?> Clear
    </a>
  <?php endif; ?>

  <?php if ($showExport && $this->can('export.data')): ?>
    <?php
      // All three formats export the CURRENT FILTER SET, not just this page —
      // the links carry the active query string through queryUrl(). PDF opens
      // a print-ready page and fires the browser's print dialog, so it needs a
      // new tab rather than replacing the list the operator is working in.
    ?>
    <div class="row gap-1">
      <a href="<?= $this->queryUrl(['export' => 'csv']) ?>" class="btn btn--outline btn--sm">
        <?= $this->icon('download', 'ic ic--sm') ?> CSV
      </a>
      <a href="<?= $this->queryUrl(['export' => 'xlsx']) ?>" class="btn btn--outline btn--sm">
        <?= $this->icon('file', 'ic ic--sm') ?> Excel
      </a>
      <a href="<?= $this->queryUrl(['export' => 'pdf']) ?>" class="btn btn--outline btn--sm"
         target="_blank" rel="noopener">
        <?= $this->icon('file', 'ic ic--sm') ?> PDF
      </a>
    </div>
  <?php endif; ?>

  <?php if (!empty($primaryAction)): ?>
    <a href="<?= $this->e($primaryAction['href']) ?>" class="btn btn--primary btn--sm">
      <?= $this->icon($primaryAction['icon'] ?? 'plus', 'ic ic--sm') ?>
      <?= $this->e($primaryAction['label']) ?>
    </a>
  <?php endif; ?>

  <?php // Preserve sort when a filter changes. ?>
  <?php if (!empty($filters['sort_by'])): ?>
    <input type="hidden" name="sort_by" value="<?= $this->e($filters['sort_by']) ?>">
    <input type="hidden" name="sort_dir" value="<?= $this->e($filters['sort_dir'] ?? 'DESC') ?>">
  <?php endif; ?>
</form>
