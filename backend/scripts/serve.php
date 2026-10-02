<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in development server.
 *
 * The built-in server does not read .htaccess, so this script reproduces the
 * same rewrite rules locally:
 *
 *   /admin, /admin/*  → public/admin/index.php
 *   real files        → served as-is
 *   everything else   → public/index.php  (the API)
 *
 * Start it with:
 *
 *   php -S localhost:8000 -t backend/public backend/scripts/serve.php
 *
 * Then open http://localhost:8000/admin
 *
 * NEVER use this in production — Hostinger serves through Apache/LiteSpeed
 * with public/.htaccess.
 */

$publicPath = dirname(__DIR__) . '/public';
$uri        = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri        = '/' . ltrim($uri, '/');

// Block the paths .htaccess denies, so local behaviour matches production.
if (preg_match('#^/(app|config|routes|database|storage|scripts|languages)(/|$)#', $uri) === 1
    || preg_match('#(^|/)\.#', $uri) === 1) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden.', 'code' => 'forbidden']);

    return true;
}

// Serve an existing static file (CSS, JS, uploads, images) directly.
$candidate = realpath($publicPath . $uri);

if ($candidate !== false
    && is_file($candidate)
    && str_starts_with($candidate, realpath($publicPath) ?: $publicPath)
    && !str_ends_with($candidate, '.php')) {
    return false; // Let the built-in server handle it.
}

// Admin panel.
if ($uri === '/admin' || str_starts_with($uri, '/admin/')) {
    require $publicPath . '/admin/index.php';

    return true;
}

// Everything else is the API.
require $publicPath . '/index.php';

return true;
