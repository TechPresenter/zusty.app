<?php

declare(strict_types=1);

/**
 * AI writing and matching: /ai/bio, /ai/campaign-brief, /ai/creator-match
 * and the `ai` block of /app/config.
 *
 * No real provider is called. The suite starts tests/fixtures/mock_openai.php
 * on 127.0.0.1:8099 — a stand-in that answers like OpenAI's Chat Completions
 * endpoint, refusals included — and points a temporary provider at it, so the
 * whole path is real: routing, gates, the outbound HTTP request, parsing,
 * logging and cost. What the backend sent is read back from the mock's log.
 *
 * The admin's AI settings and default provider are restored at the end, and
 * the temporary provider, its logs and its model row are removed.
 */

use Zuste\Core\Crypto;
use Zuste\Core\Database;
use Zuste\Services\AiProviderService;
use Zuste\Services\AiService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

const AI_MOCK_HOST = '127.0.0.1';
const AI_MOCK_PORT = 8099;
const AI_TEST_KEY  = 'sk-test-good-' . 'e7c1f0';

$mockLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zuste-mock-ai-' . getmypid() . '.log';
@unlink($mockLog);

$mock = proc_open(
    [PHP_BINARY, '-S', AI_MOCK_HOST . ':' . AI_MOCK_PORT, __DIR__ . '/../fixtures/mock_openai.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $mockLog . '.out', 'a'], 2 => ['file', $mockLog . '.out', 'a']],
    $pipes,
    dirname(__DIR__, 2),
    array_merge(getenv(), ['ZUSTE_MOCK_AI_LOG' => $mockLog])
);

$mockUp = false;

for ($i = 0; $i < 50 && is_resource($mock); $i++) {
    $socket = @fsockopen(AI_MOCK_HOST, AI_MOCK_PORT, $errno, $errstr, 0.2);

    if ($socket !== false) {
        fclose($socket);
        $mockUp = true;
        break;
    }

    usleep(100_000);
}

/** Everything the mock has received since the last reset, oldest first. */
$sent = static function () use ($mockLog): array {
    $lines = is_file($mockLog) ? file($mockLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), $lines ?: []), 'is_array'));
};

$resetSent = static function () use ($mockLog): void {
    file_put_contents($mockLog, '');
};

$originalSettings = Database::select("SELECT `setting_key`, `value` FROM `settings` WHERE `group_key` = 'ai'");
$originalDefaults = Database::select('SELECT `id`, `is_default` FROM `ai_providers`');

$setting = static function (string $key, string $value): void {
    Database::execute(
        "UPDATE `settings` SET `value` = :value WHERE `group_key` = 'ai' AND `setting_key` = :key",
        ['value' => $value, 'key' => $key]
    );
};

// A run aborted before its cleanup may have left the provider behind.
Database::execute("DELETE FROM `ai_logs` WHERE `provider` = 'it-mock-openai'");
Database::execute("DELETE FROM `ai_providers` WHERE `code` = 'it-mock-openai'");

$providerId = Database::insert('ai_providers', [
    'code'            => 'it-mock-openai',
    'name'            => 'IT Mock OpenAI',
    'adapter'         => 'openai',
    'description'     => 'Integration-test stand-in. Safe to delete.',
    'api_key'         => Crypto::encrypt(AI_TEST_KEY),
    'api_base_url'    => 'http://' . AI_MOCK_HOST . ':' . AI_MOCK_PORT . '/v1',
    'default_model'   => 'gpt-6-luna',
    'is_enabled'      => 1,
    'is_default'      => 0,
    'priority'        => 1,
    'timeout_seconds' => 20,
    'max_retries'     => 1,
    'created_at'      => date('Y-m-d H:i:s'),
    'updated_at'      => date('Y-m-d H:i:s'),
]);

AiProviderService::makeDefault($providerId);
AiProviderService::saveModel($providerId, [
    'model_code'           => 'gpt-6-luna',
    'label'                => 'GPT-6 Luna',
    'input_cost_per_mtok'  => '0.10',
    'output_cost_per_mtok' => '0.50',
]);

