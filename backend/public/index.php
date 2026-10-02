<?php

declare(strict_types=1);

/**
 * Zusty REST API — front controller.
 *
 * Every /api/* request is rewritten here by .htaccess. Nothing else in the
 * application is reachable from the web root.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Router;

define('ZUSTE_START', microtime(true));

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader())
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

$app = App::boot($basePath);

$app->runApi(static function (Router $router) use ($basePath): void {
    (require $basePath . '/routes/api.php')($router);
});
