<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Admin\Widget;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\PermissionService;
use Zuste\Services\WidgetService;

/**
 * The dashboard: widget data over AJAX, layout persistence, and the
 * productivity widgets (tasks and notes) that write back.
 */
final class WidgetController extends BaseAdminController
{
    /** GET /admin — the dashboard shell. */
    public function dashboard(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        $admin  = $this->admin($request);
        $layout = $this->layoutFor($admin);

        $this->view($request, 'admin.dashboard.index', [
            'pageTitle'    => 'Dashboard',
            'pageSubtitle' => 'Marketplace health at a glance',
            'layout'       => $layout,
            'catalogue'    => Widget::grouped((array) $request->attribute('permissions', [])),
            'layouts'      => Database::select(
                'SELECT `id`, `name`, `is_default` FROM `dashboard_layouts` WHERE `admin_id` = :id ORDER BY `name`',
                ['id' => (int) $admin['id']]
            ),
        ]);
    }

    /**
     * GET /admin/widgets/data?keys=a,b,c
     *
     * Batched on purpose: one request for the whole dashboard beats sixteen
     * parallel ones, especially on a shared host with limited PHP workers.
     */
    public function data(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        $admin = $this->admin($request);
        $keys  = array_slice(array_filter(array_map('trim', explode(',', $request->string('keys')))), 0, 30);

        if ($keys === []) {
            Response::success(['widgets' => []]);
        }

        $preferences = $this->preferences((int) $admin['id']);
        $widgets     = [];
        $errors      = [];

        foreach ($keys as $key) {
            if (!Widget::exists($key)) {
                continue;
            }

            try {
                $widgets[$key] = WidgetService::resolve($key, $admin, (array) ($preferences[$key]['options'] ?? []));
            } catch (HttpException $e) {
                // A single failing widget must never blank the dashboard.
                $errors[$key] = $e->getMessage();
            } catch (\Throwable $e) {
                \Zuste\Core\Logger::error('Widget failed to resolve', ['widget' => $key, 'error' => $e->getMessage()]);
                $errors[$key] = 'This widget could not be loaded.';
            }
        }

        Response::success(['widgets' => $widgets, 'errors' => $errors]);
    }

    /** GET /admin/widgets/{key} — refresh a single widget. */
    public function single(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        $key = (string) $request->routeParam('key', '');

        if (!Widget::exists($key)) {
            throw HttpException::notFound('Unknown widget.');
        }

        $admin       = $this->admin($request);
        $preferences = $this->preferences((int) $admin['id']);

        Response::success([
            'widget' => WidgetService::resolve($key, $admin, (array) ($preferences[$key]['options'] ?? [])),
        ]);
    }

    /** POST /admin/widgets/layout — persist the arrangement. */
    public function saveLayout(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        $adminId = $this->adminId($request);
        $name    = $request->string('name', 'Default') ?: 'Default';

        /** @var list<array<string,mixed>> $submitted */
        $submitted = $request->array('layout');
        $layout    = [];

        foreach (array_slice($submitted, 0, 40) as $entry) {
            $key = (string) ($entry['key'] ?? '');

            if (!Widget::exists($key)) {
                continue;
            }

            $widget = Widget::find($key);
            $needed = (string) ($widget['permission'] ?? '');

            // Never persist a widget the admin is not allowed to see.
            if ($needed !== '' && !PermissionService::adminCan($adminId, $needed)) {
                continue;
            }

            $layout[] = [
                'key'       => $key,
                'size'      => in_array((int) ($entry['size'] ?? 0), [2, 3, 4, 6, 12], true) ? (int) $entry['size'] : (int) $widget['size'],
                'collapsed' => !empty($entry['collapsed']),
            ];
        }

        if ($layout === []) {
            throw HttpException::unprocessable('A dashboard needs at least one widget.');
        }

        Database::execute(
            'INSERT INTO `dashboard_layouts` (`admin_id`, `name`, `is_default`, `layout`, `created_at`)
             VALUES (:admin_id, :name, 1, :layout, NOW())
             ON DUPLICATE KEY UPDATE `layout` = VALUES(`layout`), `is_default` = 1',
            [
                'admin_id' => $adminId,
                'name'     => mb_substr($name, 0, 100),
                'layout'   => (string) json_encode($layout, JSON_UNESCAPED_UNICODE),
            ]
        );

        // Only one layout can be the default.
        Database::execute(
            'UPDATE `dashboard_layouts` SET `is_default` = 0 WHERE `admin_id` = :admin_id AND `name` <> :name',
            ['admin_id' => $adminId, 'name' => $name]
        );

        Response::success(['count' => count($layout)], 'Dashboard layout saved.');
    }

    /** POST /admin/widgets/layout/reset */
    public function resetLayout(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        Database::execute(
            'DELETE FROM `dashboard_layouts` WHERE `admin_id` = :id',
            ['id' => $this->adminId($request)]
        );

        Response::success(null, 'Dashboard reset to the default layout.');
    }

