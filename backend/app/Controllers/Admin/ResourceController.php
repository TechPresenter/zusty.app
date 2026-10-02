<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Admin\Resource;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Services\ExportService;
use Zuste\Core\Response;
use Zuste\Helpers\Str;
use Zuste\Services\CreatorService;
use Zuste\Services\NotificationService;
use Zuste\Services\RevisionService;

/**
 * Generic CRUD for every resource described in Admin\Resource.
 *
 * One implementation of list/filter/sort/create/update/delete/reorder/bulk,
 * driven by declarative definitions — so the CMS modules cannot drift apart
 * and a fix lands everywhere at once.
 */
final class ResourceController extends BaseAdminController
{
    /**
     * Most rows a single CSV export may return.
     *
     * A request that has to buffer an unbounded result set is a memory
     * incident waiting to happen. Anything larger belongs in a background job;
     * until then the export is capped and the cap is recorded in the activity
     * log, so a truncated file is never mistaken for a complete one.
     */
    private const EXPORT_LIMIT = 10000;

    /** GET /admin/{resource} */
    public function index(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->viewPermission);

        $params   = $this->listParams($request, $resource->sortable, $resource->defaultSort);
        $clauses  = [];
        $bindings = [];

        if ($resource->softDeletes) {
            $clauses[] = 't.`deleted_at` IS NULL';
        }

        if ($params['search'] !== '' && $resource->searchable !== []) {
            $parts = [];
            foreach ($resource->searchable as $index => $column) {
                $parts[]                      = 't.' . Database::quoteIdentifier($column) . " LIKE :search{$index} ESCAPE '\\\\'";
                $bindings["search{$index}"]   = Str::likeTerm($params['search']);
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }

        foreach ($resource->filters as $filter) {
            $name = $filter['name'];

            if (!$request->filled($name)) {
                continue;
            }

            $value = $request->string($name);

            if (!array_key_exists($value, $filter['options'])) {
                continue; // Reject anything not in the declared option set.
            }

            $clauses[]        = 't.' . Database::quoteIdentifier($name) . ' = :f_' . $name;
            $bindings['f_' . $name] = $value;
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        // Blogs display their category name, so join when the definition needs it.
        $join   = '';
        $select = 't.*';

        if ($resource->key === 'blogs') {
            $join   = ' LEFT JOIN `blog_categories` bc ON bc.`id` = t.`category_id`';
            $select = 't.*, bc.`name` AS `category_name`';
        }

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM ' . Database::quoteIdentifier($resource->table) . ' t' . $join . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT ' . $select . ' FROM ' . Database::quoteIdentifier($resource->table) . ' t' . $join . $where
            . ' ORDER BY t.' . Database::quoteIdentifier($params['sort_by']) . ' ' . $params['sort_dir']
            . ', t.`id` DESC LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        $exportAs = strtolower($request->string('export'));

        if (in_array($exportAs, ['csv', 'xlsx', 'pdf'], true)) {
            $this->authorize($request, 'export.data');

            // Re-query WITHOUT the page window.
            //
            // `$rows` above is one page — LIMIT per_page OFFSET offset — so
            // exporting it handed back at most 20-50 rows regardless of how
            // many the filters actually matched. An operator filtering 500
            // coupons and clicking Export got 20, with nothing to say so.
            //
            // The same $where and $bindings are reused, so the export is
            // exactly the filtered set the screen is showing, just complete.
            // The cap is a deliberate backstop: a genuinely huge export should
            // be a background job, not a request that exhausts memory.
            $exportRows = Database::select(
                'SELECT ' . $select . ' FROM ' . Database::quoteIdentifier($resource->table) . ' t' . $join . $where
                . ' ORDER BY t.' . Database::quoteIdentifier($params['sort_by']) . ' ' . $params['sort_dir']
                . ', t.`id` DESC LIMIT ' . self::EXPORT_LIMIT,
                $bindings
            );

            $this->log(
                $request,
                'resource.exported',
                $resource->key,
                null,
                count($exportRows) . ' row(s) exported'
                    . (count($exportRows) === self::EXPORT_LIMIT ? ' (capped)' : '') . '.'
            );

            $columns = array_map(static fn (array $c): string => $c['key'], $resource->columns);
            $labels  = array_map(static fn (array $c): string => $c['label'], $resource->columns);

            // Flatten to the displayed columns once, so all three writers get
            // the same shape and a value can never appear in one format and
            // not another.
            $matrix = [];

            foreach ($exportRows as $row) {
                $line = [];

                foreach ($columns as $column) {
                    $value  = $row[$column] ?? '';
                    $line[] = is_scalar($value) || $value === null
                        ? (string) $value
                        : (string) json_encode($value);
                }

                $matrix[] = $line;
            }

            $filename = 'zuste-' . $resource->key;

            switch ($exportAs) {
                case 'xlsx':
                    ExportService::xlsx($filename, $resource->plural, $labels, $matrix);
                    // no break — xlsx() never returns

                case 'pdf':
                    // Delivered as a print-ready A4 page that fires the browser's
                    // print dialog on load. The browser writes the PDF, which is
                    // why no PDF library is needed; the output is a real PDF.
                    ExportService::pdf(
                        $resource->plural,
                        $labels,
                        $matrix,
                        'Exported ' . date('d M Y, g:i A') . ' — ' . count($matrix) . ' row(s)'
                    );
                    // no break — pdf() never returns

                default:
                    $this->exportCsv($filename, $labels, $exportRows, $columns);
            }
        }

        $this->view($request, 'admin.resource.index', [
            'pageTitle'    => $resource->plural,
            'pageSubtitle' => $resource->description,
            'resource'     => $resource,
            'rows'         => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => $request->all(),
        ]);
    }

    /** GET /admin/{resource}/create */
    public function create(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->managePermission);

        if (!$resource->creatable) {
            throw HttpException::forbidden('This resource cannot be created from the panel.');
        }

        // Seed the form with declared defaults.
        $row = ['id' => 0];

        foreach ($resource->fields as $field) {
            $row[$field['name']] = $field['default'] ?? '';
        }

        if ($resource->sortableRows) {
            $row['sort_order'] = $resource->nextSortOrder();
        }

        $this->view($request, 'admin.resource.form', [
            'pageTitle'    => 'New ' . strtolower($resource->singular),
            'pageSubtitle' => $resource->description,
            'resource'     => $resource,
            'row'          => $row,
            'isEdit'       => false,
        ]);
    }

