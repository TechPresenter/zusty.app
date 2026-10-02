<?php

declare(strict_types=1);

/**
 * Standalone error page for the admin panel.
 *
 * Rendered directly by App::renderAdminError(), so it deliberately has no
 * layout dependency and no database access — it must work even when the
 * database is the thing that broke.
 *
 * @var int    $errorStatus
 * @var string $errorMessage
 */

$status  = $errorStatus ?? 500;
$message = $errorMessage ?? 'An unexpected error occurred.';

$titles = [
    400 => 'Bad request',
    401 => 'Sign in required',
    403 => 'Not permitted',
    404 => 'Page not found',
    405 => 'Method not allowed',
    409 => 'Conflict',
    419 => 'Session expired',
    422 => 'Could not process that',
    429 => 'Too many requests',
    500 => 'Something went wrong',
    503 => 'Under maintenance',
];

$title = $titles[$status] ?? 'Error';

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $status ?> · <?= $e($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/css/admin.css">
<link rel="icon" type="image/png" sizes="64x64" href="/admin/assets/img/favicon.png">
</head>
<body>

<main style="min-height:100vh;display:grid;place-items:center;padding:24px">
  <div class="card slide-up" style="max-width:520px;width:100%;text-align:center">
    <div class="card__body" style="padding:48px 32px">

      <img src="/admin/assets/img/zusty-logo.png" width="166" height="36" alt="Zusty" style="display:block;margin:0 auto 28px">

      <div class="empty__art" style="margin-bottom:24px">
        <svg class="ic ic--lg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($status === 404): ?>
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
          <?php elseif ($status === 403 || $status === 401): ?>
            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          <?php else: ?>
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <path d="M12 9v4M12 17h.01"/>
          <?php endif; ?>
        </svg>
      </div>

      <div class="display text-primary mb-2"><?= $status ?></div>
      <h1 class="h2 mb-3"><?= $e($title) ?></h1>
      <p class="muted mb-6" style="max-width:380px;margin-inline:auto"><?= $e($message) ?></p>

      <div class="row gap-3" style="justify-content:center">
        <a href="/admin" class="btn btn--primary">Back to dashboard</a>
        <button type="button" class="btn btn--outline" onclick="history.back()">Go back</button>
      </div>

    </div>
  </div>
</main>

</body>
</html>