    /** POST /admin/widgets/{key}/preference — favourite, collapse, refresh rate. */
    public function savePreference(Request $request): never
    {
        $this->authorize($request, 'dashboard.view');

        $key = (string) $request->routeParam('key', '');

        if (!Widget::exists($key)) {
            throw HttpException::notFound('Unknown widget.');
        }

        $refresh = $request->int('refresh_seconds', 0);

        // Anything below 15s would hammer the database from every open tab.
        if ($refresh !== 0 && $refresh < 15) {
            $refresh = 15;
        }

        Database::execute(
            'INSERT INTO `widget_preferences` (`admin_id`, `widget_key`, `is_favorite`, `is_collapsed`, `refresh_seconds`, `options`)
             VALUES (:admin_id, :key, :favorite, :collapsed, :refresh, :options)
             ON DUPLICATE KEY UPDATE
                `is_favorite` = VALUES(`is_favorite`),
                `is_collapsed` = VALUES(`is_collapsed`),
                `refresh_seconds` = VALUES(`refresh_seconds`),
                `options` = VALUES(`options`)',
            [
                'admin_id'  => $this->adminId($request),
                'key'       => $key,
                'favorite'  => $request->bool('is_favorite') ? 1 : 0,
                'collapsed' => $request->bool('is_collapsed') ? 1 : 0,
                'refresh'   => min(3600, max(0, $refresh)),
                'options'   => (string) json_encode($request->array('options'), JSON_UNESCAPED_UNICODE),
            ]
        );

        Response::success(null, 'Preference saved.');
    }

    /** GET /admin/widgets/{key}/export?format=csv|xlsx */
    public function export(Request $request): never
    {
        $this->authorize($request, 'export.data');

        $key = (string) $request->routeParam('key', '');

        if (!Widget::exists($key)) {
            throw HttpException::notFound('Unknown widget.');
        }

        $resolved = WidgetService::resolve($key, $this->admin($request));
        $data     = $resolved['data'];
        $format   = $request->string('format', 'csv');

        // Flatten whichever shape this widget returned into rows.
        [$headers, $rows] = $this->flattenForExport($resolved, $data);

        if ($rows === []) {
            $this->back('error', 'This widget has no tabular data to export.');
        }

        $filename = 'zuste-' . preg_replace('/[^a-z0-9]+/i', '-', $key);

        if ($format === 'xlsx') {
            \Zuste\Services\ExportService::xlsx($filename, (string) $resolved['title'], $headers, $rows);
        }

        \Zuste\Services\ExportService::csv($filename, $headers, $rows);
    }

    // =================================================================
    // Productivity widgets
    // =================================================================

