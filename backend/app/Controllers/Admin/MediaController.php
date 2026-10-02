<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use PDOException;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\View;
use Zuste\Helpers\Str;
use Zuste\Services\UploadService;

/**
 * Media library.
 *
 * Two independent notions of "folder" live side by side and are deliberately
 * kept apart:
 *
 *  - `media`.`folder` mirrors the physical storage directory. It is chosen by
 *    UploadService when the bytes are written and is never rewritten
 *    afterwards, because moving a file in the library must not move it on disk.
 *  - `media`.`folder_id` is the logical position in the `media_folders` tree.
 *    That is what this screen shows, filters on and moves files between.
 *
 * `media_folders`.`path` is a materialised path (`marketing/2026/q1`) kept in
 * step with `parent_id` by recomputing the whole moved subtree inside one
 * transaction.
 */
final class MediaController extends BaseAdminController
{
    private const PERMISSION = 'cms.media';

    /** Columns a client may order the grid by. */
    private const SORTABLE = ['created_at', 'size_bytes', 'original_name'];

    /** Coarse MIME buckets offered as a filter. */
    private const TYPE_GROUPS = ['image', 'video', 'audio', 'document'];

    /** Root folders sit at depth 1; deeper than this is refused. */
    private const MAX_DEPTH = 6;

    /** Upper bound on ids accepted by a single bulk action. */
    private const BULK_LIMIT = 500;

    // =================================================================
    // Screens
    // =================================================================

    /** GET /admin/media */
    public function index(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $tree    = $this->tree();
        $filters = $this->filters($request, $tree);
        $listing = $this->fetchFiles($filters, $tree);
        $storage = $this->storage();

        // fetchFiles() may have clamped an out-of-range page; the toolbar and
        // the pagination links must agree with what was actually fetched.
        $filters['page'] = $listing['page'];

        $this->view($request, 'admin.media.index', [
            'pageTitle'     => 'Media library',
            'pageSubtitle'  => number_format((int) $storage['live_files']) . ' files · '
                             . self::bytes((int) $storage['live_bytes']) . ' stored',
            'tree'          => $tree,
            'filters'       => $filters,
            'files'         => $listing['rows'],
            'pagination'    => $listing['pagination'],
            'folderOptions' => $this->folderOptions($tree),
            'gridState'     => $this->gridState($tree, $storage),
            'storage'       => $storage,
            'largest'       => $this->largestFiles(),
            'folderSizes'   => $this->folderSizes($tree),
            'accepted'      => $this->acceptedExtensions(),
            'maxUploadMb'   => $this->maxUploadMb(),
            'maxDepth'      => self::MAX_DEPTH,
        ]);
    }

    /**
     * GET /admin/media/grid
     *
     * The file grid on its own, so folder clicks and filter changes swap the
     * grid in place instead of reloading the shell.
     */
    public function grid(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $tree    = $this->tree();
        $filters = $this->filters($request, $tree);
        $listing = $this->fetchFiles($filters, $tree);
        $storage = $this->storage();

        $filters['page'] = $listing['page'];

        // The partial calls $this->can(); share what the shell would have.
        View::share('admin', $this->admin($request));
        View::share('permissions', (array) $request->attribute('permissions', []));

        Response::html(View::render('admin.media.partials-grid', [
            'filters'       => $filters,
            'files'         => $listing['rows'],
            'pagination'    => $listing['pagination'],
            'folderOptions' => $this->folderOptions($tree),
            'gridState'     => $this->gridState($tree, $storage),
        ]));
    }

    // =================================================================
    // Upload
    // =================================================================

    /**
     * POST /admin/media/upload
     *
     * One file per request: the browser sends them in parallel so each gets
     * its own progress row and its own error message.
     */
    public function upload(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $file = $request->file('file');

        if ($file === null) {
            $this->fail($request, 'No file was received.');
        }

        $folder   = null;
        $folderId = $request->int('folder_id');

        if ($folderId > 0) {
            $folder = Database::selectOne(
                'SELECT `id`, `slug`, `path` FROM `media_folders` WHERE `id` = :id',
                ['id' => $folderId]
            );

            if ($folder === null) {
                $this->fail($request, 'That folder no longer exists. Refresh and try again.');
            }
        }

        $originalName = (string) ($file['name'] ?? 'file');
        $extension    = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $incomingSize = (int) ($file['size'] ?? 0);

        // UploadService owns every safety check: size, extension allow-list,
        // real MIME sniffing, GD re-encoding, random filename, no-exec dir.
        $upload = UploadService::store(
            $file,
            $folder !== null ? (string) $folder['slug'] : 'general',
            $this->kindFor($extension),
            'admin',
            $this->adminId($request)
        );

        // lastInsertId() can come back as 0 when the slow-query recorder writes
        // to query_log between the INSERT and the read. The stored path carries
        // a 16-byte random name, so it identifies the row on its own.
        $mediaId = (int) $upload['id'];

        if ($mediaId <= 0) {
            $mediaId = (int) Database::scalar(
                'SELECT `id` FROM `media` WHERE `path` = :path ORDER BY `id` DESC LIMIT 1',
                ['path' => $upload['path']]
            );
        }

        if ($mediaId <= 0) {
            $this->fail($request, 'The file was stored but could not be registered. Try uploading it again.', 500);
        }

        Database::update('media', [
            'folder_id'           => $folder !== null ? (int) $folder['id'] : null,
            'original_size_bytes' => $incomingSize > 0 ? $incomingSize : null,
            // Images come back smaller after the GD round trip; that is the
            // only case where "optimised" is true rather than a guess.
            'is_optimized'        => ($incomingSize > 0 && (int) $upload['size'] < $incomingSize) ? 1 : 0,
        ], ['id' => $mediaId]);

        if ($folder !== null) {
            $this->recount([(int) $folder['id']]);
        }

        $this->log(
            $request,
            'media.uploaded',
            'media',
            $mediaId,
            'Uploaded ' . $originalName . ' into ' . ($folder === null ? 'Unfiled' : (string) $folder['path']) . '.',
            ['size_bytes' => $upload['size'], 'mime' => $upload['mime']]
        );

        $this->respond($request, 'Uploaded ' . $originalName . '.', '/admin/media', [
            'id'   => $mediaId,
            'url'  => $upload['url'],
            'size' => $upload['size'],
            'name' => $originalName,
        ]);
    }

