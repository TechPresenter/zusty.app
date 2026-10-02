<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Helpers\Str;

/**
 * Navigation menu builder.
 *
 * A menu is a named container bound to one surface (the app drawer, the web
 * footer…); its items form a two-level tree held in `menu_items` through
 * `parent_id` and `sort_order`. The builder screen edits that whole tree in
 * the browser and saves it back as one atomic payload.
 *
 * There is no dedicated menus permission in the RBAC seed, so reading uses
 * `cms.view` and every write uses `cms.pages`.
 */
final class MenuController extends BaseAdminController
{
    private const VIEW_PERMISSION   = 'cms.view';
    private const MANAGE_PERMISSION = 'cms.pages';

    private const SORTABLE = ['name', 'slug', 'location', 'is_active', 'created_at', 'updated_at'];

    /** @var array<string,string> */
    private const LOCATIONS = [
        'app_drawer' => 'App drawer',
        'app_bottom' => 'App bottom bar',
        'web_header' => 'Website header',
        'web_footer' => 'Website footer',
        'admin'      => 'Admin panel',
    ];

    /** @var array<string,string> */
    private const LINK_TYPES = [
        'screen'   => 'App screen',
        'page'     => 'CMS page',
        'category' => 'Category',
        'url'      => 'External URL',
        'none'     => 'No link (label only)',
    ];

    /** @var array<string,string> */
    private const AUDIENCES = [
        'all'      => 'Everyone',
        'brands'   => 'Brands',
        'creators' => 'Creators',
        'guests'   => 'Signed-out visitors',
    ];

    /** `menu_items`.`sort_order` is a SMALLINT. */
    private const MAX_SORT = 32767;

    /** Parent + child. A grandchild is refused server-side. */
    private const MAX_DEPTH = 2;

    // =================================================================
    // Menus
    // =================================================================

    /** GET /admin/menus */
    public function index(Request $request): never
    {
        $this->authorize($request, self::VIEW_PERMISSION);

        $params   = $this->listParams($request, self::SORTABLE, 'created_at');
        $location = $request->string('location');
        $state    = $request->string('state');

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(m.`name` LIKE :search ESCAPE '\\\\' OR m.`slug` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if (array_key_exists($location, self::LOCATIONS)) {
            $clauses[]            = 'm.`location` = :location';
            $bindings['location'] = $location;
        }

        if ($state === 'active' || $state === 'inactive') {
            $clauses[]         = 'm.`is_active` = :state';
            $bindings['state'] = $state === 'active' ? 1 : 0;
        }

        $this->applyDateRange($clauses, $bindings, 'm.`created_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM `menus` m WHERE $where", $bindings);

        $sort = Database::quoteIdentifier($params['sort_by']);

        $rows = Database::select(
            "SELECT m.*,
                    (SELECT COUNT(*) FROM `menu_items` i WHERE i.`menu_id` = m.`id`)                        AS `item_count`,
                    (SELECT COUNT(*) FROM `menu_items` i WHERE i.`menu_id` = m.`id` AND i.`is_active` = 1)  AS `active_count`
               FROM `menus` m
              WHERE $where
              ORDER BY m.$sort {$params['sort_dir']}, m.`id` DESC
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                "SELECT m.`name`, m.`slug`, m.`location`, m.`description`, m.`is_active`,
                        (SELECT COUNT(*) FROM `menu_items` i WHERE i.`menu_id` = m.`id`) AS `item_count`,
                        m.`created_at`
                   FROM `menus` m
                  WHERE $where
                  ORDER BY m.`name` ASC
                  LIMIT 5000",
                $bindings
            );

            $this->exportCsv(
                'menus',
                ['Name', 'Slug', 'Location', 'Description', 'Active', 'Items', 'Created'],
                $all,
                ['name', 'slug', 'location', 'description', 'is_active', 'item_count', 'created_at']
            );
        }

        $this->view($request, 'admin.menus.index', [
            'pageTitle'    => 'Menus',
            'pageSubtitle' => 'Navigation for the apps and the website.',
            'rows'         => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'locations'    => self::LOCATIONS,
            'canManage'    => $this->canManage($request),
            'filters'      => [
                'q'        => $params['search'],
                'location' => $location,
                'state'    => $state,
                'from'     => $params['from'],
                'to'       => $params['to'],
                'sort_by'  => $params['sort_by'],
                'sort_dir' => $params['sort_dir'],
            ],
        ]);
    }

    /** POST /admin/menus */
    public function store(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:100',
            'slug'        => 'nullable|string|max:100',
            'location'    => 'required|in:' . implode(',', array_keys(self::LOCATIONS)),
            'description' => 'nullable|string|max:255',
        ]);