$model = static function (string $code) use ($providerId): void {
    Database::update('ai_providers', ['default_model' => $code], ['id' => $providerId]);
};

$brand   = Actors::create('ai-brand', 'brand');
$creator = Actors::create('ai-creator', 'creator');

$creatorId = Actors::id('ai-creator');
$brandId   = Actors::id('ai-brand');

$lastLog = static function () use ($providerId): ?array {
    return Database::selectOne('SELECT * FROM `ai_logs` WHERE `provider_id` = :p ORDER BY `id` DESC LIMIT 1', ['p' => $providerId]);
};

$logCount = static function () use ($providerId): int {
    return (int) Database::scalar('SELECT COUNT(*) FROM `ai_logs` WHERE `provider_id` = :p', ['p' => $providerId]);
};

$everyBody = [];

try {
    // -----------------------------------------------------------------
    Suite::group('AI — setup');

    Suite::check('the mock provider is listening', $mockUp, 'could not start php -S on ' . AI_MOCK_HOST . ':' . AI_MOCK_PORT);
    Suite::check('the test accounts exist', $brand !== null && $creator !== null, Actors::lastError());

    if (!$mockUp || $brand === null || $creator === null) {
        throw new RuntimeException('AI suite fixtures are not available.');
    }

    // -----------------------------------------------------------------
    Suite::group('AI — switched off');

    $setting('enabled', '0');

    $config = Http::get('/app/config', [], 'ai-creator');
    Suite::equals('the app is told AI is off', $config->at('data.ai.enabled'), false);
    Suite::equals('and offers no bio writer', $config->at('data.ai.bio_writer'), false);

    $res = Http::post('/ai/bio', [], 'ai-creator');
    Suite::status('a request is refused', $res, 403);
    Suite::equals('with a code the app can act on', $res->code(), 'ai_disabled');

    // -----------------------------------------------------------------
    Suite::group('AI — switched on');

    $setting('enabled', '1');
    $setting('feature_bio_writer', '1');
    $setting('feature_campaign_brief', '1');
    $setting('feature_creator_match', '1');
    $setting('daily_limit_per_user', '50');
    $setting('monthly_budget', '0');

    $config = Http::get('/app/config', [], 'ai-creator');
    $etagOn = (string) $config->header('etag');

    Suite::equals('the app is told AI is on', $config->at('data.ai.enabled'), true);
    Suite::check(
        'every feature is offered',
        $config->at('data.ai.bio_writer') === true && $config->at('data.ai.campaign_brief') === true
            && $config->at('data.ai.creator_match') === true,
        json_encode($config->at('data.ai'))
    );
    Suite::equals('with the daily allowance', $config->at('data.ai.daily_limit'), 50);
    $everyBody[] = $config->body;

    $setting('feature_creator_match', '0');
    Suite::equals('one feature can be switched off on its own', Http::get('/app/config', [], 'ai-creator')->at('data.ai.creator_match'), false);
    Suite::equals('and its endpoint refuses', Http::post('/ai/creator-match', ['brief' => str_repeat('A skincare launch brief. ', 3)], 'ai-brand')->code(), 'ai_disabled');
    $setting('feature_creator_match', '1');

    // Removing the key must reach installed apps even though no setting changed.
    Database::update('ai_providers', ['api_key' => null], ['id' => $providerId]);
    $config = Http::get('/app/config', [], 'ai-creator');
    Suite::equals('without a key the app is told AI is off', $config->at('data.ai.enabled'), false);
    Suite::check('and the config validator changes with it', (string) $config->header('etag') !== $etagOn, 'ETag stayed ' . $etagOn);
    Suite::equals('a request says AI is not set up', Http::post('/ai/bio', [], 'ai-creator')->code(), 'ai_unavailable');
    Database::update('ai_providers', ['api_key' => Crypto::encrypt(AI_TEST_KEY)], ['id' => $providerId]);

    // -----------------------------------------------------------------
    Suite::group('AI — who may call what');

    Suite::status('a signed-out caller is refused', Http::post('/ai/bio', []), 401);
    Suite::status('a brand cannot use the creator bio writer', Http::post('/ai/bio', [], 'ai-brand'), 403);
    Suite::status('a creator cannot write campaign briefs', Http::post('/ai/campaign-brief', ['idea' => 'Launch our new face serum'], 'ai-creator'), 403);
    Suite::status('a creator cannot run creator matching', Http::post('/ai/creator-match', ['brief' => str_repeat('A brief. ', 5)], 'ai-creator'), 403);

    // -----------------------------------------------------------------
    Suite::group('AI — bio writer');

    $resetSent();
    $usedBefore = AiService::usedToday($creatorId);
    $res        = Http::post('/ai/bio', ['notes' => 'Friendly, mention my skincare routines', 'max_chars' => 200], 'ai-creator');
    $everyBody[] = $res->body;
    $text       = (string) $res->at('data.text');

    Suite::ok('a creator gets a draft', $res, 200);
    Suite::check('the draft fits the field', $text !== '' && mb_strlen($text) <= 200, mb_strlen($text) . ' characters');
    Suite::check('without the quotation marks the model wrapped it in', !str_starts_with($text, '"'), mb_substr($text, 0, 40));
    Suite::equals('the allowance left is reported', $res->at('data.remaining_today'), 50 - $usedBefore - 1);

    $calls = $sent();
    $call  = end($calls) ?: [];

    Suite::equals('one request reached the provider', count($calls), 1);
    Suite::equals('authenticated with the stored key', $call['auth'] ?? null, 'Bearer ' . AI_TEST_KEY);
    Suite::equals('for the configured model', $call['body']['model'] ?? null, 'gpt-6-luna');
    Suite::check(
        'a reasoning model gets max_completion_tokens and no temperature',
        isset($call['body']['max_completion_tokens']) && !isset($call['body']['max_tokens']) && !isset($call['body']['temperature']),
        json_encode(array_diff_key($call['body'] ?? [], ['messages' => 1]))
    );

    $system = (string) ($call['body']['messages'][0]['content'] ?? '');
    $user   = (string) ($call['body']['messages'][1]['content'] ?? '');

    Suite::check('the admin-editable template is the system prompt', str_contains($system, 'first person'), mb_substr($system, 0, 80));
    Suite::check('the creator\'s notes are passed on', str_contains($user, 'mention my skincare routines'), mb_substr($user, 0, 200));
    Suite::check('the length rule comes from the code', str_contains($user, 'at most 200 characters'), mb_substr($user, -200));

    $log = $lastLog();
    Suite::check(
        'the call is logged with tokens and cost',
        $log !== null && $log['status'] === 'success' && (int) $log['input_tokens'] === 120
            && (int) $log['output_tokens'] === 80 && abs((float) $log['cost'] - 0.000052) < 0.0000001,
        json_encode($log)
    );
    Suite::check(
        'against the creator who asked',
        $log !== null && $log['requested_by_type'] === 'user' && (int) $log['requested_by_id'] === $creatorId,
        json_encode([$log['requested_by_type'] ?? null, $log['requested_by_id'] ?? null])
    );

    // -----------------------------------------------------------------
    Suite::group('AI — models that refuse a parameter');

    $model('mock-no-temperature');
    $resetSent();
    $res   = Http::post('/ai/bio', [], 'ai-creator');
    $calls = $sent();

    Suite::ok('a model that refuses temperature still answers', $res, 200);
    Suite::check(
        'after one retry without it',
        count($calls) === 2 && isset($calls[0]['body']['temperature']) && !isset($calls[1]['body']['temperature']),
        count($calls) . ' request(s)'
    );

    $model('mock-legacy');
    $resetSent();
    $res   = Http::post('/ai/bio', [], 'ai-creator');
    $calls = $sent();

    Suite::ok('an older model that wants max_tokens still answers', $res, 200);
    Suite::check(
        'after one retry with max_tokens',
        count($calls) === 2 && isset($calls[0]['body']['max_completion_tokens']) && isset($calls[1]['body']['max_tokens']),
        count($calls) . ' request(s)'
    );

    $model('gpt-6-luna');

    // -----------------------------------------------------------------
    Suite::group('AI — campaign brief writer');

    $resetSent();
    $res = Http::post('/ai/campaign-brief', [
        'idea'             => 'Get honest reviews of our new vitamin C face serum',
        'title'            => 'Glow Serum launch',
        'platform'         => 'instagram',
        'deliverable_type' => 'reel',
        'posts_count'      => 2,
        'budget_min'       => 5000,
        'budget_max'       => 15000,
    ], 'ai-brand');
    $everyBody[] = $res->body;
    $text = (string) $res->at('data.text');

    Suite::ok('a brand gets a draft brief', $res, 200);
    Suite::check('as plain text without Markdown', $text !== '' && !str_contains($text, '##') && !str_contains($text, '**'), mb_substr($text, 0, 80));
    Suite::check('that fits a campaign description', mb_strlen($text) <= 5000, mb_strlen($text) . ' characters');

    $calls = $sent();
    $user  = (string) (end($calls)['body']['messages'][1]['content'] ?? '');

    Suite::check('the deliverables are spelled out', str_contains($user, '2 × reel per creator'), mb_substr($user, 0, 300));
    Suite::check('the budget is spelled out', str_contains($user, '5,000 to 15,000 per creator'), mb_substr($user, 0, 300));
    Suite::status('an idea is required', Http::post('/ai/campaign-brief', ['title' => 'No idea given'], 'ai-brand'), 422);

    // -----------------------------------------------------------------
    Suite::group('AI — creator matching');

    $resetSent();
    $res = Http::post('/ai/creator-match', [
        'brief' => 'We are launching a vitamin C face serum and want honest, relatable skincare reviews from Indian creators.',
    ], 'ai-brand');
    $everyBody[] = $res->body;

    $matches = $res->at('data.matches');
    $matches = is_array($matches) ? $matches : [];
    $calls   = $sent();
    $user    = (string) (end($calls)['body']['messages'][1]['content'] ?? '');

    preg_match_all('/^(\d+) \|/m', $user, $m);
    $candidateIds = array_map('intval', $m[1]);

    Suite::ok('a brand gets matches', $res, 200);
    Suite::check('between one and five of them', count($matches) >= 1 && count($matches) <= 5, count($matches) . ' match(es)');
    Suite::check('the request asked for JSON', (end($calls)['body']['response_format']['type'] ?? '') === 'json_object');
    Suite::check(
        'only creators that were candidates come back',
        $matches !== [] && array_diff(array_column($matches, 'id'), $candidateIds) === [] && !in_array(999999, array_column($matches, 'id'), true),
        json_encode(array_column($matches, 'id'))
    );
    Suite::check(
        'each with a score, a reason and its creator card',
        $matches !== [] && array_reduce($matches, static fn (bool $ok, array $c): bool => $ok
            && is_int($c['match_score'] ?? null) && ($c['match_reason'] ?? '') !== ''
            && ($c['display_name'] ?? '') !== '' && ($c['handle'] ?? '') !== '', true),
        json_encode($matches[0] ?? null)
    );

    $scores = array_column($matches, 'match_score');
    $sorted = $scores;
    rsort($sorted);
    Suite::equals('best match first', $scores, $sorted);

    $emails = $candidateIds === [] ? [] : Database::column(
        'SELECT u.`email` FROM `creators` c JOIN `users` u ON u.`id` = c.`user_id` WHERE c.`id` IN ('
            . implode(',', $candidateIds) . ') AND u.`email` IS NOT NULL'
    );
    Suite::check(
        'no candidate\'s email address is sent to the provider',
        array_filter($emails, static fn ($email): bool => $email !== '' && str_contains($user, (string) $email)) === [],
        count($emails) . ' emails checked'
    );

    $before = $logCount();
    $res    = Http::post('/ai/creator-match', ['brief' => str_repeat('Nobody fits this one. ', 3), 'category_id' => 999999], 'ai-brand');
    Suite::check(
        'with no candidates nothing is sent or spent',
        $res->status === 200 && $res->at('data.matches') === [] && $logCount() === $before,
        $res->describe()
    );

    // -----------------------------------------------------------------
    Suite::group('AI — failures are contained');

    $model('mock-down');
    $usedBefore = AiService::usedToday($creatorId);
    $res        = Http::post('/ai/bio', [], 'ai-creator');

    Suite::status('a provider outage is reported as such', $res, 502);
    Suite::equals('with a code the app can act on', $res->code(), 'ai_failed');
    Suite::check('without the provider\'s own error text', !str_contains($res->body, 'server had an error'), $res->message());
    Suite::check('the failure is logged', ($lastLog()['status'] ?? '') === 'failed', json_encode($lastLog()));
    Suite::equals('and does not use up the allowance', AiService::usedToday($creatorId), $usedBefore);

    $model('mock-empty');
    Suite::status('an empty reply is a failure, not a blank draft', Http::post('/ai/bio', [], 'ai-creator'), 502);

    $model('gpt-6-luna');
    Database::update('ai_providers', ['api_key' => Crypto::encrypt('not-a-valid-key')], ['id' => $providerId]);
    Suite::status('a rejected key is a failure', Http::post('/ai/bio', [], 'ai-creator'), 502);
    Suite::check(
        'and the reason is kept for the admin',
        str_contains((string) Database::scalar('SELECT `last_error` FROM `ai_providers` WHERE `id` = :id', ['id' => $providerId]), 'rejected the API key'),
        (string) Database::scalar('SELECT `last_error` FROM `ai_providers` WHERE `id` = :id', ['id' => $providerId])
    );
    Database::update('ai_providers', ['api_key' => Crypto::encrypt(AI_TEST_KEY), 'last_error' => null], ['id' => $providerId]);

    // -----------------------------------------------------------------
    Suite::group('AI — daily allowance and budget');

    Suite::clearRateLimits();
    $setting('daily_limit_per_user', (string) (AiService::usedToday($creatorId) + 1));

    $last = Http::post('/ai/bio', [], 'ai-creator');
    Suite::ok('the last request of the day works', $last, 200);
    Suite::equals('and says none are left', $last->at('data.remaining_today'), 0);

    $over = Http::post('/ai/bio', [], 'ai-creator');
    Suite::status('the next one is refused', $over, 429);
    Suite::check('with a Retry-After until tomorrow', (int) $over->header('retry-after') > 0, (string) $over->header('retry-after'));
    Suite::check('another account is unaffected', Http::post('/ai/campaign-brief', ['idea' => 'Launch our new face serum'], 'ai-brand')->status === 200);

    $setting('daily_limit_per_user', '50');
    $setting('monthly_budget', '0.000001');
    $res = Http::post('/ai/campaign-brief', ['idea' => 'Launch our new face serum'], 'ai-brand');
    Suite::equals('a spent monthly budget stops requests', $res->code(), 'ai_budget_reached');
    $setting('monthly_budget', '0');

    // -----------------------------------------------------------------
    Suite::group('AI — the key stays on the server');

    $leaks = array_filter($everyBody, static fn (string $body): bool => str_contains($body, AI_TEST_KEY) || str_contains($body, 'sk-test-'));
    Suite::equals('no response ever contains the key', count($leaks), 0);
    Suite::check(
        'and no log row does either',
        (int) Database::scalar(
            'SELECT COUNT(*) FROM `ai_logs` WHERE `provider_id` = :p AND `error_message` LIKE :key',
            ['p' => $providerId, 'key' => '%sk-test-%']
        ) === 0
    );
} catch (RuntimeException $e) {
    Suite::check('AI suite fixtures', false, $e->getMessage());
} finally {
    foreach ($originalSettings as $row) {
        $setting((string) $row['setting_key'], (string) ($row['value'] ?? ''));
    }

    Database::execute("DELETE FROM `ai_logs` WHERE `provider` = 'it-mock-openai'");
    Database::execute('DELETE FROM `ai_providers` WHERE `id` = :id', ['id' => $providerId]);

    foreach ($originalDefaults as $row) {
        Database::update('ai_providers', ['is_default' => (int) $row['is_default']], ['id' => (int) $row['id']]);
    }

    if (is_resource($mock)) {
        proc_terminate($mock);
        proc_close($mock);
    }

    @unlink($mockLog);
    @unlink($mockLog . '.out');
}
