<?php

declare(strict_types=1);

/**
 * Active admin sessions.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $rows
 * @var string $currentId
 */

$this->extend('admin.layouts.app');
$this->start('content');

?>

<div class="card">
  <div class="card__head">
    <div>
      <h2 class="h3">Signed in now</h2>
      <p class="muted small mt-1">Sessions active in the last two hours. Revoking one signs that browser out immediately.</p>
    </div>
    <span class="chip chip--neutral"><?= $this->number(count($rows)) ?> session<?= count($rows) === 1 ? '' : 's' ?></span>
  </div>

  <?php if ($rows === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('users', 'ic ic--lg') ?></div>
      <p class="empty__title">No active sessions</p>
      <p class="empty__text">Nobody is signed in to the admin panel right now.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Administrator</th>
            <th>Role</th>
            <th>Address</th>
            <th>Device</th>
            <th>Signed in</th>
            <th>Last activity</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $isMe = (string) $row['id'] === $currentId; ?>
            <tr<?= $isMe ? ' style="background:color-mix(in srgb, var(--primary) 4%, transparent)"' : '' ?>>
              <td>
                <div class="cell-user">
                  <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $row['admin_name'])) ?></div>
                  <div class="flex-1 truncate">
                    <span class="cell-user__name truncate"><?= $this->e($row['admin_name']) ?></span>
                    <div class="cell-user__meta truncate"><?= $this->e($row['admin_email']) ?></div>
                  </div>
                </div>
              </td>

              <td class="small muted"><?= $this->e($row['role_name']) ?></td>

              <td class="mono tiny"><?= $this->e($row['ip_address'] ?? '—') ?></td>

              <td class="tiny muted truncate" style="max-width:220px" title="<?= $this->e($row['user_agent'] ?? '') ?>">
                <?= $this->e(\Zuste\Services\SecurityService::deviceType((string) ($row['user_agent'] ?? ''))) ?>
                <div class="tiny faint truncate"><?= $this->e($row['user_agent'] ?? '—') ?></div>
              </td>

              <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($row['created_at']) ?></td>

              <td class="small muted" style="white-space:nowrap">
                <?= $this->timeAgo($row['last_activity']) ?>
                <?php if ($isMe): ?>
                  <div class="tiny text-primary" style="font-weight:600">This is you</div>
                <?php endif; ?>
              </td>

              <td class="actions">
                <?php if ($isMe): ?>
                  <span class="tiny faint">—</span>
                <?php else: ?>
                  <form method="post" action="/admin/security/sessions/<?= $this->e($row['id']) ?>/revoke" style="display:inline">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn btn--outline btn--sm text-error"
                            data-confirm="<?= $this->e($row['admin_name']) ?> will be signed out immediately."
                            data-confirm-title="Revoke this session?"
                            data-confirm-label="Revoke" data-confirm-tone="danger">
                      Revoke
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>