    // =================================================================
    // File actions
    // =================================================================

    /** POST /admin/media/{id}/update — rename, alt text and folder in one save. */
    public function updateFile(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $id   = $request->routeParamInt('id');
        $file = Database::selectOne('SELECT `id`, `folder_id`, `original_name` FROM `media` WHERE `id` = :id', ['id' => $id]);

        if ($file === null) {
            $this->fail($request, 'That file no longer exists.', 404);
        }

        $data = $this->validate($request, [
            'original_name' => 'required|string|min:1|max:191',
            'alt_text'      => 'nullable|string|max:255',
            'folder_id'     => 'nullable|integer',
        ]);

        // validate() drops optional keys that were not submitted.
        $altText = trim((string) ($data['alt_text'] ?? ''));

        // An empty `folder_id` means "Unfiled"; a *missing* one means "leave it
        // where it is". Collapsing the two would silently unfile a file on any
        // post that does not carry the field.
        $current = $file['folder_id'] === null ? 0 : (int) $file['folder_id'];

        if ($request->has('folder_id')) {
            $rawParent = trim((string) ($data['folder_id'] ?? ''));
            $target    = $rawParent === '' ? 0 : (int) $rawParent;
        } else {
            $target = $current;
        }

        if ($target > 0 && !Database::exists('media_folders', ['id' => $target])) {
            $this->fail($request, 'That folder no longer exists.');
        }

        Database::update('media', [
            'original_name' => mb_substr(trim((string) $data['original_name']), 0, 191),
            'alt_text'      => $altText === '' ? null : $altText,
            'folder_id'     => $target > 0 ? $target : null,
        ], ['id' => $id]);

        $this->recount([(int) ($file['folder_id'] ?? 0), $target]);

        $this->log($request, 'media.updated', 'media', $id, 'Updated ' . $file['original_name'] . '.');

        $this->respond($request, 'File saved.', '/admin/media');
    }

