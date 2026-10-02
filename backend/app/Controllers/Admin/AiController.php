<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Helpers\Money;
use Zuste\Services\AiProviderService;
use Zuste\Services\AiService;

/**
 * AI usage.
 *
 * The prompt library itself is the generic CMS resource at /admin/ai-prompts,
 * and the provider, key, model and the app's AI switches live in
 * Settings -> AI. This controller covers what the prompts cost and what they
 * returned, with a read-only summary of that setup.
 */
final class AiController extends BaseAdminController
{
    private const LOG_SORTABLE = ['created_at', 'cost', 'duration_ms', 'input_tokens', 'output_tokens', 'status'];

    private const STATUSES = ['success', 'failed', 'rate_limited', 'filtered'];

    /** How many days the summary and the chart cover. */
    private const WINDOW_DAYS = 30;

    // =================================================================
    // Overview
    // =================================================================

    /** GET /admin/ai */
    public function index(Request $request): never
    {
        $this->authorize($request, 'ai.view');

        $since = date('Y-m-d 00:00:00', strtotime('-' . (self::WINDOW_DAYS - 1) . ' days'));

        $prompts = Database::select(
            'SELECT p.`id`, p.`code`, p.`name`, p.`purpose`, p.`model`, p.`is_active`, p.`version`,
                    p.`temperature`, p.`max_tokens`, p.`updated_at`,
                    COALESCE(l.`calls`, 0)  AS `calls`,
                    COALESCE(l.`spend`, 0)  AS `spend`,
                    COALESCE(l.`failures`, 0) AS `failures`,
                    l.`last_used_at`
               FROM `ai_prompts` p
               LEFT JOIN (
                     SELECT `prompt_code`,
                            COUNT(*)                        AS `calls`,
                            SUM(`cost`)                     AS `spend`,
                            SUM(`status` <> \'success\')     AS `failures`,
                            MAX(`created_at`)               AS `last_used_at`
                       FROM `ai_logs`
                      WHERE `created_at` >= :since
                      GROUP BY `prompt_code`
                   ) l ON l.`prompt_code` = p.`code`
              ORDER BY p.`is_active` DESC, p.`code` ASC',
            ['since' => $since]
        );

        $monthStart = date('Y-m-01 00:00:00');

        $monthSpend = (float) Database::scalar(
            'SELECT COALESCE(SUM(`cost`), 0) FROM `ai_logs` WHERE `created_at` >= :since',
            ['since' => $monthStart]
        );

        $windowSummary = Database::selectOne(
            "SELECT COUNT(*)                       AS `calls`,
                    SUM(`status` = 'success')      AS `successes`,
                    COALESCE(SUM(`cost`), 0)       AS `spend`,
                    COALESCE(SUM(`input_tokens`), 0)  AS `tokens_in`,
                    COALESCE(SUM(`output_tokens`), 0) AS `tokens_out`
               FROM `ai_logs`
              WHERE `created_at` >= :since",
            ['since' => $since]
        ) ?? [];

        $recentFailures = Database::select(
            "SELECT `id`, `prompt_code`, `provider`, `model`, `status`, `error_message`, `created_at`
               FROM `ai_logs`
              WHERE `status` <> 'success'
              ORDER BY `created_at` DESC
              LIMIT 8"
        );

        $budget = (float) Config::settingString('ai', 'monthly_budget', '0');
        $active = AiProviderService::active();

        $this->view($request, 'admin.ai.index', [
            'pageTitle'      => 'AI',
            'pageSubtitle'   => 'Prompt usage, spend and the setup behind them.',
            'prompts'        => $prompts,
            // A read-only summary. Changing any of it happens in Settings -> AI.
            'setup'          => [
                'enabled'  => Config::settingBool('ai', 'enabled', false),
                'provider' => $active === null ? null : (string) $active['name'],
                'model'    => $active === null ? '' : (string) ($active['default_model'] ?? ''),
                'verified' => $active !== null && $active['verified_at'] !== null,
                'features' => AiService::appFeatures(),
                'limit'    => AiService::dailyLimit(),
            ],
            'canManage'      => $this->canManage($request),
            'monthSpend'     => $monthSpend,
            'budget'         => $budget,
            'windowSummary'  => $windowSummary,
            'windowDays'     => self::WINDOW_DAYS,
            'recentFailures' => $recentFailures,
            'promptCount'    => count($prompts),
        ]);
    }

    // =================================================================
    // Logs
    // =================================================================

    /** GET /admin/ai/logs */
    public function logs(Request $request): never
    {
        $this->authorize($request, 'ai.view');

        $params = $this->listParams($request, self::LOG_SORTABLE, 'created_at');

        $status     = $request->string('status');
        $provider   = trim($request->string('provider'));
        $promptCode = trim($request->string('prompt_code'));

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $clauses[] = '`status` = :status';
            $bindings['status'] = $status;
        }

        if ($provider !== '') {
            $clauses[] = '`provider` = :provider';
            $bindings['provider'] = $provider;
        }

        if ($promptCode !== '') {
            $clauses[] = '`prompt_code` = :prompt_code';
            $bindings['prompt_code'] = $promptCode;
        }

        if ($params['search'] !== '') {
            $clauses[] = '(`error_message` LIKE :search OR `model` LIKE :search OR `prompt_code` LIKE :search)';
            $bindings['search'] = '%' . $params['search'] . '%';
        }

        $this->applyDateRange($clauses, $bindings, '`created_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                "SELECT `created_at`, `prompt_code`, `provider`, `model`, `status`,
                        `input_tokens`, `output_tokens`, `cost`, `duration_ms`,
                        `requested_by_type`, `requested_by_id`, `error_message`
                   FROM `ai_logs`
                  WHERE $where
                  ORDER BY `created_at` DESC
                  LIMIT 20000",
                $bindings
            );

            $this->exportCsv(
                'ai-logs',
                ['When', 'Prompt', 'Provider', 'Model', 'Status', 'Input tokens', 'Output tokens',
                 'Cost (USD)', 'Duration (ms)', 'Requested by', 'Requester id', 'Error'],
                $all,
                ['created_at', 'prompt_code', 'provider', 'model', 'status', 'input_tokens', 'output_tokens',
                 'cost', 'duration_ms', 'requested_by_type', 'requested_by_id', 'error_message']
            );
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM `ai_logs` WHERE $where", $bindings);

        $rows = Database::select(
            "SELECT * FROM `ai_logs`
              WHERE $where
              ORDER BY `{$params['sort_by']}` {$params['sort_dir']}
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        $since = date('Y-m-d 00:00:00', strtotime('-' . (self::WINDOW_DAYS - 1) . ' days'));

        $summary = Database::selectOne(
            "SELECT COUNT(*)                          AS `calls`,
                    SUM(`status` = 'success')         AS `successes`,
                    COALESCE(SUM(`cost`), 0)          AS `spend`,
                    COALESCE(SUM(`input_tokens`), 0)  AS `tokens_in`,
                    COALESCE(SUM(`output_tokens`), 0) AS `tokens_out`,
                    COALESCE(AVG(`duration_ms`), 0)   AS `avg_duration`
               FROM `ai_logs`
              WHERE `created_at` >= :since",
            ['since' => $since]
        ) ?? [];

        $byModel = Database::select(
            "SELECT COALESCE(NULLIF(`model`, ''), '(unnamed)') AS `model`,
                    `provider`,
                    COUNT(*)                          AS `calls`,
                    COALESCE(SUM(`cost`), 0)          AS `spend`,
                    COALESCE(SUM(`input_tokens`), 0)  AS `tokens_in`,
                    COALESCE(SUM(`output_tokens`), 0) AS `tokens_out`,
                    COALESCE(AVG(`duration_ms`), 0)   AS `avg_duration`
               FROM `ai_logs`
              WHERE `created_at` >= :since
              GROUP BY `provider`, COALESCE(NULLIF(`model`, ''), '(unnamed)')
              ORDER BY `spend` DESC
              LIMIT 12",
            ['since' => $since]
        );

        $this->view($request, 'admin.ai.logs', [
            'pageTitle'    => 'AI logs',
            'pageSubtitle' => 'Every model call, what it cost and how long it took.',
            'rows'         => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'summary'      => $summary,
            'byModel'      => $byModel,
            'daily'        => $this->dailySpend($since),
            'windowDays'   => self::WINDOW_DAYS,
            'providers'    => $this->distinctProviders(),
            'promptCodes'  => $this->distinctPromptCodes(),
            'filters'      => [
                'q'           => $params['search'],
                'status'      => $status,
                'provider'    => $provider,
                'prompt_code' => $promptCode,
                'from'        => $params['from'],
                'to'          => $params['to'],
                'sort_by'     => $params['sort_by'],
                'sort_dir'    => $params['sort_dir'],
            ],
        ]);
    }

    // =================================================================
    // Internals
    // =================================================================

    /**
     * Daily spend for the chart, gap-filled so every day has a bar.
     *
     * @return list<array{date:string,spend:float,calls:int}>
     */
    private function dailySpend(string $since): array
    {
        $rows = Database::select(
            'SELECT DATE(`created_at`) AS `day`,
                    COALESCE(SUM(`cost`), 0) AS `spend`,
                    COUNT(*) AS `calls`
               FROM `ai_logs`
              WHERE `created_at` >= :since
              GROUP BY DATE(`created_at`)',
            ['since' => $since]
        );

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row['day']] = [
                'spend' => (float) $row['spend'],
                'calls' => (int) $row['calls'],
            ];
        }