    /** POST /admin/widgets/tasks */
    public function addTask(Request $request): never
    {
        $data = $this->validate($request, [
            'title'    => 'required|string|min:2|max:255',
            'priority' => 'nullable|in:low,normal,high',
            'due_on'   => 'nullable|date',
        ]);

        $adminId = $this->adminId($request);

        $id = Database::insert('admin_tasks', [
            'admin_id'   => $adminId,
            'title'      => (string) $data['title'],
            'priority'   => (string) ($data['priority'] ?? 'normal'),
            'due_on'     => $data['due_on'] ?? null,
            'sort_order' => (int) (Database::scalar(
                'SELECT COALESCE(MAX(`sort_order`), 0) + 1 FROM `admin_tasks` WHERE `admin_id` = :id',
                ['id' => $adminId]
            ) ?? 1),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::created(['id' => $id], 'Task added.');
    }

    /** POST /admin/widgets/tasks/{id}/toggle */
    public function toggleTask(Request $request): never
    {
        $taskId  = $request->routeParamInt('id');
        $adminId = $this->adminId($request);

        $updated = Database::execute(
            'UPDATE `admin_tasks`
             SET `is_done` = 1 - `is_done`,
                 `completed_at` = IF(`is_done` = 0, NOW(), NULL)
             WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => $taskId, 'admin_id' => $adminId]
        );

        if ($updated === 0) {
            throw HttpException::notFound('Task not found.');
        }

        Response::success(null, 'Task updated.');
    }

    /** POST /admin/widgets/tasks/{id}/delete */
    public function deleteTask(Request $request): never
    {
        $deleted = Database::execute(
            'DELETE FROM `admin_tasks` WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => $request->routeParamInt('id'), 'admin_id' => $this->adminId($request)]
        );

        if ($deleted === 0) {
            throw HttpException::notFound('Task not found.');
        }

        Response::success(null, 'Task removed.');
    }

    /** POST /admin/widgets/notes */
    public function saveNote(Request $request): never
    {
        $data = $this->validate($request, [
            'body'  => 'required|string|min:1|max:2000',
            'color' => 'nullable|hex_color',
            'id'    => 'nullable|integer',
        ]);

        $adminId = $this->adminId($request);
        $noteId  = (int) ($data['id'] ?? 0);

        if ($noteId > 0) {
            $updated = Database::execute(
                'UPDATE `admin_notes` SET `body` = :body, `color` = :color
                 WHERE `id` = :id AND `admin_id` = :admin_id',
                [
                    'body'     => (string) $data['body'],
                    'color'    => (string) ($data['color'] ?? '#FFD9DE'),
                    'id'       => $noteId,
                    'admin_id' => $adminId,
                ]
            );

            if ($updated === 0) {
                throw HttpException::notFound('Note not found.');
            }

            Response::success(['id' => $noteId], 'Note saved.');
        }

        $noteId = Database::insert('admin_notes', [
            'admin_id'   => $adminId,
            'body'       => (string) $data['body'],
            'color'      => (string) ($data['color'] ?? '#FFD9DE'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::created(['id' => $noteId], 'Note added.');
    }

    /** POST /admin/widgets/notes/{id}/delete */
    public function deleteNote(Request $request): never
    {
        $deleted = Database::execute(
            'DELETE FROM `admin_notes` WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => $request->routeParamInt('id'), 'admin_id' => $this->adminId($request)]
        );

        if ($deleted === 0) {
            throw HttpException::notFound('Note not found.');
        }

        Response::success(null, 'Note removed.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * The admin's saved layout, or a sensible default for their role.
     *
     * @param  array<string,mixed> $admin
     * @return list<array{key:string,size:int,collapsed:bool}>
     */
    private function layoutFor(array $admin): array
    {
        $adminId = (int) $admin['id'];

        $saved = Database::selectOne(
            'SELECT `layout` FROM `dashboard_layouts`
             WHERE `admin_id` = :id AND `is_default` = 1 ORDER BY `id` DESC LIMIT 1',
            ['id' => $adminId]
        );

        $layout = $saved !== null ? json_decode((string) $saved['layout'], true) : null;

        if (!is_array($layout) || $layout === []) {
            $layout = Widget::defaultLayout((int) ($admin['role_level'] ?? 50));
        }

        $permissions = PermissionService::forAdmin($adminId);
        $visible     = [];

        foreach ($layout as $entry) {
            $key = (string) ($entry['key'] ?? '');

            if (!Widget::exists($key)) {
                continue;
            }

            $widget = Widget::find($key);
            $needed = (string) ($widget['permission'] ?? '');

            // A role change must silently drop widgets the admin lost access to.
            if ($needed !== '' && !in_array($needed, $permissions, true)) {
                continue;
            }

            $visible[] = [
                'key'       => $key,
                'size'      => (int) ($entry['size'] ?? $widget['size']),
                'collapsed' => (bool) ($entry['collapsed'] ?? false),
                'widget'    => $widget,
            ];
        }

        return $visible;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function preferences(int $adminId): array
    {
        $rows = Database::select(
            'SELECT * FROM `widget_preferences` WHERE `admin_id` = :id',
            ['id' => $adminId]
        );

        $preferences = [];

        foreach ($rows as $row) {
            $preferences[(string) $row['widget_key']] = [
                'is_favorite'     => (bool) $row['is_favorite'],
                'is_collapsed'    => (bool) $row['is_collapsed'],
                'refresh_seconds' => (int) $row['refresh_seconds'],
                'options'         => json_decode((string) ($row['options'] ?? '{}'), true) ?: [],
            ];
        }

        return $preferences;
    }

    /**
     * Turn any widget payload into headers + rows for export.
     *
     * @param  array<string,mixed> $resolved
     * @param  array<string,mixed> $data
     * @return array{0:list<string>,1:list<list<string>>}
     */
    private function flattenForExport(array $resolved, array $data): array
    {
        // Table widgets already carry columns and rows.
        if (isset($data['columns'], $data['rows'])) {
            $headers = array_map('strval', $data['columns']);
            $rows    = [];

            foreach ($data['rows'] as $row) {
                $line = [];

                foreach ((array) ($row['cells'] ?? []) as $cell) {
                    $line[] = (string) ($cell['text'] ?? $cell['chip'] ?? '');
                }

                $rows[] = $line;
            }

            return [$headers, $rows];
        }

        // Series, bars, segments and items all reduce to label/value pairs.
        foreach (['series', 'bars', 'segments', 'items', 'stages'] as $shape) {
            if (!isset($data[$shape]) || !is_array($data[$shape])) {
                continue;
            }

            $rows = [];

            foreach ($data[$shape] as $entry) {
                $rows[] = [
                    (string) ($entry['label'] ?? ''),
                    (string) ($entry['value_label'] ?? $entry['value'] ?? ''),
                    (string) ($entry['meta'] ?? ''),
                ];
            }

            return [['Label', 'Value', 'Detail'], $rows];
        }

        if (isset($data['rows']) && is_array($data['rows'])) {
            $rows = [];

            foreach ($data['rows'] as $entry) {
                $rows[] = [(string) ($entry['label'] ?? ''), (string) ($entry['value'] ?? ''), (string) ($entry['meta'] ?? '')];
            }

            return [['Label', 'Value', 'Detail'], $rows];
        }

        // A single KPI still exports as one row.
        if (isset($data['value'])) {
            return [['Metric', 'Value'], [[(string) $resolved['title'], (string) $data['value']]]];
        }

        return [[], []];
    }
}
