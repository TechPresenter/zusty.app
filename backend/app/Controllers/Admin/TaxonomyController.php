<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Services\TaxonomyService;

/**
 * Manage the option lists the apps render.
 *
 * Editing here changes what Android, iOS and Web display on their next launch —
 * there is no release involved. Because of that reach, two guard rails apply:
 *
 *   * a **system** taxonomy backs a workflow the server already understands
 *     (booking states, sort keys the API parses). Its terms can be relabelled,
 *     reordered and disabled, but values cannot be added, renamed or deleted,
 *     because the backend would stop recognising them;
 *   * every write flushes the cached bundle and moves its version stamp, so
 *     clients notice the change instead of holding a stale list.
 */
final class TaxonomyController extends BaseAdminController
{
    /** GET /admin/taxonomies */
    public function index(Request $request): never
    {
        $this->authorize($request, 'taxonomies.view');

        $rows = Database::select(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM `taxonomy_terms` tt WHERE tt.`taxonomy_id` = t.`id`) AS `term_count`,
                    (SELECT COUNT(*) FROM `taxonomy_terms` tt
                      WHERE tt.`taxonomy_id` = t.`id` AND tt.`is_active` = 1) AS `active_count`
               FROM `taxonomies` t
              ORDER BY t.`sort_order`, t.`id`'
        );

