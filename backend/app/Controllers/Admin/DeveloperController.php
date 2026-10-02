<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Cache;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Helpers\Str;
use Zuste\Services\ApiLogService;

/**
 * Developer tools: the API request log, the slow-query monitor, the cache
 * manager and a server-side REST tester.
 *
 * Everything here reads real infrastructure state. Nothing is simulated, and
 * every destructive action is permissioned, confirmed in the UI and written to
 * the activity log.
 */
final class DeveloperController extends BaseAdminController
{
    /** Columns a user may sort the request log by. */
    private const LOG_SORTABLE = ['created_at', 'duration_ms', 'status_code', 'query_count', 'memory_kb', 'path'];

    /** Columns a user may sort the query monitor by. */
    private const QUERY_SORTABLE = ['occurrences', 'max_duration_ms', 'duration_ms', 'last_seen_at', 'first_seen_at'];

    /** Verbs the API tester may issue. */
    private const TESTER_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** Extensions whose presence changes what this application can do. */
    private const WATCHED_EXTENSIONS = ['pdo_mysql', 'curl', 'zip', 'gd', 'openssl', 'mbstring', 'apcu', 'opcache'];

    /**
     * Extensions that register under a different name than operators use.
     *
     * OPcache is the one that matters: it registers as "Zend OPcache", so
     * extension_loaded('opcache') is false on a host where OPcache is enabled.
     * Probing only the short name reported it missing everywhere — including the
     * production hosts where it is on — which is exactly backwards for a panel
     * whose job is to tell an operator whether it is on.
     */
    private const EXTENSION_ALIASES = ['opcache' => 'Zend OPcache'];

    /** Cache coordinates for the overview's api_logs rollup. */
    private const STATS_CACHE_KEY   = 'developer.api.stats';
    private const STATS_CACHE_GROUP = 'developer';
    private const STATS_CACHE_TTL   = 120;

    /** Status-code buckets offered as a filter. */
    private const STATUS_CLASSES = ['2xx' => [200, 299], '3xx' => [300, 399], '4xx' => [400, 499], '5xx' => [500, 599]];

    /** Hard ceiling on how much of a tester response is rendered. */
    private const MAX_RESPONSE_CHARS = 200000;

    // =================================================================
    // Overview
    // =================================================================

    /** GET /admin/developer */
    public function index(Request $request): never
    {
        $this->authorize($request, 'developer.logs');

        $queries = Database::selectOne(
            'SELECT COUNT(*)                             AS `shapes`,
                    COALESCE(SUM(`occurrences`), 0)      AS `occurrences`,
                    COALESCE(MAX(`max_duration_ms`), 0)  AS `slowest`,
                    MAX(`last_seen_at`)                  AS `last_seen`
               FROM `query_log`'
        ) ?? [];

        $cacheStats = Cache::stats();

        $extensions = [];
        foreach (self::WATCHED_EXTENSIONS as $extension) {
            $extensions[$extension] = extension_loaded($extension)
                || (isset(self::EXTENSION_ALIASES[$extension]) && extension_loaded(self::EXTENSION_ALIASES[$extension]));
        }

        $this->view($request, 'admin.developer.index', [
            'pageTitle'    => 'Developer tools',
            'pageSubtitle' => 'Request log, query monitor, cache and API tester.',
            // Four aggregates over the largest table in the schema, for a 24 h
            // rolling summary that nobody reads to the second. Cached briefly so
            // opening this screen does not re-scan api_logs every time.
            'api'          => Cache::remember(
                self::STATS_CACHE_KEY,
                self::STATS_CACHE_TTL,
                static fn (): array => ApiLogService::stats(),
                self::STATS_CACHE_GROUP
            ),
            'queries'      => [
                'shapes'      => (int) ($queries['shapes'] ?? 0),
                'occurrences' => (int) ($queries['occurrences'] ?? 0),
                'slowest'     => (float) ($queries['slowest'] ?? 0),
                'last_seen'   => $queries['last_seen'] ?? null,
            ],
            'cache'        => $cacheStats,
            'environment'  => [
                'php'          => PHP_VERSION,
                'sapi'         => PHP_SAPI,
                'database'     => (string) Database::scalar('SELECT VERSION()'),
                'server'       => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
                'timezone'     => date_default_timezone_get(),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_exec'     => (string) ini_get('max_execution_time'),
                'app_env'      => (string) Config::get('app.env', 'production'),
                'app_debug'    => (bool) Config::get('app.debug', false),
                'app_version'  => (string) Config::get('app.version', '1.0.0'),
                'app_url'      => (string) Config::get('app.url', ''),
                'curl_version' => function_exists('curl_version') ? (string) (curl_version()['version'] ?? '') : '',
            ],
            'extensions'   => $extensions,
            'settings'     => [
                'api_logging'   => Config::settingBool('developer', 'api_logging', true),
                'sample_rate'   => ApiLogService::sampleRate(),
                'slow_query_ms' => ApiLogService::slowQueryThresholdMs(),
                'stored'        => (int) Database::scalar(
                    "SELECT COUNT(*) FROM `settings` WHERE `group_key` = 'developer'"
                ) > 0,
                'cache_enabled' => Config::settingBool('cache', 'enabled', true),
                'cache_ttl'     => Config::settingInt('cache', 'default_ttl', 3600),
            ],
        ]);
    }

