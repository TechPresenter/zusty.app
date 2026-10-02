<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\AiProviderService;

/**
 * AI provider settings: credentials, model catalogue and connection tests.
 *
 * The stored API key is never rendered back to the page. The form posts an
 * empty key field to mean "leave it as it is", so a secret is only ever written
 * in one direction.
 */
final class AiProviderController extends BaseAdminController
{
    private const VIEW_PERMISSION   = 'ai.view';
    private const MANAGE_PERMISSION = 'ai.manage';

    /**
     * Refuse a write, in the format the caller asked for.
     *
     * The validator on these endpoints already answers an XHR with a 422 JSON
     * body, but the business-rule guards below used $this->back(), which always
     * redirects. A JSON client saw an empty 302 — and, if it followed the
     * redirect, a 200 HTML page — so a refused save was indistinguishable from
     * a successful one. A browser form post still gets the redirect-with-flash.
     */
    private function refuse(Request $request, string $message, int $status = 422, string $code = 'unprocessable'): never
    {
        if ($request->expectsJson()) {
            Response::error($message, $status, $code);
        }

        $this->back('error', $message, '/admin/ai/providers');
    }

    /** GET /admin/ai/providers */
    public function index(Request $request): never
    {
        $this->authorize($request, self::VIEW_PERMISSION);

        $providers = AiProviderService::all();
        $models    = [];

        foreach ($providers as $provider) {
            $models[(int) $provider['id']] = AiProviderService::models((int) $provider['id'], false);
        }

        $configured = 0;
        $enabled    = 0;
        $spend      = 0.0;

        foreach ($providers as $provider) {
            $configured += ($provider['api_key'] ?? null) !== null ? 1 : 0;
            $enabled    += (int) $provider['is_enabled'] === 1 ? 1 : 0;
            $spend      += (float) $provider['spend_this_month'];
        }

        $this->view($request, 'admin.ai.providers', [
            'pageTitle'    => 'AI providers',
            'pageSubtitle' => 'Keys, models and per-model pricing for every AI service the platform can call.',
            'providers'    => $providers,
            'models'       => $models,
            'adapters'     => AiProviderService::ADAPTERS,
            'summary'      => [
                'total'      => count($providers),
                'configured' => $configured,
                'enabled'    => $enabled,
                'spend'      => $spend,
                'active'     => AiProviderService::active(),
            ],
        ]);
    }

    /** POST /admin/ai/providers */
    public function store(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $data = $this->validate($request, [
            'code'            => 'required|slug|max:40',
            'name'            => 'required|string|min:2|max:100',
            'adapter'         => 'required|in:' . implode(',', array_keys(AiProviderService::ADAPTERS)),
            'description'     => 'nullable|string|max:255',
            'api_base_url'    => 'nullable|url|max:255',
            'organisation_id' => 'nullable|string|max:120',
            'api_version'     => 'nullable|string|max:40',
            'default_model'   => 'nullable|string|max:80',
            'priority'        => 'nullable|integer|min_value:0|max_value:9999',
            'timeout_seconds' => 'nullable|integer|min_value:5|max_value:600',
            'max_retries'     => 'nullable|integer|min_value:0|max_value:10',
            'monthly_budget'  => 'nullable|numeric|min_value:0',
            'api_key'         => 'nullable|string|max:400',
        ]);

        if (AiProviderService::findByCode($data['code']) !== null) {
            $this->refuse($request, 'A provider with that code already exists.', 409, 'conflict');
        }

        $id = AiProviderService::create(
            $data + ['is_enabled' => $request->input('is_enabled'), 'is_default' => $request->input('is_default')],
            (string) ($data['api_key'] ?? ''),
            $this->adminId($request)
        );

        $this->log($request, 'ai_provider.created', 'ai_provider', $id, 'Added AI provider "' . $data['name'] . '".');

        $this->respond($request, 'Provider added.', '/admin/ai/providers');
    }

    /** POST /admin/ai/providers/{id} */
    public function update(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $id       = $request->routeParamInt('id');
        $provider = AiProviderService::find($id);

        if ($provider === null) {
            $this->refuse($request, 'That provider no longer exists.', 404, 'not_found');
        }

        $data = $this->validate($request, [
            'name'            => 'required|string|min:2|max:100',
            'adapter'         => 'required|in:' . implode(',', array_keys(AiProviderService::ADAPTERS)),
            'description'     => 'nullable|string|max:255',
            'api_base_url'    => 'nullable|url|max:255',
            'organisation_id' => 'nullable|string|max:120',
            'api_version'     => 'nullable|string|max:40',
            'default_model'   => 'nullable|string|max:80',
            'priority'        => 'nullable|integer|min_value:0|max_value:9999',
            'timeout_seconds' => 'nullable|integer|min_value:5|max_value:600',
            'max_retries'     => 'nullable|integer|min_value:0|max_value:10',
            'monthly_budget'  => 'nullable|numeric|min_value:0',
            'api_key'         => 'nullable|string|max:400',
        ]);

        // Refuse to enable a provider that cannot possibly work, rather than
        // letting it fail on the first real call.
        $submittedKey = trim((string) ($data['api_key'] ?? ''));
        $wantsEnabled = (bool) $request->input('is_enabled');
        $hasKey       = $submittedKey !== '' || ($provider['api_key'] ?? null) !== null;

        if ($wantsEnabled && !$hasKey) {
            $this->refuse($request, 'Add an API key before enabling this provider.');
        }

        AiProviderService::update(
            $id,
            $data + ['is_enabled' => $wantsEnabled, 'is_default' => $request->input('is_default')],
            $submittedKey === '' ? null : $submittedKey
        );

        $this->log($request, 'ai_provider.updated', 'ai_provider', $id, 'Updated AI provider "' . $data['name'] . '".', [
            'key_changed' => $submittedKey !== '',
        ]);

        $this->respond($request, 'Provider saved.', '/admin/ai/providers');
    }

