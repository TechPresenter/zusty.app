<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use PDOException;
use Zuste\Core\Cache;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Str;
use Zuste\Services\KycService;
use Zuste\Services\PermissionService;
use Zuste\Services\RevisionService;
use Zuste\Services\SearchService;

/**
 * Productivity: the things that make a long day in the panel shorter.
 *
 *  - Global search across every module the operator is allowed to see.
 *  - Saved filters, so a query nobody wants to retype survives the session.
 *  - A recycle bin for everything that soft-deletes.
 *  - Revision history with a readable diff and a restore.
 *
 * Two whitelists do the security work here. MODULES turns a saved filter's
 * module key into a path (a stored query string can therefore never point
 * somewhere else), and RECYCLABLE turns an entity type into a table name (so
 * no request value is ever spliced into SQL as an identifier).
 */
final class ProductivityController extends BaseAdminController
{
    /**
     * Modules a filter may be saved against.
     *
     * The `path` is the only thing that is ever concatenated with a saved
     * query string, and it comes from here — never from the request.
     *
     * @var array<string,array{label:string,path:string,permission:string}>
     */
    private const MODULES = [
        'users'         => ['label' => 'Users',              'path' => '/admin/users',                  'permission' => 'users.view'],
        'creators'      => ['label' => 'Creators',           'path' => '/admin/creators',               'permission' => 'creators.view'],
        'kyc'           => ['label' => 'KYC queue',          'path' => '/admin/kyc',                    'permission' => 'kyc.view'],
        'bookings'      => ['label' => 'Bookings',           'path' => '/admin/bookings',               'permission' => 'bookings.view'],
        'disputes'      => ['label' => 'Disputes',           'path' => '/admin/disputes',               'permission' => 'bookings.dispute'],
        'reviews'       => ['label' => 'Reviews',            'path' => '/admin/reviews',                'permission' => 'reviews.view'],
        'reports'       => ['label' => 'Reports',            'path' => '/admin/reports',                'permission' => 'reports.view'],
        'payments'      => ['label' => 'Payments',           'path' => '/admin/payments',               'permission' => 'payments.view'],
        'refunds'       => ['label' => 'Refunds',            'path' => '/admin/refunds',                'permission' => 'payments.view'],
        'wallets'       => ['label' => 'Wallets',            'path' => '/admin/wallets',                'permission' => 'wallet.view'],
        'withdrawals'   => ['label' => 'Withdrawals',        'path' => '/admin/withdrawals',            'permission' => 'wallet.withdrawals'],
        'settlements'   => ['label' => 'Settlements',        'path' => '/admin/settlements',            'permission' => 'payments.view'],
        'tickets'       => ['label' => 'Support tickets',    'path' => '/admin/tickets',                'permission' => 'support.view'],
        'contact'       => ['label' => 'Contact inbox',      'path' => '/admin/contact',                'permission' => 'cms.view'],
        'notifications' => ['label' => 'Notifications',      'path' => '/admin/notifications',          'permission' => 'notifications.view'],
        'media'         => ['label' => 'Media library',      'path' => '/admin/media',                  'permission' => 'cms.media'],
        'categories'    => ['label' => 'Categories',         'path' => '/admin/categories',             'permission' => 'categories.view'],
        'pages'         => ['label' => 'Pages',              'path' => '/admin/pages',                  'permission' => 'cms.view'],
        'blogs'         => ['label' => 'Blog',               'path' => '/admin/blogs',                  'permission' => 'cms.view'],
        'banners'       => ['label' => 'Banners',            'path' => '/admin/banners',                'permission' => 'cms.view'],
        'faqs'          => ['label' => 'FAQs',               'path' => '/admin/faqs',                   'permission' => 'cms.view'],
        'testimonials'  => ['label' => 'Testimonials',       'path' => '/admin/testimonials',           'permission' => 'cms.view'],
        'onboarding'    => ['label' => 'Onboarding slides',  'path' => '/admin/onboarding-slides',      'permission' => 'cms.view'],
        'redirects'     => ['label' => 'Redirects',          'path' => '/admin/redirects',              'permission' => 'cms.view'],
        'sections'      => ['label' => 'Homepage sections',  'path' => '/admin/sections',               'permission' => 'cms.view'],
        'coupons'       => ['label' => 'Coupons',            'path' => '/admin/coupons',                'permission' => 'marketing.view'],
        'popups'        => ['label' => 'Popups',             'path' => '/admin/popups',                 'permission' => 'marketing.view'],
        'flags'         => ['label' => 'Feature flags',      'path' => '/admin/flags',                  'permission' => 'settings.view'],
        'ai-prompts'    => ['label' => 'AI prompts',         'path' => '/admin/ai-prompts',             'permission' => 'ai.view'],
        'admins'        => ['label' => 'Admin users',        'path' => '/admin/admins',                 'permission' => 'admins.view'],
        'logs'          => ['label' => 'Activity log',       'path' => '/admin/logs',                   'permission' => 'logs.activity'],
        'login-history' => ['label' => 'Login history',      'path' => '/admin/security/login-history', 'permission' => 'security.logins'],
        'revisions'     => ['label' => 'Revisions',          'path' => '/admin/revisions',              'permission' => 'revisions.view'],
        'recycle-bin'   => ['label' => 'Recycle bin',        'path' => '/admin/recycle-bin',            'permission' => 'recyclebin.manage'],
    ];

    /**
     * Query keys a saved filter may never carry.
     *
     * `_method` would let a stored link rewrite the HTTP verb (Request honours
     * it), and `export` would hand back a file download instead of a screen.
     *
     * @var list<string>
     */
    private const REJECTED_FILTER_KEYS = ['_method', '_token', 'csrf_token', 'export'];

