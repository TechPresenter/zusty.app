<?php

declare(strict_types=1);

/**
 * Abuse reports queue.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $reports
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$status    = (string) ($filters['status'] ?? 'open');
$canHandle = $this->can('reports.handle');

$subjectLink = static function (string $type, int $id): string {
    return match ($type) {
        'user'    => '/admin/users/' . $id,
        'creator' => '/admin/creators/' . $id,
        'booking' => '/admin/bookings/' . $id,
        'review'  => '/admin/reviews',
        default   => '',
    };
};

?>

<div class="chip-row mb-5">
  <?php foreach ([
      'open' => 'Open', 'investigating' => 'Investigating',
      'resolved' => 'Resolved', 'dismissed' => 'Dismissed', 'all' => 'All',
  ] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= $status === $value ? ' chip-filter--active' : '' ?>"><?= $this->e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search reports…',
      'selects'           => [
          ['name' => 'reportable_type', 'label' => 'Subject', 'options' => [
              '' => 'Any subject', 'user' => 'User', 'creator' => 'Creator', 'booking' => 'Booking',
              'review' => 'Review', 'message' => 'Message', 'portfolio' => 'Portfolio',
          ]],
      ],
  ]); ?>

  <?php if ($reports === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing reported</p>
      <p class="empty__text">No abuse reports match this filter.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Reporter</th>
            <th>Subject</th>
            <th>Reason</th>
            <th>Details</th>
            <th>Status</th>
            <th>Reported</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reports as $report): ?>
            <?php $link = $subjectLink((string) $report['reportable_type'], (int) $report['reportable_id']); ?>
            <tr>
              <td>
                <div class="cell-user">
                  <?php if (!empty($report['reporter_avatar'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($report['reporter_avatar']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $report['reporter_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <a href="/admin/users/<?= (int) $report['reporter_id'] ?>" class="cell-user__name truncate">
                      <?= $this->e($report['reporter_name']) ?>
                    </a>
                  </div>
                </div>
              </td>

              <td class="small">
                <span class="chip chip--neutral"><?= $this->e(ucfirst((string) $report['reportable_type'])) ?></span>
                <?php if ($link !== ''): ?>
                  <a href="<?= $this->e($link) ?>" class="tiny text-secondary mono">#<?= (int) $report['reportable_id'] ?></a>
                <?php else: ?>
                  <span class="tiny faint mono">#<?= (int) $report['reportable_id'] ?></span>
                <?php endif; ?>
              </td>

              <td>
                <span class="chip chip--<?= in_array($report['reason'], ['fraud', 'abuse', 'impersonation'], true) ? 'error' : 'warning' ?>">
                  <?= $this->e(ucfirst(str_replace('_', ' ', (string) $report['reason']))) ?>
                </span>
              </td>

              <td class="small muted clamp-2" style="max-width:280px"><?= $this->e($report['description'] ?: '—') ?></td>

              <td>
                <span class="chip chip--<?= match ((string) $report['status']) {
                    'resolved'  => 'success',
                    'dismissed' => 'neutral',
                    default     => 'warning',
                } ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst((string) $report['status'])) ?>
                </span>
                <?php if (!empty($report['handler_name'])): ?>
                  <div class="tiny faint mt-1">by <?= $this->e($report['handler_name']) ?></div>
                <?php endif; ?>
                <?php if (!empty($report['resolution_note'])): ?>
                  <div class="tiny muted mt-1 clamp-2" style="max-width:180px"><?= $this->e($report['resolution_note']) ?></div>
                <?php endif; ?>
              </td>

              <td class="small muted" style="white-space:nowrap"><?= $this->timeAgo($report['created_at']) ?></td>

              <td class="actions">
                <?php if ($canHandle && in_array($report['status'], ['open', 'investigating'], true)): ?>
                  <div class="row gap-2 row--end">
                    <?php if ($report['status'] === 'open'): ?>
                      <form method="post" action="/admin/reports/<?= (int) $report['id'] ?>/handle" style="display:inline">
                        <?= $this->csrf() ?>
                        <input type="hidden" name="status" value="investigating">
                        <button type="submit" class="btn btn--ghost btn--sm">Investigate</button>
                      </form>
                    <?php endif; ?>

                    <button type="button" class="btn btn--outline btn--sm"
                            onclick="handleReport(<?= (int) $report['id'] ?>, 'resolved')">Resolve</button>
                    <button type="button" class="btn btn--ghost btn--sm faint"
                            onclick="handleReport(<?= (int) $report['id'] ?>, 'dismissed')">Dismiss</button>
                  </div>
                <?php else: ?>
                  <span class="tiny faint"><?= $this->date($report['handled_at']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
  <?php endif; ?>
</div>

<form id="report-form" method="post" style="display:none">
  <?= $this->csrf() ?>
  <input type="hidden" name="status" id="report-status">
  <input type="hidden" name="note" id="report-note">
</form>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
function handleReport(id, status) {
  const note = window.prompt(
    status === 'resolved'
      ? 'What action was taken? (recorded in the audit trail)'
      : 'Why is this report being dismissed?'
  );
  if (note === null) return;

  const form = document.getElementById('report-form');
  form.action = '/admin/reports/' + id + '/handle';
  document.getElementById('report-status').value = status;
  document.getElementById('report-note').value = note.trim();
  form.submit();
}
</script>
<?php $this->stop(); ?>
