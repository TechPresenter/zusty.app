<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Admin\Integration;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Validator;
use Zuste\Services\IntegrationService;

/**
 * The integrations hub: one screen listing every external service, and one
 * detail screen per service covering credentials, webhooks, sync and logs.
 */
final class IntegrationController extends BaseAdminController
{
    private const VIEW_PERMISSION   = 'integrations.view';
    private const MANAGE_PERMISSION = 'integrations.manage';

    /** GET /admin/integrations */
    public function index(Request $request): never
    {
        $this->authorize($request, self::VIEW_PERMISSION);

        $overview = IntegrationService::overview();
        $category = $request->string('category');
        $search   = mb_strtolower(trim($request->string('q')));
        $status   = $request->string('status');

        $grouped = [];

        foreach (Integration::byCategory() as $slug => $set) {
            if ($category !== '' && $category !== $slug) {
                continue;
            }

            foreach ($set as $key => $definition) {
                $entry = $overview[$key];

                if ($status !== '' && $entry['status'] !== $status) {
                    continue;
                }

                if ($search !== '' && !str_contains(
                    mb_strtolower($definition['name'] . ' ' . $definition['vendor'] . ' ' . $definition['description'] . ' ' . $key),
                    $search
                )) {
                    continue;
                }

                $grouped[$slug][$key] = $entry;
            }
        }

        $this->view($request, 'admin.integrations.index', [
            'pageTitle'    => 'Integrations',
            'pageSubtitle' => 'Every external service the platform talks to, in one place.',
            'grouped'      => $grouped,
            'categories'   => Integration::CATEGORIES,
            'summary'      => IntegrationService::summary(),
            'filters'      => ['q' => $request->string('q'), 'category' => $category, 'status' => $status],
        ]);
    }

    /** GET /admin/integrations/{key} */
    public function show(Request $request): never
    {
        $this->authorize($request, self::VIEW_PERMISSION);

        $key   = $this->key($request);
        $state = IntegrationService::state($key);

        if ($state === null) {
            throw HttpException::notFound('That integration does not exist.');
        }

        $this->view($request, 'admin.integrations.show', [
            'pageTitle'    => $state['definition']['name'],
            'pageSubtitle' => $state['definition']['description'],
            'state'        => $state,
            'categories'   => Integration::CATEGORIES,
            'logs'         => IntegrationService::logs($key, 60),
            'usage'        => IntegrationService::usage($key, 30),
        ]);
    }

