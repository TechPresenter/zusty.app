<?php

declare(strict_types=1);

/**
 * Legacy single-document KYC submissions — READ-ONLY.
 *
 * The approve path this screen used to offer set `kyc_status = verified`
 * straight from one document, outside the verification state machine (no
 * completeness check, no audit event, no blue-tick separation). Decisions are
 * made in the KYC panel; this keeps the history visible and nothing more.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $documents
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$tone = static fn (string $status): string => match ($status) {
    'verified'                 => 'success',
    'pending', 'under_review'  => 'warning',
    'rejected'                 => 'error',
    default                    => 'neutral',
};

$status = (string) ($filters['status'] ?? 'pending');

?>

<div class="chip-row mb-5">
  <?php foreach ([
      'pending'      => 'Awaiting review',
      'under_review' => 'Under review',
      'verified'     => 'Verified',
      'rejected'     => 'Rejected',
      'all'          => 'All submissions',
  ] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= $status === $value ? ' chip-filter--active' : '' ?>">
      <?= $this->e($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="alert alert--warning mb-5">
  <strong class="small">This legacy queue is read-only.</strong>
  <span class="small">
    Only submissions from the retired single-document flow are kept here, for reference. Everything
    submitted since — and every decision — lives in the <a href="/admin/kyc">KYC panel</a>, which applies
    the document checklist, the audit trail and the separate blue-tick decision.
  </span>
</div>

<div class="card">

  <?php $this->partial('admin.partials.toolbar', [
      'filters'           => $filters,
      'searchPlaceholder' => 'Search by name, email or document holder…',
  ]); ?>

  <?php if ($documents === []): ?>
    <div class="empty">
      <div class="empty__art"><?= $this->icon('user-check', 'ic ic--lg') ?></div>
      <p class="empty__title">Nothing to review</p>
      <p class="empty__text">There are no KYC submissions matching this filter.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Document</th>
            <th>Holder name</th>
            <th>Number</th>
            <th>Submitted</th>
            <th>Status</th>
            <th class="text-right">Files</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($documents as $document): ?>
            <tr>
              <td>
                <div class="cell-user">
                  <?php if (!empty($document['avatar_url'])): ?>
                    <img class="avatar avatar--sm" src="<?= $this->e($document['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <div class="avatar avatar--sm avatar--fallback"><?= $this->e($this->initials((string) $document['user_name'])) ?></div>
                  <?php endif; ?>
                  <div class="flex-1 truncate">
                    <div class="cell-user__name truncate">
                      <a href="/admin/users/<?= (int) $document['user_id'] ?>"><?= $this->e($document['user_name']) ?></a>
                    </div>
                    <div class="cell-user__meta truncate"><?= $this->e($document['email']) ?></div>
                  </div>
                </div>
              </td>

              <td class="small"><?= $this->e(ucwords(str_replace('_', ' ', (string) $document['document_type']))) ?></td>
              <td class="small"><?= $this->e($document['holder_name']) ?></td>
              <td class="small mono faint"><?= $this->e($document['document_number']) ?></td>
              <td class="small muted"><?= $this->dateTime($document['created_at']) ?></td>

              <td>
                <span class="chip chip--<?= $tone((string) $document['status']) ?>">
                  <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $document['status']))) ?>
                </span>
                <?php if (!empty($document['rejection_reason'])): ?>
                  <div class="tiny text-error mt-1 clamp-2" style="max-width:200px"><?= $this->e($document['rejection_reason']) ?></div>
                <?php endif; ?>
              </td>

              <td class="actions">
                <div class="row gap-2 row--end">
                  <?php // Opening an identity image takes the same permission as
                        // opening a document in the KYC panel. ?>
                  <?php foreach ([
                      ['front_image_url', 'Front'],
                      ['back_image_url', 'Back'],
                      ['selfie_url', 'Selfie'],
                  ] as [$field, $label]): ?>
                    <?php if (!empty($document[$field]) && $this->can('kyc.download')): ?>
                      <a href="<?= $this->e($document[$field]) ?>" target="_blank" rel="noopener noreferrer"
                         class="btn btn--ghost btn--sm" title="Open <?= $this->e($label) ?> image">
                        <?= $this->icon('image', 'ic ic--sm') ?> <?= $this->e($label) ?>
                      </a>
                    <?php endif; ?>
                  <?php endforeach; ?>

                </div>
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