        $this->view($request, 'admin.taxonomies.index', [
            'pageTitle'    => 'App option lists',
            'pageSubtitle' => count($rows) . ' taxonomies · version ' . TaxonomyService::version(),
            'taxonomies'   => $rows,
        ]);
    }

    /** GET /admin/taxonomies/{id} */
    public function show(Request $request): never
    {
        $this->authorize($request, 'taxonomies.view');

        $taxonomy = $this->find($request->routeParamInt('id'));

        $terms = Database::select(
            'SELECT * FROM `taxonomy_terms`
              WHERE `taxonomy_id` = :id
              ORDER BY `sort_order`, `id`',
            ['id' => (int) $taxonomy['id']]
        );

        $this->view($request, 'admin.taxonomies.show', [
            'pageTitle'    => $taxonomy['name'],
            'pageSubtitle' => count($terms) . ' options · key ' . $taxonomy['key'],
            'taxonomy'     => $taxonomy,
            'terms'        => $terms,
        ]);
    }

    /** POST /admin/taxonomies/{id}/terms — create a term. */
    public function storeTerm(Request $request): never
    {
        $this->authorize($request, 'taxonomies.manage');

        $taxonomy = $this->find($request->routeParamInt('id'));

        // Adding a value the server does not understand would silently break
        // filtering, so system lists are closed to new terms.
        if ((int) $taxonomy['is_system'] === 1) {
            $this->back('error', 'This is a system list — its options are fixed. You can relabel and reorder them.');
        }

        $data = $this->validate($request, [
            'value'       => 'required|string|max:60|slug',
            'label'       => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:60',
            'color_hex'   => 'nullable|hex_color',
            'metadata'    => 'nullable|string|max:2000',
        ]);

        if (Database::exists('taxonomy_terms', [
            'taxonomy_id' => (int) $taxonomy['id'],
            'value'       => $data['value'],
        ])) {
            $this->back('error', 'That value already exists in this list.');
        }

        $nextOrder = (int) Database::scalar(
            'SELECT COALESCE(MAX(`sort_order`), 0) + 10 FROM `taxonomy_terms` WHERE `taxonomy_id` = :id',
            ['id' => (int) $taxonomy['id']]
        );

        Database::insert('taxonomy_terms', [
            'taxonomy_id' => (int) $taxonomy['id'],
            'value'       => $data['value'],
            'label'       => $data['label'],
            'description' => $data['description'] ?? null,
            'icon'        => $data['icon'] ?? null,
            'color_hex'   => $data['color_hex'] ?? null,
            'metadata'    => $this->normaliseMetadata($data['metadata'] ?? null),
            'sort_order'  => $nextOrder,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->afterWrite($request, 'taxonomy.term_created', (int) $taxonomy['id'],
            'Added "' . $data['label'] . '" to ' . $taxonomy['name'] . '.');

        $this->respond($request, 'Option added.');
    }

    /** POST /admin/taxonomies/{id}/terms/{termId} — update a term. */
    public function updateTerm(Request $request): never
    {
        $this->authorize($request, 'taxonomies.manage');

        $taxonomy = $this->find($request->routeParamInt('id'));
        $term     = $this->findTerm((int) $taxonomy['id'], $request->routeParamInt('termId'));

        $data = $this->validate($request, [
            'label'       => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:60',
            'color_hex'   => 'nullable|hex_color',
            'metadata'    => 'nullable|string|max:2000',
            'is_active'   => 'nullable|boolean',
            'is_default'  => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min_value:0|max_value:9999',
        ]);

        $isDefault = !empty($data['is_default']);

        Database::transaction(static function () use ($taxonomy, $term, $data, $isDefault): void {
            // Only one term may be the default, so setting one clears the rest.
            if ($isDefault) {
                Database::execute(
                    'UPDATE `taxonomy_terms` SET `is_default` = 0 WHERE `taxonomy_id` = :id',
                    ['id' => (int) $taxonomy['id']]
                );
            }

            Database::update('taxonomy_terms', [
                'label'       => $data['label'],
                'description' => $data['description'] ?? null,
                'icon'        => $data['icon'] ?? null,
                'color_hex'   => $data['color_hex'] ?? null,
                'metadata'    => self::encodeMetadata($data['metadata'] ?? null),
                'is_active'   => !empty($data['is_active']) ? 1 : 0,
                'is_default'  => $isDefault ? 1 : 0,
                'sort_order'  => (int) ($data['sort_order'] ?? $term['sort_order']),
            ], ['id' => (int) $term['id']]);
        });

        $this->afterWrite($request, 'taxonomy.term_updated', (int) $term['id'],
            'Updated "' . $data['label'] . '" in ' . $taxonomy['name'] . '.');

        $this->respond($request, 'Option saved.');
    }

    /** POST /admin/taxonomies/{id}/terms/{termId}/delete */
    public function destroyTerm(Request $request): never
    {
        $this->authorize($request, 'taxonomies.manage');

        $taxonomy = $this->find($request->routeParamInt('id'));
        $term     = $this->findTerm((int) $taxonomy['id'], $request->routeParamInt('termId'));

        if ((int) $taxonomy['is_system'] === 1) {
            $this->back('error', 'System options cannot be deleted. Disable it instead — existing records keep their value.');
        }

        Database::delete('taxonomy_terms', ['id' => (int) $term['id']]);

        $this->afterWrite($request, 'taxonomy.term_deleted', (int) $taxonomy['id'],
            'Removed "' . $term['label'] . '" from ' . $taxonomy['name'] . '.');

        $this->respond($request, 'Option removed.');
    }

    /** POST /admin/taxonomies/{id}/reorder — persist a drag-and-drop order. */
    public function reorder(Request $request): never
    {
        $this->authorize($request, 'taxonomies.manage');

        $taxonomy = $this->find($request->routeParamInt('id'));
        $order    = $request->array('order');

        if ($order === []) {
            $this->back('error', 'Nothing to reorder.');
        }

        Database::transaction(static function () use ($taxonomy, $order): void {
            foreach (array_values($order) as $index => $termId) {
                Database::update(
                    'taxonomy_terms',
                    ['sort_order' => ($index + 1) * 10],
                    ['id' => (int) $termId, 'taxonomy_id' => (int) $taxonomy['id']]
                );
            }
        });

        $this->afterWrite($request, 'taxonomy.reordered', (int) $taxonomy['id'],
            'Reordered ' . $taxonomy['name'] . '.');

        $this->respond($request, 'Order saved.');
    }

    /** POST /admin/taxonomies/{id} — update the list itself. */
    public function update(Request $request): never
    {
        $this->authorize($request, 'taxonomies.manage');

        $taxonomy = $this->find($request->routeParamInt('id'));

        $data = $this->validate($request, [
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min_value:0|max_value:9999',
        ]);

        Database::update('taxonomies', [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
            'sort_order'  => (int) ($data['sort_order'] ?? $taxonomy['sort_order']),
        ], ['id' => (int) $taxonomy['id']]);

        $this->afterWrite($request, 'taxonomy.updated', (int) $taxonomy['id'],
            'Updated list ' . $data['name'] . '.');

        $this->respond($request, 'List saved.');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Flush the cached bundle and log the change.
     *
     * Every write goes through here — forgetting to flush would leave clients
     * serving a stale list for up to an hour, which is exactly the failure the
     * version stamp exists to prevent.
     */
    private function afterWrite(Request $request, string $action, int $subjectId, string $description): void
    {
        TaxonomyService::flush();

        $this->log($request, $action, 'taxonomy', $subjectId, $description, [
            'version' => TaxonomyService::version(),
        ]);
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        return Database::selectOne('SELECT * FROM `taxonomies` WHERE `id` = :id', ['id' => $id])
            ?? throw HttpException::notFound('That option list was not found.');
    }

    /** @return array<string,mixed> */
    private function findTerm(int $taxonomyId, int $termId): array
    {
        return Database::selectOne(
            'SELECT * FROM `taxonomy_terms` WHERE `id` = :id AND `taxonomy_id` = :taxonomy_id',
            ['id' => $termId, 'taxonomy_id' => $taxonomyId]
        ) ?? throw HttpException::notFound('That option was not found.');
    }

    private function normaliseMetadata(?string $raw): ?string
    {
        return self::encodeMetadata($raw);
    }

    /**
     * Metadata arrives as free text. Store it only if it is valid JSON —
     * a malformed blob would break every client that reads the bundle.
     */
    private static function encodeMetadata(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw HttpException::unprocessable('Metadata must be a valid JSON object, e.g. {"rank":1}.');
        }

        return json_encode($decoded);
    }
}
