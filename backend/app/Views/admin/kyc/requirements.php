<?php

declare(strict_types=1);

/**
 * KYC requirement rules — which documents each side must provide, and the
 * format and size limits applied to each.
 *
 * @var \Zuste\Core\View $this
 * @var list<array<string,mixed>> $brand
 * @var list<array<string,mixed>> $creator
 */

$this->extend('admin.layouts.app');
$this->start('content');

/** How many of a list are switched on. */
$liveCount = static fn (array $rows): int => count(array_filter(
    $rows,
    static fn (array $r): bool => (int) $r['is_active'] === 1
));

/** One editable requirement row. */
$row = function (array $requirement): void {
    $isActive = (int) $requirement['is_active'] === 1;
    ?>
    <form method="post" action="/admin/kyc/requirements/<?= (int) $requirement['id'] ?>"
          class="card mb-2" style="border:1px solid var(--outline-variant)<?= $isActive ? '' : ';opacity:.7' ?>">
      <?= $this->csrf() ?>
      <div class="card__body" style="padding:12px 14px">
        <div class="row gap-3" style="flex-wrap:wrap;align-items:flex-end">

          <label class="field" style="flex:2 1 220px">
            <span class="field__label tiny">Label</span>
            <input type="text" name="label" class="input input--sm" required maxlength="150"
                   value="<?= $this->e((string) $requirement['label']) ?>">
          </label>

          <label class="field" style="flex:3 1 280px">
            <span class="field__label tiny">Helper text</span>
            <input type="text" name="description" class="input input--sm" maxlength="500"
                   value="<?= $this->e((string) ($requirement['description'] ?? '')) ?>">
          </label>

          <label class="field" style="flex:1 1 160px">
            <span class="field__label tiny">Formats</span>
            <input type="text" name="allowed_formats" class="input input--sm" required
                   value="<?= $this->e((string) $requirement['allowed_formats']) ?>">
          </label>

          <label class="field" style="flex:0 0 90px">
            <span class="field__label tiny">Max MB</span>
            <input type="number" name="max_size_mb" class="input input--sm" min="1" max="100" required
                   value="<?= (int) $requirement['max_size_mb'] ?>">
          </label>

          <label class="field" style="flex:0 0 90px">
            <span class="field__label tiny">Order</span>
            <input type="number" name="sort_order" class="input input--sm" min="0" max="9999"
                   value="<?= (int) $requirement['sort_order'] ?>">
          </label>
        </div>

        <div class="row gap-4 mt-2" style="flex-wrap:wrap;align-items:center">
          <label class="row gap-2 tiny">
            <input type="checkbox" name="is_required" value="1"
                   <?= (int) $requirement['is_required'] === 1 ? 'checked' : '' ?>> Required
          </label>
          <label class="row gap-2 tiny">
            <input type="checkbox" name="allows_multiple" value="1"
                   <?= (int) $requirement['allows_multiple'] === 1 ? 'checked' : '' ?>> Allow multiple files
          </label>
          <label class="row gap-2 tiny">
            <input type="checkbox" name="requires_expiry" value="1"
                   <?= (int) $requirement['requires_expiry'] === 1 ? 'checked' : '' ?>> Needs expiry date
          </label>
          <label class="row gap-2 tiny">
            <input type="checkbox" name="is_active" value="1"
                   <?= (int) $requirement['is_active'] === 1 ? 'checked' : '' ?>> Active
          </label>

          <span class="tiny faint mono"><?= $this->e((string) $requirement['slot']) ?></span>

          <?php if (!$isActive): ?>
            <span class="chip chip--neutral tiny">Off — not asked for</span>
          <?php endif; ?>

          <button type="submit" class="btn btn--sm btn--secondary" style="margin-left:auto">Save</button>
        </div>
      </div>
    </form>
    <?php
};

?>

<div class="card mb-5" style="border-color:var(--secondary);background:color-mix(in srgb, var(--secondary) 6%, transparent)">
  <div class="card__body row gap-3" style="padding:14px 18px">
    <span class="text-secondary"><?= $this->icon('info') ?></span>
    <p class="small">
      These rules are data, not code — turning a document off here immediately stops it being asked
      for, and unmarking <em>Required</em> lets applicants submit without it. Existing submissions are
      not re-evaluated, so tightening a rule only affects new ones. Every save is recorded in the
      activity log against your account, with each changed field's old and new value.
    </p>
  </div>
</div>

<div class="card mb-5">
  <div class="card__head">
    <h3 class="card__title">Brand — business verification</h3>
    <span class="tiny muted"><?= $liveCount($brand) ?> of <?= count($brand) ?> document types active</span>
  </div>
  <div class="card__body">
    <?php foreach ($brand as $requirement) { $row($requirement); } ?>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <h3 class="card__title">Creator — identity verification</h3>
    <span class="tiny muted"><?= $liveCount($creator) ?> of <?= count($creator) ?> document types active</span>
  </div>
  <div class="card__body">
    <?php foreach ($creator as $requirement) { $row($requirement); } ?>
  </div>
</div>

<?php $this->stop(); ?>