    /** POST /admin/ai/providers/{id}/delete */
    public function destroy(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $id       = $request->routeParamInt('id');
        $provider = AiProviderService::find($id);

        if ($provider === null) {
            $this->refuse($request, 'That provider no longer exists.', 404, 'not_found');
        }

        AiProviderService::delete($id);

        $this->log($request, 'ai_provider.deleted', 'ai_provider', $id, 'Removed AI provider "' . $provider['name'] . '".');

        $this->respond($request, 'Provider removed. Its cost history is kept.', '/admin/ai/providers');
    }

    /** POST /admin/ai/providers/{id}/default */
    public function makeDefault(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $id       = $request->routeParamInt('id');
        $provider = AiProviderService::find($id);

        if ($provider === null) {
            $this->refuse($request, 'That provider no longer exists.', 404, 'not_found');
        }

        if (($provider['api_key'] ?? null) === null) {
            $this->refuse($request, 'Add an API key before making this the default provider.');
        }

        AiProviderService::makeDefault($id);

        $this->log($request, 'ai_provider.default', 'ai_provider', $id, 'Made "' . $provider['name'] . '" the default AI provider.');

        $this->respond($request, $provider['name'] . ' is now the default provider.', '/admin/ai/providers');
    }

    /** POST /admin/ai/providers/{id}/clear-key */
    public function clearKey(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $id       = $request->routeParamInt('id');
        $provider = AiProviderService::find($id);

        if ($provider === null) {
            $this->refuse($request, 'That provider no longer exists.', 404, 'not_found');
        }

        AiProviderService::clearKey($id);

        $this->log($request, 'ai_provider.key_cleared', 'ai_provider', $id, 'Cleared the API key for "' . $provider['name'] . '".');

        $this->respond($request, 'Key removed and the provider switched off.', '/admin/ai/providers');
    }

    /** POST /admin/ai/providers/{id}/test */
    public function test(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $id       = $request->routeParamInt('id');
        $provider = AiProviderService::find($id);

        if ($provider === null) {
            $this->refuse($request, 'That provider no longer exists.', 404, 'not_found');
        }

        $result = AiProviderService::test($provider, $request->string('model') ?: null);

        $this->log(
            $request,
            'ai_provider.tested',
            'ai_provider',
            $id,
            'Tested "' . $provider['name'] . '": ' . ($result['ok'] ? 'reachable' : 'failed'),
            ['status' => $result['status'], 'model' => $result['model']]
        );

        if ($request->expectsJson()) {
            $this->respond($request, $result['message'], null, $result);
        }

        $this->back(
            $result['ok'] ? 'success' : 'error',
            $result['message'] . ' (' . $result['duration_ms'] . 'ms)',
            '/admin/ai/providers'
        );
    }

    // -----------------------------------------------------------------
    // Models
    // -----------------------------------------------------------------

    /** POST /admin/ai/providers/{id}/models */
    public function storeModel(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $id       = $request->routeParamInt('id');
        $provider = AiProviderService::find($id);

        if ($provider === null) {
            $this->refuse($request, 'That provider no longer exists.', 404, 'not_found');
        }

        $data = $this->validate($request, [
            'model_code'           => 'required|string|min:2|max:80',
            'label'                => 'required|string|min:2|max:100',
            'input_cost_per_mtok'  => 'nullable|numeric|min_value:0',
            'output_cost_per_mtok' => 'nullable|numeric|min_value:0',
            'context_window'       => 'nullable|integer|min_value:0',
            'max_output_tokens'    => 'nullable|integer|min_value:0',
            'sort_order'           => 'nullable|integer|min_value:0',
        ]);

        $existing = Database::selectOne(
            'SELECT `id` FROM `ai_provider_models` WHERE `provider_id` = :p AND `model_code` = :m',
            ['p' => $id, 'm' => $data['model_code']]
        );

        if ($existing !== null) {
            $this->refuse($request, 'That model is already listed for this provider.', 409, 'conflict');
        }

        AiProviderService::saveModel($id, $data + [
            'supports_vision' => $request->input('supports_vision'),
            'supports_tools'  => $request->input('supports_tools', 1),
            'is_active'       => $request->input('is_active', 1),
        ]);

        $this->log($request, 'ai_model.created', 'ai_provider', $id, 'Added model ' . $data['model_code'] . ' to ' . $provider['name'] . '.');

        $this->respond($request, 'Model added.', '/admin/ai/providers');
    }

    /** POST /admin/ai/providers/{id}/models/{model}/delete */
    public function deleteModel(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $id      = $request->routeParamInt('id');
        $modelId = $request->routeParamInt('model');

        if (AiProviderService::find($id) === null) {
            $this->refuse($request, 'That provider no longer exists.', 404, 'not_found');
        }

        $removed = AiProviderService::deleteModel($id, $modelId);

        if ($removed === 0) {
            $this->refuse($request, 'That model is not listed for this provider.', 404, 'not_found');
        }

        $this->log($request, 'ai_model.deleted', 'ai_provider', $id, 'Removed a model from provider #' . $id . '.');

        $this->respond($request, 'Model removed.', '/admin/ai/providers');
    }
}
