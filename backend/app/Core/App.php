<?php

declare(strict_types=1);

namespace Zuste\Core;

use Throwable;

/**
 * Application kernel.
 *
 * Boots the environment, configures error handling, and runs the router for
 * either the API or the admin panel. Both front controllers (public/index.php
 * and public/admin/index.php) call into here, so security defaults are applied
 * exactly once and in one place.
 */
final class App
{
    private static ?self $instance = null;

    private Router $router;

    private string $basePath;

    private float $startedAt;

    private string $mode = 'api';

    private function __construct(string $basePath)
    {
        $this->basePath  = rtrim($basePath, '/\\');
        $this->startedAt = microtime(true);
        $this->router    = new Router();
    }

    public static function boot(string $basePath): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $app = new self($basePath);
        $app->loadEnvironment();
        $app->configureErrorHandling();
        $app->configureRuntime();
        $app->ensureStorageDirectories();

        return self::$instance = $app;
    }

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            throw new \RuntimeException('Application has not been booted.');
        }

        return self::$instance;
    }

    public function basePath(string $append = ''): string
    {
        return $this->basePath . ($append === '' ? '' : DIRECTORY_SEPARATOR . ltrim($append, '/\\'));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    // -----------------------------------------------------------------
    // Boot steps
    // -----------------------------------------------------------------

    private function loadEnvironment(): void
    {
        // Prefer an .env stored one level above the app root — on shared
        // hosting that keeps it outside the web-accessible directory.
        $candidates = [
            dirname($this->basePath) . DIRECTORY_SEPARATOR . '.env',
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                Env::load($candidate);
                break;
            }
        }

        Config::load($this->basePath . DIRECTORY_SEPARATOR . 'config');
    }

    private function configureRuntime(): void
    {
        date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));

        mb_internal_encoding('UTF-8');
        setlocale(LC_NUMERIC, 'C'); // Guarantees "." as the decimal separator in SQL.

        ini_set('default_charset', 'UTF-8');
        ini_set('serialize_precision', '-1');

        // Never advertise the PHP version.
        header_remove('X-Powered-By');
        ini_set('expose_php', '0');
    }

    private function configureErrorHandling(): void
    {
        $debug = (bool) Config::get('app.debug', false);

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            $this->renderException($e);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::critical('Fatal error', [
                    'message' => $error['message'],
                    'file'    => $error['file'],
                    'line'    => $error['line'],
                ]);

                if (!Response::wasSent() && !headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'message' => 'A fatal error occurred.',
                        'code'    => 'fatal_error',
                    ]);
                }
            }
        });
    }

    private function ensureStorageDirectories(): void
    {
        $directories = [
            (string) Config::get('log.path'),
            (string) Config::get('storage.base_path'),
            (string) Config::get('storage.backup_path'),
            (string) Config::get('storage.temp_path'),
            (string) Config::get('storage.upload_path'),
        ];

        foreach ($directories as $directory) {
            if ($directory === '' || is_dir($directory)) {
                continue;
            }

            @mkdir($directory, 0750, true);
        }
    }

    // -----------------------------------------------------------------
    // Running
    // -----------------------------------------------------------------

    /**
     * Handle an API request.
     *
     * @param callable(Router):void $routeLoader
     */
    public function runApi(callable $routeLoader): void
    {
        $this->mode = 'api';

        $request   = Request::capture();
        $startedAt = microtime(true);

        Response::securityHeaders(false);
        Response::cors($request->origin());

        if ($request->isMethod('OPTIONS')) {
            Response::json(['success' => true, 'message' => 'OK'], 204);
        }

        // The response helpers terminate with exit(), so the only place that
        // reliably sees the finished status code is a shutdown function. It
        // also means the log is written after the bytes are on the wire, so
        // logging never adds latency to the response the caller waits for.
        register_shutdown_function(
            static function () use ($request, $startedAt): void {
                // Only real API calls. On the dev server every unmatched static
                // asset also reaches this front controller, and those 404s would
                // otherwise drown the log the table exists to hold.
                if (!str_starts_with($request->path(), '/api/')) {
                    return;
                }

                \Zuste\Services\ApiLogService::record(
                    $request,
                    http_response_code() ?: 200,
                    $startedAt,
                    null,
                    Database::queryCount()
                );
            }
        );

        $this->registerApiMiddleware();
        $routeLoader($this->router);

        try {
            $this->router->dispatch($request);
        } catch (Throwable $e) {
            $this->renderException($e, $request);
        }
    }

    /**
     * Handle an admin panel request.
     *
     * @param callable(Router):void $routeLoader
     */
    public function runAdmin(callable $routeLoader): void
    {
        $this->mode = 'admin';

        $request = Request::capture();

        Response::securityHeaders(true);
        Session::start();

        $this->registerAdminMiddleware();
        $routeLoader($this->router);

        try {
            $this->router->dispatch($request);
        } catch (Throwable $e) {
            $this->renderException($e, $request);
        }
    }

    private function registerApiMiddleware(): void
    {
        $this->router->registerMiddleware('auth', static function (Request $request): void {
            \Zuste\Middleware\AuthMiddleware::handle($request, true);
        });

        $this->router->registerMiddleware('auth.optional', static function (Request $request): void {
            \Zuste\Middleware\AuthMiddleware::handle($request, false);
        });

        $this->router->registerMiddleware('creator', static function (Request $request): void {
            \Zuste\Middleware\AuthMiddleware::requireAccountType($request, 'creator');
        });

        $this->router->registerMiddleware('brand', static function (Request $request): void {
            \Zuste\Middleware\AuthMiddleware::requireAccountType($request, 'brand');
        });

        $this->router->registerMiddleware('verified', static function (Request $request): void {
            \Zuste\Middleware\AuthMiddleware::requireVerified($request);
        });

        // Only needed on routes that ask for `auth` alone — `brand`, `creator`
        // and `verified` already exclude guests by construction.
        $this->router->registerMiddleware('no-guest', static function (Request $request): void {
            \Zuste\Middleware\AuthMiddleware::denyGuest($request);
        });

        // Mandatory KYC gate. A no-op when verification is switched off for
        // that side of the marketplace in Admin → Settings.
        $this->router->registerMiddleware('kyc', static function (Request $request): void {
            \Zuste\Middleware\KycMiddleware::handle($request);
        });

        $this->router->registerMiddleware('throttle', static function (Request $request, ?string $argument): void {
            \Zuste\Middleware\ThrottleMiddleware::handle($request, $argument);
        });

        $this->router->registerMiddleware('maintenance', static function (Request $request): void {
            \Zuste\Middleware\MaintenanceMiddleware::handle($request);
        });

        $this->router->registerMiddleware('cron', static function (Request $request): void {
            \Zuste\Middleware\CronMiddleware::handle($request);
        });
    }

    private function registerAdminMiddleware(): void
    {
        $this->router->registerMiddleware('admin.guest', static function (Request $request): void {
            \Zuste\Middleware\AdminAuthMiddleware::guestOnly($request);
        });

        $this->router->registerMiddleware('admin.auth', static function (Request $request): void {
            \Zuste\Middleware\AdminAuthMiddleware::handle($request);
        });

        $this->router->registerMiddleware('permission', static function (Request $request, ?string $permission): void {
            \Zuste\Middleware\AdminAuthMiddleware::requirePermission($request, (string) $permission);
        });

        $this->router->registerMiddleware('csrf', static function (Request $request): void {
            \Zuste\Middleware\CsrfMiddleware::handle($request);
        });

        $this->router->registerMiddleware('throttle', static function (Request $request, ?string $argument): void {
            \Zuste\Middleware\ThrottleMiddleware::handle($request, $argument);
        });
    }

    // -----------------------------------------------------------------
    // Error rendering
    // -----------------------------------------------------------------

    private function renderException(Throwable $e, ?Request $request = null): void
    {
        $debug      = (bool) Config::get('app.debug', false);
        $wantsJson  = $request === null || $request->expectsJson() || $this->mode === 'api';

        // Roll back any half-finished write so we never leave torn state.
        if (Database::inTransaction()) {
            try {
                Database::rollBack();
            } catch (Throwable) {
                // Already unusable; nothing more we can do.
            }
        }

        if ($e instanceof ValidationException) {
            if ($wantsJson) {
                Response::validationError($e->errors(), $e->getMessage());
            }

            Session::flash('errors', $e->errors());
            Session::flash('old', $request?->all() ?? []);
            Response::redirect(Session::previousUrl() ?? '/admin');
        }

        if ($e instanceof TokenException) {
            Response::unauthorized($e->getMessage(), $e->expired ? 'token_expired' : 'invalid_token');
        }

        if ($e instanceof HttpException) {
            if ($e->status() >= 500) {
                Logger::exception($e, Logger::ERROR);
            }

            // A 429 is only actionable if the client is told how long to wait.
            // The throttle middleware sets this via Response::tooManyRequests(),
            // but an HttpException thrown deeper in the stack renders through
            // Response::error(), which does not — so the header is attached here.
            if ($e->status() === 429 && $e->retryAfter() !== null) {
                Response::header('Retry-After', (string) $e->retryAfter());
            }

            if ($wantsJson) {
                Response::error($e->getMessage(), $e->status(), $e->errorCode(), $e->errors());
            }

            $this->renderAdminError($e->status(), $e->getMessage());
        }

        Logger::exception($e, Logger::CRITICAL, [
            'path'   => $request?->path(),
            'method' => $request?->method(),
        ]);

        if ($wantsJson) {
            $payload = [
                'success' => false,
                'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.',
                'code'    => 'server_error',
            ];

            if ($debug) {
                $payload['debug'] = [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
                ];
            }

            Response::json($payload, 500);
        }

        $this->renderAdminError(500, $debug ? $e->getMessage() : 'An unexpected error occurred.');
    }

    private function renderAdminError(int $status, string $message): never
    {
        $view = $this->basePath('app/Views/admin/errors/generic.php');

        if (is_file($view)) {
            ob_start();
            $errorStatus  = $status;
            $errorMessage = $message;
            require $view;
            Response::html((string) ob_get_clean(), $status);
        }

        Response::html(
            '<!doctype html><meta charset="utf-8"><title>' . $status . '</title>'
            . '<body style="font:16px/1.5 system-ui;padding:3rem;color:#28171A">'
            . '<h1 style="color:#B80049">' . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="/admin" style="color:#632CE5">Back to dashboard</a></p></body>',
            $status
        );
    }
}