        $slug = self::slugFor((string) ($data['slug'] ?? ''), (string) $data['name']);

        $id = Database::insert('menus', [
            'name'        => $data['name'],
            'slug'        => $slug,
            'location'    => $data['location'],
            'description' => ($data['description'] ?? '') !== '' ? $data['description'] : null,
            'is_active'   => $request->bool('is_active') ? 1 : 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->log($request, 'menu.created', 'menu', $id, 'Created the menu ' . $data['name'] . '.');

        $this->respond($request, 'Menu created. Add its items in the builder.', '/admin/menus/' . $id);
    }

    /** POST /admin/menus/{id} */
    public function update(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $menu = $this->menuOrFail($request->routeParamInt('id'));
        $id   = (int) $menu['id'];

        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:100',
            'slug'        => 'nullable|string|max:100',
            'location'    => 'required|in:' . implode(',', array_keys(self::LOCATIONS)),
            'description' => 'nullable|string|max:255',
        ]);

        $slug = self::slugFor((string) ($data['slug'] ?? ''), (string) $data['name'], $id);

        Database::update('menus', [
            'name'        => $data['name'],
            'slug'        => $slug,
            'location'    => $data['location'],
            'description' => ($data['description'] ?? '') !== '' ? $data['description'] : null,
            'is_active'   => $request->bool('is_active') ? 1 : 0,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        $this->log($request, 'menu.updated', 'menu', $id, 'Updated the menu ' . $data['name'] . '.', [
            'before' => ['name' => $menu['name'], 'slug' => $menu['slug'], 'location' => $menu['location']],
            'after'  => ['name' => $data['name'], 'slug' => $slug, 'location' => $data['location']],
        ]);

        $this->respond($request, 'Menu saved.', '/admin/menus/' . $id);
    }

    /** POST /admin/menus/{id}/delete */
    public function destroy(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $menu = $this->menuOrFail($request->routeParamInt('id'));
        $id   = (int) $menu['id'];

        $itemCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM `menu_items` WHERE `menu_id` = :menu',
            ['menu' => $id]
        );

        Database::transaction(static function () use ($id): void {
            // Children first: `menu_items`.`parent_id` points back at the same
            // table, so removing parents before children would rely on the
            // cascade rather than on this statement.
            Database::execute(
                'DELETE FROM `menu_items` WHERE `menu_id` = :menu AND `parent_id` IS NOT NULL',
                ['menu' => $id]
            );
            Database::execute('DELETE FROM `menu_items` WHERE `menu_id` = :menu', ['menu' => $id]);
            Database::execute('DELETE FROM `menus` WHERE `id` = :id', ['id' => $id]);
        });

        $this->log(
            $request,
            'menu.deleted',
            'menu',
            $id,
            'Deleted the menu ' . $menu['name'] . ' and ' . $itemCount . ' item(s).',
            ['slug' => $menu['slug'], 'items_removed' => $itemCount]
        );

        $this->respond(
            $request,
            'Menu deleted along with ' . $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . '.',
            '/admin/menus'
        );
    }

