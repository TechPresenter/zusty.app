<?php

declare(strict_types=1);

/**
 * Abuse reports queue.
 *
 * Each report names what was reported, a copy of it as it read when it was
 * filed (a message or a brief can change or go before anyone looks), and the
 * account behind it, with how many people have blocked that account and how
 * many other reports about it are still open.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $reports
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

use Zuste\Services\ReportService;

$this->extend('admin.layouts.app');
$this->start('content');

$status     = (string) ($filters['status'] ?? 'open');
$canHandle  = $this->can('reports.handle');
$reportedId = (int) ($filters['reported_user_id'] ?? 0);

// Where each kind of subject is looked at. Messages and campaign briefs have
// no screen of their own here, so they open the account that posted them;
// the copy kept with the report shows what was said, and Take down removes it.
$subjectLink = static function (array $report): string {
    $id         = (int) $report['reportable_id'];
    $reportedId = (int) ($report['reported_user_id'] ?? 0);
    $creatorId  = (int) ($report['reported_creator_id'] ?? 0);

    return match ((string) $report['reportable_type']) {
        'user'      => '/admin/users/' . $id,
        'creator'   => '/admin/creators/' . $id,
        'booking'   => '/admin/bookings/' . $id,
        'review'    => '/admin/reviews?id=' . $id,
        'portfolio' => $creatorId > 0 ? '/admin/creators/' . $creatorId : '',
        'message', 'campaign' => $reportedId > 0 ? '/admin/users/' . $reportedId : '',
        default     => '',
    };
};

// Content a moderator can remove from the queue itself (ReportService::takeDown).
// An account is suspended from its user page, a booking acted on from its own.
$takeDownLabels = [
    'message'   => ['Remove message', 'The message is removed from the chat for both people.'],
    'campaign'  => ['Take down brief', 'The brief is cancelled and taken off the board; creators who applied are told it closed.'],
    'review'    => ['Hide review', 'The review is hidden and stops counting toward the rating.'],
    'portfolio' => ['Remove item', 'The item is refused and leaves the creator\'s public profile.'],
];

$typeLabels = [
    'user' => 'User', 'creator' => 'Creator profile', 'booking' => 'Booking', 'review' => 'Review',
    'message' => 'Message', 'portfolio' => 'Portfolio item', 'campaign' => 'Campaign brief',
];

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

<?php if ($reportedId > 0): ?>
  <div class="callout callout--info mb-4">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div class="small">
      Showing reports about <a href="/admin/users/<?= $reportedId ?>">account #<?= $reportedId ?></a>.
      <a href="<?= $this->queryUrl(['reported_user_id' => null, 'page' => 1]) ?>">Show every report</a>
    </div>
  </div>
<?php endif; ?>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search what was reported, notes or names…',
      'selects'           => [
          ['name' => 'reportable_type', 'label' => 'Subject', 'options' => ['' => 'Any subject'] + $typeLabels],
          ['name' => 'reason', 'label' => 'Reason', 'options' => ['' => 'Any reason'] + ReportService::REASON_LABELS],
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
            <th>Reported account</th>
            <th>Reason</th>
            <th>Details</th>
            <th>Status</th>
            <th>Reported</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reports as $report): ?>
            <?php
              $link       = $subjectLink($report);
              $type       = (string) $report['reportable_type'];
              $reason     = (string) $report['reason'];
              $reportedBy = (int) ($report['reported_user_id'] ?? 0);
              // Web links and site paths only: anything else in an older row is
              // never made clickable.
              $evidence   = json_decode((string) ($report['evidence_urls'] ?? ''), true);
              $evidence   = is_array($evidence)
                  ? array_values(array_filter(
                      $evidence,
                      static fn (mixed $url): bool => is_string($url) && ReportService::isSafeLink($url)
                  ))
                  : [];
            ?>
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

              <td class="small" style="max-width:300px">
                <span class="chip chip--neutral"><?= $this->e($typeLabels[$type] ?? ucfirst($type)) ?></span>
                <?php if ($link !== ''): ?>
                  <a href="<?= $this->e($link) ?>" class="tiny text-secondary mono">#<?= (int) $report['reportable_id'] ?></a>
                <?php else: ?>
                  <span class="tiny faint mono">#<?= (int) $report['reportable_id'] ?></span>
                <?php endif; ?>
                <?php if (!empty($report['subject_excerpt'])): ?>
                  <div class="tiny muted mt-1 clamp-2" title="<?= $this->e($report['subject_excerpt']) ?>">
                    “<?= $this->e($report['subject_excerpt']) ?>”
                  </div>
                <?php endif; ?>
                <?php foreach ($evidence as $i => $url): ?>
                  <a href="<?= $this->e($url) ?>" class="tiny" target="_blank" rel="noopener noreferrer">Attachment <?= $i + 1 ?></a>
                <?php endforeach; ?>
              </td>

              <td class="small">
                <?php if ($reportedBy > 0): ?>
                  <a href="/admin/users/<?= $reportedBy ?>" class="truncate"><?= $this->e($report['reported_name'] ?: 'Account #' . $reportedBy) ?></a>
                  <?php if (($report['reported_status'] ?? 'active') !== 'active'): ?>
                    <span class="chip chip--neutral"><?= $this->e(ucfirst((string) $report['reported_status'])) ?></span>
                  <?php endif; ?>
                  <div class="tiny muted mt-1">
                    <?php $openAbout = (int) ($report['reported_open_reports'] ?? 0); ?>
                    <a href="<?= $this->queryUrl(['reported_user_id' => $reportedBy, 'status' => 'all', 'page' => 1]) ?>">
                      <?= $openAbout ?> open report<?= $openAbout === 1 ? '' : 's' ?>
                    </a>
                    · blocked by <?= (int) ($report['reported_blocked_by'] ?? 0) ?>
                  </div>
                <?php else: ?>
                  <span class="tiny faint">—</span>
                <?php endif; ?>
              </td>

              <td>
                <span class="chip chip--<?= in_array($reason, ReportService::SERIOUS_REASONS, true) ? 'error' : 'warning' ?>">
                  <?= $this->e(ReportService::REASON_LABELS[$reason] ?? ucfirst(str_replace('_', ' ', $reason))) ?>
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

                    <?php if (isset($takeDownLabels[$type])): ?>
                      <button type="button" class="btn btn--danger btn--sm"
                              title="<?= $this->e($takeDownLabels[$type][1]) ?>"
                              onclick="takeDown(<?= (int) $report['id'] ?>, <?= $this->e((string) json_encode($takeDownLabels[$type][1])) ?>)">
                        <?= $this->e($takeDownLabels[$type][0]) ?>
                      </button>
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

function takeDown(id, effect) {
  const note = window.prompt(
    effect + '\n\nThe person who posted it is told it was removed, not who reported it. '
      + 'Every open report on it is resolved.\n\nNote for the audit trail (optional):'
  );
  if (note === null) return;

  const form = document.getElementById('report-form');
  form.action = '/admin/reports/' + id + '/take-down';
  document.getElementById('report-status').value = '';
  document.getElementById('report-note').value = note.trim();
  form.submit();
}
</script>
<?php $this->stop(); ?>
