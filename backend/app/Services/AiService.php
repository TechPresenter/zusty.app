<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;

/**
 * AI features for the apps: which ones are on, and running one.
 *
 * Every request goes through run(), which applies the same gates in the same
 * order — master switch, feature switch, a configured provider, the monthly
 * budget, the caller's daily allowance — then renders the admin-editable
 * prompt template, calls the provider and writes one `ai_logs` row whatever
 * happens.
 *
 * The provider key never leaves the server. The apps call the /ai endpoints and
 * only ever learn whether a feature is available.
 */
final class AiService
{
    /**
     * Feature => the Settings -> AI switch that controls it and the prompt
     * template it runs. Which account type may use it is enforced by the route.
     */
    public const FEATURES = [
        'bio_writer'     => ['setting' => 'feature_bio_writer',     'prompt' => 'bio_writer'],
        'campaign_brief' => ['setting' => 'feature_campaign_brief', 'prompt' => 'campaign_brief'],
        'creator_match'  => ['setting' => 'feature_creator_match',  'prompt' => 'creator_match'],
    ];

    /** True when AI is switched on and a provider can actually be called. */
    public static function available(): bool
    {
        return Config::settingBool('ai', 'enabled', false) && AiProviderService::active() !== null;
    }

    /**
     * The `ai` block of /app/config: which features the app should offer.
     * It says nothing about providers, models or keys.
     *
     * @return array{enabled:bool,bio_writer:bool,campaign_brief:bool,creator_match:bool,daily_limit:int}
     */
    public static function appFeatures(): array
    {
        $available = self::available();
        $active    = $available ? self::activePromptCodes() : [];
        $flags     = ['enabled' => $available];

        foreach (self::FEATURES as $feature => $meta) {
            $flags[$feature] = $available
                && Config::settingBool('ai', $meta['setting'], true)
                && in_array($meta['prompt'], $active, true);
        }

        $flags['daily_limit'] = self::dailyLimit();

        return $flags;
    }

    /**
     * Run a feature for a user.
     *
     * Rules the calling code depends on — length, output format — go in
     * `$outputRule`, which is appended to the template rather than living in
     * it, so an admin editing the prompt cannot break what the app receives.
     *
     * `$parse` turns the reply into the value the caller needs and returns
     * null when the reply is unusable; such a call is logged as failed and
     * reported to the user as one.
     *
     * @param array<string,string|int|float|null> $vars Values for the template's {{placeholders}}.
     * @param (callable(string):mixed)|null       $parse
     * @return array{text:string,parsed:mixed,remaining_today:int|null}
     */
    public static function run(
        string $feature,
        array $vars,
        int $userId,
        string $outputRule = '',
        bool $json = false,
        ?callable $parse = null
    ): array {
        $meta = self::FEATURES[$feature] ?? throw new \InvalidArgumentException('Unknown AI feature: ' . $feature);

        if (!Config::settingBool('ai', 'enabled', false) || !Config::settingBool('ai', $meta['setting'], true)) {
            throw new HttpException('This AI feature is switched off.', 403, 'ai_disabled');
        }

        $prompt   = Database::selectOne(
            'SELECT * FROM `ai_prompts` WHERE `code` = :code AND `is_active` = 1',
            ['code' => $meta['prompt']]
        );
        $provider = AiProviderService::active();

        if ($prompt === null || $provider === null) {
            throw new HttpException('AI is not set up yet. Please try again later.', 403, 'ai_unavailable');
        }

        $budget = Config::settingFloat('ai', 'monthly_budget', 0);

        if (($budget > 0 && self::monthSpend() >= $budget) || AiProviderService::budgetExhausted($provider)) {
            throw new HttpException('AI is unavailable for the rest of this month.', 403, 'ai_budget_reached');
        }

        $limit = self::dailyLimit();
        $used  = self::usedToday($userId);

        if ($limit > 0 && $used >= $limit) {
            throw HttpException::tooManyRequests(
                'You have used all ' . $limit . ' AI requests for today. Try again tomorrow.',
                max(60, strtotime('tomorrow') - time())
            );
        }

        $model = trim((string) ($prompt['model'] ?? '')) ?: trim((string) ($provider['default_model'] ?? ''));
        $user  = self::render((string) $prompt['user_prompt'], $vars);

        if ($outputRule !== '') {
            $user .= "\n\n" . $outputRule;
        }

        $call = AiProviderService::complete(
            $provider,
            $model,
            self::render((string) $prompt['system_prompt'], $vars),
            $user,
            [
                'temperature' => $prompt['temperature'] === null ? null : (float) $prompt['temperature'],
                'max_tokens'  => (int) ($prompt['max_tokens'] ?: 1000),
                'json'        => $json,
            ]
        );

        $parsed = null;

        if ($call['ok'] && $parse !== null) {
            $parsed = $parse($call['text']);

            if ($parsed === null) {
                $call = ['ok' => false, 'error' => 'The reply could not be used: ' . mb_substr($call['text'], 0, 200)] + $call;
            }
        }

        self::log($prompt, $provider, $call, $userId);

        if (!$call['ok']) {
            throw new HttpException('The AI could not answer right now. Please try again.', 502, 'ai_failed');
        }

        return [
            'text'            => $call['text'],
            'parsed'          => $parsed,
            'remaining_today' => $limit > 0 ? max(0, $limit - $used - 1) : null,
        ];
    }

