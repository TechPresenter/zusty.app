<?php

declare(strict_types=1);

/**
 * The option lists the apps render.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $taxonomies
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
  <div class="card__body row gap-3" style="padding:14px 18px">
    <span class="text-secondary"><?= $this->icon('smartphone') ?></span>
    <p class="small">
      These lists power every dropdown, chip and filter in the Android, iOS and web apps.
      A change here reaches all three on their next launch — no release required.
      Lists marked <strong>System</strong> back a server workflow, so their options can be
      relabelled and reordered but not added to or removed.
    </p>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>List</th>
          <th>Key</th>
          <th>Where it appears</th>
          <th>Options</th>
          <th>Status</th>
          <th class="text-right">Manage</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($taxonomies as $taxonomy): ?>
          <tr>
            <td>
              <div class="cell-user__name">
                <a href="/admin/taxonomies/<?= (int) $taxonomy['id'] ?>"><?= $this->e($taxonomy['name']) ?></a>
              </div>
              <?php if ((int) $taxonomy['is_system'] === 1): ?>
                <span class="chip chip--warning chip--sm mt-1">System</span>
              <?php endif; ?>
            </td>

            <td class="small mono faint"><?= $this->e($taxonomy['key']) ?></td>

            <td class="small muted clamp-2" style="max-width:340px">
              <?= $this->e($taxonomy['description'] ?? '—') ?>
            </td>

            <td class="small">
              <?= (int) $taxonomy['active_count'] ?>
              <?php if ((int) $taxonomy['term_count'] > (int) $taxonomy['active_count']): ?>
                <span class="tiny muted">
                  (<?= (int) $taxonomy['term_count'] - (int) $taxonomy['active_count'] ?> hidden)
                </span>
              <?php endif; ?>
            </td>

            <td>
              <span class="chip chip--<?= (int) $taxonomy['is_active'] === 1 ? 'success' : 'neutral' ?>">
                <span class="chip__dot"></span><?= (int) $taxonomy['is_active'] === 1 ? 'Live' : 'Hidden' ?>
              </span>
            </td>

            <td class="actions">
              <a href="/admin/taxonomies/<?= (int) $taxonomy['id'] ?>" class="btn btn--sm btn--secondary">
                Manage options
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->stop(); ?>
