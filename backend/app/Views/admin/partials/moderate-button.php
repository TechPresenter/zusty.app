<?php

declare(strict_types=1);

/**
 * Single-action moderation button for a review row.
 *
 * @var \Zuste\Core\View $this
 * @var int    $reviewId
 * @var string $action   publish | hide | delete | unflag
 * @var string $label
 * @var string $style    outline | ghost | primary
 * @var string $icon
 * @var bool   $danger
 * @var string $confirm
 */

$style   = $style ?? 'outline';
$icon    = $icon ?? '';
$danger  = $danger ?? false;
$confirm = $confirm ?? 'Apply this moderation action?';
$label   = $label ?? '';

?>
<form method="post" action="/admin/reviews/<?= (int) $reviewId ?>/moderate" style="display:inline">
  <?= $this->csrf() ?>
  <input type="hidden" name="action" value="<?= $this->e($action) ?>">
  <button type="submit"
          class="btn btn--<?= $this->e($style) ?> btn--sm<?= $label === '' ? ' btn--icon' : '' ?><?= $danger ? ' text-error' : '' ?>"
          <?= $label === '' ? 'aria-label="' . $this->e(ucfirst($action)) . '"' : '' ?>
          data-confirm="<?= $this->e($confirm) ?>"
          data-confirm-title="<?= $this->e(ucfirst($action)) ?> this review?"
          data-confirm-label="<?= $this->e(ucfirst($action)) ?>"
          data-confirm-tone="<?= $danger ? 'danger' : 'warning' ?>">
    <?php if ($icon !== ''): ?><?= $this->icon($icon, 'ic ic--sm') ?><?php endif; ?>
    <?= $this->e($label) ?>
  </button>
</form>
