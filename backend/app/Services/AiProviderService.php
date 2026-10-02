<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Core\Logger;

/**
 * The AI provider catalogue: credentials, model pricing, connection tests and
 * spend accounting.
 *
 * Keys are encrypted at rest and never leave this class in plaintext except on
 * the outbound request that uses them. Nothing here enables a provider or
 * spends money on its own — an administrator has to add a key and switch the
 * provider on first.
 */
final class AiProviderService
{
    /**
     * How each adapter is addressed. Kept as data so adding a provider that
     * speaks an existing dialect is a row in the database, not a code change.
     *
     * @var array<string,array{label:string,auth:string,chat_path:string,doc:string}>
     */
    public const ADAPTERS = [
        'anthropic' => [
            'label'     => 'Anthropic (Claude)',
            'auth'      => 'x-api-key',
            'chat_path' => '/messages',
            'doc'       => 'Sends x-api-key and a pinned anthropic-version header.',
        ],
        'openai' => [
            'label'     => 'OpenAI',
            'auth'      => 'bearer',
            'chat_path' => '/chat/completions',
            'doc'       => 'Bearer token; Chat Completions wire format.',
        ],
        'google' => [
            'label'     => 'Google Gemini',
            'auth'      => 'query_key',
            'chat_path' => '/models/{model}:generateContent',
            'doc'       => 'API key on the query string; generateContent wire format.',
        ],
        'azure_openai' => [
            'label'     => 'Azure OpenAI',
            'auth'      => 'api-key',
            'chat_path' => '/openai/deployments/{model}/chat/completions',
            'doc'       => 'api-key header. Base URL is your resource endpoint; the model is the deployment name.',
        ],
        'openai_compatible' => [
            'label'     => 'OpenAI-compatible',
            'auth'      => 'bearer',
            'chat_path' => '/chat/completions',
            'doc'       => 'Any endpoint that speaks the Chat Completions format (Mistral, Groq, OpenRouter, local runtimes).',
        ],
    ];

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Every provider with its model count and this month's spend.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(bool $includeDeleted = false): array
    {
        $where = $includeDeleted ? '' : ' WHERE p.`deleted_at` IS NULL';

        return Database::select(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM `ai_provider_models` m
                      WHERE m.`provider_id` = p.`id` AND m.`is_active` = 1) AS `model_count`,
                    (SELECT COALESCE(SUM(l.`cost`), 0) FROM `ai_logs` l
                      WHERE l.`provider_id` = p.`id`
                        AND l.`created_at` >= DATE_FORMAT(NOW(), "%Y-%m-01")) AS `spend_this_month`,
                    (SELECT COUNT(*) FROM `ai_logs` l
                      WHERE l.`provider_id` = p.`id`
                        AND l.`created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS `calls_30d`
               FROM `ai_providers` p' . $where . '
              ORDER BY p.`is_enabled` DESC, p.`priority` ASC, p.`name` ASC'
        );
    }

    /** One provider by id, or null. */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `ai_providers` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $id]
        );
    }

    /** One provider by its stable code, or null. */
    public static function findByCode(string $code): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `ai_providers` WHERE `code` = :code AND `deleted_at` IS NULL',
            ['code' => $code]
        );
    }

    /**
     * The provider a call should use: the default when it is usable, otherwise
     * the highest-priority enabled provider that has a key.
     *
     * Returns null when nothing is configured — callers must treat that as
     * "AI is off", not as an error.
     */
    public static function active(): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM `ai_providers`
              WHERE `deleted_at` IS NULL AND `is_enabled` = 1
                AND `api_key` IS NOT NULL AND `api_key` <> \'\'
              ORDER BY `is_default` DESC, `priority` ASC
              LIMIT 1'
        );

        return $row ?: null;
    }

    /**
     * Models for a provider.
     *
     * @return list<array<string,mixed>>
     */
    public static function models(int $providerId, bool $activeOnly = true): array
    {
        return Database::select(
            'SELECT * FROM `ai_provider_models`
              WHERE `provider_id` = :id' . ($activeOnly ? ' AND `is_active` = 1' : '') . '
              ORDER BY `sort_order` ASC, `label` ASC',
            ['id' => $providerId]
        );
    }

    /** Decrypt a provider's key. Never render the result to a page. */
    public static function apiKey(array $provider): ?string
    {
        $stored = $provider['api_key'] ?? null;

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypto::decrypt($stored);
        } catch (\Throwable $e) {
            Logger::error('Could not decrypt an AI provider key', [
                'provider_id' => $provider['id'] ?? null,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Create a provider. `$apiKey` is encrypted here; pass plaintext.
     */
    public static function create(array $data, ?string $apiKey, int $adminId): int
    {
        $payload = self::normalise($data) + [
            'api_key'    => ($apiKey ?? '') !== '' ? Crypto::encrypt((string) $apiKey) : null,
            'created_by' => $adminId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        return Database::transaction(static function () use ($payload): int {
            $id = Database::insert('ai_providers', $payload);

            if ((int) ($payload['is_default'] ?? 0) === 1) {
                self::clearOtherDefaults($id);
            }

            return $id;
        });
    }

    /**
     * Update a provider. A null `$apiKey` leaves the stored key untouched — the
     * form never round-trips the secret, so "unchanged" has to be expressible.
     */
    public static function update(int $id, array $data, ?string $apiKey): int
    {
        $payload = self::normalise($data) + ['updated_at' => date('Y-m-d H:i:s')];

        if ($apiKey !== null) {
            // An explicit empty string clears the key; a value replaces it.
            $payload['api_key']     = $apiKey === '' ? null : Crypto::encrypt($apiKey);
            $payload['verified_at'] = null;
            $payload['last_error']  = null;
        }

        return Database::transaction(static function () use ($id, $payload): int {
            $affected = Database::update('ai_providers', $payload, ['id' => $id]);

            if ((int) ($payload['is_default'] ?? 0) === 1) {
                self::clearOtherDefaults($id);
            }

            return $affected;
        });
    }

    /** Soft delete. A provider with logs is kept so cost history survives. */
    public static function delete(int $id): int
    {
        return Database::update(
            'ai_providers',
            ['deleted_at' => date('Y-m-d H:i:s'), 'is_enabled' => 0, 'is_default' => 0],
            ['id' => $id]
        );
    }

    /** Remove the stored key without touching anything else. */
    public static function clearKey(int $id): int
    {
        return Database::update(
            'ai_providers',
            ['api_key' => null, 'verified_at' => null, 'is_enabled' => 0, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
    }

    /** Promote one provider to default, demoting the rest. */
    public static function makeDefault(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            Database::update('ai_providers', ['is_default' => 1], ['id' => $id]);
            self::clearOtherDefaults($id);
        });
    }

    private static function clearOtherDefaults(int $keepId): void
    {
        Database::execute(
            'UPDATE `ai_providers` SET `is_default` = 0 WHERE `id` <> :id',
            ['id' => $keepId]
        );
    }

    /**
     * Whitelist the columns a form may write. Anything not listed here is
     * dropped rather than passed through to the table.
     */
    private static function normalise(array $data): array
    {
        $out = [];

        foreach ([
            'code', 'name', 'adapter', 'description', 'api_base_url',
            'organisation_id', 'api_version', 'default_model',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $value     = trim((string) $data[$key]);
                $out[$key] = $value === '' ? null : $value;
            }
        }

        // `code` and `name` are required by the schema, so never null them out.
        foreach (['code', 'name'] as $key) {
            if (array_key_exists($key, $out) && $out[$key] === null) {
                unset($out[$key]);
            }
        }

        foreach (['is_enabled', 'is_default'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = (int) ((bool) $data[$key]);
            }
        }

        foreach (['priority', 'timeout_seconds', 'max_retries', 'rate_limit_per_min'] as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) !== '') {
                $out[$key] = (int) $data[$key];
            }
        }

        if (array_key_exists('monthly_budget', $data)) {
            $budget = trim((string) $data['monthly_budget']);
            $out['monthly_budget'] = $budget === '' ? null : round((float) $budget, 2);
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Models
    // -----------------------------------------------------------------

    public static function saveModel(int $providerId, array $data, ?int $modelId = null): int
    {
        $payload = [
            'provider_id'          => $providerId,
            'model_code'           => trim((string) ($data['model_code'] ?? '')),
            'label'                => trim((string) ($data['label'] ?? '')),
            'input_cost_per_mtok'  => self::nullableDecimal($data['input_cost_per_mtok'] ?? null),
            'output_cost_per_mtok' => self::nullableDecimal($data['output_cost_per_mtok'] ?? null),
            'context_window'       => self::nullableInt($data['context_window'] ?? null),
            'max_output_tokens'    => self::nullableInt($data['max_output_tokens'] ?? null),
            'supports_vision'      => (int) ((bool) ($data['supports_vision'] ?? 0)),
            'supports_tools'       => (int) ((bool) ($data['supports_tools'] ?? 1)),
            'is_active'            => (int) ((bool) ($data['is_active'] ?? 1)),
            'sort_order'           => (int) ($data['sort_order'] ?? 0),
        ];

        if ($modelId !== null) {
            return Database::update('ai_provider_models', $payload, ['id' => $modelId, 'provider_id' => $providerId]);
        }

        return Database::insert('ai_provider_models', $payload);
    }

    public static function deleteModel(int $providerId, int $modelId): int
    {
        return Database::execute(
            'DELETE FROM `ai_provider_models` WHERE `id` = :id AND `provider_id` = :provider',
            ['id' => $modelId, 'provider' => $providerId]
        );
    }

    private static function nullableDecimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        return $value === '' ? null : round((float) $value, 4);
    }

    private static function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }

    // -----------------------------------------------------------------
    // Cost
    // -----------------------------------------------------------------

    /**
     * Cost of a call, in USD — the currency providers publish their prices in
     * and the currency ai_logs.cost stores. It is never converted, so every
     * AI spend surface renders it with a dollar symbol.
     *
     * Prices are stored per million tokens. Returns 0.0 when the model is not
     * in the catalogue or carries no price — an unpriced model must not
     * silently invent a number.
     */
    public static function cost(int $providerId, string $modelCode, int $inputTokens, int $outputTokens): float
    {
        $model = Database::selectOne(
            'SELECT `input_cost_per_mtok`, `output_cost_per_mtok`
               FROM `ai_provider_models`
              WHERE `provider_id` = :p AND `model_code` = :m',
            ['p' => $providerId, 'm' => $modelCode]
        );

        if ($model === null) {
            return 0.0;
        }

        $in  = $model['input_cost_per_mtok'] === null ? 0.0 : (float) $model['input_cost_per_mtok'];
        $out = $model['output_cost_per_mtok'] === null ? 0.0 : (float) $model['output_cost_per_mtok'];

        $usd = ($inputTokens / 1_000_000) * $in + ($outputTokens / 1_000_000) * $out;

        return round($usd, 6);
    }

    /** Spend against a provider so far this calendar month, in USD. */
    public static function monthlySpend(int $providerId): float
    {
        return (float) Database::scalar(
            'SELECT COALESCE(SUM(`cost`), 0) FROM `ai_logs`
              WHERE `provider_id` = :id AND `created_at` >= DATE_FORMAT(NOW(), "%Y-%m-01")',
            ['id' => $providerId]
        );
    }

    /** True when the provider has a budget and has reached it. */
    public static function budgetExhausted(array $provider): bool
    {
        $budget = $provider['monthly_budget'] ?? null;

        if ($budget === null || (float) $budget <= 0) {
            return false;
        }

        return self::monthlySpend((int) $provider['id']) >= (float) $budget;
    }

    // -----------------------------------------------------------------
    // Calls
    // -----------------------------------------------------------------

    /**
     * Send the smallest possible real request and report what came back.
     *
     * This costs a fraction of a cent: the reply is capped at 16 tokens. It is
     * a genuine credential check — a provider that reports success here can
     * actually be called — and it goes through complete(), so it exercises the
     * same request a real feature sends.
     *
     * @return array{ok:bool,status:int,duration_ms:float,message:string,model:string}
     */
    public static function test(array $provider, ?string $model = null): array
    {
        $model = trim((string) ($model ?: ($provider['default_model'] ?? '')));

        $fail = static fn (string $message): array => [
            'ok' => false, 'status' => 0, 'duration_ms' => 0.0, 'message' => $message, 'model' => $model,
        ];

        if (self::apiKey($provider) === null) {
            return $fail('No API key is stored for this provider.');
        }

        if ($model === '') {
            return $fail('Set a default model, or pick one to test with.');
        }

        if (!self::baseUrlAllowed(rtrim((string) ($provider['api_base_url'] ?? ''), '/'))) {
            return $fail('Set a valid https base URL for this provider first.');
        }

        $call    = self::complete($provider, $model, '', 'Hi', ['max_tokens' => 16, 'probe' => true]);
        $message = $call['ok'] ? 'Reached the provider and the credentials were accepted.' : $call['error'];

        self::recordTest((int) $provider['id'], $call['ok'], $call['ok'] ? '' : $message);

        return [
            'ok'          => $call['ok'],
            'status'      => $call['status'],
            'duration_ms' => (float) $call['duration_ms'],
            'message'     => $message,
            'model'       => $model,
        ];
    }

    /**
     * Run one prompt against a provider and return the reply.
     *
     * Never throws for a provider-side failure: the outcome, the token counts
     * and a one-line reason come back in the array, so the caller can log the
     * call either way. Network errors, 429s and 5xx responses are retried up to
     * the provider's `max_retries`.
     *
     * OpenAI-style models disagree about optional parameters — reasoning models
     * refuse `temperature`, older ones refuse `max_completion_tokens` — and new
     * models arrive faster than any list here could track. A 400 that names one
     * of those parameters is therefore retried once without it.
     *
     * @param array{temperature?:float|null,max_tokens?:int,json?:bool,probe?:bool} $options
     * @return array{ok:bool,text:string,input_tokens:int,output_tokens:int,status:int,duration_ms:int,error:string,filtered:bool,model:string}
     */
    public static function complete(array $provider, string $model, string $system, string $user, array $options = []): array
    {
        $result = [
            'ok' => false, 'text' => '', 'input_tokens' => 0, 'output_tokens' => 0, 'status' => 0,
            'duration_ms' => 0, 'error' => '', 'filtered' => false, 'model' => $model,
        ];

        $adapter = (string) ($provider['adapter'] ?? 'openai_compatible');
        $key     = self::apiKey($provider);
        $base    = rtrim((string) ($provider['api_base_url'] ?? ''), '/');

        if ($key === null) {
            return ['error' => 'No API key is stored for this provider.'] + $result;
        }

        if ($model === '') {
            return ['error' => 'No model is set for this provider.'] + $result;
        }

        if (!self::baseUrlAllowed($base)) {
            return ['error' => 'Set a valid https base URL for this provider first.'] + $result;
        }

        $reasoning = !in_array($adapter, ['anthropic', 'google'], true) && self::isReasoningModel($model);
        $maxTokens = max(1, (int) ($options['max_tokens'] ?? 1000));
        $probe     = (bool) ($options['probe'] ?? false);

        $params = [
            'temperature'      => $reasoning ? null : ($options['temperature'] ?? null),
            // Reasoning tokens come out of the same budget as the answer, so a
            // reasoning model gets headroom or it can spend all of it thinking.
            'max_tokens'       => $reasoning && !$probe ? min($maxTokens + 4000, 32000) : $maxTokens,
            'token_param'      => $adapter === 'openai' || ($adapter === 'azure_openai' && $reasoning)
                ? 'max_completion_tokens'
                : 'max_tokens',
            'reasoning_effort' => $reasoning ? 'low' : null,
            'json'             => (bool) ($options['json'] ?? false),
        ];

        $retries  = max(0, min(3, (int) ($provider['max_retries'] ?? 2)));
        $attempt  = 0;
        $adjusted = [];
        $started  = microtime(true);

        while (true) {
            [$url, $headers, $body] = self::buildRequest(
                $adapter, $base, $key, $model, (string) ($provider['api_version'] ?? ''), $system, $user, $params
            );

            [$status, $raw, $error] = self::send(
                $url,
                $headers,
                (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) ($provider['timeout_seconds'] ?? 60)
            );

            $rejected = $status === 400 ? self::rejectedParam($raw, $body) : null;

            if ($rejected !== null && !isset($adjusted[$rejected])) {
                $adjusted[$rejected] = true;
                $params              = self::withoutParam($params, $rejected);
                continue;
            }

            if (($error !== '' || $status === 429 || $status >= 500) && $attempt < $retries) {
                $attempt++;
                usleep(400_000 * $attempt);
                continue;
            }

            break;
        }

        $result['status']      = $status;
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        if ($error !== '') {
            return ['error' => $error] + $result;
        }

        if ($status < 200 || $status >= 300) {
            return ['error' => self::describeFailure($status, $raw)] + $result;
        }

        $parsed = self::parseResponse($adapter, $raw);

        $result['text']          = trim($parsed['text']);
        $result['input_tokens']  = $parsed['input_tokens'];
        $result['output_tokens'] = $parsed['output_tokens'];
        $result['filtered']      = $parsed['filtered'];

        // A probe only proves the provider accepted the request.
        if ($probe || $result['text'] !== '') {
            return ['ok' => true] + $result;
        }

        return ['error' => match (true) {
            $parsed['filtered']  => 'The provider declined to answer this request.',
            $parsed['truncated'] => 'The model used its whole token budget before answering. Raise max tokens on this prompt.',
            default              => 'The provider returned an empty reply.',
        }] + $result;
    }

    /**
     * Provider calls carry the API key, so they only go to https endpoints.
     * Outside production a plain-http loopback address is accepted too, which
     * is what lets the test suite point a provider at a local mock.
     */
    public static function baseUrlAllowed(string $base): bool
    {
        if (preg_match('#^https://[A-Za-z0-9.\-]+(:\d{1,5})?(/\S*)?$#', $base) === 1) {
            return true;
        }

        return Config::get('app.env') !== 'production'
            && preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?(/\S*)?$#', $base) === 1;
    }

    /**
     * OpenAI's reasoning families (o1, o3, o4, GPT-5 and later) refuse
     * `temperature` and think before answering. A model this misses is still
     * covered by the parameter retry in complete().
     */
    public static function isReasoningModel(string $model): bool
    {
        return preg_match('/^(o\d|gpt-([5-9]|\d{2}))/i', $model) === 1;
    }

    /**
     * @param  array{temperature:float|null,max_tokens:int,token_param:string,reasoning_effort:string|null,json:bool} $params
     * @return array{0:string,1:list<string>,2:array<string,mixed>}
     */
    private static function buildRequest(
        string $adapter,
        string $base,
        string $key,
        string $model,
        string $apiVersion,
        string $system,
        string $user,
        array $params
    ): array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($adapter === 'anthropic') {
            $body = [
                'model'      => $model,
                'max_tokens' => $params['max_tokens'],
                'messages'   => [['role' => 'user', 'content' => $user]],
            ];

            if ($system !== '') {
                $body['system'] = $system;
            }

            if ($params['temperature'] !== null) {
                $body['temperature'] = $params['temperature'];
            }

            return [
                $base . '/messages',
                array_merge($headers, [
                    'x-api-key: ' . $key,
                    'anthropic-version: ' . ($apiVersion !== '' ? $apiVersion : '2023-06-01'),
                ]),
                $body,
            ];
        }

        if ($adapter === 'google') {
            $config = ['maxOutputTokens' => $params['max_tokens']];

            if ($params['temperature'] !== null) {
                $config['temperature'] = $params['temperature'];
            }

            if ($params['json']) {
                $config['responseMimeType'] = 'application/json';
            }

            $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $user]]]], 'generationConfig' => $config];

            if ($system !== '') {
                $body['systemInstruction'] = ['parts' => [['text' => $system]]];
            }

            return [$base . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key), $headers, $body];
        }

        // OpenAI and everything that speaks its dialect.
        $messages   = $system !== '' ? [['role' => 'system', 'content' => $system]] : [];
        $messages[] = ['role' => 'user', 'content' => $user];

        $body = ['messages' => $messages, $params['token_param'] => $params['max_tokens']];

        if ($params['temperature'] !== null) {
            $body['temperature'] = $params['temperature'];
        }

        if ($params['reasoning_effort'] !== null) {
            $body['reasoning_effort'] = $params['reasoning_effort'];
        }

        if ($params['json']) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        if ($adapter === 'azure_openai') {
            return [
                $base . '/openai/deployments/' . rawurlencode($model) . '/chat/completions?api-version='
                    . rawurlencode($apiVersion !== '' ? $apiVersion : '2024-02-01'),
                array_merge($headers, ['api-key: ' . $key]),
                $body,
            ];
        }

        return [
            $base . '/chat/completions',
            array_merge($headers, ['Authorization: Bearer ' . $key]),
            ['model' => $model] + $body,
        ];
    }

    /**
     * The reply text, token usage and how the generation ended.
     *
     * @return array{text:string,input_tokens:int,output_tokens:int,truncated:bool,filtered:bool}
     */
    private static function parseResponse(string $adapter, string $raw): array
    {
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : [];

        if ($adapter === 'anthropic') {
            $text = '';

            foreach ((array) ($data['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }

            return [
                'text'          => $text,
                'input_tokens'  => (int) ($data['usage']['input_tokens'] ?? 0),
                'output_tokens' => (int) ($data['usage']['output_tokens'] ?? 0),
                'truncated'     => ($data['stop_reason'] ?? '') === 'max_tokens',
                'filtered'      => ($data['stop_reason'] ?? '') === 'refusal',
            ];
        }

        if ($adapter === 'google') {
            $candidate = (array) ($data['candidates'][0] ?? []);
            $text      = '';

            foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                if (is_array($part) && empty($part['thought'])) {
                    $text .= (string) ($part['text'] ?? '');
                }
            }

            $usage  = (array) ($data['usageMetadata'] ?? []);
            $finish = (string) ($candidate['finishReason'] ?? '');

            return [
                'text'          => $text,
                'input_tokens'  => (int) ($usage['promptTokenCount'] ?? 0),
                // Thinking is billed as output.
                'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
                'truncated'     => $finish === 'MAX_TOKENS',
                'filtered'      => in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true)
                    || isset($data['promptFeedback']['blockReason']),
            ];
        }

        $choice  = (array) ($data['choices'][0] ?? []);
        $content = $choice['message']['content'] ?? '';

        // Some compatible servers return content as a list of parts.
        if (is_array($content)) {
            $content = implode('', array_map(
                static fn ($part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part,
                $content
            ));
        }

        return [
            'text'          => (string) $content,
            'input_tokens'  => (int) ($data['usage']['prompt_tokens'] ?? 0),
            // Includes reasoning tokens, which are billed as output.
            'output_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
            'truncated'     => ($choice['finish_reason'] ?? '') === 'length',
            'filtered'      => ($choice['finish_reason'] ?? '') === 'content_filter'
                || !empty($choice['message']['refusal']),
        ];
    }

    /**
     * The optional parameter a 400 complains about — only if we sent it and
     * the request still makes sense without it.
     *
     * @param array<string,mixed> $body
     */
    private static function rejectedParam(string $raw, array $body): ?string
    {
        $error = json_decode($raw, true)['error'] ?? null;

        if (!is_array($error)) {
            return null;
        }

        $sent = array_values(array_intersect(
            ['temperature', 'reasoning_effort', 'response_format', 'max_tokens', 'max_completion_tokens'],
            array_keys($body)
        ));

        $named = (string) ($error['param'] ?? '');

        if (in_array($named, $sent, true)) {
            return $named;
        }

        $message = (string) ($error['message'] ?? '');

        foreach ($sent as $param) {
            if (preg_match('/\b' . preg_quote($param, '/') . '\b/', $message) === 1) {
                return $param;
            }
        }

        return null;
    }

    /**
     * @param  array{temperature:float|null,max_tokens:int,token_param:string,reasoning_effort:string|null,json:bool} $params
     * @return array{temperature:float|null,max_tokens:int,token_param:string,reasoning_effort:string|null,json:bool}
     */
    private static function withoutParam(array $params, string $param): array
    {
        return match ($param) {
            'temperature'           => ['temperature' => null] + $params,
            'reasoning_effort'      => ['reasoning_effort' => null] + $params,
            'response_format'       => ['json' => false] + $params,
            'max_tokens'            => ['token_param' => 'max_completion_tokens'] + $params,
            'max_completion_tokens' => ['token_param' => 'max_tokens'] + $params,
            default                 => $params,
        };
    }

    /**
     * POST JSON and return [status, body, transportError].
     *
     * Redirects are never followed: a provider that 302s somewhere else would
     * otherwise receive the API key at the redirect target.
     *
     * @param  list<string> $headers
     * @return array{0:int,1:string,2:string}
     */
    private static function send(string $url, array $headers, string $body, int $timeout): array
    {
        $timeout = max(5, min(120, $timeout));

        if (function_exists('curl_init')) {
            $ch = curl_init($url);

            if ($ch === false) {
                return [0, '', 'Could not initialise the HTTP client.'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                return [0, '', $err !== '' ? $err : 'The request failed before a response arrived.'];
            }

            return [$status, (string) $raw, ''];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            return [0, '', 'The request failed before a response arrived.'];
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $raw, ''];
    }

    /** Turn a provider's error body into one honest sentence. */
    private static function describeFailure(int $status, string $raw): string
    {
        $decoded = json_decode($raw, true);
        $detail  = '';

        if (is_array($decoded)) {
            $detail = (string) (
                $decoded['error']['message']
                ?? $decoded['error']['code']
                ?? $decoded['message']
                ?? ''
            );
        }

        $reason = match (true) {
            $status === 401, $status === 403 => 'The provider rejected the API key.',
            $status === 404                  => 'The endpoint or model was not found — check the base URL and model name.',
            $status === 429                  => 'The provider is rate limiting this key.',
            $status >= 500                   => 'The provider returned a server error.',
            default                          => 'The provider refused the request.',
        };

        return trim($reason . ($detail !== '' ? ' ' . mb_substr($detail, 0, 240) : '') . ' (HTTP ' . $status . ')');
    }

    private static function recordTest(int $providerId, bool $ok, string $error): void
    {
        try {
            Database::update('ai_providers', [
                'verified_at' => $ok ? date('Y-m-d H:i:s') : null,
                'last_error'  => $ok ? null : mb_substr($error, 0, 500),
                'updated_at'  => date('Y-m-d H:i:s'),
            ], ['id' => $providerId]);
        } catch (\Throwable $e) {
            Logger::error('Could not record an AI provider test result', ['error' => $e->getMessage()]);
        }
    }
}
