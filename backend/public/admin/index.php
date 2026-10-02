<?php

declare(strict_types=1);

/**
 * Zusty Admin Panel — front controller.
 *
 * All /admin/* requests are rewritten here by ../.htaccess.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Router;
use Zuste\Core\View;

define('ZUSTE_START', microtime(true));

$basePath = dirname(__DIR__, 2);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader())
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

$app = App::boot($basePath);

View::setPath($basePath . '/app/Views');

$app->runAdmin(static function (Router $router) use ($basePath): void {
    (require $basePath . '/routes/admin.php')($router);
});