    /** POST /admin/menus/{id}/duplicate */
    public function duplicate(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $menu = $this->menuOrFail($request->routeParamInt('id'));
        $id   = (int) $menu['id'];

        $name = mb_substr($menu['name'] . ' copy', 0, 100);
        $slug = self::slugFor('', $name);

        $items = Database::select(
            'SELECT * FROM `menu_items` WHERE `menu_id` = :menu ORDER BY `sort_order` ASC, `id` ASC',
            ['menu' => $id]
        );

        $newId = Database::transaction(static function () use ($menu, $name, $slug, $items): int {
            $newMenuId = Database::insert('menus', [
                'name'        => $name,
                'slug'        => $slug,
                'location'    => $menu['location'],
                'description' => $menu['description'],
                // A copy starts switched off so it cannot go live by accident.
                'is_active'   => 0,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            $idMap = [];

            // Roots first so a child always has its new parent id available.
            foreach ([true, false] as $rootsPass) {
                foreach ($items as $item) {
                    $isRoot = $item['parent_id'] === null;

                    if ($isRoot !== $rootsPass) {
                        continue;
                    }

                    $parent = null;

                    if (!$isRoot) {
                        $oldParent = (int) $item['parent_id'];

                        // A child whose parent was not copied would dangle.
                        if (!isset($idMap[$oldParent])) {
                            continue;
                        }

                        $parent = $idMap[$oldParent];
                    }

                    $idMap[(int) $item['id']] = Database::insert('menu_items', [
                        'menu_id'       => $newMenuId,
                        'parent_id'     => $parent,
                        'label'         => $item['label'],
                        'icon'          => $item['icon'],
                        'link_type'     => $item['link_type'],
                        'link_value'    => $item['link_value'],
                        'badge_text'    => $item['badge_text'],
                        'badge_color'   => $item['badge_color'],
                        'audience'      => $item['audience'],
                        'open_external' => (int) $item['open_external'],
                        'is_active'     => (int) $item['is_active'],
                        'sort_order'    => (int) $item['sort_order'],
                    ]);
                }
            }

            return $newMenuId;
        });

        $this->log(
            $request,
            'menu.duplicated',
            'menu',
            $newId,
            'Duplicated ' . $menu['name'] . ' as ' . $name . '.',
            ['source_menu_id' => $id, 'items_copied' => count($items)]
        );

        $this->respond(
            $request,
            'Copied ' . count($items) . ' item' . (count($items) === 1 ? '' : 's') . ' into a switched-off draft.',
            '/admin/menus/' . $newId
        );
    }

    // =================================================================
    // Builder
    // =================================================================

    /** GET /admin/menus/{id} */
    public function builder(Request $request): never
    {
        $this->authorize($request, self::VIEW_PERMISSION);

        $menu = $this->menuOrFail($request->routeParamInt('id'));
        $id   = (int) $menu['id'];

        $rows = Database::select(
            'SELECT * FROM `menu_items` WHERE `menu_id` = :menu ORDER BY `sort_order` ASC, `id` ASC',
            ['menu' => $id]
        );

        [$tree, $repaired] = self::buildTree($rows);

        $editId   = $request->int('item');
        $editItem = null;

        if ($editId > 0) {
            foreach ($rows as $row) {
                if ((int) $row['id'] === $editId) {
                    $editItem = $row;
                    break;
                }
            }

            if ($editItem === null) {
                $this->back('error', 'That item is not part of this menu.', '/admin/menus/' . $id);
            }
        }

        // Which items may be picked as a parent: roots only, never the item
        // being edited, and never an item that already has children of its own
        // when the edited item has children too.
        $childCounts = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                $childCounts[(int) $row['parent_id']] = ($childCounts[(int) $row['parent_id']] ?? 0) + 1;
            }
        }

        $editHasChildren = $editItem !== null && ($childCounts[(int) $editItem['id']] ?? 0) > 0;

        $parentOptions = [];
        if (!$editHasChildren) {
            foreach ($tree as $branch) {
                if ($editItem !== null && (int) $branch['row']['id'] === (int) $editItem['id']) {
                    continue;
                }

                $parentOptions[] = $branch['row'];
            }
        }

        $this->view($request, 'admin.menus.builder', [
            'pageTitle'       => $menu['name'],
            'pageSubtitle'    => 'Menu builder · ' . (self::LOCATIONS[$menu['location']] ?? $menu['location']),
            'menu'            => $menu,
            'tree'            => $tree,
            'items'           => $rows,
            'repaired'        => $repaired,
            'childCounts'     => $childCounts,
            'editItem'        => $editItem,
            'editHasChildren' => $editHasChildren,
            'parentOptions'   => $parentOptions,
            'locations'       => self::LOCATIONS,
            'linkTypes'       => self::LINK_TYPES,
            'audiences'       => self::AUDIENCES,
            'pages'           => Database::select(
                'SELECT `slug`, `title`, `is_active` FROM `pages` ORDER BY `title` ASC'
            ),
            'categories'      => Database::select(
                'SELECT `id`, `name`, `is_active` FROM `categories` WHERE `deleted_at` IS NULL ORDER BY `name` ASC'
            ),
            'canManage'       => $this->canManage($request),
        ]);
    }