    /**
     * Everything the recycle bin knows how to show. Membership here is a
     * precondition, not the whole test: each table is still checked for a
     * `deleted_at` column at runtime, because several admin tables (pages,
     * faqs, feature_flags, redirects…) delete for real.
     *
     * `title` and `sub` are the two columns shown for each row; both are read
     * through quoteIdentifier before they reach SQL.
     *
     * `manage` is an *extra* permission demanded on top of `recyclebin.manage`
     * before a row of that type may be restored or destroyed. It exists for the
     * two types where undeleting is not a harmless convenience: bringing an
     * `admins` row back re-enables a panel login, and a `bank_accounts` row
     * decides where a payout lands. Types without the key need only
     * `recyclebin.manage`.
     *
     * @var array<string,array{table:string,label:string,singular:string,icon:string,title:string,sub:string,manage?:string}>
     */
    private const RECYCLABLE = [
        'users' => [
            'table' => 'users', 'label' => 'Users', 'singular' => 'User', 'icon' => 'users',
            'title' => 'name', 'sub' => 'email',
        ],
        'creators' => [
            'table' => 'creators', 'label' => 'Creators', 'singular' => 'Creator', 'icon' => 'star',
            'title' => 'display_name', 'sub' => 'handle',
        ],
        'bookings' => [
            'table' => 'bookings', 'label' => 'Bookings', 'singular' => 'Booking', 'icon' => 'calendar',
            'title' => 'code', 'sub' => 'title',
        ],
        'campaigns' => [
            'table' => 'campaigns', 'label' => 'Brand campaigns', 'singular' => 'Campaign', 'icon' => 'megaphone',
            'title' => 'title', 'sub' => 'code',
        ],
        'categories' => [
            'table' => 'categories', 'label' => 'Categories', 'singular' => 'Category', 'icon' => 'grid',
            'title' => 'name', 'sub' => 'slug',
        ],
        'blogs' => [
            'table' => 'blogs', 'label' => 'Blog articles', 'singular' => 'Article', 'icon' => 'file',
            'title' => 'title', 'sub' => 'slug',
        ],
        'coupons' => [
            'table' => 'coupons', 'label' => 'Coupons', 'singular' => 'Coupon', 'icon' => 'card',
            'title' => 'code', 'sub' => 'name',
        ],
        'popups' => [
            'table' => 'popups', 'label' => 'Popups', 'singular' => 'Popup', 'icon' => 'megaphone',
            'title' => 'name', 'sub' => 'title',
        ],
        'reviews' => [
            'table' => 'reviews', 'label' => 'Reviews', 'singular' => 'Review', 'icon' => 'star',
            'title' => 'title', 'sub' => 'comment',
        ],
        'media' => [
            'table' => 'media', 'label' => 'Media files', 'singular' => 'File', 'icon' => 'image',
            'title' => 'original_name', 'sub' => 'folder',
        ],
        'messages' => [
            'table' => 'messages', 'label' => 'Chat messages', 'singular' => 'Message', 'icon' => 'chat',
            'title' => 'body', 'sub' => 'type',
        ],
        'marketing_campaigns' => [
            'table' => 'marketing_campaigns', 'label' => 'Marketing campaigns', 'singular' => 'Campaign', 'icon' => 'megaphone',
            'title' => 'name', 'sub' => 'subject',
        ],
        'creator_packages' => [
            'table' => 'creator_packages', 'label' => 'Creator packages', 'singular' => 'Package', 'icon' => 'wallet',
            'title' => 'title', 'sub' => 'platform',
        ],
        'creator_portfolio' => [
            'table' => 'creator_portfolio', 'label' => 'Portfolio items', 'singular' => 'Portfolio item', 'icon' => 'image',
            'title' => 'title', 'sub' => 'media_kind',
        ],
        'bank_accounts' => [
            'table' => 'bank_accounts', 'label' => 'Payout accounts', 'singular' => 'Payout account', 'icon' => 'card',
            'title' => 'account_holder_name', 'sub' => 'bank_name', 'manage' => 'wallet.withdrawals',
        ],
        'admins' => [
            'table' => 'admins', 'label' => 'Admin users', 'singular' => 'Admin', 'icon' => 'user-check',
            'title' => 'name', 'sub' => 'email', 'manage' => 'admins.manage',
        ],
    ];

    /** Cache group invalidated whenever the bin changes (the row counts). */
    private const BIN_CACHE_GROUP = 'recyclebin';

    /**
     * Cache group for things derived from the schema rather than from the data.
     *
     * The foreign-key map only moves when a migration runs, so it must not live
     * in BIN_CACHE_GROUP — every restore or delete flushes that group, which
     * would turn a one-hour cache into a one-request one and put an
     * information_schema scan on the next page load.
     */
    private const SCHEMA_CACHE_GROUP = 'recyclebin.schema';

    // =================================================================
    // 1. Global search
    // =================================================================

    /**
     * GET /admin/search
     *
     * No permission of its own: every signed-in admin may search. What comes
     * back is filtered source by source against their own grants.
     */
    public function search(Request $request): never
    {
        $term        = $request->string('q');
        $permissions = $this->permissions($request);
        $groups      = SearchService::search($term, $permissions, SearchService::DEFAULT_LIMIT);

        $this->view($request, 'admin.productivity.search', [
            'pageTitle'    => 'Search',
            'pageSubtitle' => 'One box across users, creators, bookings, tickets and content.',
            'term'         => $term,
            'groups'       => $groups,
            // The number of rows on the screen, not the number that matched:
            // each source is capped at DEFAULT_LIMIT. The view says so.
            'total'        => SearchService::total($groups),
            'sources'      => SearchService::availableSources($permissions),
            'minLength'    => SearchService::MIN_TERM_LENGTH,
            'perSource'    => SearchService::DEFAULT_LIMIT,
            'filters'      => ['q' => $term],
        ]);
    }

    /**
     * GET /admin/search/json
     *
     * The same structure as the screen, for an omnibox that can call it as the
     * operator types. Permission filtering is identical because it happens
     * inside SearchService, not in the view.
     */
    public function searchJson(Request $request): never
    {
        $term   = $request->string('q');
        $limit  = max(1, min(10, $request->int('limit', 5)));
        $groups = SearchService::search($term, $this->permissions($request), $limit);

        Response::success(
            [
                'term'   => $term,
                'total'  => SearchService::total($groups),
                'groups' => $groups,
            ],
            $groups === [] ? 'No matches.' : 'OK'
        );
    }

    // =================================================================
    // 2. Saved filters
    // =================================================================

    /**
     * GET /admin/saved-filters
     *
     * Everyone sees their own filters, plus the ones a colleague shared *on a
     * module they can already open*. A filter's name and conditions routinely
     * carry the thing that was being looked for — an email address, a phone
     * number, a booking code — so a shared filter is only listed to someone who
     * could have run the same search themselves. Your own filters always show,
     * even on a module your access was later revoked on, so you can still
     * delete them.
     */
    public function savedFilters(Request $request): never
    {
        $adminId = $this->adminId($request);
        $module  = $request->string('module');
        $allowed = $this->allowedModules($request);

        $bindings = ['admin_id' => $adminId];

        if ($allowed === []) {
            $clauses = ['f.`admin_id` = :admin_id'];
        } else {
            $placeholders = [];
            $index        = 0;

            foreach (array_keys($allowed) as $allowedModule) {
                $name                = 'mod' . $index++;
                $placeholders[]      = ':' . $name;
                $bindings[$name]     = $allowedModule;
            }

            $clauses = [
                '(f.`admin_id` = :admin_id'
                . ' OR (f.`is_shared` = 1 AND f.`module` IN (' . implode(', ', $placeholders) . ')))',
            ];
        }

        if (isset(self::MODULES[$module])) {
            $clauses[]          = 'f.`module` = :module';
            $bindings['module'] = $module;
        } else {
            $module = '';
        }

        $rows = Database::select(
            'SELECT f.*, a.`name` AS `owner_name`
               FROM `saved_filters` f
               LEFT JOIN `admins` a ON a.`id` = f.`admin_id`
              WHERE ' . implode(' AND ', $clauses) . '
              ORDER BY f.`is_default` DESC, f.`use_count` DESC, f.`name` ASC',
            $bindings
        );

        // Decorate for the view: the module label, the destination path and a
        // readable breakdown of what the stored query actually filters on.
        $filtersByModule = [];

        foreach ($rows as $row) {
            $key        = (string) $row['module'];
            $definition = self::MODULES[$key] ?? null;

            $row['module_label'] = $definition['label'] ?? $key;
            $row['module_path']  = $definition['path'] ?? '';
            $row['is_orphaned']  = $definition === null;
            $row['is_mine']      = (int) $row['admin_id'] === $adminId;
            $row['parts']        = self::describeQuery((string) $row['query_string']);

            $filtersByModule[$row['module_label']][] = $row;
        }

        ksort($filtersByModule);

        $this->view($request, 'admin.productivity.saved-filters', [
            'pageTitle'       => 'Saved filters',
            'pageSubtitle'    => 'Keep a list view you use often one click away.',
            'filtersByModule' => $filtersByModule,
            'total'           => count($rows),
            'modules'         => $allowed,
            'selectedModule'  => $module,
        ]);
    }