    /** POST /admin/integrations/{key} */
    public function save(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $key        = $this->key($request);
        $definition = Integration::find($key);

        if ($definition === null) {
            throw HttpException::notFound('That integration does not exist.');
        }

        if ($definition['delegate'] !== null) {
            $this->back('error', 'This integration is configured in its own module.', '/admin/integrations/' . $key);
        }

        // Validate against the rules each field declares, so a bad value is
        // rejected before it reaches the encrypted blob.
        $rules = [];

        foreach ($definition['fields'] as $field) {
            if ($field['type'] !== 'switch') {
                $rules[$field['name']] = $field['rules'];
            }
        }

        $data = $rules === [] ? [] : Validator::validate($request->all(), $rules);

        // Switches only arrive when ticked, so pass the raw payload through
        // for them and let the service read presence.
        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'switch' && $request->has($field['name'])) {
                $data[$field['name']] = 1;
            }
        }

        IntegrationService::save($key, $data, $this->adminId($request));

        $this->log($request, 'integration.saved', 'integration', null, 'Configured the ' . $definition['name'] . ' integration.');

        $this->respond($request, $definition['name'] . ' saved.', '/admin/integrations/' . $key);
    }

    /** POST /admin/integrations/{key}/toggle */
    public function toggle(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $key   = $this->key($request);
        $state = IntegrationService::state($key);

        if ($state === null) {
            throw HttpException::notFound('That integration does not exist.');
        }

        if ($state['definition']['delegate'] !== null) {
            $this->back('error', 'This integration is managed in its own module.', '/admin/integrations/' . $key);
        }

        $enable = $state['row'] === null || (int) $state['row']['is_enabled'] === 0;

        // Enabling something with no credentials would report "connected" and
        // then fail on first use, so refuse it here instead.
        if ($enable && $state['status'] === 'not_configured') {
            $this->back('error', 'Add the credentials before enabling this integration.', '/admin/integrations/' . $key);
        }

        IntegrationService::toggle($key, $enable, $this->adminId($request));

        $this->log(
            $request,
            $enable ? 'integration.enabled' : 'integration.disabled',
            'integration',
            null,
            ($enable ? 'Enabled ' : 'Disabled ') . $state['definition']['name'] . '.'
        );

        $this->respond($request, $state['definition']['name'] . ($enable ? ' enabled.' : ' disabled.'), '/admin/integrations/' . $key);
    }

    /** POST /admin/integrations/{key}/test */
    public function test(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $key   = $this->key($request);
        $state = IntegrationService::state($key);

        if ($state === null) {
            throw HttpException::notFound('That integration does not exist.');
        }

        $result = IntegrationService::test($key, $this->adminId($request));

        if ($request->expectsJson()) {
            $this->respond($request, $result['message'], null, $result);
        }

        $this->back(
            $result['ok'] ? 'success' : 'error',
            $result['message'] . ' (' . $result['duration_ms'] . 'ms)',
            '/admin/integrations/' . $key
        );
    }

    /** POST /admin/integrations/{key}/sync */
    public function sync(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $key    = $this->key($request);
        $result = IntegrationService::sync($key, $this->adminId($request));

        if ($request->expectsJson()) {
            $this->respond($request, $result['message'], null, $result);
        }

        $this->back($result['ok'] ? 'success' : 'error', $result['message'], '/admin/integrations/' . $key);
    }

    /** POST /admin/integrations/{key}/webhook */
    public function saveWebhook(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $key        = $this->key($request);
        $definition = Integration::find($key);

        if ($definition === null || ($definition['webhook'] ?? false) !== true) {
            $this->back('error', 'This integration does not use webhooks.', '/admin/integrations/' . $key);
        }

        $data = $this->validate($request, ['webhook_events' => 'nullable|string|max:500']);

        $rotate = $request->has('rotate');
        $secret = IntegrationService::saveWebhook($key, (string) ($data['webhook_events'] ?? ''), $rotate, $this->adminId($request));

        $this->log($request, 'integration.webhook', 'integration', null, 'Updated webhook settings for ' . $definition['name'] . '.');

        if ($secret !== '') {
            // Shown once. Storing it in the flash rather than the row keeps it
            // out of every later render of this page.
            \Zuste\Core\Session::flash('webhook_secret', $secret);
            $this->back('success', 'Webhook secret rotated — copy it now, it is not shown again.', '/admin/integrations/' . $key);
        }

        $this->respond($request, 'Webhook settings saved.', '/admin/integrations/' . $key);
    }

    /** POST /admin/integrations/{key}/schedule */
    public function saveSchedule(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $key        = $this->key($request);
        $definition = Integration::find($key);

        if ($definition === null || ($definition['sync'] ?? false) !== true) {
            $this->back('error', 'This integration does not support sync.', '/admin/integrations/' . $key);
        }

        $data = $this->validate($request, [
            'sync_interval_min' => 'nullable|integer|min_value:5|max_value:10080',
        ]);

        IntegrationService::saveSchedule(
            $key,
            $request->has('auto_sync'),
            (int) ($data['sync_interval_min'] ?? 60),
            $this->adminId($request)
        );

        $this->respond($request, 'Sync schedule saved.', '/admin/integrations/' . $key);
    }

    /** POST /admin/integrations/{key}/secret/{field}/clear */
    public function clearSecret(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $key   = $this->key($request);
        $field = (string) $request->routeParam('field', '');

        if (!in_array($field, Integration::secretFields($key), true)) {
            $this->back('error', 'That is not a credential on this integration.', '/admin/integrations/' . $key);
        }

        IntegrationService::clearSecret($key, $field, $this->adminId($request));

        $this->log($request, 'integration.secret_cleared', 'integration', null, 'Cleared "' . $field . '" on ' . $key . '.');

        $this->respond($request, 'Credential removed and the integration switched off.', '/admin/integrations/' . $key);
    }

    /**
     * The key from the route, checked against the catalogue.
     *
     * The key never reaches SQL as an identifier — it is only ever a bound
     * value — but validating it here turns a typo into a clean 404.
     */
    private function key(Request $request): string
    {
        $key = (string) $request->routeParam('key', '');

        if (!Integration::exists($key)) {
            throw HttpException::notFound('That integration does not exist.');
        }

        return $key;
    }
}