    /**
     * POST /admin/menus/{id}/tree
     *
     * Body: {"tree":[{"id":1,"parent_id":null,"sort_order":1}, …]}
     *
     * The posted menu id is never trusted: every id is checked against the
     * items this menu actually owns, and the payload is applied only when the
     * whole structure is valid.
     */
    public function saveTree(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $menu   = $this->menuOrFail($request->routeParamInt('id'));
        $menuId = (int) $menu['id'];

        $owned = [];
        foreach (Database::select('SELECT `id` FROM `menu_items` WHERE `menu_id` = :menu', ['menu' => $menuId]) as $row) {
            $owned[(int) $row['id']] = true;
        }

        $posted = $request->array('tree');

        if (!array_is_list($posted)) {
            $this->rejectTree($request, 'The layout must be a list of items.');
        }

        /** @var array<int,array{parent:?int,sort:int,index:int}> $nodes */
        $nodes = [];

        foreach ($posted as $index => $entry) {
            if (!is_array($entry)) {
                $this->rejectTree($request, 'Entry ' . ($index + 1) . ' of the layout is not an item.');
            }

            $rawId = $entry['id'] ?? null;

            if (!is_numeric($rawId) || (int) $rawId <= 0) {
                $this->rejectTree($request, 'Entry ' . ($index + 1) . ' of the layout has no usable item id.');
            }

            $id = (int) $rawId;

            if (!isset($owned[$id])) {
                $this->rejectTree($request, 'Item #' . $id . ' does not belong to this menu.');
            }

            if (isset($nodes[$id])) {
                $this->rejectTree($request, 'Item #' . $id . ' appears more than once in the layout.');
            }

            $rawParent = $entry['parent_id'] ?? null;

            if ($rawParent === null || $rawParent === '' || $rawParent === 0 || $rawParent === '0') {
                $parent = null;
            } elseif (is_numeric($rawParent)) {
                $parent = (int) $rawParent;
            } else {
                $this->rejectTree($request, 'Item #' . $id . ' has an unreadable parent.');
            }

            if ($parent !== null) {
                if ($parent === $id) {
                    $this->rejectTree($request, 'Item #' . $id . ' cannot be its own parent.');
                }

                if (!isset($owned[$parent])) {
                    $this->rejectTree($request, 'Item #' . $id . ' was given a parent that is not in this menu.');
                }
            }

            $rawSort = $entry['sort_order'] ?? null;

            if (!is_numeric($rawSort)) {
                $this->rejectTree($request, 'Item #' . $id . ' has no position.');
            }

            $sort = (int) $rawSort;

            if ($sort < 0 || $sort > self::MAX_SORT) {
                $this->rejectTree($request, 'Item #' . $id . ' has a position outside the allowed range.');
            }

            $nodes[$id] = ['parent' => $parent, 'sort' => $sort, 'index' => (int) $index];
        }

        if (count($nodes) !== count($owned)) {
            $this->rejectTree(
                $request,
                'The layout covers ' . count($nodes) . ' item(s) but this menu has ' . count($owned)
                . '. Refresh the page so you are working from the current menu.'
            );
        }

        // Depth and cycles. Two levels is the hard limit, and an item may never
        // appear among its own ancestors.
        foreach ($nodes as $id => $node) {
            $cursor  = $node['parent'];
            $visited = [$id => true];
            $depth   = 1;

            while ($cursor !== null) {
                if (isset($visited[$cursor])) {
                    $this->rejectTree($request, 'Item #' . $id . ' would end up inside itself.');
                }

                $visited[$cursor] = true;
                $depth++;

                if ($depth > self::MAX_DEPTH) {
                    $this->rejectTree(
                        $request,
                        'Item #' . $id . ' would sit three levels deep. Menus support a parent and one level of children.'
                    );
                }

                $cursor = $nodes[$cursor]['parent'];
            }
        }

        // Re-sequence each sibling group to 1…n so positions stay contiguous
        // however the browser numbered them.
        $groups = [];
        foreach ($nodes as $id => $node) {
            $groups[$node['parent'] === null ? 'root' : 'p' . $node['parent']][] = $id;
        }

        foreach ($groups as $siblings) {
            usort($siblings, static function (int $a, int $b) use ($nodes): int {
                return $nodes[$a]['sort'] <=> $nodes[$b]['sort']
                    ?: $nodes[$a]['index'] <=> $nodes[$b]['index'];
            });

            foreach ($siblings as $position => $id) {
                $nodes[$id]['sort'] = $position + 1;
            }
        }

        Database::transaction(static function () use ($nodes, $menuId): void {
            foreach ($nodes as $id => $node) {
                Database::execute(
                    'UPDATE `menu_items` SET `parent_id` = :parent, `sort_order` = :sort
                      WHERE `id` = :id AND `menu_id` = :menu',
                    ['parent' => $node['parent'], 'sort' => $node['sort'], 'id' => $id, 'menu' => $menuId]
                );
            }
        });

        $this->log(
            $request,
            'menu.reordered',
            'menu',
            $menuId,
            'Rearranged ' . count($nodes) . ' item(s) in ' . $menu['name'] . '.'
        );

        $this->respond($request, 'Menu layout saved.', '/admin/menus/' . $menuId, ['items' => count($nodes)]);
    }