    /** POST /admin/saved-filters */
    public function storeSavedFilter(Request $request): never
    {
        $adminId = $this->adminId($request);

        $data = $this->validate($request, [
            'module'       => 'required|string|max:60',
            'name'         => 'required|string|min:2|max:120',
            'query_string' => 'nullable|string|max:2000',
            'is_shared'    => 'nullable|boolean',
            'is_default'   => 'nullable|boolean',
        ]);

        $module = (string) $data['module'];

        if (!isset(self::MODULES[$module])) {
            $this->back('error', 'That module cannot have saved filters.', '/admin/saved-filters');
        }

        if (!PermissionService::adminCan($adminId, self::MODULES[$module]['permission'])) {
            $this->back('error', 'You do not have access to ' . self::MODULES[$module]['label'] . '.', '/admin/saved-filters');
        }

        // The validator omits optional keys that were not submitted.
        $query = self::normalizeQueryString((string) ($data['query_string'] ?? ''));

        if (strlen($query) > 1000) {
            $this->back('error', 'That filter is too long to store. Remove a condition and try again.', '/admin/saved-filters');
        }

        $name     = (string) $data['name'];
        $isShared = $request->bool('is_shared') ? 1 : 0;
        $default  = $request->bool('is_default') ? 1 : 0;

        $duplicate = (int) Database::scalar(
            'SELECT COUNT(*) FROM `saved_filters`
              WHERE `admin_id` = :admin_id AND `module` = :module AND `name` = :name',
            ['admin_id' => $adminId, 'module' => $module, 'name' => $name]
        );

        if ($duplicate > 0) {
            $this->back('error', 'You already have a filter called "' . $name . '" on that module.', '/admin/saved-filters');
        }

        try {
            $id = Database::transaction(static function () use ($adminId, $module, $name, $query, $isShared, $default): int {
                if ($default === 1) {
                    Database::execute(
                        'UPDATE `saved_filters` SET `is_default` = 0 WHERE `admin_id` = :admin_id AND `module` = :module',
                        ['admin_id' => $adminId, 'module' => $module]
                    );
                }

                return Database::insert('saved_filters', [
                    'admin_id'     => $adminId,
                    'module'       => $module,
                    'name'         => $name,
                    'query_string' => $query,
                    'is_shared'    => $isShared,
                    'is_default'   => $default,
                    'use_count'    => 0,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (PDOException $e) {
            // 23000 is the unique key on (admin_id, module, name) — the only
            // failure worth naming. Anything else is a fault, not a clash, and
            // must not be reported to the operator as a duplicate.
            if ((string) $e->getCode() === '23000') {
                $this->back('error', 'A filter with that name already exists on this module.', '/admin/saved-filters');
            }

            Logger::error('Saved filter could not be stored', [
                'admin_id' => $adminId,
                'module'   => $module,
                'error'    => $e->getMessage(),
            ]);

            $this->back('error', 'That filter could not be saved. Try again in a moment.', '/admin/saved-filters');
        }

        $this->log($request, 'saved_filter.created', 'saved_filter', $id, 'Saved the "' . $name . '" filter on ' . self::MODULES[$module]['label'] . '.');

        $this->respond($request, 'Filter saved.', '/admin/saved-filters');
    }

    /** POST /admin/saved-filters/{id}/rename */
    public function renameSavedFilter(Request $request): never
    {
        $row = $this->ownedFilter($request);

        $data = $this->validate($request, ['name' => 'required|string|min:2|max:120']);
        $name = (string) $data['name'];

        $clash = (int) Database::scalar(
            'SELECT COUNT(*) FROM `saved_filters`
              WHERE `admin_id` = :admin_id AND `module` = :module AND `name` = :name AND `id` <> :id',
            ['admin_id' => (int) $row['admin_id'], 'module' => (string) $row['module'], 'name' => $name, 'id' => (int) $row['id']]
        );

        if ($clash > 0) {
            $this->back('error', 'You already have a filter with that name here.', '/admin/saved-filters');
        }

        try {
            Database::update('saved_filters', ['name' => $name], ['id' => (int) $row['id'], 'admin_id' => (int) $row['admin_id']]);
        } catch (PDOException $e) {
            // The check above is not atomic; the unique key is the real guard.
            if ((string) $e->getCode() === '23000') {
                $this->back('error', 'You already have a filter with that name here.', '/admin/saved-filters');
            }

            Logger::error('Saved filter could not be renamed', [
                'filter_id' => (int) $row['id'],
                'error'     => $e->getMessage(),
            ]);

            $this->back('error', 'That filter could not be renamed. Try again in a moment.', '/admin/saved-filters');
        }

        $this->log($request, 'saved_filter.renamed', 'saved_filter', (int) $row['id'], 'Renamed "' . $row['name'] . '" to "' . $name . '".');

        $this->respond($request, 'Filter renamed.', '/admin/saved-filters');
    }

    /** POST /admin/saved-filters/{id}/share */
    public function toggleSavedFilterShared(Request $request): never
    {
        $row    = $this->ownedFilter($request);
        $shared = (int) $row['is_shared'] === 0 ? 1 : 0;

        Database::update(
            'saved_filters',
            ['is_shared' => $shared],
            ['id' => (int) $row['id'], 'admin_id' => (int) $row['admin_id']]
        );

        $this->log(
            $request,
            'saved_filter.shared',
            'saved_filter',
            (int) $row['id'],
            ($shared === 1 ? 'Shared' : 'Un-shared') . ' the "' . $row['name'] . '" filter.'
        );

        $this->respond($request, $shared === 1 ? 'Filter shared with the team.' : 'Filter is private again.', '/admin/saved-filters');
    }

    /** POST /admin/saved-filters/{id}/default */
    public function toggleSavedFilterDefault(Request $request): never
    {
        $row     = $this->ownedFilter($request);
        $makeDefault = (int) $row['is_default'] === 0;
        $adminId = (int) $row['admin_id'];
        $module  = (string) $row['module'];
        $id      = (int) $row['id'];

        Database::transaction(static function () use ($id, $adminId, $module, $makeDefault): void {
            if ($makeDefault) {
                // Only one default per module per admin.
                Database::execute(
                    'UPDATE `saved_filters` SET `is_default` = 0 WHERE `admin_id` = :admin_id AND `module` = :module',
                    ['admin_id' => $adminId, 'module' => $module]
                );
            }

            Database::update('saved_filters', ['is_default' => $makeDefault ? 1 : 0], ['id' => $id, 'admin_id' => $adminId]);
        });

        $this->log(
            $request,
            'saved_filter.default',
            'saved_filter',
            $id,
            ($makeDefault ? 'Made "' : 'Cleared the default on "') . $row['name'] . '".'
        );

        $this->respond($request, $makeDefault ? 'Set as the default for that module.' : 'Default cleared.', '/admin/saved-filters');
    }

    /** POST /admin/saved-filters/{id}/delete */
    public function deleteSavedFilter(Request $request): never
    {
        $row = $this->ownedFilter($request);

        Database::execute(
            'DELETE FROM `saved_filters` WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => (int) $row['id'], 'admin_id' => (int) $row['admin_id']]
        );

        $this->log($request, 'saved_filter.deleted', 'saved_filter', (int) $row['id'], 'Deleted the "' . $row['name'] . '" filter.');

        $this->respond($request, 'Filter deleted.', '/admin/saved-filters');
    }

    /**
     * POST /admin/saved-filters/{id}/apply
     *
     * Rebuilds the destination from the whitelisted module path and the stored
     * query string, so the redirect target cannot be influenced by what was
     * typed into the save form.
     */
    public function applySavedFilter(Request $request): never
    {
        $adminId = $this->adminId($request);
        $id      = $request->routeParamInt('id');

        $row = Database::selectOne(
            'SELECT * FROM `saved_filters`
              WHERE `id` = :id AND (`admin_id` = :admin_id OR `is_shared` = 1)',
            ['id' => $id, 'admin_id' => $adminId]
        );

        if ($row === null) {
            $this->back('error', 'That filter is not available to you.', '/admin/saved-filters');
        }

        $module = (string) $row['module'];

        if (!isset(self::MODULES[$module])) {
            $this->back('error', 'The module this filter points at no longer exists.', '/admin/saved-filters');
        }

        if (!PermissionService::adminCan($adminId, self::MODULES[$module]['permission'])) {
            $this->back('error', 'You do not have access to ' . self::MODULES[$module]['label'] . '.', '/admin/saved-filters');
        }

        Database::execute(
            'UPDATE `saved_filters` SET `use_count` = `use_count` + 1 WHERE `id` = :id',
            ['id' => $id]
        );

        // Re-normalise on the way out as well: a row edited directly in the
        // database still cannot smuggle a path or a fragment into the URL.
        $query = self::normalizeQueryString((string) $row['query_string']);

        Response::redirect(self::MODULES[$module]['path'] . ($query === '' ? '' : '?' . $query));
    }

    // =================================================================
    // 3. Recycle bin
    // =================================================================

    /** GET /admin/recycle-bin */
    public function recycleBin(Request $request): never
    {
        $this->authorize($request, 'recyclebin.manage');

        $types = $this->accessibleBinTypes($request);
        $type  = $request->string('type');

        if (!isset($types[$type])) {
            // Land on the first type that actually holds something.
            $type = '';

            foreach ($types as $key => $summary) {
                if ($summary['count'] > 0) {
                    $type = $key;
                    break;
                }
            }
        }

        $rows       = [];
        $pagination = $this->paginationMeta(0, 1, 25);
        $impact     = [];
        $kycHeld    = [];
        $params     = $this->listParams($request, ['id', 'deleted_at'], 'deleted_at');

        if ($type !== '') {
            $definition = self::RECYCLABLE[$type];
            $table      = Database::quoteIdentifier($definition['table']);
            $titleCol   = Database::quoteIdentifier($definition['title']);
            $subCol     = Database::quoteIdentifier($definition['sub']);

            $clauses  = ['`deleted_at` IS NOT NULL'];
            $bindings = [];

            if ($params['search'] !== '') {
                $clauses[]          = '(' . $titleCol . " LIKE :search ESCAPE '\\\\' OR " . $subCol . " LIKE :search ESCAPE '\\\\')";
                $bindings['search'] = Str::likeTerm($params['search']);
            }

            $this->applyDateRange($clauses, $bindings, '`deleted_at`', $params['from'], $params['to']);

            $where = ' WHERE ' . implode(' AND ', $clauses);
            $total = (int) Database::scalar('SELECT COUNT(*) FROM ' . $table . $where, $bindings);

            $rows = Database::select(
                'SELECT `id`, ' . $titleCol . ' AS `bin_title`, ' . $subCol . ' AS `bin_sub`, `deleted_at`
                   FROM ' . $table . $where . '
                  ORDER BY ' . Database::quoteIdentifier($params['sort_by']) . ' ' . $params['sort_dir'] . '
                  LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
                $bindings
            );

            $pagination = $this->paginationMeta($total, $params['page'], $params['per_page']);

            $impact = $this->cascadeImpact(
                $definition['table'],
                array_map(static fn (array $row): int => (int) $row['id'], $rows)
            );

            // Accounts the bin will not delete for good, so the screen says so
            // up front instead of offering a button that is going to refuse.
            if ($type === 'users' && $rows !== []) {
                [$in, $inBindings] = Database::inClause(array_map(static fn (array $row): int => (int) $row['id'], $rows));

                $kycHeld = array_map('intval', Database::column(
                    'SELECT DISTINCT `user_id` FROM `kyc_profiles` WHERE `user_id` IN ' . $in,
                    $inBindings
                ));
            }
        }

        $this->view($request, 'admin.productivity.recycle-bin', [
            'pageTitle'    => 'Recycle bin',
            'pageSubtitle' => 'Everything that was soft-deleted, and still recoverable.',
            'types'        => $types,
            'type'         => $type,
            'definition'   => $type === '' ? null : self::RECYCLABLE[$type],
            'rows'         => $rows,
            'impact'       => $impact,
            // Binned accounts with a KYC history: kept for good (see
            // hardDeleteBinned()), so the screen offers no permanent delete.
            'kycHeld'      => $kycHeld,
            'pagination'   => $pagination,
            'binTotal'     => array_sum(array_column($types, 'count')),
            'filters'      => [
                'q'        => $params['search'],
                'type'     => $type,
                'from'     => $params['from'],
                'to'       => $params['to'],
                'sort_by'  => $params['sort_by'],
                'sort_dir' => $params['sort_dir'],
            ],
        ]);
    }

    /** POST /admin/recycle-bin/restore */
    public function restoreDeleted(Request $request): never
    {
        $this->authorize($request, 'recyclebin.manage');

        [$type, $definition, $row] = $this->binRow($request);

        $this->authorizeBinType($request, $type);

        Database::update($definition['table'], ['deleted_at' => null], ['id' => (int) $row['id']]);

        Cache::forgetGroup(self::BIN_CACHE_GROUP);

        $label = $this->binLabel($definition, $row);

        $this->log($request, 'recyclebin.restored', $type, (int) $row['id'], 'Restored ' . strtolower($definition['singular']) . ' ' . $label . '.');

        $this->respond($request, $definition['singular'] . ' restored.', '/admin/recycle-bin?type=' . rawurlencode($type));
    }

    /** POST /admin/recycle-bin/delete */
    public function purgeDeleted(Request $request): never
    {
        $this->authorize($request, 'recyclebin.manage');

        [$type, $definition, $row] = $this->binRow($request);

        $this->authorizeBinType($request, $type);

        $label  = $this->binLabel($definition, $row);
        $back   = '/admin/recycle-bin?type=' . rawurlencode($type);
        $result = $this->hardDeleteBinned($type, '`id` = :bin_id', ['bin_id' => (int) $row['id']]);

        Cache::forgetGroup(self::BIN_CACHE_GROUP);

        if ($result['kyc_kept'] > 0) {
            $this->log(
                $request,
                'recyclebin.purge_refused',
                $type,
                (int) $row['id'],
                'Kept ' . strtolower($definition['singular']) . ' ' . $label . ' in the recycle bin: it has a KYC verification history.',
                $result
            );

            $this->back(
                $result['erase_failed'] > 0 ? 'error' : 'warning',
                $label . ' has a KYC verification history, which is kept as the record of who reviewed the identity, '
                . 'so the account cannot be deleted for good. It stays in the recycle bin.'
                . ($result['erase_failed'] > 0
                    ? ' Its identity documents could not be erased just now — try again.'
                    : ' Its identity documents have been erased.'),
                $back
            );
        }

        if ($result['erase_failed'] > 0) {
            $this->back('error', 'The identity documents of ' . $label . ' could not be erased, so nothing was deleted. Try again.', $back);
        }

        if ($result['referenced'] > 0) {
            $this->back(
                'error',
                $label . ' is still referenced by records that must be kept (such as payments, invoices or bookings), '
                . 'so it cannot be deleted for good. It stays in the recycle bin.',
                $back
            );
        }

        if ($result['deleted'] === 0) {
            $this->back('error', 'Nothing was deleted — that record had already gone.', $back);
        }

        $this->log(
            $request,
            'recyclebin.purged',
            $type,
            (int) $row['id'],
            'Permanently deleted ' . strtolower($definition['singular']) . ' ' . $label . '.'
        );

        $this->respond($request, $definition['singular'] . ' permanently deleted.', '/admin/recycle-bin?type=' . rawurlencode($type));
    }

    /** POST /admin/recycle-bin/empty */
    public function emptyBinType(Request $request): never
    {
        $this->authorize($request, 'recyclebin.manage');

        $type  = $request->string('type');
        $types = $this->accessibleBinTypes($request);

        if (!isset($types[$type])) {
            $this->back('error', 'That entity type is not in the recycle bin.', '/admin/recycle-bin');
        }

        $this->authorizeBinType($request, $type);

        $definition = self::RECYCLABLE[$type];

        $result   = $this->hardDeleteBinned($type);
        $affected = $result['deleted'];
        $kept     = $this->keptSentence($result);

        Cache::forgetGroup(self::BIN_CACHE_GROUP);

        if ($affected === 0) {
            $this->back(
                'error',
                $kept === ''
                    ? 'There was nothing of that type left in the recycle bin.'
                    : 'Nothing was deleted.' . $kept,
                '/admin/recycle-bin?type=' . rawurlencode($type)
            );
        }

        $this->log(
            $request,
            'recyclebin.emptied',
            $type,
            null,
            'Permanently deleted ' . $affected . ' ' . strtolower($definition['label']) . ' from the recycle bin.',
            $result
        );

        $this->respond($request, $affected . ' record(s) permanently deleted.' . $kept, '/admin/recycle-bin');
    }

    /**
     * Permanently delete binned rows of one type, narrowed by [$condition] (SQL
     * over the type's own columns, bound by [$bindings]) when one is given.
     *
     * Users carry two extra rules, because a user row is what the KYC audit
     * trail hangs from:
     *
     *  - An account with a KYC verification profile is never hard-deleted.
     *    kyc_profiles cascades from users, and kyc_events from kyc_profiles,
     *    and a foreign-key cascade does not fire the triggers that keep
     *    kyc_events append-only — so one "Empty bin" used to erase, silently,
     *    the legal record of who approved or rejected an identity. Those
     *    accounts stay in the bin and are counted, so the operator is told.
     *  - Every binned account in scope that still holds identity documents has
     *    them erased first (KycService::purgeForUser — files, numbers, names),
     *    because asking for a permanent delete is asking for exactly that, and
     *    a cascade would only drop the rows and leave the files behind. An
     *    account whose erasure fails is not deleted, so a retry finds it.
     *
     * A row that something else still references through a RESTRICT foreign
     * key (the financial trail; the KYC profile edge once that is RESTRICT
     * too) is left where it is and counted, rather than failing the whole
     * statement with a database error.
     *
     * @param  array<string,mixed> $bindings
     * @return array{deleted:int,kyc_kept:int,referenced:int,erased:int,erase_failed:int}
     */
    private function hardDeleteBinned(string $type, string $condition = '', array $bindings = []): array
    {
        $table  = Database::quoteIdentifier(self::RECYCLABLE[$type]['table']);
        $where  = '`deleted_at` IS NOT NULL' . ($condition !== '' ? ' AND ' . $condition : '');
        $result = ['deleted' => 0, 'kyc_kept' => 0, 'referenced' => 0, 'erased' => 0, 'erase_failed' => 0];

        if (self::RECYCLABLE[$type]['table'] === 'users') {
            $result['kyc_kept'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM `users` WHERE ' . $where . '
                    AND EXISTS (SELECT 1 FROM `kyc_profiles` kp WHERE kp.`user_id` = `users`.`id`)',
                $bindings
            );

            $toErase = array_map('intval', Database::column(
                'SELECT `id` FROM `users` WHERE ' . $where . '
                    AND (EXISTS (SELECT 1 FROM `kyc_profiles` kp
                                  WHERE kp.`user_id` = `users`.`id` AND kp.`purged_at` IS NULL)
                         OR EXISTS (SELECT 1 FROM `kyc_documents` kd
                                     WHERE kd.`user_id` = `users`.`id` AND kd.`purged_at` IS NULL))',
                $bindings
            ));

            $failed = [];

            foreach ($toErase as $userId) {
                try {
                    KycService::purgeForUser($userId, 'Account permanently removed from the recycle bin; identity documents erased.');
                    $result['erased']++;
                } catch (\Throwable $e) {
                    $failed[] = $userId;

                    Logger::error('Could not erase KYC data while emptying the recycle bin', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            $result['erase_failed'] = count($failed);

            $where .= ' AND NOT EXISTS (SELECT 1 FROM `kyc_profiles` kp WHERE kp.`user_id` = `users`.`id`)';

            if ($failed !== []) {
                $where .= ' AND `id` NOT IN (' . implode(', ', $failed) . ')';
            }
        }

        try {
            $result['deleted'] = Database::execute('DELETE FROM ' . $table . ' WHERE ' . $where, $bindings);

            return $result;
        } catch (PDOException $e) {
            if (!self::isReferencedRow($e)) {
                throw $e;
            }
        }

        // At least one row is still referenced, and the statement is all or
        // nothing — so go row by row and keep the ones that are blocked.
        $ids = Database::column('SELECT `id` FROM ' . $table . ' WHERE ' . $where, $bindings);

        if (count($ids) === 1) {
            // The only candidate is the row that just failed; no need to try it twice.
            $result['referenced'] = 1;

            return $result;
        }

        foreach ($ids as $id) {
            try {
                $result['deleted'] += Database::execute(
                    'DELETE FROM ' . $table . ' WHERE `id` = :bin_row_id AND ' . $where,
                    $bindings + ['bin_row_id' => (int) $id]
                );
            } catch (PDOException $e) {
                if (!self::isReferencedRow($e)) {
                    throw $e;
                }

                $result['referenced']++;
            }
        }

        return $result;
    }

    /** Did a DELETE fail because another row still points at it (errno 1451 / 1217)? */
    private static function isReferencedRow(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1217], true);
    }

    /**
     * The sentence telling the operator what a permanent delete left behind.
     *
     * @param array{kyc_kept:int,referenced:int,erase_failed:int} $result
     */
    private function keptSentence(array $result): string
    {
        $parts = [];

        if ($result['kyc_kept'] > 0) {
            $parts[] = $result['kyc_kept'] . ' account(s) with a KYC verification history were kept in the bin — '
                . 'that history is the record of who reviewed the identity and must outlive the account'
                . ($result['erase_failed'] > 0 ? '.' : '; their identity documents have been erased.');
        }

        if ($result['erase_failed'] > 0) {
            $parts[] = 'The identity documents of ' . $result['erase_failed'] . ' account(s) could not be erased, '
                . 'so those accounts were left as they were — try again.';
        }

        if ($result['referenced'] > 0) {
            $parts[] = $result['referenced'] . ' record(s) are still referenced by records that must be kept '
                . '(such as payments, invoices or bookings) and were left in the bin.';
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /** POST /admin/recycle-bin/purge */
    public function purgeOlderThan(Request $request): never
    {
        $this->authorize($request, 'recyclebin.manage');

        $data = $this->validate($request, [
            'days' => 'required|integer|min_value:1|max_value:3650',
        ]);

        $adminId  = $this->adminId($request);
        $days     = (int) $data['days'];
        $affected = 0;
        $touched  = [];
        $skipped  = [];
        $failed   = [];
        $kept     = ['kyc_kept' => 0, 'referenced' => 0, 'erase_failed' => 0];

        // Every available type is swept, not just the ones the (briefly cached)
        // counts claim are occupied — a stale count must not leave rows behind.
        // Types that demand a permission of their own are skipped rather than
        // silently included, and the operator is told which ones.
        //
        // Deliberately not one transaction. The types share no invariant: every
        // row involved is already soft-deleted and already past the retention
        // window, so a sweep that reaches ten tables out of sixteen is
        // unfinished work, not a corrupt state, and re-running finishes it. One
        // transaction would instead hold locks on users, bookings, messages and
        // media until the last DELETE committed. What a failure must not do is
        // destroy rows without a record, so a table that throws is caught,
        // logged and named back to the operator, and the audit entry below is
        // written either way.
        foreach (array_keys($this->binTypes()) as $type) {
            $extra = self::RECYCLABLE[$type]['manage'] ?? '';

            if ($extra !== '' && !PermissionService::adminCan($adminId, $extra)) {
                $skipped[] = self::RECYCLABLE[$type]['label'];
                continue;
            }

            try {
                // Same rules as a single delete: accounts with a KYC history
                // are kept (their documents erased), referenced rows are left.
                $result = $this->hardDeleteBinned(
                    $type,
                    '`deleted_at` < DATE_SUB(NOW(), INTERVAL :days DAY)',
                    ['days' => $days]
                );

                $removed = $result['deleted'];

                foreach (array_keys($kept) as $key) {
                    $kept[$key] += $result[$key];
                }
            } catch (\Throwable $e) {
                $failed[$type] = self::RECYCLABLE[$type]['label'];

                Logger::error('Recycle-bin retention purge failed for one type', [
                    'type'  => $type,
                    'table' => self::RECYCLABLE[$type]['table'],
                    'days'  => $days,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($removed > 0) {
                $affected      += $removed;
                $touched[$type] = $removed;
            }
        }

        Cache::forgetGroup(self::BIN_CACHE_GROUP);

        $this->log(
            $request,
            'recyclebin.purged_old',
            null,
            null,
            'Purged ' . $affected . ' record(s) deleted more than ' . $days . ' day(s) ago.',
            ['removed' => $touched, 'skipped' => $skipped, 'failed' => $failed, 'kept' => $kept, 'days' => $days]
        );

        $keptSentence = $this->keptSentence($kept);

        $message = $affected === 0
            ? ($keptSentence === '' ? 'Nothing was old enough to purge.' : 'Nothing was deleted.')
            : $affected . ' record(s) permanently deleted.';

        $message .= $keptSentence;

        if ($skipped !== []) {
            $message .= ' ' . implode(' and ', $skipped) . ' were left alone — you do not have permission to remove those.';
        }

        if ($failed !== []) {
            $message .= ' ' . implode(' and ', $failed) . ' could not be swept and were left as they were — try again.';
        }

        $this->respond($request, $message, '/admin/recycle-bin');
    }

    // =================================================================
    // 4. Revisions
    // =================================================================

    /** GET /admin/revisions */
    public function revisions(Request $request): never
    {
        $this->authorize($request, 'revisions.view');

        $params     = $this->listParams($request, ['id', 'created_at', 'version', 'entity_type'], 'created_at');
        $entityType = $request->string('entity_type');
        $entityId   = $request->int('entity_id');

        $clauses  = ['1 = 1'];
        $bindings = [];

        if (RevisionService::supports($entityType)) {
            $clauses[]               = '`entity_type` = :entity_type';
            $bindings['entity_type'] = $entityType;
        } else {
            $entityType = '';
        }

        if ($entityId > 0) {
            $clauses[]             = '`entity_id` = :entity_id';
            $bindings['entity_id'] = $entityId;
        }

        if ($params['search'] !== '') {
            $clauses[]          = "(`change_note` LIKE :search ESCAPE '\\\\' OR `admin_name` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        $this->applyDateRange($clauses, $bindings, '`created_at`', $params['from'], $params['to']);

        $where = ' WHERE ' . implode(' AND ', $clauses);
        $total = (int) Database::scalar('SELECT COUNT(*) FROM `revisions`' . $where, $bindings);

        $rows = Database::select(
            'SELECT `id`, `entity_type`, `entity_id`, `version`, `change_note`, `admin_id`, `admin_name`, `created_at`,
                    CHAR_LENGTH(`snapshot`) AS `snapshot_bytes`
               FROM `revisions`' . $where . '
              ORDER BY ' . Database::quoteIdentifier($params['sort_by']) . ' ' . $params['sort_dir'] . '
              LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                'SELECT `created_at`, `entity_type`, `entity_id`, `version`, `admin_name`, `change_note`
                   FROM `revisions`' . $where . ' ORDER BY `created_at` DESC LIMIT 10000',
                $bindings
            );

            $this->exportCsv(
                'revisions',
                ['When', 'Entity type', 'Entity id', 'Version', 'Changed by', 'Note'],
                $all,
                ['created_at', 'entity_type', 'entity_id', 'version', 'admin_name', 'change_note']
            );
        }

        // Counts per entity type drive the filter chips.
        $counts = [];

        foreach (Database::select('SELECT `entity_type`, COUNT(*) AS `total` FROM `revisions` GROUP BY `entity_type`') as $row) {
            $counts[(string) $row['entity_type']] = (int) $row['total'];
        }

        $this->view($request, 'admin.productivity.revisions', [
            'pageTitle'    => 'Revisions',
            'pageSubtitle' => 'Every stored version of your content, with a diff and a way back.',
            'rows'         => $rows,
            'counts'       => $counts,
            'entities'     => RevisionService::entities(),
            'entityType'   => $entityType,
            'entityId'     => $entityId,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'revision'     => null,
            'filters'      => [
                'q'           => $params['search'],
                'entity_type' => $entityType,
                'entity_id'   => $entityId > 0 ? (string) $entityId : '',
                'from'        => $params['from'],
                'to'          => $params['to'],
                'sort_by'     => $params['sort_by'],
                'sort_dir'    => $params['sort_dir'],
            ],
        ]);
    }

    /** GET /admin/revisions/{id} */
    public function revision(Request $request): never
    {
        $this->authorize($request, 'revisions.view');

        $revision = RevisionService::find($request->routeParamInt('id'));

        if ($revision === null) {
            $this->back('error', 'That revision no longer exists.', '/admin/revisions');
        }

        $entityType = (string) $revision['entity_type'];
        $entityId   = (int) $revision['entity_id'];
        $definition = RevisionService::entity($entityType);

        $snapshot = RevisionService::decode($revision['snapshot']);
        $previous = RevisionService::previous($entityType, $entityId, (int) $revision['version']);
        $before   = $previous === null ? [] : RevisionService::decode($previous['snapshot']);

        $source = $definition === null ? null : RevisionService::sourceRow($entityType, $entityId);

        $this->view($request, 'admin.productivity.revisions', [
            'pageTitle'    => RevisionService::labelFor($entityType) . ' · version ' . (int) $revision['version'],
            'pageSubtitle' => RevisionService::snapshotLabel($entityType, $snapshot, $entityId),
            'revision'     => $revision,
            'previous'     => $previous,
            'snapshot'     => $snapshot,
            'changes'      => RevisionService::diff($before, $snapshot),
            'definition'   => $definition,
            'entityUrl'    => RevisionService::urlFor($entityType, $entityId),
            'sourceExists' => $source !== null,
            'latestVersion' => RevisionService::latestVersion($entityType, $entityId),
            'canRestore'   => $definition !== null
                && PermissionService::adminCan($this->adminId($request), $definition['permission']),
            'rows'         => [],
            'counts'       => [],
            'entities'     => RevisionService::entities(),
            'entityType'   => $entityType,
            'entityId'     => $entityId,
            'filters'      => [],
        ]);
    }

    /**
     * POST /admin/revisions/{id}/restore
     *
     * Viewing history is one permission; rewriting a live record with it is
     * another. The second check is the destination entity's own manage
     * permission, so a reviewer who can read every revision still cannot
     * republish a page they are not trusted to edit.
     */
    public function restoreRevision(Request $request): never
    {
        $this->authorize($request, 'revisions.view');

        $revision = RevisionService::find($request->routeParamInt('id'));

        if ($revision === null) {
            $this->back('error', 'That revision no longer exists.', '/admin/revisions');
        }

        $entityType = (string) $revision['entity_type'];
        $entityId   = (int) $revision['entity_id'];
        $definition = RevisionService::entity($entityType);

        if ($definition === null) {
            $this->back('error', 'That entity type can no longer be restored.', '/admin/revisions/' . (int) $revision['id']);
        }

        $this->authorize($request, $definition['permission']);

        $snapshot = RevisionService::decode($revision['snapshot']);

        if ($snapshot === []) {
            $this->back('error', 'That snapshot is empty, so there is nothing to restore.', '/admin/revisions/' . (int) $revision['id']);
        }

        try {
            $written = RevisionService::restore(
                $entityType,
                $entityId,
                $snapshot,
                (int) $revision['version'],
                $this->admin($request)
            );
        } catch (\Throwable $e) {
            // PDOException extends RuntimeException, so the driver's message has
            // to be intercepted first — otherwise "Duplicate entry 'about' for
            // key 'uq_pages_slug'" is what the operator reads. A snapshot can
            // stop being writable after the fact: a slug another record has
            // since taken, an enum value dropped from the schema, a column that
            // shrank. RevisionService runs the write in a transaction, so the
            // record is untouched either way.
            $authored = $e instanceof \RuntimeException && !$e instanceof PDOException;

            if (!$authored) {
                Logger::error('Revision restore failed', [
                    'revision_id' => (int) $revision['id'],
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'error'       => $e->getMessage(),
                ]);
            }

            $this->back(
                'error',
                $authored
                    ? $e->getMessage()
                    : 'This version could not be written back — the record or the database has moved on since it was saved. Nothing was changed.',
                '/admin/revisions/' . (int) $revision['id']
            );
        }

        $this->log(
            $request,
            'revision.restored',
            $entityType,
            $entityId,
            'Restored ' . strtolower($definition['label']) . ' #' . $entityId . ' to version ' . (int) $revision['version'] . '.',
            ['revision_id' => (int) $revision['id'], 'columns' => $written]
        );

        $this->respond(
            $request,
            $definition['label'] . ' restored to version ' . (int) $revision['version'] . '.',
            '/admin/revisions?entity_type=' . rawurlencode($entityType) . '&entity_id=' . $entityId
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * The signed-in admin's effective permissions, as set by AdminAuthMiddleware.
     *
     * @return list<string>
     */
    private function permissions(Request $request): array
    {
        $permissions = $request->attribute('permissions', []);

        return is_array($permissions) ? array_values(array_map('strval', $permissions)) : [];
    }

    /**
     * Modules this admin may save a filter against.
     *
     * @return array<string,array{label:string,path:string,permission:string}>
     */
    private function allowedModules(Request $request): array
    {
        $adminId = $this->adminId($request);
        $allowed = [];

        foreach (self::MODULES as $key => $definition) {
            if (PermissionService::adminCan($adminId, $definition['permission'])) {
                $allowed[$key] = $definition;
            }
        }

        return $allowed;
    }

    /**
     * Load a saved filter that belongs to the current admin, or stop.
     *
     * Ownership is enforced in the WHERE clause, not by hiding a button.
     *
     * @return array<string,mixed>
     */
    private function ownedFilter(Request $request): array
    {
        $row = Database::selectOne(
            'SELECT * FROM `saved_filters` WHERE `id` = :id AND `admin_id` = :admin_id',
            ['id' => $request->routeParamInt('id'), 'admin_id' => $this->adminId($request)]
        );

        if ($row === null) {
            $this->back('error', 'That filter does not exist, or it belongs to someone else.', '/admin/saved-filters');
        }

        return $row;
    }

    /**
     * Reduce a user-supplied query string to a safe, canonical form.
     *
     * A pasted URL keeps only its query part; a fragment is dropped; keys are
     * limited to a conservative character set; values are capped; everything is
     * re-encoded with http_build_query. The result therefore cannot contain a
     * scheme, a host, a path separator or a `#` — appending it to a whitelisted
     * path can only ever produce that path.
     */
    private static function normalizeQueryString(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $questionMark = strpos($raw, '?');

        if ($questionMark !== false) {
            $raw = substr($raw, $questionMark + 1);
        }

        $hash = strpos($raw, '#');

        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }

        $raw = ltrim($raw, '&');

        if ($raw === '') {
            return '';
        }

        $parsed = [];
        parse_str($raw, $parsed);

        $clean = [];

        foreach ($parsed as $key => $value) {
            $name = (string) $key;

            if (preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $name) !== 1) {
                continue;
            }

            if (in_array($name, self::REJECTED_FILTER_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $list = [];

                foreach ($value as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }

                    $list[] = mb_substr((string) $item, 0, 200);

                    if (count($list) >= 50) {
                        break;
                    }
                }

                if ($list !== []) {
                    $clean[$name] = $list;
                }

                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $clean[$name] = mb_substr((string) $value, 0, 200);
        }

        return $clean === [] ? '' : http_build_query($clean);
    }

    /**
     * Turn a stored query string into readable key/value pairs for the UI.
     *
     * @return list<array{key:string,value:string}>
     */
    private static function describeQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        $parts = [];

        foreach ($parsed as $key => $value) {
            $parts[] = [
                'key'   => str_replace('_', ' ', (string) $key),
                'value' => is_array($value)
                    ? implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '…', $value))
                    : (is_scalar($value) ? (string) $value : '…'),
            ];
        }

        return $parts;
    }

    // -----------------------------------------------------------------
    // Recycle-bin internals
    // -----------------------------------------------------------------

    /**
     * The entity types the bin can show right now: whitelisted, the table still
     * has a `deleted_at` column, and how many rows are sitting in it.
     *
     * @return array<string,array{label:string,singular:string,icon:string,count:int}>
     */
    private function binTypes(): array
    {
        /** @var array<string,array{label:string,singular:string,icon:string,count:int}> $types */
        $types = Cache::remember('recyclebin.types', 60, static function (): array {
            $tables = array_column(self::RECYCLABLE, 'table');

            [$in, $bindings] = Database::inClause(array_values($tables));

            /** @var list<string> $soft */
            $soft = array_map('strval', Database::column(
                'SELECT `TABLE_NAME` FROM `information_schema`.`COLUMNS`
                  WHERE `TABLE_SCHEMA` = DATABASE()
                    AND `COLUMN_NAME` = \'deleted_at\'
                    AND `TABLE_NAME` IN ' . $in,
                $bindings
            ));

            $result = [];

            foreach (self::RECYCLABLE as $key => $definition) {
                if (!in_array($definition['table'], $soft, true)) {
                    continue; // No soft deletes on this table — nothing to bin.
                }

                $result[$key] = [
                    'label'    => $definition['label'],
                    'singular' => $definition['singular'],
                    'icon'     => $definition['icon'],
                    'count'    => (int) Database::scalar(
                        'SELECT COUNT(*) FROM ' . Database::quoteIdentifier($definition['table']) . ' WHERE `deleted_at` IS NOT NULL'
                    ),
                ];
            }

            return $result;
        }, self::BIN_CACHE_GROUP);

        return $types;
    }

    /**
     * The bin types this admin may actually work with.
     *
     * The cached map is shared by every operator, so the per-admin filter has
     * to happen here rather than inside the cache callback. A type the admin
     * cannot manage is not merely un-restorable — it is not listed at all,
     * because a payout account's holder name and a deleted administrator's
     * email are not something `recyclebin.manage` alone should show.
     *
     * @return array<string,array{label:string,singular:string,icon:string,count:int}>
     */
    private function accessibleBinTypes(Request $request): array
    {
        $adminId = $this->adminId($request);
        $visible = [];

        foreach ($this->binTypes() as $key => $summary) {
            $extra = self::RECYCLABLE[$key]['manage'] ?? '';

            if ($extra !== '' && !PermissionService::adminCan($adminId, $extra)) {
                continue;
            }

            $visible[$key] = $summary;
        }

        return $visible;
    }

    /**
     * Resolve `type` + `id` from the request into a real soft-deleted row.
     *
     * @return array{0:string,1:array{table:string,label:string,singular:string,icon:string,title:string,sub:string},2:array<string,mixed>}
     */
    private function binRow(Request $request): array
    {
        $type  = $request->string('type');
        $id    = $request->int('id');
        $types = $this->accessibleBinTypes($request);

        if (!isset($types[$type])) {
            $this->back('error', 'That entity type is not in the recycle bin.', '/admin/recycle-bin');
        }

        if ($id <= 0) {
            $this->back('error', 'No record was selected.', '/admin/recycle-bin?type=' . rawurlencode($type));
        }

        $definition = self::RECYCLABLE[$type];

        $row = Database::selectOne(
            'SELECT `id`, ' . Database::quoteIdentifier($definition['title']) . ' AS `bin_title`, '
            . Database::quoteIdentifier($definition['sub']) . ' AS `bin_sub`, `deleted_at`
               FROM ' . Database::quoteIdentifier($definition['table']) . '
              WHERE `id` = :id AND `deleted_at` IS NOT NULL',
            ['id' => $id]
        );

        if ($row === null) {
            $this->back('error', 'That record is not in the recycle bin.', '/admin/recycle-bin?type=' . rawurlencode($type));
        }

        return [$type, $definition, $row];
    }

    /**
     * Demand the extra permission a sensitive bin type carries, if it has one.
     *
     * `recyclebin.manage` is the permission to work the bin; it is not, on its
     * own, the permission to hand an administrator their login back or to put a
     * payout account back into circulation.
     */
    private function authorizeBinType(Request $request, string $type): void
    {
        $extra = self::RECYCLABLE[$type]['manage'] ?? '';

        if ($extra !== '') {
            $this->authorize($request, $extra);
        }
    }

    /**
     * @param array{singular:string} $definition
     * @param array<string,mixed>    $row
     */
    private function binLabel(array $definition, array $row): string
    {
        $label = trim((string) ($row['bin_title'] ?? ''));

        return $label === '' ? $definition['singular'] . ' #' . (int) $row['id'] : Str::limit($label, 80);
    }

    /**
     * How much else disappears when one of these rows is deleted for real.
     *
     * The child tables come from the database's own foreign keys, restricted to
     * ON DELETE CASCADE, so the number shown is what MySQL will actually
     * remove rather than a guess written into a constant. All of them are
     * counted in a single UNION ALL — `users` alone has thirty cascade
     * children, and one query per child was thirty round trips for one page.
     *
     * The figure is deliberately not cached: it sits next to the permanent
     * delete control, and a child row can appear (a new message, notification
     * or booking for a soft-deleted user) without anything in the bin changing,
     * so a stale number would understate what is about to be destroyed.
     *
     * @param  list<int> $ids
     * @return array<int,array{total:int,tables:array<string,int>}>
     */
    private function cascadeImpact(string $table, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $children = $this->cascadeChildren($table);

        if ($children === []) {
            return [];
        }

        $impact = [];

        foreach ($ids as $id) {
            $impact[$id] = ['total' => 0, 'tables' => []];
        }

        $parts    = [];
        $bindings = [];

        // Database::inClause() returns positional placeholders, so every branch
        // needs its own copy of the id list and the bindings have to be appended
        // in exactly the order the branches are written.
        foreach ($children as $child) {
            [$in, $idBindings] = Database::inClause($ids);

            $childColumn = Database::quoteIdentifier((string) $child['child_column']);

            $parts[] = 'SELECT ? AS `src`, ' . $childColumn . ' AS `parent_id`, COUNT(*) AS `total`
                          FROM ' . Database::quoteIdentifier((string) $child['child_table']) . '
                         WHERE ' . $childColumn . ' IN ' . $in . '
                         GROUP BY ' . $childColumn;

            $bindings[] = (string) $child['child_table'];

            foreach ($idBindings as $value) {
                $bindings[] = $value;
            }
        }

        $rows = Database::select(implode("\n UNION ALL\n", $parts), $bindings);

        foreach ($rows as $row) {
            $childTable = (string) $row['src'];
            $parentId   = (int) $row['parent_id'];
            $count      = (int) $row['total'];

            if ($count === 0 || !isset($impact[$parentId])) {
                continue;
            }

            $impact[$parentId]['total']              += $count;
            $impact[$parentId]['tables'][$childTable] = ($impact[$parentId]['tables'][$childTable] ?? 0) + $count;
        }

        return $impact;
    }

    /**
     * Tables that cascade-delete when a row of `$table` is removed.
     *
     * @return list<array{child_table:string,child_column:string}>
     */
    private function cascadeChildren(string $table): array
    {
        /** @var list<array{child_table:string,child_column:string}> $children */
        $children = Cache::remember('recyclebin.cascade.' . $table, 3600, static function () use ($table): array {
            /** @var list<array{child_table:string,child_column:string}> $rows */
            $rows = Database::select(
                "SELECT kcu.`TABLE_NAME` AS `child_table`, kcu.`COLUMN_NAME` AS `child_column`
                   FROM `information_schema`.`KEY_COLUMN_USAGE` kcu
                   INNER JOIN `information_schema`.`REFERENTIAL_CONSTRAINTS` rc
                           ON rc.`CONSTRAINT_SCHEMA` = kcu.`CONSTRAINT_SCHEMA`
                          AND rc.`CONSTRAINT_NAME` = kcu.`CONSTRAINT_NAME`
                  WHERE kcu.`TABLE_SCHEMA` = DATABASE()
                    AND kcu.`REFERENCED_TABLE_NAME` = :table
                    AND kcu.`REFERENCED_COLUMN_NAME` = 'id'
                    AND rc.`DELETE_RULE` = 'CASCADE'
                  ORDER BY kcu.`TABLE_NAME`",
                ['table' => $table]
            );

            return $rows;
        }, self::SCHEMA_CACHE_GROUP);

        return $children;
    }
}