    /** GET /admin/{resource}/{id}/edit */
    public function edit(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->managePermission);

        $row = $this->findRow($resource, $request->routeParamInt('id'));

        $this->view($request, 'admin.resource.form', [
            'pageTitle'    => 'Edit ' . strtolower($resource->singular),
            'pageSubtitle' => $resource->labelFor($row),
            'resource'     => $resource,
            'row'          => $row,
            'isEdit'       => true,
        ]);
    }

    /** POST /admin/{resource} */
    public function store(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->managePermission);

        $data = $this->validate($request, $resource->rules(false));
        $data = $this->prepare($resource, $data, $request, null);

        $data['created_at'] = date('Y-m-d H:i:s');

        $id = Database::insert($resource->table, $data);

        $this->afterWrite($resource, $id);

        // Version 1 is the record as it was created, so the history has a
        // baseline to diff the first edit against and an "as created" state to
        // restore to. Without it the revisions screen stayed empty forever.
        $this->snapshot($request, $resource, $id, 'Initial version.');

        $this->log($request, $resource->key . '.created', $resource->key, $id, 'Created ' . $resource->labelFor($data));

        $this->respond(
            $request,
            $resource->singular . ' created.',
            '/admin/' . $resource->key
        );
    }

    /** POST /admin/{resource}/{id} */
    public function update(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->managePermission);

        $id     = $request->routeParamInt('id');
        $before = $this->findRow($resource, $id);

        $data = $this->validate($request, $resource->rules(true, $id));
        $data = $this->prepare($resource, $data, $request, $before);

        // Remember the state about to be replaced BEFORE the write, so the
        // revisions screen can diff it and restore it. This is the snapshot the
        // whole /admin/revisions module was built around and never received.
        $this->snapshot($request, $resource, $id, 'Replaced by an edit.', $before);

        Database::update($resource->table, $data, ['id' => $id]);

        $this->afterWrite($resource, $id);

        $diff = \Zuste\Services\ActivityLogger::diff($before, $data);

        $this->log($request, $resource->key . '.updated', $resource->key, $id, 'Updated ' . $resource->labelFor($before), $diff);

        $this->respond($request, $resource->singular . ' updated.', '/admin/' . $resource->key);
    }

    /** POST /admin/{resource}/{id}/delete */
    public function destroy(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->managePermission);

        if (!$resource->deletable) {
            $this->back('error', 'This resource cannot be deleted.');
        }

        $id  = $request->routeParamInt('id');
        $row = $this->findRow($resource, $id);

        $blocked = $this->undeletableIds($resource, [$id]);

        if (isset($blocked[$id])) {
            $this->back('error', $blocked[$id]);
        }

        if ($resource->softDeletes) {
            Database::update($resource->table, ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        } else {
            Database::delete($resource->table, ['id' => $id]);
        }

        $this->afterWrite($resource, $id);

        $this->log($request, $resource->key . '.deleted', $resource->key, $id, 'Deleted ' . $resource->labelFor($row));

        $this->respond($request, $resource->singular . ' deleted.', '/admin/' . $resource->key);
    }

    /** POST /admin/{resource}/bulk */
    public function bulk(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->managePermission);

        $action = $request->string('bulk_action');
        $ids     = array_values(array_filter(array_map('intval', $request->array('ids')), static fn (int $i): bool => $i > 0));

        if ($ids === []) {
            $this->back('error', 'Select at least one row.');
        }

        if (!in_array($action, ['activate', 'deactivate', 'feature', 'unfeature', 'delete'], true)) {
            $this->back('error', 'Unknown bulk action.');
        }

        // The state columns differ per resource and some resources have none —
        // articles publish through `status`, flags through `is_enabled`. Resolve
        // the column from the definition instead of assuming a name, and refuse
        // here rather than building SQL for a column that does not exist.
        $column = match ($action) {
            'activate', 'deactivate' => $resource->activeColumn,
            'feature', 'unfeature'   => $resource->featuredColumn,
            'delete'                 => null,
        };

        if ($action !== 'delete' && $column === null) {
            $this->back('error', 'That bulk action does not apply to ' . strtolower($resource->plural) . '.');
        }

        /** @var array<int,string> $skipped */
        $skipped = [];

        if ($action === 'delete') {
            if (!$resource->deletable) {
                $this->back('error', 'This resource cannot be deleted.');
            }

            // Bulk must honour every rule the single-row path enforces.
            $skipped = $this->undeletableIds($resource, $ids);
            $ids     = array_values(array_diff($ids, array_keys($skipped)));

            if ($ids === []) {
                $this->back('error', 'Nothing was deleted. ' . $this->blockedSummary($skipped));
            }
        }

        $placeholders = [];
        $bindings     = [];

        foreach ($ids as $index => $id) {
            $placeholders[]            = ':id' . $index;
            $bindings['id' . $index]   = $id;
        }

        $inClause = ' WHERE `id` IN (' . implode(', ', $placeholders) . ')';
        $table    = Database::quoteIdentifier($resource->table);

        // The system guard also lives in the statement, so a record flagged
        // between the check above and the write is still protected.
        $guard = in_array('is_system', $this->tableColumns($resource->table), true)
            ? ' AND `is_system` = 0'
            : '';

        $affected = match ($action) {
            'activate', 'feature'     => Database::execute('UPDATE ' . $table . ' SET ' . Database::quoteIdentifier((string) $column) . ' = 1' . $inClause, $bindings),
            'deactivate', 'unfeature' => Database::execute('UPDATE ' . $table . ' SET ' . Database::quoteIdentifier((string) $column) . ' = 0' . $inClause, $bindings),
            'delete'                  => $resource->softDeletes
                ? Database::execute('UPDATE ' . $table . ' SET `deleted_at` = NOW()' . $inClause . $guard, $bindings)
                : Database::execute('DELETE FROM ' . $table . $inClause . $guard, $bindings),
        };

        $this->afterWrite($resource, null);

        $message = $affected . ' ' . strtolower($resource->plural) . ' updated.';

        if ($skipped !== []) {
            $message .= ' ' . count($skipped) . ' skipped — ' . $this->blockedSummary($skipped);
        }

        $this->log(
            $request,
            $resource->key . '.bulk_' . $action,
            $resource->key,
            null,
            $affected . ' row(s) affected.',
            ['ids' => $ids, 'skipped' => array_keys($skipped)]
        );

        $this->respond($request, $message, '/admin/' . $resource->key);
    }

    /** POST /admin/{resource}/reorder */
    public function reorder(Request $request): never
    {
        $resource = $this->resource($request);
        $this->authorize($request, $resource->managePermission);

        if (!$resource->sortableRows) {
            throw HttpException::unprocessable('This resource is not manually sortable.');
        }

        /** @var list<int> $order ids in ascending sort_order, as the client wants them */
        $order = array_values(array_unique(array_filter(
            array_map('intval', $request->array('order')),
            static fn (int $id): bool => $id > 0
        )));

        if ($order === []) {
            throw HttpException::unprocessable('No rows were sent to reorder.');
        }

        $table = $resource->table;

        $moved = Database::transaction(static function () use ($table, $order): int {
            [$in, $params] = Database::inClause($order);

            $current = Database::select(
                'SELECT `id`, `sort_order` FROM ' . Database::quoteIdentifier($table) . ' WHERE `id` IN ' . $in,
                $params
            );

            if ($current === []) {
                return 0;
            }

            // Reuse the positions these rows already occupy instead of
            // renumbering them 1..n.
            //
            // The list is paginated, so a drag on page 2 used to stamp 1,2,3…
            // over rows that page 1 was already using — silently shuffling
            // records the operator could not even see. Permuting the slots the
            // submitted rows already hold keeps every other page untouched and
            // makes the endpoint safe to call from any page.
            $known = [];

            foreach ($current as $row) {
                $known[(int) $row['id']] = (int) $row['sort_order'];
            }

            // Ids that no longer exist are dropped rather than failing the save.
            $ids   = array_values(array_filter($order, static fn (int $id): bool => isset($known[$id])));
            $slots = array_values($known);
            sort($slots, SORT_NUMERIC);

            // Ties make an order undefined, so force the slots strictly upward.
            $previous = null;

            foreach ($slots as $index => $slot) {
                if ($previous !== null && $slot <= $previous) {
                    $slot = $previous + 1;
                }

                $slots[$index] = $slot;
                $previous      = $slot;
            }

            $written = 0;

            foreach ($ids as $position => $id) {
                if ($known[$id] === $slots[$position]) {
                    continue; // Already where it belongs.
                }

                Database::update($table, ['sort_order' => $slots[$position]], ['id' => $id]);
                $written++;
            }

            return $written;
        });

        $this->afterWrite($resource, null);

        $this->log($request, $resource->key . '.reordered', $resource->key, null, $moved . ' row(s) moved.', ['order' => $order]);

        Response::success(['moved' => $moved], 'Order saved.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function resource(Request $request): Resource
    {
        return Resource::find((string) $request->routeParam('resource', ''));
    }

    /** @return array<string,mixed> */
    private function findRow(Resource $resource, int $id): array
    {
        $sql = 'SELECT * FROM ' . Database::quoteIdentifier($resource->table) . ' WHERE `id` = :id';

        if ($resource->softDeletes) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        $row = Database::selectOne($sql, ['id' => $id]);

        if ($row === null) {
            throw HttpException::notFound($resource->singular . ' not found.');
        }

        return $row;
    }

    /**
     * Normalise submitted data: cast switches, generate slugs, sanitise HTML.
     *
     * @param  array<string,mixed>      $data
     * @param  array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function prepare(Resource $resource, array $data, Request $request, ?array $existing): array
    {
        foreach ($resource->fields as $field) {
            $name = $field['name'];
            $type = $field['type'];

            if ($type === 'switch') {
                // An unchecked checkbox is simply absent from the POST body.
                $data[$name] = $request->bool($name) ? 1 : 0;
                continue;
            }

            if ($type === 'html' && isset($data[$name])) {
                $data[$name] = Str::sanitizeHtml((string) $data[$name]);
                continue;
            }

            if ($type === 'number' && array_key_exists($name, $data)) {
                $data[$name] = ($data[$name] === '' || $data[$name] === null) ? null : (int) $data[$name];
                continue;
            }

            if ($type === 'datetime' && array_key_exists($name, $data)) {
                $value       = (string) $data[$name];
                $data[$name] = $value === '' ? null : date('Y-m-d H:i:s', (int) strtotime($value));
                continue;
            }

            if ($type === 'select' && ($data[$name] ?? '') === '') {
                $data[$name] = null;
            }
        }

        // A blank optional field became an explicit NULL above, which MySQL
        // refuses on a NOT NULL column even when that column has a default —
        // it substitutes the default only for an ABSENT column, never for an
        // explicit NULL. Leaving them in made every popup insert fail with
        // "Column 'trigger_value' cannot be null".
        //
        // Dropping the key instead lets the schema default apply, which is what
        // "left blank" should mean. On an update the key is dropped too, so the
        // stored value is kept rather than being overwritten — also the right
        // reading of a blank optional field.
        $notNull = $this->notNullColumns($resource->table);

        foreach ($data as $column => $value) {
            if ($value === null && isset($notNull[$column])) {
                unset($data[$column]);
            }
        }

        // Slug: generate from the source field when left blank, and keep it unique.
        if ($resource->slugSource !== null) {
            $slug = trim((string) ($data['slug'] ?? ''));

            if ($slug === '') {
                $slug = (string) ($data[$resource->slugSource] ?? ($existing[$resource->slugSource] ?? ''));
            }

            $data['slug'] = Str::uniqueSlug(
                $slug,
                $resource->table,
                'slug',
                $existing !== null ? (int) $existing['id'] : null
            );
        }

        // Publishing a blog for the first time stamps the publish date.
        if ($resource->key === 'blogs') {
            if (($data['status'] ?? '') === 'published' && empty($data['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }

            if (empty($data['author_name'])) {
                $admin                = $this->admin($request);
                $data['author_name']  = (string) $admin['name'];
                $data['author_id']    = (int) $admin['id'];
            }
        }

        if ($resource->key === 'pages') {
            $data['updated_by']   = $this->adminId($request);
            $data['published_at'] = $data['published_at'] ?? date('Y-m-d H:i:s');
        }

        // Only keep keys that are real columns on the table.
        return $this->onlyTableColumns($resource->table, $data);
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function onlyTableColumns(string $table, array $data): array
    {
        return array_intersect_key($data, array_flip($this->tableColumns($table)));
    }

    /**
     * Column names on a table, read at most once per request.
     *
     * @return list<string>
     */
    private function tableColumns(string $table): array
    {
        static $cache = [];

        if (!isset($cache[$table])) {
            /** @var list<string> $columns */
            $columns       = Database::column('SHOW COLUMNS FROM ' . Database::quoteIdentifier($table));
            $cache[$table] = $columns;
        }

        return $cache[$table];
    }

    /**
     * Columns that reject NULL, so a blank form field can be dropped and let
     * the column default apply instead of being written as an explicit NULL.
     *
     * MySQL does not substitute the default for an explicit NULL — it raises
     * "Column 'x' cannot be null" — which is why blanking an optional numeric
     * field made the whole insert fail.
     *
     * @return array<string,true>
     */
    private function notNullColumns(string $table): array
    {
        static $cache = [];

        if (!isset($cache[$table])) {
            $columns = [];

            foreach (Database::select(
                'SELECT `COLUMN_NAME` FROM information_schema.columns
                  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `IS_NULLABLE` = :n',
                ['t' => $table, 'n' => 'NO']
            ) as $row) {
                $columns[(string) $row['COLUMN_NAME']] = true;
            }

            $cache[$table] = $columns;
        }

        return $cache[$table];
    }

    /**
     * Ids that must not be deleted, mapped to the reason why.
     *
     * Both the single-row and the bulk path go through here, so a record the
     * one refuses to delete cannot be destroyed by the other.
     *
     * @param  list<int> $ids
     * @return array<int,string>
     */
    private function undeletableIds(Resource $resource, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $blocked        = [];
        [$in, $params]  = Database::inClause($ids);

        // System rows (Terms, Privacy…) are structural and must survive.
        if (in_array('is_system', $this->tableColumns($resource->table), true)) {
            $system = Database::column(
                'SELECT `id` FROM ' . Database::quoteIdentifier($resource->table)
                . ' WHERE `id` IN ' . $in . ' AND `is_system` = 1',
                $params
            );

            foreach ($system as $id) {
                $blocked[(int) $id] = 'System records cannot be deleted. Deactivate them instead.';
            }
        }

        // A category still attached to a creator would orphan the pivot row.
        if ($resource->key === 'categories') {
            $inUse = Database::select(
                'SELECT `category_id`, COUNT(*) AS `total` FROM `creator_categories`'
                . ' WHERE `category_id` IN ' . $in . ' GROUP BY `category_id`',
                $params
            );

            foreach ($inUse as $row) {
                $blocked[(int) $row['category_id']] = (int) $row['total']
                    . ' creator(s) use this category. Reassign them first, or deactivate it.';
            }
        }

        return $blocked;
    }

    /**
     * One short sentence explaining why rows were left alone.
     *
     * @param array<int,string> $blocked
     */
    private function blockedSummary(array $blocked): string
    {
        return implode(' ', array_slice(array_unique(array_values($blocked)), 0, 2));
    }

    /**
     * File a point-in-time snapshot of a record with RevisionService.
     *
     * Only the entity types RevisionService whitelists can be versioned —
     * onboarding-slides, for one, is a panel resource with no revisions
     * definition, and calling record() for it would only log a warning.
     *
     * `$row` is the state to remember. On an update that is the pre-write row;
     * on a create it is read back after the INSERT so column defaults are part
     * of the baseline. A snapshot identical to the newest one already stored is
     * skipped, which is what keeps "create then edit" from filing the same
     * state twice.
     *
     * Versioning must never be the reason a save fails: RevisionService already
     * swallows its own database errors, and anything unexpected is caught here.
     *
     * @param array<string,mixed>|null $row
     */
    private function snapshot(Request $request, Resource $resource, int $id, string $note, ?array $row = null): void
    {
        if ($id <= 0 || !RevisionService::supports($resource->key)) {
            return;
        }

        try {
            $row ??= Database::selectOne(
                'SELECT * FROM ' . Database::quoteIdentifier($resource->table) . ' WHERE `id` = :id',
                ['id' => $id]
            );

            if ($row === null || $row === []) {
                return;
            }

            $latestVersion = RevisionService::latestVersion($resource->key, $id);

            if ($latestVersion > 0) {
                $latest = Database::selectOne(
                    'SELECT `snapshot` FROM `revisions`
                      WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id AND `version` = :version',
                    ['entity_type' => $resource->key, 'entity_id' => $id, 'version' => $latestVersion]
                );

                if ($latest !== null
                    && RevisionService::diff(RevisionService::decode($latest['snapshot']), $row) === []) {
                    return;
                }
            }

            RevisionService::record($resource->key, $id, $row, $note, $this->admin($request));
        } catch (\Throwable $e) {
            \Zuste\Core\Logger::error('Could not snapshot a resource revision', [
                'resource' => $resource->key,
                'id'       => $id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** Side effects that keep denormalised counters honest. */
    private function afterWrite(Resource $resource, ?int $id): void
    {
        if ($resource->key === 'categories') {
            CreatorService::refreshStatistics();
        }

        if ($resource->key === 'faqs') {
            NotificationService::flushTemplates();
        }
    }
}