    // =================================================================
    // Items
    // =================================================================

    /** POST /admin/menus/{id}/items */
    public function storeItem(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $menu   = $this->menuOrFail($request->routeParamInt('id'));
        $menuId = (int) $menu['id'];

        $data = $this->itemPayload($request, $menuId, null);

        $data['menu_id']    = $menuId;
        $data['sort_order'] = $this->nextSortOrder($menuId, $data['parent_id']);

        $id = Database::insert('menu_items', $data);

        $this->touchMenu($menuId);

        $this->log($request, 'menu_item.created', 'menu_item', $id, 'Added ' . $data['label'] . ' to ' . $menu['name'] . '.', [
            'menu_id'   => $menuId,
            'link_type' => $data['link_type'],
        ]);

        $this->respond($request, 'Item added.', '/admin/menus/' . $menuId);
    }

    /** POST /admin/menus/{id}/items/{item} */
    public function updateItem(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $menu   = $this->menuOrFail($request->routeParamInt('id'));
        $menuId = (int) $menu['id'];
        $item   = $this->itemOrFail($menuId, $request->routeParamInt('item'));
        $itemId = (int) $item['id'];

        $data = $this->itemPayload($request, $menuId, $item);

        // Moving to a different parent puts the item at the end of its new
        // group; staying put leaves the drag-and-drop order alone.
        if ($data['parent_id'] !== ($item['parent_id'] === null ? null : (int) $item['parent_id'])) {
            $data['sort_order'] = $this->nextSortOrder($menuId, $data['parent_id']);
        }

        Database::update('menu_items', $data, ['id' => $itemId]);

        $this->touchMenu($menuId);

        $this->log($request, 'menu_item.updated', 'menu_item', $itemId, 'Updated ' . $data['label'] . ' in ' . $menu['name'] . '.', [
            'menu_id' => $menuId,
            'before'  => [
                'label'      => $item['label'],
                'link_type'  => $item['link_type'],
                'link_value' => $item['link_value'],
                'parent_id'  => $item['parent_id'],
                'is_active'  => (int) $item['is_active'],
            ],
            'after'   => [
                'label'      => $data['label'],
                'link_type'  => $data['link_type'],
                'link_value' => $data['link_value'],
                'parent_id'  => $data['parent_id'],
                'is_active'  => $data['is_active'],
            ],
        ]);

        $this->respond($request, 'Item saved.', '/admin/menus/' . $menuId);
    }