    /**
     * POST /admin/developer/settings
     *
     * The `developer` group is not in the settings seed, so the one toggle this
     * module owns is created on first save. Everything else the module reads —
     * the sample rate and the slow-query threshold — already belongs to the
     * security group and stays editable in Settings → Security, so it is shown
     * here read-only rather than duplicated.
     */
    public function saveSettings(Request $request): never
    {
        $this->authorize($request, 'developer.logs');
        $this->authorize($request, 'settings.manage');

        // An unchecked checkbox is simply absent from the body.
        $logging = $request->bool('api_logging');

        $this->putSetting(
            'api_logging',
            $logging ? '1' : '0',
            'boolean',
            'API request logging',
            'Write one api_logs row per API request.',
            1
        );

        Config::flushSettings();

        $this->log($request, 'developer.settings.updated', 'settings', null, 'Turned API request logging ' . ($logging ? 'on' : 'off') . '.', [
            'api_logging' => $logging ? '1' : '0',
        ]);

        $this->respond(
            $request,
            $logging ? 'Request logging is on.' : 'Request logging is off.',
            '/admin/developer'
        );
    }

    /** Create-or-update one row in the `developer` settings group. */
    private function putSetting(string $key, string $value, string $type, string $label, string $description, int $sort): void
    {
        Database::execute(
            'INSERT INTO `settings`
                (`group_key`, `setting_key`, `value`, `type`, `label`, `description`,
                 `is_public`, `is_encrypted`, `sort_order`, `updated_at`)
             VALUES (:group, :key, :value, :type, :label, :description, 0, 0, :sort, NOW())
             ON DUPLICATE KEY UPDATE
                `value`      = VALUES(`value`),
                `type`       = VALUES(`type`),
                `label`      = VALUES(`label`),
                `updated_at` = NOW()',
            [
                'group'       => 'developer',
                'key'         => $key,
                'value'       => $value,
                'type'        => $type,
                'label'       => $label,
                'description' => $description,
                'sort'        => $sort,
            ]
        );
    }

    // =================================================================
    // API request log
    // =================================================================

    /** GET /admin/developer/api-logs */
    public function apiLogs(Request $request): never
    {
        $this->authorize($request, 'developer.logs');

        $params = $this->listParams($request, self::LOG_SORTABLE, 'created_at');
        $method = strtoupper($request->string('method'));
        $status = $request->string('status');
        $minMs  = $request->int('min_ms');

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[] = "(l.`path` LIKE :search ESCAPE '\\\\' OR l.`route_name` LIKE :search ESCAPE '\\\\'
                           OR l.`ip_address` LIKE :search ESCAPE '\\\\' OR l.`request_id` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            $clauses[] = 'l.`method` = :method';
            $bindings['method'] = $method;
        }

