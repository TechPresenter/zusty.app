<?php

declare(strict_types=1);

/**
 * A stand-in for OpenAI's Chat Completions endpoint, for the AI suite.
 *
 *     php -S localhost:8099 tests/fixtures/mock_openai.php
 *
 * It behaves like the real service where the backend's handling depends on it:
 *
 *  - a key that does not start with `sk-test-` is refused with a 401;
 *  - GPT-5/GPT-6-style models refuse `temperature` and `max_tokens` with the
 *    same 400 bodies OpenAI sends, so the parameter retry is exercised;
 *  - `mock-no-temperature` refuses `temperature` although its name does not
 *    look like a reasoning model, and `mock-legacy` refuses
 *    `max_completion_tokens` and `reasoning_effort`, like an older model;
 *  - `mock-down` always fails with a 500 and `mock-empty` returns no text.
 *
 * Replies are deterministic and depend on which prompt template sent them.
 * Every request is appended as one JSON line to the file named by
 * ZUSTE_MOCK_AI_LOG, so the suite can assert on what the backend sent.
 */

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

$log = getenv('ZUSTE_MOCK_AI_LOG') ?: sys_get_temp_dir() . '/zuste-mock-ai.log';
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path'   => parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
    'auth'   => $auth,
    'body'   => $body,
]) . "\n", FILE_APPEND | LOCK_EX);

/** @param array<string,mixed> $payload */
function reply(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function refuse(string $param, string $code, string $message): never
{
    reply(400, ['error' => ['message' => $message, 'type' => 'invalid_request_error', 'param' => $param, 'code' => $code]]);
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !str_ends_with((string) $path, '/chat/completions')) {
    reply(404, ['error' => ['message' => 'Unknown endpoint.', 'type' => 'invalid_request_error']]);
}

if (!str_starts_with($auth, 'Bearer sk-test-')) {
    reply(401, ['error' => ['message' => 'Incorrect API key provided.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]);
}

$model = (string) ($body['model'] ?? '');

if (preg_match('/^(o\d|gpt-([5-9]|\d{2}))/', $model) === 1) {
    if (array_key_exists('temperature', $body)) {
        refuse('temperature', 'unsupported_value', "Unsupported value: 'temperature' does not support "
            . $body['temperature'] . ' with this model. Only the default (1) value is supported.');
    }

    if (array_key_exists('max_tokens', $body)) {
        refuse('max_tokens', 'unsupported_parameter', "Unsupported parameter: 'max_tokens' is not supported with this model. "
            . "Use 'max_completion_tokens' instead.");
    }
}

// A model the backend cannot recognise as a reasoning model by its name.
if ($model === 'mock-no-temperature' && array_key_exists('temperature', $body)) {
    refuse('temperature', 'unsupported_value', "Unsupported value: 'temperature' does not support "
        . $body['temperature'] . ' with this model. Only the default (1) value is supported.');
}

if ($model === 'mock-legacy') {
    foreach (['max_completion_tokens', 'reasoning_effort'] as $param) {
        if (array_key_exists($param, $body)) {
            refuse($param, 'unsupported_parameter', "Unrecognized request argument supplied: {$param}");
        }
    }
}

if ($model === 'mock-down') {
    reply(500, ['error' => ['message' => 'The server had an error while processing your request.', 'type' => 'server_error']]);
}

$messages = (array) ($body['messages'] ?? []);
$prompt   = (string) (end($messages)['content'] ?? '');

$text = match (true) {
    $model === 'mock-empty'                        => '',
    str_contains($prompt, 'Candidate creators')    => (static function () use ($prompt): string {
        preg_match_all('/^(\d+) \|/m', $prompt, $m);
        $ids     = array_slice(array_map('intval', $m[1]), 0, 3);
        $matches = [['id' => 999999, 'score' => 99, 'reason' => 'Not a candidate; must be dropped.']];

        foreach ($ids as $i => $id) {
            $matches[] = ['id' => $id, 'score' => 90 - $i * 7, 'reason' => 'Mock reason for creator ' . $id . '.'];
        }

        return json_encode(['matches' => $matches]);
    })(),
    str_contains($prompt, 'Write a campaign brief') => "## Objective\n**Launch** the product with authentic creator reviews.\n\n"
        . "Target audience\n- Urban women aged 22 to 35\n\nDisclosure\n- Use #ad as required by the ASCI guidelines.",
    str_contains($prompt, 'Write a profile bio')    => '"' . str_repeat('I create honest skincare content for everyday routines. ', 12) . '"',
    default                                        => 'Hello',
};

reply(200, [
    'id'      => 'chatcmpl-mock',
    'object'  => 'chat.completion',
    'model'   => $model,
    'choices' => [[
        'index'         => 0,
        'message'       => ['role' => 'assistant', 'content' => $text],
        'finish_reason' => $text === '' ? 'length' : 'stop',
    ]],
    'usage'   => ['prompt_tokens' => 120, 'completion_tokens' => $text === '' ? 64 : 80, 'total_tokens' => 200],
]);