    /** POST /admin/menus/{id}/items/{item}/delete */
    public function destroyItem(Request $request): never
    {
        $this->authorize($request, self::MANAGE_PERMISSION);

        $menu   = $this->menuOrFail($request->routeParamInt('id'));
        $menuId = (int) $menu['id'];
        $item   = $this->itemOrFail($menuId, $request->routeParamInt('item'));
        $itemId = (int) $item['id'];

        $children = (int) Database::scalar(
            'SELECT COUNT(*) FROM `menu_items` WHERE `menu_id` = :menu AND `parent_id` = :parent',
            ['menu' => $menuId, 'parent' => $itemId]
        );

        Database::transaction(static function () use ($menuId, $itemId): void {
            Database::execute(
                'DELETE FROM `menu_items` WHERE `menu_id` = :menu AND `parent_id` = :parent',
                ['menu' => $menuId, 'parent' => $itemId]
            );
            Database::execute(
                'DELETE FROM `menu_items` WHERE `id` = :id AND `menu_id` = :menu',
                ['id' => $itemId, 'menu' => $menuId]
            );
        });

        $this->touchMenu($menuId);

        $this->log($request, 'menu_item.deleted', 'menu_item', $itemId, 'Removed ' . $item['label'] . ' from ' . $menu['name'] . '.', [
            'menu_id'          => $menuId,
            'children_removed' => $children,
        ]);

        $this->respond(
            $request,
            $children > 0
                ? 'Item removed along with ' . $children . ' child item' . ($children === 1 ? '' : 's') . '.'
                : 'Item removed.',
            '/admin/menus/' . $menuId
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function canManage(Request $request): bool
    {
        return in_array(self::MANAGE_PERMISSION, (array) $request->attribute('permissions', []), true);
    }

    /**
     * A unique menu slug that always fits the column.
     *
     * `menus`.`slug` is varchar(100) and uniqueSlug() appends "-2", "-3"… on a
     * collision, so the base is trimmed to leave room for the counter — a
     * hundred-character name would otherwise overflow the column on the second
     * menu that shares it.
     */
    private static function slugFor(string $preferred, string $name, ?int $ignoreId = null): string
    {
        $preferred = trim($preferred);
        $base      = mb_substr(Str::slug($preferred !== '' ? $preferred : $name), 0, 90);

        return Str::uniqueSlug($base, 'menus', 'slug', $ignoreId);
    }

    /** @return array<string,mixed> */
    private function menuOrFail(int $id): array
    {
        $menu = Database::selectOne('SELECT * FROM `menus` WHERE `id` = :id', ['id' => $id]);

        if ($menu === null) {
            throw HttpException::notFound('That menu does not exist.');
        }

        return $menu;
    }

    /** @return array<string,mixed> */
    private function itemOrFail(int $menuId, int $itemId): array
    {
        $item = Database::selectOne(
            'SELECT * FROM `menu_items` WHERE `id` = :id AND `menu_id` = :menu',
            ['id' => $itemId, 'menu' => $menuId]
        );

        if ($item === null) {
            throw HttpException::notFound('That item is not part of this menu.');
        }

        return $item;
    }

    /** Keep `menus`.`updated_at` honest when only its items changed. */
    private function touchMenu(int $menuId): void
    {
        Database::update('menus', ['updated_at' => date('Y-m-d H:i:s')], ['id' => $menuId]);
    }

    private function nextSortOrder(int $menuId, ?int $parentId): int
    {
        $next = $parentId === null
            ? (int) Database::scalar(
                'SELECT COALESCE(MAX(`sort_order`), 0) + 1 FROM `menu_items`
                  WHERE `menu_id` = :menu AND `parent_id` IS NULL',
                ['menu' => $menuId]
            )
            : (int) Database::scalar(
                'SELECT COALESCE(MAX(`sort_order`), 0) + 1 FROM `menu_items`
                  WHERE `menu_id` = :menu AND `parent_id` = :parent',
                ['menu' => $menuId, 'parent' => $parentId]
            );

        return min(max($next, 1), self::MAX_SORT);
    }

    /**
     * Validate a submitted item and return the exact column set to write.
     *
     * @param  array<string,mixed>|null $existing The row being edited, if any.
     * @return array<string,mixed>
     */
    private function itemPayload(Request $request, int $menuId, ?array $existing): array
    {
        $redirect = '/admin/menus/' . $menuId
            . ($existing !== null ? '?item=' . (int) $existing['id'] : '');

        $data = $this->validate($request, [
            'label'       => 'required|string|min:1|max:100',
            'icon'        => 'nullable|string|max:64',
            'link_type'   => 'required|in:' . implode(',', array_keys(self::LINK_TYPES)),
            'link_value'  => 'nullable|string|max:255',
            'badge_text'  => 'nullable|string|max:20',
            'badge_color' => 'nullable|hex_color',
            'audience'    => 'required|in:' . implode(',', array_keys(self::AUDIENCES)),
            'parent_id'   => 'nullable|integer',
        ]);

        // The validator drops optional keys that were never submitted, so every
        // optional field is read defensively.
        $icon = trim((string) ($data['icon'] ?? ''));

        if ($icon !== '' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $icon) !== 1) {
            $this->rejectItem($request, 'icon', 'An icon is a Material Symbols name such as calendar_today — letters, numbers, dots, dashes and underscores only.', $redirect);
        }

        $parentId = (int) ($data['parent_id'] ?? 0);

        if ($parentId > 0) {
            if ($existing !== null && $parentId === (int) $existing['id']) {
                $this->rejectItem($request, 'parent_id', 'An item cannot be its own parent.', $redirect);
            }

            $parent = Database::selectOne(
                'SELECT `id`, `parent_id`, `label` FROM `menu_items` WHERE `id` = :id AND `menu_id` = :menu',
                ['id' => $parentId, 'menu' => $menuId]
            );

            if ($parent === null) {
                $this->rejectItem($request, 'parent_id', 'The chosen parent is not part of this menu.', $redirect);
            }

            if ($parent['parent_id'] !== null) {
                $this->rejectItem($request, 'parent_id', '"' . $parent['label'] . '" is already a child item. Menus support a parent and one level of children.', $redirect);
            }

            if ($existing !== null) {
                $ownChildren = (int) Database::scalar(
                    'SELECT COUNT(*) FROM `menu_items` WHERE `menu_id` = :menu AND `parent_id` = :parent',
                    ['menu' => $menuId, 'parent' => (int) $existing['id']]
                );

                if ($ownChildren > 0) {
                    $this->rejectItem($request, 'parent_id', 'This item has ' . $ownChildren . ' child item(s), so it has to stay at the top level.', $redirect);
                }
            }
        }

        $linkType  = (string) $data['link_type'];
        $linkValue = trim((string) ($data['link_value'] ?? ''));

        [$linkOk, $linkMessage, $normalisedLink] = self::resolveLinkValue($linkType, $linkValue);

        if (!$linkOk) {
            $this->rejectItem($request, 'link_value', $linkMessage, $redirect);
        }

        $badgeText  = trim((string) ($data['badge_text'] ?? ''));
        $badgeColor = strtoupper(trim((string) ($data['badge_color'] ?? '')));

        return [
            'parent_id'     => $parentId > 0 ? $parentId : null,
            'label'         => (string) $data['label'],
            'icon'          => $icon !== '' ? $icon : null,
            'link_type'     => $linkType,
            'link_value'    => $normalisedLink,
            'badge_text'    => $badgeText !== '' ? $badgeText : null,
            'badge_color'   => $badgeColor !== '' ? $badgeColor : null,
            'audience'      => (string) $data['audience'],
            'open_external' => $request->bool('open_external') ? 1 : 0,
            'is_active'     => $request->bool('is_active') ? 1 : 0,
        ];
    }

    /**
     * Check `link_value` against `link_type` and normalise it for storage.
     *
     * @return array{0:bool,1:string,2:?string}
     */
    private static function resolveLinkValue(string $type, string $value): array
    {
        switch ($type) {
            case 'none':
                if ($value !== '') {
                    return [false, 'A label-only item carries no link. Clear the link value or pick another link type.', null];
                }

                return [true, '', null];

            case 'page':
                if ($value === '') {
                    return [false, 'Choose the page this item opens.', null];
                }

                $slug = Database::scalar('SELECT `slug` FROM `pages` WHERE `slug` = :slug LIMIT 1', ['slug' => $value]);

                if ($slug === null) {
                    return [false, 'No page has the slug "' . $value . '". Pick one from the page list.', null];
                }

                return [true, '', (string) $slug];

            case 'category':
                if ($value === '') {
                    return [false, 'Choose the category this item opens.', null];
                }

                if (preg_match('/^\d+$/', $value) !== 1) {
                    return [false, 'A category link stores a category id, not "' . $value . '". Pick one from the category list.', null];
                }

                $id = Database::scalar(
                    'SELECT `id` FROM `categories` WHERE `id` = :id AND `deleted_at` IS NULL LIMIT 1',
                    ['id' => (int) $value]
                );

                if ($id === null) {
                    return [false, 'No category with id ' . $value . ' exists. Pick one from the category list.', null];
                }

                return [true, '', (string) (int) $id];

            case 'url':
                if ($value === '') {
                    return [false, 'Enter the web address this item opens.', null];
                }

                if (mb_strlen($value) > 255) {
                    return [false, 'That web address is longer than 255 characters.', null];
                }

                if (filter_var($value, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $value) !== 1) {
                    return [false, '"' . $value . '" is not a valid http or https address.', null];
                }

                return [true, '', $value];

            case 'screen':
                if ($value === '') {
                    return [false, 'Enter the app route this item opens, such as /bookings.', null];
                }

                $pattern = '#^/?[a-z0-9]+(?:[-_][a-z0-9]+)*(?:/[a-z0-9]+(?:[-_][a-z0-9]+)*)*$#';

                if (preg_match($pattern, $value) !== 1) {
                    return [
                        false,
                        '"' . $value . '" is not a route name. Use lowercase letters, numbers, dashes, underscores and slashes, for example /bookings.',
                        null,
                    ];
                }

                return [true, '', $value];
        }

        return [false, 'That link type is not supported.', null];
    }

    /**
     * Bounce an item form back with the field highlighted and what was typed
     * still in place.
     */
    private function rejectItem(Request $request, string $field, string $message, string $redirect): never
    {
        if ($request->expectsJson()) {
            Response::validationError([$field => [$message]], $message);
        }

        Session::flash('errors', [$field => [$message]]);
        Session::flash('old', $request->all());

        $this->back('error', $message, $redirect);
    }

    /** Refuse a tree payload without applying any part of it. */
    private function rejectTree(Request $request, string $message): never
    {
        if ($request->expectsJson()) {
            Response::error($message, 422, 'invalid_layout');
        }

        $this->back('error', $message);
    }

    /**
     * Group flat rows into roots and their children.
     *
     * Rows whose parent is missing, belongs elsewhere or is itself a child are
     * promoted to the top level so the editor always shows something coherent;
     * the count of those is returned so the screen can say so.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array{0:list<array{row:array<string,mixed>,children:list<array<string,mixed>>}>,1:int}
     */
    private static function buildTree(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $repaired = 0;
        $roots    = [];
        $children = [];

        foreach ($rows as $row) {
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];

            if ($parentId === null) {
                $roots[(int) $row['id']] = $row;
                continue;
            }

            $parentIsUsable = isset($byId[$parentId]) && $byId[$parentId]['parent_id'] === null;

            if (!$parentIsUsable) {
                $repaired++;
                $roots[(int) $row['id']] = $row;
                continue;
            }

            $children[$parentId][] = $row;
        }

        $tree = [];
        foreach ($roots as $id => $row) {
            $tree[] = ['row' => $row, 'children' => $children[$id] ?? []];
        }

        return [$tree, $repaired];
    }
}