    /** Requests one account may make per day across every feature; 0 means no limit. */
    public static function dailyLimit(): int
    {
        return max(0, Config::settingInt('ai', 'daily_limit_per_user', 20));
    }

    /**
     * Today's requests by one account that count against the allowance: those
     * that produced output. A provider outage does not eat into anyone's day.
     */
    public static function usedToday(int $userId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM `ai_logs`
              WHERE `requested_by_type` = 'user' AND `requested_by_id` = :user
                AND `created_at` >= :today
                AND (`status` = 'success' OR `output_tokens` > 0)",
            ['user' => $userId, 'today' => date('Y-m-d 00:00:00')]
        );
    }

    /** Spend across every provider so far this calendar month, in USD. */
    public static function monthSpend(): float
    {
        return (float) Database::scalar(
            'SELECT COALESCE(SUM(`cost`), 0) FROM `ai_logs` WHERE `created_at` >= :since',
            ['since' => date('Y-m-01 00:00:00')]
        );
    }

    /**
     * Tidy model output for a plain-text field: drop wrapping quotes and the
     * Markdown markers models add even when asked not to, then cut at a
     * sentence end so the text fits `$maxChars`.
     */
    public static function plainText(string $text, int $maxChars): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = (string) preg_replace('/^#{1,6}\s*/m', '', $text);
        $text = str_replace(['**', '__'], '', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text, " \n\t\"'“”");

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $cut  = mb_substr($text, 0, $maxChars);
        $stop = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, ".\n"), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));

        return $stop > $maxChars * 0.5 ? mb_substr($cut, 0, $stop + 1) : rtrim($cut) . '…';
    }

    /**
     * Fill {{placeholders}}. An unknown or empty value becomes an empty string;
     * callers pass "not specified" themselves where the model needs to know.
     *
     * @param array<string,string|int|float|null> $vars
     */
    private static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static fn (array $m): string => trim((string) ($vars[$m[1]] ?? '')),
            $template
        );
    }

    /** @return list<string> */
    private static function activePromptCodes(): array
    {
        $codes = array_column(self::FEATURES, 'prompt');

        return array_map('strval', Database::column(
            'SELECT `code` FROM `ai_prompts` WHERE `is_active` = 1 AND `code` IN ("'
                . implode('","', $codes) . '")'
        ));
    }

    /**
     * One row per call, successful or not. Recording must never be the reason
     * a user's request fails, so a write error is logged and swallowed.
     *
     * @param array<string,mixed> $call
     */
    private static function log(array $prompt, array $provider, array $call, int $userId): void
    {
        $status = match (true) {
            (bool) $call['ok']        => 'success',
            (bool) $call['filtered']  => 'filtered',
            $call['status'] === 429   => 'rate_limited',
            default                   => 'failed',
        };

        try {
            Database::insert('ai_logs', [
                'prompt_id'         => (int) $prompt['id'],
                'prompt_code'       => (string) $prompt['code'],
                'provider'          => (string) $provider['code'],
                'provider_id'       => (int) $provider['id'],
                'model'             => mb_substr((string) $call['model'], 0, 80),
                'input_tokens'      => (int) $call['input_tokens'],
                'output_tokens'     => (int) $call['output_tokens'],
                'cost'              => AiProviderService::cost(
                    (int) $provider['id'],
                    (string) $call['model'],
                    (int) $call['input_tokens'],
                    (int) $call['output_tokens']
                ),
                'duration_ms'       => (int) $call['duration_ms'],
                'status'            => $status,
                'error_message'     => $call['ok'] ? null : mb_substr((string) $call['error'], 0, 500),
                'requested_by_type' => 'user',
                'requested_by_id'   => $userId,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            Database::update(
                'ai_providers',
                ['last_used_at' => date('Y-m-d H:i:s')] + ($call['ok'] ? [] : ['last_error' => mb_substr((string) $call['error'], 0, 500)]),
                ['id' => (int) $provider['id']]
            );
        } catch (\Throwable $e) {
            Logger::error('Could not record an AI call', ['error' => $e->getMessage(), 'prompt' => $prompt['code'] ?? null]);
        }
    }
}
