<?php

declare(strict_types=1);

/**
 * Dispute arbitration queue.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $disputes
 * @var array<string,mixed> $pagination
 * @var array<string,mixed> $filters
 */

$this->extend('admin.layouts.app');
$this->start('content');

$status = (string) ($filters['status'] ?? 'open');

?>

<div class="chip-row mb-5">
  <?php foreach ([
      'open' => 'Open', 'under_review' => 'Under review',
      'resolved_brand' => 'Resolved (brand)', 'resolved_creator' => 'Resolved (creator)',
      'resolved_split' => 'Split', 'all' => 'All',
  ] as $value => $label): ?>
    <a href="<?= $this->queryUrl(['status' => $value, 'page' => 1]) ?>"
       class="chip-filter<?= $status === $value ? ' chip-filter--active' : '' ?>"><?= $this->e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card mb-5" style="border-color:var(--warning);background:var(--warning-container)">
  <div class="card__body row gap-3" style="padding:14px 18px">
    <span style="color:var(--warning)"><?= $this->icon('alert') ?></span>
    <p class="small" style="color:var(--warning)">
      Resolving a dispute moves real money. The refund goes to the brand's wallet and the payout to the
      creator's — they must together not exceed what is held in escrow. Both amounts are recorded with
      your resolution note.
    </p>
  </div>
</div>

<?php if ($disputes === []): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__art"><?= $this->icon('check-circle', 'ic ic--lg') ?></div>
      <p class="empty__title">No disputes</p>
      <p class="empty__text">Nothing is waiting for arbitration.</p>
    </div>
  </div>
<?php else: ?>
  <div class="col gap-4">
    <?php foreach ($disputes as $dispute): ?>
      <?php $isOpen = in_array($dispute['status'], ['open', 'under_review'], true); ?>
      <div class="card<?= $isOpen ? '' : ' ' ?>" <?= $isOpen ? 'style="border-color:var(--error)"' : '' ?>>
        <div class="card__head">
          <div>
            <div class="row gap-2 row--wrap">
              <a href="/admin/bookings/<?= (int) $dispute['booking_id'] ?>" class="mono h3 text-primary">
                #<?= $this->e($dispute['booking_code']) ?>
              </a>
              <span class="chip chip--<?= $isOpen ? 'error' : 'success' ?>">
                <span class="chip__dot"></span><?= $this->e(ucfirst(str_replace('_', ' ', (string) $dispute['status']))) ?>
              </span>
              <span class="chip chip--neutral"><?= $this->e(ucfirst(str_replace('_', ' ', (string) $dispute['reason']))) ?></span>
            </div>
            <p class="tiny muted mt-1">
              <?= $this->e($dispute['booking_title']) ?> ·
              raised by <?= $this->e($dispute['raised_by_name']) ?> (<?= $this->e($dispute['raised_by']) ?>)
              · <?= $this->timeAgo($dispute['created_at']) ?>
            </p>
          </div>
          <div class="text-right">
            <div class="label">Escrow</div>
            <div class="h3"><?= $this->money($dispute['total_amount'], (string) $dispute['currency']) ?></div>
          </div>
        </div>

        <div class="card__body">
          <div class="label mb-2">Complaint</div>
          <p class="small" style="line-height:1.65"><?= nl2br($this->e($dispute['description'])) ?></p>

          <?php if (!empty($dispute['resolution_note'])): ?>
            <div class="card mt-4" style="background:var(--surface-low)">
              <div class="card__body" style="padding:14px 16px">
                <div class="label mb-1">Resolution by <?= $this->e($dispute['handler_name'] ?? 'admin') ?></div>
                <p class="small"><?= $this->e($dispute['resolution_note']) ?></p>
                <div class="row gap-4 mt-3">
                  <div>
                    <div class="tiny faint">Refunded to brand</div>
                    <div class="small mono" style="font-weight:600"><?= $this->money($dispute['refund_amount'], (string) $dispute['currency']) ?></div>
                  </div>
                  <div>
                    <div class="tiny faint">Paid to creator</div>
                    <div class="small mono" style="font-weight:600"><?= $this->money($dispute['payout_amount'], (string) $dispute['currency']) ?></div>
                  </div>
                  <div>
                    <div class="tiny faint">Resolved</div>
                    <div class="small"><?= $this->dateTime($dispute['resolved_at']) ?></div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($isOpen && $this->can('bookings.dispute')): ?>
            <hr class="divider">

            <form method="post" action="/admin/disputes/<?= (int) $dispute['id'] ?>/resolve">
              <?= $this->csrf() ?>

              <div class="form-grid form-grid--2">
                <div class="field">
                  <label class="field__label" for="outcome_<?= (int) $dispute['id'] ?>">Outcome <span class="req">*</span></label>
                  <select class="select" name="outcome" id="outcome_<?= (int) $dispute['id'] ?>" required>
                    <option value="resolved_brand">In favour of the brand (full refund)</option>
                    <option value="resolved_creator">In favour of the creator (full payout)</option>
                    <option value="resolved_split">Split between both</option>
                    <option value="withdrawn">Withdrawn — no money moves</option>
                  </select>
                </div>

                <div class="field">
                  <label class="field__label" for="refund_<?= (int) $dispute['id'] ?>">Refund to brand</label>
                  <input class="input" type="number" step="0.01" min="0"
                         max="<?= $this->e($dispute['total_amount']) ?>"
                         name="refund_amount" id="refund_<?= (int) $dispute['id'] ?>" value="0">
                  <p class="field__hint">Max <?= $this->money($dispute['total_amount'], (string) $dispute['currency']) ?></p>
                </div>

                <div class="field">
                  <label class="field__label" for="payout_<?= (int) $dispute['id'] ?>">Payout to creator</label>
                  <input class="input" type="number" step="0.01" min="0"
                         name="payout_amount" id="payout_<?= (int) $dispute['id'] ?>" value="0">
                </div>
              </div>

              <div class="field">
                <label class="field__label" for="note_<?= (int) $dispute['id'] ?>">Resolution note <span class="req">*</span></label>
                <textarea class="textarea" name="note" id="note_<?= (int) $dispute['id'] ?>" rows="3" required
                          minlength="10" placeholder="Explain the decision. This is permanently recorded."></textarea>
              </div>

              <button type="submit" class="btn btn--primary"
                      data-confirm="This moves money immediately and cannot be undone."
                      data-confirm-title="Resolve this dispute?" data-confirm-label="Resolve">
                <?= $this->icon('check', 'ic ic--sm') ?> Resolve dispute
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php $this->partial('admin.partials.pagination', ['pagination' => $pagination]); ?>
<?php endif; ?>

<?php $this->stop(); ?>