        $series = [];
        $cursor = strtotime(substr($since, 0, 10));

        for ($day = 0; $day < self::WINDOW_DAYS; $day++) {
            $date = date('Y-m-d', strtotime('+' . $day . ' days', $cursor));

            $series[] = [
                'date'  => $date,
                'spend' => $indexed[$date]['spend'] ?? 0.0,
                'calls' => $indexed[$date]['calls'] ?? 0,
            ];
        }

        return $series;
    }

    /** @return list<string> */
    private function distinctProviders(): array
    {
        return array_map(
            'strval',
            Database::column("SELECT DISTINCT `provider` FROM `ai_logs` WHERE `provider` <> '' ORDER BY `provider` LIMIT 50")
        );
    }

    /** @return list<string> */
    private function distinctPromptCodes(): array
    {
        return array_map(
            'strval',
            Database::column(
                "SELECT DISTINCT `prompt_code` FROM `ai_logs`
                  WHERE `prompt_code` IS NOT NULL AND `prompt_code` <> ''
                  ORDER BY `prompt_code` LIMIT 100"
            )
        );
    }

    private function canManage(Request $request): bool
    {
        return \Zuste\Services\PermissionService::adminCan($this->adminId($request), 'ai.manage');
    }

    /**
     * Model spend is always USD: providers publish their prices in dollars and
     * AiProviderService::cost() stores them unconverted, so the default
     * currency here is USD rather than the platform currency.
     *
     * Aggregates — the KPI tiles, the per-prompt and per-model totals — render
     * to two decimals so the headline figure stays short and tables keep their
     * decimal points aligned. Precision is opt-in with $precise, for the
     * per-call figures and chart tooltips that exist to show the exact number.
     *
     * Cost is decimal(10,6), so an aggregate can be nonzero yet below a cent.
     * Those keep all six decimals as well, because "$0.00" on a row that did
     * spend money is wrong, not merely rounded.
     *
     * Exactly zero is the one case where extra precision says nothing, and
     * "$0.000000" on an empty dashboard reads like a broken number, so nothing
     * spent is rendered as plain "$0".
     */
    public static function formatCost(float $cost, bool $precise = false, ?string $currency = 'USD'): string
    {
        $decimals = match (true) {
            $cost === 0.0     => 0,
            $precise          => 6,
            abs($cost) < 0.01 => 6,
            default           => 2,
        };

        return Money::symbol($currency) . Money::groupIndian($cost, $decimals);
    }
}