    /** POST /admin/media/{id}/delete — soft delete; the bytes stay on disk. */
    public function destroyFile(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $id   = $request->routeParamInt('id');
        $file = Database::selectOne(
            'SELECT `id`, `folder_id`, `original_name`, `deleted_at` FROM `media` WHERE `id` = :id',
            ['id' => $id]
        );

        if ($file === null) {
            $this->fail($request, 'That file no longer exists.', 404);
        }

        if ($file['deleted_at'] !== null) {
            $this->fail($request, 'That file is already in the trash.');
        }

        Database::update('media', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        $this->recount([(int) ($file['folder_id'] ?? 0)]);

        $this->log($request, 'media.trashed', 'media', $id, 'Moved ' . $file['original_name'] . ' to the trash.');

        $this->respond($request, 'Moved to the trash.', '/admin/media');
    }

    /** POST /admin/media/{id}/restore */
    public function restoreFile(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $id   = $request->routeParamInt('id');
        $file = Database::selectOne(
            'SELECT `id`, `folder_id`, `original_name`, `deleted_at` FROM `media` WHERE `id` = :id',
            ['id' => $id]
        );

        if ($file === null) {
            $this->fail($request, 'That file no longer exists.', 404);
        }

        if ($file['deleted_at'] === null) {
            $this->fail($request, 'That file is not in the trash.');
        }

        Database::execute('UPDATE `media` SET `deleted_at` = NULL WHERE `id` = :id', ['id' => $id]);
        $this->recount([(int) ($file['folder_id'] ?? 0)]);

        $this->log($request, 'media.restored', 'media', $id, 'Restored ' . $file['original_name'] . '.');

        $this->respond($request, 'File restored.', '/admin/media');
    }

    /**
     * POST /admin/media/{id}/purge
     *
     * Permanent: the row goes and the file is unlinked. Only offered for files
     * already in the trash, so a single mis-click can never destroy anything.
     */
    public function purgeFile(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $id   = $request->routeParamInt('id');
        $file = Database::selectOne(
            'SELECT `id`, `folder_id`, `original_name`, `path`, `deleted_at` FROM `media` WHERE `id` = :id',
            ['id' => $id]
        );

        if ($file === null) {
            $this->fail($request, 'That file no longer exists.', 404);
        }

        if ($file['deleted_at'] === null) {
            $this->fail($request, 'Move the file to the trash before deleting it permanently.');
        }

        $this->unlinkStoredFile((string) $file['path']);

        Database::execute('DELETE FROM `media` WHERE `id` = :id', ['id' => $id]);
        $this->recount([(int) ($file['folder_id'] ?? 0)]);

        $this->log($request, 'media.purged', 'media', $id, 'Permanently deleted ' . $file['original_name'] . '.');

        $this->respond($request, 'File deleted permanently.', '/admin/media');
    }

    /** POST /admin/media/bulk — move, trash, restore or purge a selection. */
    public function bulk(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $action = $request->string('action');

        if (!in_array($action, ['move', 'trash', 'restore', 'purge'], true)) {
            $this->fail($request, 'Choose an action for the selected files.');
        }

        $ids = [];
        foreach ($request->array('ids') as $value) {
            if (is_scalar($value) && ctype_digit(trim((string) $value)) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        $ids = array_values($ids);

        if ($ids === []) {
            $this->fail($request, 'Select at least one file first.');
        }

        if (count($ids) > self::BULK_LIMIT) {
            $this->fail($request, 'Select no more than ' . self::BULK_LIMIT . ' files at a time.');
        }

        [$in, $bindings] = $this->namedIn($ids, 'id');

        // Folders whose counters will need recomputing afterwards.
        $touched = array_map(
            static fn (mixed $value): int => (int) $value,
            Database::column("SELECT DISTINCT `folder_id` FROM `media` WHERE `id` IN ($in)", $bindings)
        );

        $now = date('Y-m-d H:i:s');

        // What the audit entry records: the ids actually acted on, which for a
        // purge is only the subset that was already in the trash.
        $loggedIds = $ids;

        switch ($action) {
            case 'move':
                $raw    = trim($request->string('folder_id'));
                $target = $raw === '' ? 0 : (int) $raw;

                if ($target > 0 && !Database::exists('media_folders', ['id' => $target])) {
                    $this->fail($request, 'That folder no longer exists.');
                }

                $affected = Database::execute(
                    "UPDATE `media` SET `folder_id` = :target WHERE `id` IN ($in)",
                    $bindings + ['target' => $target > 0 ? $target : null]
                );

                $touched[] = $target;
                $message   = $affected . ' ' . ($affected === 1 ? 'file' : 'files') . ' moved.';
                break;

            case 'trash':
                $affected = Database::execute(
                    "UPDATE `media` SET `deleted_at` = :now WHERE `id` IN ($in) AND `deleted_at` IS NULL",
                    $bindings + ['now' => $now]
                );
                $message = $affected . ' ' . ($affected === 1 ? 'file' : 'files') . ' moved to the trash.';
                break;

            case 'restore':
                $affected = Database::execute(
                    "UPDATE `media` SET `deleted_at` = NULL WHERE `id` IN ($in) AND `deleted_at` IS NOT NULL",
                    $bindings
                );
                $message = $affected . ' ' . ($affected === 1 ? 'file' : 'files') . ' restored.';
                break;

            default:
                $rows = Database::select(
                    "SELECT `id`, `path` FROM `media` WHERE `id` IN ($in) AND `deleted_at` IS NOT NULL",
                    $bindings
                );

                if ($rows === []) {
                    $this->fail($request, 'Only files already in the trash can be deleted permanently.');
                }

                $purgeIds = [];
                foreach ($rows as $row) {
                    $this->unlinkStoredFile((string) $row['path']);
                    $purgeIds[] = (int) $row['id'];
                }

                [$purgeIn, $purgeBindings] = $this->namedIn($purgeIds, 'pid');
                $affected  = Database::execute("DELETE FROM `media` WHERE `id` IN ($purgeIn)", $purgeBindings);
                $loggedIds = $purgeIds;
                $message   = $affected . ' ' . ($affected === 1 ? 'file' : 'files') . ' deleted permanently.';

                $skipped = count($ids) - count($purgeIds);

                if ($skipped > 0) {
                    $message .= ' ' . $skipped . ' ' . ($skipped === 1 ? 'file was' : 'files were')
                              . ' not in the trash and ' . ($skipped === 1 ? 'was' : 'were') . ' left alone.';
                }
                break;
        }

        $this->recount($touched);

        $this->log($request, 'media.bulk_' . $action, 'media', null, $message, ['ids' => $loggedIds]);

        $this->respond($request, $message, '/admin/media');
    }

    // =================================================================
    // Folders
    // =================================================================

    /** POST /admin/media/folders */
    public function storeFolder(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $data = $this->validate($request, [
            'name'      => 'required|string|min:1|max:100',
            'parent_id' => 'nullable|integer',
        ]);

        $name     = trim((string) $data['name']);
        $rawParent = trim((string) ($data['parent_id'] ?? ''));
        $parentId  = $rawParent === '' ? 0 : (int) $rawParent;
        $parent    = null;

        if ($parentId > 0) {
            $parent = Database::selectOne('SELECT `id`, `path` FROM `media_folders` WHERE `id` = :id', ['id' => $parentId]);

            if ($parent === null) {
                $this->fail($request, 'The parent folder no longer exists.');
            }
        }

        $slug = $this->slugFor($request, $name);

        $path  = $parent === null ? $slug : (string) $parent['path'] . '/' . $slug;
        $depth = substr_count($path, '/') + 1;

        if ($depth > self::MAX_DEPTH) {
            $this->fail($request, 'Folders can be nested ' . self::MAX_DEPTH . ' levels deep at most.');
        }

        if (strlen($path) > 255) {
            $this->fail($request, 'That path is too long. Use a shorter folder name.');
        }

        if (Database::exists('media_folders', ['path' => $path])) {
            $this->fail($request, 'A folder called "' . $name . '" already exists there.');
        }

        try {
            $id = Database::insert('media_folders', [
                'parent_id'  => $parent === null ? null : (int) $parent['id'],
                'name'       => $name,
                'slug'       => $slug,
                'path'       => $path,
                'is_system'  => 0,
                'created_by' => $this->adminId($request),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            // Two admins creating the same folder at once land here.
            if ($this->isUniqueViolation($e)) {
                $this->fail($request, 'A folder called "' . $name . '" already exists there.', 409);
            }

            throw $e;
        }

        // See upload(): lastInsertId() is not always trustworthy here.
        if ($id <= 0) {
            $id = (int) Database::scalar('SELECT `id` FROM `media_folders` WHERE `path` = :path', ['path' => $path]);
        }

        $this->log($request, 'media_folder.created', 'media_folder', $id, 'Created media folder ' . $path . '.');

        $this->respond($request, 'Folder created.', '/admin/media', ['id' => $id, 'path' => $path]);
    }

    /**
     * POST /admin/media/folders/{id}/update
     *
     * Rename and move share one endpoint because both rewrite the materialised
     * path of the folder and of everything beneath it.
     */
    public function updateFolder(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $id     = $request->routeParamInt('id');
        $tree   = $this->tree();
        $folder = $tree['rows'][$id] ?? null;

        if ($folder === null) {
            $this->fail($request, 'That folder no longer exists.', 404);
        }

        if ((int) $folder['is_system'] === 1) {
            $this->fail($request, 'System folders cannot be renamed or moved.');
        }

        $data = $this->validate($request, [
            'name'      => 'required|string|min:1|max:100',
            'parent_id' => 'nullable|integer',
        ]);

        $name      = trim((string) $data['name']);
        $rawParent = trim((string) ($data['parent_id'] ?? ''));
        $parentId  = $rawParent === '' ? 0 : (int) $rawParent;

        if ($parentId === $id) {
            $this->fail($request, 'A folder cannot be moved inside itself.');
        }

        $subtree = $this->descendants($id, $tree['children']);

        if ($parentId > 0 && in_array($parentId, $subtree, true)) {
            $this->fail($request, 'A folder cannot be moved inside one of its own sub-folders.');
        }

        if ($parentId > 0 && !isset($tree['rows'][$parentId])) {
            $this->fail($request, 'The destination folder no longer exists.');
        }

        $slug = $this->slugFor($request, $name);

        $basePath = $parentId > 0
            ? (string) $tree['rows'][$parentId]['path'] . '/' . $slug
            : $slug;

        $baseDepth = substr_count($basePath, '/') + 1;

        if (($baseDepth + $this->subtreeHeight($id, $tree['children']) - 1) > self::MAX_DEPTH) {
            $this->fail($request, 'That move would nest folders more than ' . self::MAX_DEPTH . ' levels deep.');
        }

        $paths = $this->subtreePaths($id, $basePath, $tree);

        foreach ($paths as $path) {
            if (strlen($path) > 255) {
                $this->fail($request, 'That move makes a path longer than 255 characters. Use shorter names.');
            }
        }

        // Anything outside the moving subtree that already owns one of the new
        // paths blocks the move — report it instead of hitting the unique key.
        [$idIn, $idBindings]     = $this->namedIn(array_keys($paths), 'sid');
        [$pathIn, $pathBindings] = $this->namedIn(array_values($paths), 'p');

        $clash = Database::scalar(
            "SELECT `path` FROM `media_folders` WHERE `id` NOT IN ($idIn) AND `path` IN ($pathIn) LIMIT 1",
            $idBindings + $pathBindings
        );

        if ($clash !== null) {
            $this->fail($request, 'A folder already exists at ' . (string) $clash . '.', 409);
        }

        $previousPath = (string) $folder['path'];

        try {
            Database::transaction(static function () use ($paths, $id, $parentId, $name, $slug): void {
                // Park every path on a value no slug can produce, so rewriting
                // the subtree cannot trip the unique key half way through.
                foreach (array_keys($paths) as $folderId) {
                    Database::execute(
                        'UPDATE `media_folders` SET `path` = :path WHERE `id` = :id',
                        ['path' => '~pending~' . $folderId, 'id' => $folderId]
                    );
                }

                Database::execute(
                    'UPDATE `media_folders` SET `name` = :name, `slug` = :slug, `parent_id` = :parent WHERE `id` = :id',
                    ['name' => $name, 'slug' => $slug, 'parent' => $parentId > 0 ? $parentId : null, 'id' => $id]
                );

                foreach ($paths as $folderId => $path) {
                    Database::execute(
                        'UPDATE `media_folders` SET `path` = :path WHERE `id` = :id',
                        ['path' => $path, 'id' => $folderId]
                    );
                }
            });
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                $this->fail($request, 'Another folder already uses that path. Refresh and try again.', 409);
            }

            throw $e;
        }

        $this->log(
            $request,
            'media_folder.updated',
            'media_folder',
            $id,
            'Moved media folder ' . $previousPath . ' to ' . $basePath . '.',
            ['subtree' => count($paths)]
        );

        $this->respond($request, 'Folder saved.', '/admin/media', ['path' => $basePath]);
    }

    /**
     * POST /admin/media/folders/{id}/delete
     *
     * Rules, both enforced here and stated on screen:
     *  - a folder holding sub-folders is refused outright;
     *  - a folder holding files is refused unless `delete_files=1` is sent,
     *    which trashes every live file in it and detaches all of them to
     *    Unfiled so no row is left pointing at a folder that has gone.
     */
    public function destroyFolder(Request $request): never
    {
        $this->authorize($request, self::PERMISSION);

        $id     = $request->routeParamInt('id');
        $tree   = $this->tree();
        $folder = $tree['rows'][$id] ?? null;

        if ($folder === null) {
            $this->fail($request, 'That folder no longer exists.', 404);
        }

        if ((int) $folder['is_system'] === 1) {
            $this->fail($request, 'System folders cannot be deleted.');
        }

        $childCount = count($tree['children'][$id] ?? []);

        if ($childCount > 0) {
            $this->fail(
                $request,
                'Delete or move its ' . $childCount . ' sub-folder' . ($childCount === 1 ? '' : 's') . ' first.'
            );
        }

        $counts = Database::selectOne(
            'SELECT COUNT(*) AS `total`, COALESCE(SUM(`deleted_at` IS NULL), 0) AS `live`
               FROM `media` WHERE `folder_id` = :id',
            ['id' => $id]
        ) ?? ['total' => 0, 'live' => 0];

        $total = (int) $counts['total'];
        $live  = (int) $counts['live'];

        if ($total > 0 && !$request->bool('delete_files')) {
            $this->fail(
                $request,
                'This folder holds ' . $total . ' file' . ($total === 1 ? '' : 's') . '. '
                . 'Confirm "also move its files to the trash" to delete it.',
                409
            );
        }

        $alreadyTrashed = $total - $live;

        $now = date('Y-m-d H:i:s');

        Database::transaction(static function () use ($id, $now): void {
            Database::execute(
                'UPDATE `media`
                    SET `deleted_at` = COALESCE(`deleted_at`, :now), `folder_id` = NULL
                  WHERE `folder_id` = :id',
                ['now' => $now, 'id' => $id]
            );

            Database::execute('DELETE FROM `media_folders` WHERE `id` = :id', ['id' => $id]);
        });

        $this->log(
            $request,
            'media_folder.deleted',
            'media_folder',
            $id,
            'Deleted media folder ' . (string) $folder['path'] . '.',
            ['files_trashed' => $live, 'files_detached' => $total]
        );

        $message = 'Folder deleted.';

        if ($live > 0) {
            $message .= ' ' . $live . ' file' . ($live === 1 ? '' : 's') . ' moved to the trash.';
        }

        if ($alreadyTrashed > 0) {
            $message .= ' ' . $alreadyTrashed . ' file' . ($alreadyTrashed === 1 ? '' : 's')
                      . ' already in the trash ' . ($alreadyTrashed === 1 ? 'is' : 'are') . ' now unfiled.';
        }

        $this->respond($request, $message, '/admin/media');
    }

    // =================================================================
    // Tree
    // =================================================================

    /**
     * The folder tree with live per-folder and rolled-up counts.
     *
     * Two aggregate queries in total, whatever the tree size — the roll-up is
     * folded in PHP over those aggregates, never over the media rows.
     *
     * @return array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}}
     */
    private function tree(): array
    {
        $folders = Database::select(
            'SELECT `id`, `parent_id`, `name`, `slug`, `path`, `is_system`
               FROM `media_folders`
              ORDER BY `path` ASC'
        );

        // `held` counts trashed rows too: they still carry the folder_id, and
        // deleting the folder has to detach them, so the delete confirmation
        // must be offered on that number rather than on the live one.
        $totals = Database::select(
            'SELECT `folder_id`,
                    COALESCE(SUM(`deleted_at` IS NULL), 0) AS `files`,
                    COALESCE(SUM(CASE WHEN `deleted_at` IS NULL THEN `size_bytes` ELSE 0 END), 0) AS `bytes`,
                    COUNT(*) AS `held`
               FROM `media`
              GROUP BY `folder_id`'
        );

        $own     = [];
        $unfiled = ['files' => 0, 'bytes' => 0, 'held' => 0];

        foreach ($totals as $row) {
            $entry = [
                'files' => (int) $row['files'],
                'bytes' => (int) $row['bytes'],
                'held'  => (int) $row['held'],
            ];

            if ($row['folder_id'] === null) {
                $unfiled = $entry;
                continue;
            }

            $own[(int) $row['folder_id']] = $entry;
        }

        $rows     = [];
        $children = [];

        foreach ($folders as $folder) {
            $id        = (int) $folder['id'];
            $parent    = $folder['parent_id'] === null ? 0 : (int) $folder['parent_id'];
            $rows[$id] = $folder + [
                'files' => $own[$id]['files'] ?? 0,
                'bytes' => $own[$id]['bytes'] ?? 0,
                'held'  => $own[$id]['held'] ?? 0,
                'depth' => substr_count((string) $folder['path'], '/') + 1,
            ];

            $children[$parent][] = $id;
        }

        // A parent that is missing (or a cycle introduced outside this screen)
        // would otherwise hide folders entirely, so re-root any orphan.
        foreach ($children as $parent => $ids) {
            if ($parent !== 0 && !isset($rows[$parent])) {
                foreach ($ids as $orphan) {
                    $children[0][] = $orphan;
                }

                unset($children[$parent]);
            }
        }

        $rollup  = [];
        $collect = static function (int $id) use (&$collect, &$rollup, $children, $rows): array {
            if (isset($rollup[$id])) {
                return $rollup[$id];
            }

            // Seeded before recursion so a cycle terminates instead of hanging.
            $rollup[$id] = ['files' => 0, 'bytes' => 0];

            $files = (int) ($rows[$id]['files'] ?? 0);
            $bytes = (int) ($rows[$id]['bytes'] ?? 0);

            foreach ($children[$id] ?? [] as $childId) {
                $child  = $collect((int) $childId);
                $files += $child['files'];
                $bytes += $child['bytes'];
            }

            return $rollup[$id] = ['files' => $files, 'bytes' => $bytes];
        };

        foreach (array_keys($rows) as $id) {
            $collect($id);
        }

        return [
            'rows'     => $rows,
            'children' => $children,
            'rollup'   => $rollup,
            'unfiled'  => $unfiled,
        ];
    }

    /**
     * The folder and everything beneath it.
     *
     * @param  array<int,list<int>> $children
     * @return list<int>
     */
    private function descendants(int $id, array $children): array
    {
        $ids   = [];
        $seen  = [];
        $stack = [$id];

        while ($stack !== []) {
            $current = (int) array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $ids[]          = $current;

            foreach ($children[$current] ?? [] as $childId) {
                $stack[] = (int) $childId;
            }
        }

        return $ids;
    }

    /** @param array<int,list<int>> $children */
    private function subtreeHeight(int $id, array $children, int $guard = 0): int
    {
        if ($guard > self::MAX_DEPTH) {
            return 1;
        }

        $height = 1;

        foreach ($children[$id] ?? [] as $childId) {
            $height = max($height, 1 + $this->subtreeHeight((int) $childId, $children, $guard + 1));
        }

        return $height;
    }

    /**
     * New materialised paths for a subtree rooted at a new base path.
     *
     * @param  array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}} $tree
     * @return array<int,string>
     */
    private function subtreePaths(int $rootId, string $basePath, array $tree): array
    {
        $paths = [$rootId => $basePath];
        $stack = [$rootId];
        $seen  = [$rootId => true];

        while ($stack !== []) {
            $current = (int) array_pop($stack);

            foreach ($tree['children'][$current] ?? [] as $childId) {
                $childId = (int) $childId;

                if (isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId]  = true;
                $paths[$childId] = $paths[$current] . '/' . (string) $tree['rows'][$childId]['slug'];
                $stack[]         = $childId;
            }
        }

        return $paths;
    }

    /**
     * Flat, indented folder list for the move selects.
     *
     * @param  array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}} $tree
     * @return list<array{id:int,label:string,path:string,depth:int}>
     */
    private function folderOptions(array $tree): array
    {
        $options = [];

        $walk = static function (int $parent, int $depth) use (&$walk, $tree, &$options): void {
            foreach ($tree['children'][$parent] ?? [] as $childId) {
                $childId = (int) $childId;

                if (!isset($tree['rows'][$childId])) {
                    continue;
                }

                $options[] = [
                    // Non-breaking spaces: an <option> collapses ordinary ones.
                    'id'    => $childId,
                    'label' => str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . (string) $tree['rows'][$childId]['name'],
                    'path'  => (string) $tree['rows'][$childId]['path'],
                    'depth' => $depth,
                ];

                $walk($childId, $depth + 1);
            }
        };

        $walk(0, 0);

        return $options;
    }

    /**
     * Per-folder usage, largest first, for the storage card.
     *
     * @param  array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}} $tree
     * @return list<array{name:string,path:string,files:int,bytes:int}>
     */
    private function folderSizes(array $tree): array
    {
        $sizes = [];

        foreach ($tree['rows'] as $id => $row) {
            $sizes[] = [
                'name'  => (string) $row['name'],
                'path'  => (string) $row['path'],
                'files' => (int) $row['files'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        if ($tree['unfiled']['files'] > 0) {
            $sizes[] = [
                'name'  => 'Unfiled',
                'path'  => '—',
                'files' => (int) $tree['unfiled']['files'],
                'bytes' => (int) $tree['unfiled']['bytes'],
            ];
        }

        usort($sizes, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $sizes;
    }

    // =================================================================
    // Listing
    // =================================================================

    /**
     * Normalise every filter the grid understands, rejecting anything unknown.
     *
     * @param  array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}} $tree
     * @return array<string,mixed>
     */
    private function filters(Request $request, array $tree): array
    {
        $params = $this->listParams($request, self::SORTABLE, 'created_at');

        $folder = $request->string('folder');

        if ($folder !== 'unfiled'
            && ($folder === '' || !ctype_digit($folder) || !isset($tree['rows'][(int) $folder]))) {
            $folder = '';
        }

        $type = $request->string('type');

        if (!in_array($type, self::TYPE_GROUPS, true)) {
            $type = '';
        }

        return [
            'q'        => $params['search'],
            'folder'   => $folder,
            'type'     => $type,
            'state'    => $request->string('state') === 'trashed' ? 'trashed' : 'active',
            'sub'      => $request->string('sub') === '1' ? '1' : '',
            'from'     => $params['from'],
            'to'       => $params['to'],
            'sort_by'  => $params['sort_by'],
            'sort_dir' => $params['sort_dir'],
            'page'     => $params['page'],
            'per_page' => $params['per_page'],
            'offset'   => $params['offset'],
        ];
    }

    /**
     * @param  array<string,mixed> $filters
     * @param  array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}} $tree
     * @return array{rows:list<array<string,mixed>>,page:int,pagination:array{total:int,page:int,per_page:int,last_page:int,from:int,to:int}}
     */
    private function fetchFiles(array $filters, array $tree): array
    {
        // Every column is qualified: `admins` and `users` both carry
        // created_at and deleted_at, so bare names would be ambiguous.
        $clauses  = [$filters['state'] === 'trashed' ? 'm.`deleted_at` IS NOT NULL' : 'm.`deleted_at` IS NULL'];
        $bindings = [];

        if ($filters['folder'] === 'unfiled') {
            $clauses[] = 'm.`folder_id` IS NULL';
        } elseif ($filters['folder'] !== '') {
            $folderId = (int) $filters['folder'];
            $ids      = $filters['sub'] === '1'
                ? $this->descendants($folderId, $tree['children'])
                : [$folderId];

            [$in, $inBindings] = $this->namedIn($ids, 'fid');

            $clauses[] = 'm.`folder_id` IN (' . $in . ')';
            $bindings += $inBindings;
        }

        if ($filters['q'] !== '') {
            $clauses[]          = "(m.`original_name` LIKE :search ESCAPE '\\\\' OR m.`alt_text` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm((string) $filters['q']);
        }

        if ($filters['type'] !== '') {
            // Literal, chosen from a fixed set — nothing user supplied lands here.
            $clauses[] = match ($filters['type']) {
                'image' => "m.`mime_type` LIKE 'image/%'",
                'video' => "m.`mime_type` LIKE 'video/%'",
                'audio' => "m.`mime_type` LIKE 'audio/%'",
                default => "(m.`mime_type` NOT LIKE 'image/%' AND m.`mime_type` NOT LIKE 'video/%' AND m.`mime_type` NOT LIKE 'audio/%')",
            };
        }

        $this->applyDateRange($clauses, $bindings, 'm.`created_at`', (string) $filters['from'], (string) $filters['to']);

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM `media` m WHERE $where", $bindings);

        $sortBy  = (string) $filters['sort_by'];
        $sortDir = (string) $filters['sort_dir'];
        $limit   = (int) $filters['per_page'];

        // Trashing the last rows of the final page — or an edited URL — would
        // otherwise land on an empty page that renders "No files yet" with no
        // pagination to escape through. Clamp to the last page that exists.
        $lastPage = (int) max(1, (int) ceil($total / max(1, $limit)));
        $page     = min(max(1, (int) $filters['page']), $lastPage);
        $offset   = ($page - 1) * $limit;

        $rows = $total === 0 ? [] : Database::select(
            "SELECT m.`id`, m.`folder_id`, m.`original_name`, m.`file_name`, m.`url`, m.`thumbnail_url`,
                    m.`mime_type`, m.`extension`, m.`size_bytes`, m.`original_size_bytes`, m.`is_optimized`,
                    m.`width`, m.`height`, m.`duration_secs`, m.`alt_text`, m.`uploader_type`, m.`uploader_id`,
                    m.`created_at`, m.`deleted_at`,
                    f.`name` AS `folder_name`, f.`path` AS `folder_path`,
                    COALESCE(a.`name`, u.`name`) AS `uploader_name`
               FROM `media` m
               LEFT JOIN `media_folders` f ON f.`id` = m.`folder_id`
               LEFT JOIN `admins` a ON a.`id` = m.`uploader_id` AND m.`uploader_type` = 'admin'
               LEFT JOIN `users`  u ON u.`id` = m.`uploader_id` AND m.`uploader_type` = 'user'
              WHERE $where
              ORDER BY m.`$sortBy` $sortDir, m.`id` DESC
              LIMIT $limit OFFSET $offset",
            $bindings
        );

        return [
            'rows'       => $rows,
            'page'       => $page,
            'pagination' => $this->paginationMeta($total, $page, $limit),
        ];
    }

    /**
     * Library totals in one aggregate pass.
     *
     * @return array<string,int>
     */
    private function storage(): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS `all_files`,
                    COALESCE(SUM(`deleted_at` IS NULL), 0) AS `live_files`,
                    COALESCE(SUM(CASE WHEN `deleted_at` IS NULL THEN `size_bytes` ELSE 0 END), 0) AS `live_bytes`,
                    COALESCE(SUM(`deleted_at` IS NOT NULL), 0) AS `trashed_files`,
                    COALESCE(SUM(CASE WHEN `deleted_at` IS NOT NULL THEN `size_bytes` ELSE 0 END), 0) AS `trashed_bytes`,
                    COALESCE(SUM(`mime_type` LIKE \'image/%\'), 0) AS `image_files`,
                    COALESCE(SUM(`mime_type` LIKE \'video/%\'), 0) AS `video_files`
               FROM `media`'
        ) ?? [];

        $keys   = ['all_files', 'live_files', 'live_bytes', 'trashed_files', 'trashed_bytes', 'image_files', 'video_files'];
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = (int) ($row[$key] ?? 0);
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function largestFiles(): array
    {
        return Database::select(
            'SELECT m.`id`, m.`original_name`, m.`size_bytes`, m.`mime_type`, m.`url`, f.`path` AS `folder_path`
               FROM `media` m
               LEFT JOIN `media_folders` f ON f.`id` = m.`folder_id`
              WHERE m.`deleted_at` IS NULL
              ORDER BY m.`size_bytes` DESC, m.`id` DESC
              LIMIT 10'
        );
    }

    /**
     * Fresh counts shipped alongside every grid render, so the tree badges and
     * the storage card stay honest after an AJAX refresh.
     *
     * @param  array{rows:array<int,array<string,mixed>>,children:array<int,list<int>>,rollup:array<int,array{files:int,bytes:int}>,unfiled:array{files:int,bytes:int,held:int}} $tree
     * @param  array<string,int> $storage
     * @return array<string,mixed>
     */
    private function gridState(array $tree, array $storage): array
    {
        $folders = [];

        foreach ($tree['rows'] as $id => $row) {
            $folders[] = [
                'id'    => (int) $id,
                'files' => (int) ($tree['rollup'][$id]['files'] ?? 0),
                'size'  => self::bytes((int) ($tree['rollup'][$id]['bytes'] ?? 0)),
            ];
        }

        return [
            'folders' => $folders,
            'unfiled' => [
                'files' => (int) $tree['unfiled']['files'],
                'size'  => self::bytes((int) $tree['unfiled']['bytes']),
            ],
            'storage' => [
                'live_files'    => $storage['live_files'],
                'live_size'     => self::bytes($storage['live_bytes']),
                'trashed_files' => $storage['trashed_files'],
                'trashed_size'  => self::bytes($storage['trashed_bytes']),
            ],
        ];
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Recompute the stored counters for the folders given.
     *
     * The aggregate is done by MySQL; PHP never sees a media row.
     *
     * @param list<int|string> $folderIds
     */
    private function recount(array $folderIds): void
    {
        $ids = [];

        foreach ($folderIds as $value) {
            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        foreach ($ids as $id) {
            Database::execute(
                'UPDATE `media_folders` f
                    SET f.`file_count` = (SELECT COUNT(*) FROM `media` m
                                           WHERE m.`folder_id` = f.`id` AND m.`deleted_at` IS NULL),
                        f.`size_bytes` = (SELECT COALESCE(SUM(m.`size_bytes`), 0) FROM `media` m
                                           WHERE m.`folder_id` = f.`id` AND m.`deleted_at` IS NULL)
                  WHERE f.`id` = :id',
                ['id' => $id]
            );
        }
    }

    /**
     * Build a bound `IN (:p0, :p1, …)` fragment.
     *
     * Database::inClause() is positional, which cannot be mixed with the named
     * bindings the rest of these queries use.
     *
     * @param  list<int|string> $values
     * @return array{0:string,1:array<string,mixed>}
     */
    private function namedIn(array $values, string $prefix): array
    {
        if ($values === []) {
            return ['NULL', []];
        }

        $names    = [];
        $bindings = [];

        foreach (array_values($values) as $index => $value) {
            $name            = $prefix . $index;
            $names[]         = ':' . $name;
            $bindings[$name] = $value;
        }

        return [implode(', ', $names), $bindings];
    }

    /**
     * Folder slug from a folder name.
     *
     * Str::slug() never returns an empty string — for input with no letters or
     * digits it invents `item-<random>`, which would silently give the folder a
     * meaningless path. Reject that input instead of accepting the invention.
     */
    private function slugFor(Request $request, string $name): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $name) !== 1) {
            $this->fail($request, 'Use at least one letter or number in the folder name.');
        }

        return mb_substr(Str::slug($name), 0, 100);
    }

    /** Which UploadService limit set an extension belongs to. */
    private function kindFor(string $extension): string
    {
        if (in_array($extension, array_map('strval', Config::settingArray('upload', 'allowed_video_types', ['mp4', 'mov', 'webm'])), true)) {
            return 'video';
        }

        if (in_array($extension, array_map('strval', Config::settingArray('upload', 'allowed_image_types', ['jpg', 'jpeg', 'png', 'webp'])), true)) {
            return 'image';
        }

        return 'document';
    }

    /**
     * Everything the configured limits actually accept, for the file input and
     * the on-screen hint.
     *
     * @return list<string>
     */
    private function acceptedExtensions(): array
    {
        $extensions = [];

        foreach (['allowed_image_types', 'allowed_video_types', 'allowed_document_types'] as $key) {
            foreach (Config::settingArray('upload', $key, []) as $value) {
                $extension = strtolower(trim((string) $value));

                if ($extension !== '') {
                    $extensions[$extension] = $extension;
                }
            }
        }

        $list = array_values($extensions);
        sort($list);

        return $list;
    }

    private function maxUploadMb(): int
    {
        return max(
            Config::settingInt('upload', 'max_image_mb', 5),
            Config::settingInt('upload', 'max_video_mb', 50),
            Config::settingInt('upload', 'max_document_mb', 10)
        );
    }

    /**
     * Remove a stored file, refusing to touch anything outside the uploads
     * root even if a path column were ever tampered with.
     */
    private function unlinkStoredFile(string $relativePath): void
    {
        if ($relativePath === '') {
            return;
        }

        $root = realpath(rtrim((string) Config::get('storage.upload_path'), '/\\'));

        if ($root === false) {
            return;
        }

        $target = realpath($root . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], $relativePath));

        if ($target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_file($target)) {
            return;
        }

        @unlink($target);
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000'
            || in_array((string) ($e->errorInfo[1] ?? ''), ['1062'], true);
    }

    /** Report a rule violation as JSON for AJAX, as a toast for a plain post. */
    private function fail(Request $request, string $message, int $status = 422): never
    {
        if ($request->expectsJson()) {
            Response::error($message, $status, 'media_rejected');
        }

        $this->back('error', $message, '/admin/media');
    }

    /** Human readable byte size. */
    public static function bytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = (int) min(floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $index);

        return ($index === 0 ? (string) (int) $value : number_format($value, $value >= 100 ? 0 : 1)) . ' ' . $units[$index];
    }

    /**
     * Bucket a MIME type for the type icon and the filter chips.
     */
    public static function group(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default                              => 'document',
        };
    }
}

/*
 * Deliberately not used here: UploadService::delete(). It unlinks the file and
 * sets deleted_at in one step, which would make "restore" restore a row whose
 * bytes had already gone. Soft delete keeps the file; purgeFile() unlinks.
 */
