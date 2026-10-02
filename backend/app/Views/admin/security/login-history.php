<?php

declare(strict_types=1);

/**
 * Login history.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$outcomeTone = static fn (string $o): string => match ($o) {
    'success'                       => 'success',
    'bad_credentials', '2fa_failed' => 'error',
    'locked', 'suspended'           => 'warning',
    'throttled'                     => 'warning',
    default                         => 'neutral',
};

?>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by email, phone or IP address…',
      'showDateRange'     => true,
      'showExport'        => true,
      'selects'           => [
          ['name' => 'outcome', 'label' => 'Outcome', 'options' => [
              ''                => 'Any outcome',
              'success'         => 'Success',
              'bad_credentials' => 'Wrong credentials',
              'locked'          => 'Account locked',
              'suspended'       => 'Blocked',
              'throttled'       => 'Rate limited',
              '2fa_required'    => 'Second factor required',
              '2fa_failed'      => 'Second factor failed',
          ]],
          ['name' => 'actor_type', 'label' => 'Actor', 'options' => [
              '' => 'Admins and users', 'admin' => 'Admins only', 'user' => 'App users only',
          ]],
      ],
  ]); ?>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('shield', 'ic ic--lg') ?></div>
      <p class="empty__title">No sign-in attempts match</p>
      <p class="empty__text">Widen the date range or clear the filters.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>
              <a href="<?= $this->queryUrl(['sort_by' => 'created_at', 'sort_dir' => ($filters['sort_dir'] ?? 'DESC') === 'DESC' ? 'ASC' : 'DESC']) ?>">When</a>
            </th>
            <th>Identifier</th>
            <th>Outcome</th>
            <th>Method</th>
            <th>Address</th>
            <th>Device</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="small muted" style="white-space:nowrap">
                <?= $this->dateTime($row['created_at']) ?>
                <div class="tiny faint"><?= $this->timeAgo($row['created_at']) ?></div>
              </td>

              <td class="truncate" style="max-width:220px">
                <span class="small" style="font-weight:600"><?= $this->e($row['identifier']) ?></span>
                <div class="tiny muted"><?= $this->e(ucfirst((string) $row['actor_type'])) ?></div>
              </td>

              <td>
                <span class="chip chip--<?= $outcomeTone((string) $row['outcome']) ?>">
                  <span class="chip__dot"></span>
                  <?= $this->e(ucfirst(str_replace('_', ' ', (string) $row['outcome']))) ?>
                </span>
              </td>

              <td class="small muted"><?= $this->e(ucfirst(str_replace('_', ' ', (string) $row['auth_method']))) ?></td>

              <td class="mono tiny"><?= $this->e($row['ip_address'] ?? '—') ?></td>

              <td class="small muted">
                <?= $this->e(ucfirst((string) $row['device_type'])) ?>
                <?php if ((int) $row['is_new_device'] === 1): ?>
                  <span class="chip chip--info tiny">New</span>
                <?php endif; ?>
              </td>

              <td class="tiny muted truncate" style="max-width:260px" title="<?= $this->e($row['failure_reason'] ?? '') ?>">
                <?= $this->e($row['failure_reason'] ?? '—') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>