        if (isset(self::STATUS_CLASSES[$status])) {
            [$low, $high] = self::STATUS_CLASSES[$status];
            $clauses[] = 'l.`status_code` BETWEEN :status_low AND :status_high';
            $bindings['status_low']  = $low;
            $bindings['status_high'] = $high;
        }

        if (in_array($minMs, [200, 500, 1000, 3000], true)) {
            $clauses[] = 'l.`duration_ms` >= :min_ms';
            $bindings['min_ms'] = $minMs;
        } else {
            $minMs = 0;
        }

        $this->applyDateRange($clauses, $bindings, 'l.`created_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                "SELECT l.`created_at`, l.`method`, l.`path`, l.`route_name`, l.`status_code`,
                        l.`duration_ms`, l.`query_count`, l.`memory_kb`, l.`ip_address`,
                        l.`platform`, l.`app_version`, l.`error_message`, l.`request_id`,
                        u.`name` AS `user_name`
                   FROM `api_logs` l
                   LEFT JOIN `users` u ON u.`id` = l.`user_id`
                  WHERE $where
                  ORDER BY l.`created_at` DESC
                  LIMIT 20000",
                $bindings
            );

            $this->exportCsv(
                'api-logs',
                ['When', 'Method', 'Path', 'Route', 'Status', 'Duration (ms)', 'Queries', 'Memory (KB)',
                 'IP address', 'Platform', 'App version', 'User', 'Error', 'Request id'],
                $all,
                ['created_at', 'method', 'path', 'route_name', 'status_code', 'duration_ms', 'query_count',
                 'memory_kb', 'ip_address', 'platform', 'app_version', 'user_name', 'error_message', 'request_id']
            );
        }

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM `api_logs` l WHERE $where",
            $bindings
        );

        $rows = Database::select(
            "SELECT l.*, u.`name` AS `user_name`, u.`email` AS `user_email`
               FROM `api_logs` l
               LEFT JOIN `users` u ON u.`id` = l.`user_id`
              WHERE $where
              ORDER BY l.`{$params['sort_by']}` {$params['sort_dir']}
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        $summary = Database::selectOne(
            "SELECT COUNT(*)                                              AS `matched`,
                    COALESCE(ROUND(AVG(l.`duration_ms`)), 0)              AS `avg_ms`,
                    COALESCE(MAX(l.`duration_ms`), 0)                     AS `max_ms`,
                    SUM(CASE WHEN l.`status_code` >= 400 THEN 1 ELSE 0 END) AS `errors`
               FROM `api_logs` l
              WHERE $where",
            $bindings
        ) ?? [];

        $this->view($request, 'admin.developer.api-logs', [
            'pageTitle'    => 'API request log',
            'pageSubtitle' => 'Every request the mobile apps and the website made to the REST API.',
            'rows'         => $rows,
            'summary'      => $summary,
            'logging'       => Config::settingBool('developer', 'api_logging', true),
            'sampleRate'    => ApiLogService::sampleRate(),
            'retentionDays' => 30,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => [
                'q'        => $params['search'],
                'method'   => $method,
                'status'   => isset(self::STATUS_CLASSES[$status]) ? $status : '',
                'min_ms'   => $minMs > 0 ? (string) $minMs : '',
                'from'     => $params['from'],
                'to'       => $params['to'],
                'sort_by'  => $params['sort_by'],
                'sort_dir' => $params['sort_dir'],
            ],
        ]);
    }

    /** POST /admin/developer/api-logs/prune */
    public function pruneApiLogs(Request $request): never
    {
        // A write, not a read: pruning discards the closest thing the panel has
        // to a request audit trail. Gated on logs.system like the other log purge.
        $this->authorize($request, 'logs.system');

        $data = $this->validate($request, [
            'days' => 'required|integer|between:1,3650',
        ]);

        $days    = (int) $data['days'];
        $removed = ApiLogService::prune($days);

        // The overview rollup counts rows that no longer exist.
        Cache::forget(self::STATS_CACHE_KEY);

        $this->log(
            $request,
            'developer.api_logs.pruned',
            'api_logs',
            null,
            'Removed ' . $removed . ' request log rows older than ' . $days . ' days.',
            ['days' => $days, 'removed' => $removed]
        );

        $this->respond(
            $request,
            $removed === 0
                ? 'Nothing was older than ' . $days . ' days.'
                : $removed . ' request log rows removed.',
            '/admin/developer/api-logs'
        );
    }

    // =================================================================
    // Slow query monitor
    // =================================================================

    /** GET /admin/developer/queries */
    public function queryMonitor(Request $request): never
    {
        $this->authorize($request, 'developer.queries');

        $params = $this->listParams($request, self::QUERY_SORTABLE, 'max_duration_ms');

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[] = "(`sql_text` LIKE :search ESCAPE '\\\\' OR `route` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $this->applyDateRange($clauses, $bindings, '`last_seen_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM `query_log` WHERE $where", $bindings);

        $rows = Database::select(
            "SELECT * FROM `query_log`
              WHERE $where
              ORDER BY `{$params['sort_by']}` {$params['sort_dir']}
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        // The headline numbers and the two "worst of" cards describe the same
        // set of rows the table below is showing. Reading them from the whole
        // table while the table is filtered would mean a search silently
        // changed only half the screen.
        $summary = Database::selectOne(
            "SELECT COUNT(*)                            AS `shapes`,
                    COALESCE(SUM(`occurrences`), 0)     AS `occurrences`,
                    COALESCE(MAX(`max_duration_ms`), 0) AS `slowest`,
                    COALESCE(AVG(`duration_ms`), 0)     AS `avg_ms`,
                    MIN(`first_seen_at`)                AS `first_seen`,
                    MAX(`last_seen_at`)                 AS `last_seen`
               FROM `query_log`
              WHERE $where",
            $bindings
        ) ?? [];

        $slowest = Database::selectOne(
            "SELECT `sql_text`, `route`, `max_duration_ms`, `occurrences`
               FROM `query_log` WHERE $where ORDER BY `max_duration_ms` DESC LIMIT 1",
            $bindings
        );

        $frequent = Database::selectOne(
            "SELECT `sql_text`, `route`, `occurrences`, `duration_ms`
               FROM `query_log` WHERE $where ORDER BY `occurrences` DESC LIMIT 1",
            $bindings
        );

        $this->view($request, 'admin.developer.query-monitor', [
            'pageTitle'    => 'Query monitor',
            'pageSubtitle' => 'Statements slow enough to be worth an index.',
            'rows'         => $rows,
            'summary'      => $summary,
            'slowest'      => $slowest,
            'frequent'     => $frequent,
            'thresholdMs'  => ApiLogService::slowQueryThresholdMs(),
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => [
                'q'        => $params['search'],
                'from'     => $params['from'],
                'to'       => $params['to'],
                'sort_by'  => $params['sort_by'],
                'sort_dir' => $params['sort_dir'],
            ],
        ]);
    }

    /** POST /admin/developer/queries/clear */
    public function clearQueries(Request $request): never
    {
        // A write, not a read: pruning discards the closest thing the panel has
        // to a request audit trail. Gated on logs.system like the other log purge.
        $this->authorize($request, 'logs.system');

        $removed = ApiLogService::clearQueryLog();

        $this->log($request, 'developer.query_log.cleared', 'query_log', null, 'Cleared ' . $removed . ' aggregated slow queries.');

        $this->respond(
            $request,
            $removed === 0 ? 'The query log was already empty.' : $removed . ' aggregated queries cleared.',
            '/admin/developer/queries'
        );
    }

    // =================================================================
    // Cache manager
    // =================================================================

    /** GET /admin/developer/cache */
    public function cache(Request $request): never
    {
        $this->authorize($request, 'developer.cache');

        $groups = Database::select(
            'SELECT `cache_group`,
                    COUNT(*)                                                                     AS `entries`,
                    COALESCE(SUM(CHAR_LENGTH(`value`)), 0)                                       AS `bytes`,
                    COALESCE(SUM(`hits`), 0)                                                     AS `hits`,
                    SUM(CASE WHEN `expires_at` IS NOT NULL AND `expires_at` <= NOW() THEN 1 ELSE 0 END) AS `expired`,
                    MIN(`created_at`)                                                            AS `oldest`,
                    MAX(`expires_at`)                                                            AS `latest_expiry`
               FROM `cache_entries`
              GROUP BY `cache_group`
              ORDER BY `bytes` DESC'
        );

        $largest = Database::select(
            'SELECT `cache_key`, `cache_group`, `hits`, `expires_at`, `created_at`,
                    CHAR_LENGTH(`value`) AS `bytes`
               FROM `cache_entries`
              ORDER BY CHAR_LENGTH(`value`) DESC
              LIMIT 25'
        );

        $this->view($request, 'admin.developer.cache', [
            'pageTitle'    => 'Cache manager',
            'pageSubtitle' => 'What is cached, how big it is and how often it is read.',
            'stats'        => Cache::stats(),
            'groups'       => $groups,
            'largest'      => $largest,
            'defaultTtl'   => Config::settingInt('cache', 'default_ttl', 3600),
            'cacheEnabled' => Config::settingBool('cache', 'enabled', true),
        ]);
    }

    /** POST /admin/developer/cache/flush */
    public function flushCache(Request $request): never
    {
        $this->authorize($request, 'developer.cache');

        $removed = Cache::flush();

        $this->log($request, 'developer.cache.flushed', 'cache_entries', null, 'Flushed the whole cache (' . $removed . ' entries).');

        $this->respond(
            $request,
            $removed === 0 ? 'The cache was already empty.' : $removed . ' cache entries removed.',
            '/admin/developer/cache'
        );
    }

    /** POST /admin/developer/cache/forget — the group travels in the body, not the path. */
    public function forgetCacheGroup(Request $request): never
    {
        $this->authorize($request, 'developer.cache');

        $group = $request->string('group');

        if ($group === '' || mb_strlen($group) > 60) {
            $this->back('error', 'Choose a cache group to clear.', '/admin/developer/cache');
        }

        $removed = Cache::forgetGroup($group);

        $this->log($request, 'developer.cache.group_cleared', 'cache_entries', null, 'Cleared the "' . $group . '" cache group (' . $removed . ' entries).');

        $this->respond(
            $request,
            $removed === 0
                ? 'Nothing was cached under "' . $group . '".'
                : $removed . ' entries removed from "' . $group . '".',
            '/admin/developer/cache'
        );
    }

    /** POST /admin/developer/cache/prune */
    public function pruneCache(Request $request): never
    {
        $this->authorize($request, 'developer.cache');

        $removed = Cache::prune();

        $this->log($request, 'developer.cache.pruned', 'cache_entries', null, 'Pruned ' . $removed . ' expired cache entries.');

        $this->respond(
            $request,
            $removed === 0 ? 'No expired entries to remove.' : $removed . ' expired entries removed.',
            '/admin/developer/cache'
        );
    }

    // =================================================================
    // REST API tester
    // =================================================================

    /** GET /admin/developer/tester */
    public function tester(Request $request): never
    {
        $this->authorize($request, 'developer.tester');

        $this->renderTester($request, null, [
            'method' => 'GET',
            'path'   => '/api/v1/health',
            'body'   => '',
            'token'  => '',
        ]);
    }

    /**
     * POST /admin/developer/tester/run
     *
     * The panel issues the HTTP call itself so the tester works from a browser
     * that cannot reach the API host — a laptop behind a VPN, a firewalled
     * staging box, or an API bound to localhost only.
     *
     * Because that makes this endpoint a server-side request issuer, it is
     * constrained on every axis an SSRF would need:
     *   - the host, scheme and port come from app.url and are never taken from
     *     input, so no attacker-chosen destination is reachable;
     *   - the path must begin with "/api/", removing internal admin routes and
     *     the local filesystem from range;
     *   - "..", backslashes and anything outside a strict character whitelist
     *     are rejected, so the prefix cannot be escaped;
     *   - redirects are not followed, so the API cannot bounce the call
     *     somewhere else;
     *   - only http/https are permitted, and the call times out in 10 seconds.
     */
    public function runTest(Request $request): never
    {
        $this->authorize($request, 'developer.tester');

        $method = strtoupper($request->string('method', 'GET'));
        $path   = $request->string('path');
        $body   = $request->string('body');
        $token  = $request->string('token');

        $submitted = ['method' => $method, 'path' => $path, 'body' => $body, 'token' => $token];

        if (self::baseUrl() === '') {
            $this->finishTest($request, $submitted, self::failure(
                'The tester has no host to call. Set APP_URL in the environment file to this '
                . 'site\'s address (for example https://zustee.com) and reload this page.'
            ));
        }

        if (!in_array($method, self::TESTER_METHODS, true)) {
            $this->finishTest($request, $submitted, self::failure('That HTTP method is not available here.'));
        }

        $pathError = self::validateTesterPath($path);

        if ($pathError !== null) {
            $this->finishTest($request, $submitted, self::failure($pathError));
        }

        // The /D modifier matters: without it "$" also matches immediately
        // before a trailing newline, so a token ending in one would pass and
        // could inject a second header.
        if ($token !== '' && preg_match('/^[A-Za-z0-9\-._~+\/=]{8,4096}$/D', $token) !== 1) {
            $this->finishTest($request, $submitted, self::failure('That bearer token contains characters a header cannot hold.'));
        }

        $body = trim($body);

        if ($body !== '') {
            json_decode($body);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->finishTest($request, $submitted, self::failure('The request body is not valid JSON: ' . json_last_error_msg()));
            }
        }

        if (in_array($method, ['GET', 'DELETE'], true) && $body !== '') {
            $this->finishTest($request, $submitted, self::failure('A ' . $method . ' request cannot carry a body.'));
        }

        $result = $this->issueRequest($method, $path, $body, $token);

        $this->log(
            $request,
            'developer.tester.run',
            'api',
            null,
            $method . ' ' . $path . ' returned ' . ($result['status'] > 0 ? $result['status'] : 'no response'),
            [
                'method'      => $method,
                'path'        => $path,
                'status'      => $result['status'],
                'duration_ms' => $result['duration_ms'],
                'authorised'  => $token !== '',
            ]
        );

        $this->finishTest($request, $submitted, $result);
    }

    /**
     * Render the result, or return it as JSON when the page asked over AJAX.
     *
     * @param array<string,string> $submitted
     * @param array<string,mixed>  $result
     */
    private function finishTest(Request $request, array $submitted, array $result): never
    {
        $result = self::explainLoopbackFailure($result);

        if ($request->expectsJson()) {
            $this->respond(
                $request,
                $result['ok'] === true ? 'Request completed.' : (string) ($result['error'] ?: 'The request could not be completed.'),
                null,
                $result
            );
        }

        $this->renderTester($request, $result, $submitted);
    }

    /**
     * Name the one failure that looks like a broken feature but is not.
     *
     * The tester calls this application's own API. PHP's built-in development
     * server handles a single request at a time, so a loopback call made while
     * it is busy serving this very request cannot be answered and simply times
     * out. Apache and LiteSpeed — including Hostinger — serve concurrently and
     * are unaffected. Without this note the symptom reads as a bug in the
     * tester rather than a limit of the dev server.
     *
     * This only ever applies to a call that was actually issued and came back
     * with nothing. A request rejected before it left — a path outside /api/,
     * a body that is not JSON, an unusable token — already carries the real
     * reason, and that reason must survive: telling an operator their typo is
     * a web-server limitation would send them hunting the wrong problem.
     *
     * @param  array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function explainLoopbackFailure(array $result): array
    {
        $wasIssued = ($result['url'] ?? '') !== '';

        $isDeadlock = $wasIssued
            && ($result['ok'] ?? false) !== true
            && (int) ($result['status'] ?? 0) === 0
            && PHP_SAPI === 'cli-server';

        if (!$isDeadlock) {
            return $result;
        }

        $transportError = trim((string) ($result['error'] ?? ''));

        $result['error'] = ($transportError !== '' ? $transportError . ' ' : '')
            . 'The built-in PHP development server handles one request at a time, so it '
            . 'cannot answer a call it makes to itself. Run the panel behind Apache '
            . '(or on the live host) and this request will succeed.';

        $result['hint'] = 'dev-server-single-threaded';

        return $result;
    }

    /**
     * @param array<string,mixed>|null $result
     * @param array<string,string>     $submitted
     */
    private function renderTester(Request $request, ?array $result, array $submitted): never
    {
        $this->view($request, 'admin.developer.tester', [
            'pageTitle'    => 'API tester',
            'pageSubtitle' => 'Call the REST API from the server, with the panel as the client.',
            'methods'      => self::TESTER_METHODS,
            'baseUrl'      => self::baseUrl(),
            'transport'    => extension_loaded('curl') ? 'cURL' : 'stream wrapper',
            // PHP's built-in server handles one request at a time on Windows,
            // so a request the panel makes to itself cannot be served until the
            // panel's own request finishes. Warn rather than let it look broken.
            'selfBlocking' => PHP_SAPI === 'cli-server',
            'result'       => $result,
            'submitted'    => $submitted,
            'examples'     => [
                ['method' => 'GET', 'path' => '/api/health',                'label' => 'Health check (unversioned)'],
                ['method' => 'GET', 'path' => '/api/v1/health',             'label' => 'Health check (v1)'],
                ['method' => 'GET', 'path' => '/api/v1/settings',           'label' => 'Public settings'],
                ['method' => 'GET', 'path' => '/api/v1/categories',         'label' => 'Categories'],
                ['method' => 'GET', 'path' => '/api/v1/creators?per_page=5', 'label' => 'Creator listing'],
            ],
        ]);
    }

    /**
     * Reject anything that is not a relative path inside /api/.
     *
     * The whitelist has no ":" and no "@", so an absolute URL, a scheme and a
     * userinfo section are all impossible; "//host/x" is impossible because the
     * value has to start with "/api/".
     */
    private static function validateTesterPath(string $path): ?string
    {
        if ($path === '') {
            return 'Enter a path to call.';
        }

        if (!str_starts_with($path, '/api/')) {
            return 'Only paths under /api/ can be called from here.';
        }

        if (str_contains($path, '..') || str_contains($path, '\\')) {
            return 'The path may not contain ".." or a backslash.';
        }

        // /D anchors "$" to the very end of the string. Without it a trailing
        // newline is accepted, which is exactly the character a request-splitting
        // attempt needs.
        if (preg_match('#^/api/[A-Za-z0-9\-._~/]*(\?[A-Za-z0-9\-._~%=&+,;/*]*)?$#D', $path) !== 1) {
            return 'The path contains characters that are not allowed. Use letters, digits, - . _ ~ / and a simple query string.';
        }

        return null;
    }

    /**
     * The one host the tester may ever reach: whatever `APP_URL` names.
     *
     * There is deliberately no fallback to `$_SERVER['HTTP_HOST']`. That value
     * is the client's own `Host` header, so falling back to it would let anyone
     * who can reach this action choose the destination — and the bearer token
     * typed into the form would be delivered to that destination. The
     * configured URL is the only source that an HTTP request cannot influence.
     *
     * Returns an empty string when nothing usable is configured; the caller
     * refuses rather than guessing.
     */
    private static function baseUrl(): string
    {
        $configured = rtrim((string) Config::get('app.url', ''), '/');

        // Scheme, hostname, optional port, optional base path. No userinfo
        // ("@"), no whitespace, no query and no fragment.
        if ($configured === ''
            || preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~\-/]*)?$#Di', $configured) !== 1) {
            return '';
        }

        return $configured;
    }

    /**
     * @return array{ok:bool,status:int,duration_ms:float,headers:list<string>,body:string,
     *               pretty:string,content_type:string,size:int,url:string,transport:string,error:string,truncated:bool}
     */
    private static function failure(string $message): array
    {
        return [
            'ok'           => false,
            'status'       => 0,
            'duration_ms'  => 0.0,
            'headers'      => [],
            'body'         => '',
            'pretty'       => '',
            'content_type' => '',
            'size'         => 0,
            'url'          => '',
            'transport'    => '',
            'error'        => $message,
            'truncated'    => false,
        ];
    }

    /**
     * Perform the call with cURL when it is available, otherwise with the HTTP
     * stream wrapper.
     *
     * @return array<string,mixed>
     */
    private function issueRequest(string $method, string $path, string $body, string $token): array
    {
        $url = self::baseUrl() . $path;

        $headers = [
            'Accept: application/json',
            'User-Agent: Zuste-Admin-API-Tester/' . (string) Config::get('app.version', '1.0.0'),
        ];

        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
        }

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $started = microtime(true);

        $outcome = extension_loaded('curl')
            ? self::sendWithCurl($url, $method, $headers, $body)
            : self::sendWithStream($url, $method, $headers, $body);

        $duration = round((microtime(true) - $started) * 1000, 1);

        $raw       = $outcome['body'];
        $truncated = mb_strlen($raw) > self::MAX_RESPONSE_CHARS;

        if ($truncated) {
            $raw = mb_substr($raw, 0, self::MAX_RESPONSE_CHARS);
        }

        $pretty  = '';
        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_scalar($decoded))) {
            $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $pretty  = is_string($encoded) ? $encoded : '';
        }

        $contentType = '';
        foreach ($outcome['headers'] as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $contentType = trim(substr($header, 13));
                break;
            }
        }

        return [
            'ok'           => $outcome['error'] === '' && $outcome['status'] > 0,
            'status'       => $outcome['status'],
            'duration_ms'  => $duration,
            'headers'      => $outcome['headers'],
            'body'         => $raw,
            'pretty'       => $pretty,
            'content_type' => $contentType,
            'size'         => $outcome['size'],
            'url'          => $url,
            'transport'    => $outcome['transport'],
            'error'        => $outcome['error'],
            'truncated'    => $truncated,
        ];
    }

    /**
     * @param  list<string> $headers
     * @return array{status:int,headers:list<string>,body:string,size:int,error:string,transport:string}
     */
    private static function sendWithCurl(string $url, string $method, array $headers, string $body): array
    {
        $collected = [];
        $handle    = curl_init();

        if ($handle === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'size' => 0, 'transport' => 'cURL', 'error' => 'cURL could not be initialised.'];
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            // Never follow a redirect: the destination must stay fixed.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$collected): int {
                $trimmed = trim($line);

                if ($trimmed !== '') {
                    $collected[] = $trimmed;
                }

                return strlen($line);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($handle);

        curl_close($handle);

        $content = is_string($response) ? $response : '';

        return [
            'status'    => $status,
            'headers'   => $collected,
            'body'      => $content,
            'size'      => strlen($content),
            'transport' => 'cURL',
            'error'     => $error,
        ];
    }

    /**
     * @param  list<string> $headers
     * @return array{status:int,headers:list<string>,body:string,size:int,error:string,transport:string}
     */
    private static function sendWithStream(string $url, string $method, array $headers, string $body): array
    {
        if (!filter_var((string) ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return [
                'status' => 0, 'headers' => [], 'body' => '', 'size' => 0, 'transport' => 'stream wrapper',
                'error'  => 'Neither the cURL extension nor allow_url_fopen is available, so the server cannot issue the request.',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'           => $method,
                'header'           => implode("\r\n", $headers),
                'content'          => $body,
                'timeout'          => 10,
                'follow_location'  => 0,
                'max_redirects'    => 1,
                'ignore_errors'    => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseHeaders = [];
        $content         = @file_get_contents($url, false, $context);

        if (isset($http_response_header) && is_array($http_response_header)) {
            $responseHeaders = $http_response_header;
        }

        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        if ($content === false) {
            $last = error_get_last();

            return [
                'status'    => $status,
                'headers'   => $responseHeaders,
                'body'      => '',
                'size'      => 0,
                'transport' => 'stream wrapper',
                'error'     => $last['message'] ?? 'The request could not be completed.',
            ];
        }

        return [
            'status'    => $status,
            'headers'   => $responseHeaders,
            'body'      => $content,
            'size'      => strlen($content),
            'transport' => 'stream wrapper',
            'error'     => $status === 0 ? 'No HTTP response was received.' : '',
        ];
    }
}
