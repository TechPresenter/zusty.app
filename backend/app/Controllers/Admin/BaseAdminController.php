<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Core\Validator;
use Zuste\Core\View;
use Zuste\Services\ActivityLogger;
use Zuste\Services\PermissionService;

/**
 * Shared behaviour for admin panel controllers: view rendering with the shell
 * context, permission checks, flash redirects, list filters and CSV export.
 */
abstract class BaseAdminController
{
    /**
     * Render an admin view inside the shell layout.
     *
     * @param array<string,mixed> $data
     */
    protected function view(Request $request, string $template, array $data = []): never
    {
        $admin = $request->attribute('admin');

        View::share('admin', is_array($admin) ? $admin : []);
        View::share('permissions', (array) $request->attribute('permissions', []));
        View::share('badges', $this->badges());

        View::display($template, $data);
    }

    /**
     * Counts rendered as sidebar badges. Cheap indexed COUNT(*) queries.
     *
     * @return array<string,int>
     */
    protected function badges(): array
    {
        static $badges = null;

        if ($badges !== null) {
            return $badges;
        }

        try {
            $badges = [
                'pending_creators'    => (int) Database::scalar("SELECT COUNT(*) FROM `creators` WHERE `status` = 'pending_review' AND `deleted_at` IS NULL"),
                'pending_withdrawals' => (int) Database::scalar("SELECT COUNT(*) FROM `withdrawal_requests` WHERE `status` = 'pending'"),
                'open_tickets'        => (int) Database::scalar("SELECT COUNT(*) FROM `support_tickets` WHERE `status` IN ('open','pending_agent')"),
                'open_reports'        => (int) Database::scalar("SELECT COUNT(*) FROM `reports` WHERE `status` IN ('open','investigating')"),
                'open_disputes'       => (int) Database::scalar("SELECT COUNT(*) FROM `booking_disputes` WHERE `status` IN ('open','under_review')"),
                'new_messages'        => (int) Database::scalar("SELECT COUNT(*) FROM `contact_messages` WHERE `status` = 'new'"),
                // Deleted accounts are hidden from the KYC queues, so they are
                // left out here too — otherwise the badge counts work the
                // queue never shows.
                'pending_kyc'         => (int) Database::scalar(
                    "SELECT COUNT(*) FROM `kyc_profiles` k
                       JOIN `users` u ON u.`id` = k.`user_id` AND u.`deleted_at` IS NULL
                      WHERE k.`status` IN ('submitted','under_review') AND k.`deleted_at` IS NULL"
                ),
                // Creator profile edits parked for review, plus gallery items
                // awaiting moderation. Counted together because they land on
                // the same screen and an operator wants one number telling them
                // whether that queue needs opening.
                'pending_changes'     => (int) Database::scalar("SELECT COUNT(*) FROM `creator_change_requests` WHERE `status` = 'pending'")
                                       + (int) Database::scalar("SELECT COUNT(*) FROM `creator_portfolio` WHERE `moderation_status` = 'pending' AND `deleted_at` IS NULL"),
            ];
        } catch (\Throwable) {
            $badges = [];
        }

        return $badges;
    }

    /** The signed-in admin row. */
    protected function admin(Request $request): array
    {
        $admin = $request->attribute('admin');

        if (!is_array($admin)) {
            throw HttpException::unauthorized();
        }

        return $admin;
    }

    protected function adminId(Request $request): int
    {
        return (int) $this->admin($request)['id'];
    }

    /** Abort unless the admin holds the permission. */
    /**
     * Does the caller hold [$permission]?
     *
     * The non-throwing counterpart to {@see authorize()}, for deciding what to
     * SHOW rather than what to allow — a tab the caller cannot open should not
     * be advertised in the first place.
     */
    protected function can(Request $request, string $permission): bool
    {
        return PermissionService::adminCan($this->adminId($request), $permission);
    }

    protected function authorize(Request $request, string $permission): void
    {
        if (!PermissionService::adminCan($this->adminId($request), $permission)) {
            if ($request->expectsJson()) {
                Response::forbidden('You do not have the "' . $permission . '" permission.');
            }

            Session::toast('error', 'You do not have permission to do that.');
            Response::redirect('/admin');
        }
    }

    /**
     * @param  array<string,string> $rules
     * @return array<string,mixed>
     */
    protected function validate(Request $request, array $rules, array $messages = []): array
    {
        return Validator::validate($request->all(), $rules, $messages);
    }

    /** Redirect with a toast. */
    protected function back(string $type, string $message, ?string $url = null): never
    {
        Session::toast($type, $message);
        Response::redirect($url ?? Session::previousUrl() ?? '/admin');
    }

    /** Respond to either an AJAX call or a normal form post. */
    protected function respond(Request $request, string $message, ?string $redirect = null, array $data = []): never
    {
        if ($request->expectsJson()) {
            Response::success($data === [] ? null : $data, $message);
        }

        $this->back('success', $message, $redirect);
    }

    /** Record an audited change. */
    protected function log(Request $request, string $action, ?string $subjectType = null, int|string|null $subjectId = null, string $description = '', array $properties = []): void
    {
        ActivityLogger::admin($this->admin($request), $action, $subjectType, $subjectId, $description, $properties);
    }

    // -----------------------------------------------------------------
    // Listing helpers
    // -----------------------------------------------------------------

    /**
     * Normalise list-screen query parameters.
     *
     * @param  list<string> $sortable
     * @return array{search:string,page:int,per_page:int,offset:int,sort_by:string,sort_dir:string,from:string,to:string}
     */
    protected function listParams(Request $request, array $sortable, string $defaultSort = 'id'): array
    {
        $perPage = $request->perPage(25, 200);
        $page    = $request->page();

        return [
            'search'   => $request->string('q'),
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
            'sort_by'  => $request->sortColumn($sortable, $defaultSort),
            'sort_dir' => $request->sortDirection('DESC'),
            'from'     => $request->string('from'),
            'to'       => $request->string('to'),
        ];
    }

    /**
     * Build a pagination descriptor for the view.
     *
     * @return array{total:int,page:int,per_page:int,last_page:int,from:int,to:int}
     */
    protected function paginationMeta(int $total, int $page, int $perPage): array
    {
        $lastPage = (int) max(1, ceil($total / max(1, $perPage)));

        return [
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
            'from'      => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'to'        => min($page * $perPage, $total),
        ];
    }

    /**
     * Stream a CSV export.
     *
     * Values are prefixed with an apostrophe when they begin with a formula
     * character, which stops Excel executing =cmd|… injections.
     *
     * @param list<string>              $headers
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $columns
     */
    protected function exportCsv(string $filename, array $headers, array $rows, array $columns): never
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw HttpException::server('Could not build the export.');
        }

        // UTF-8 BOM so Excel renders ₹ and accented names correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                $value = is_scalar($value) ? (string) $value : (string) json_encode($value);

                if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $value = "'" . $value;
                }

                $line[] = $value;
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        Response::download(
            $csv,
            $filename . '-' . date('Y-m-d-His') . '.csv',
            'text/csv; charset=utf-8'
        );
    }

    /**
     * Append a date range to a WHERE clause.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $bindings
     */
    protected function applyDateRange(array &$clauses, array &$bindings, string $column, string $from, string $to): void
    {
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $clauses[]        = $column . ' >= :range_from';
            $bindings['range_from'] = $from . ' 00:00:00';
        }

        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $clauses[]      = $column . ' <= :range_to';
            $bindings['range_to'] = $to . ' 23:59:59';
        }
    }

    protected function currency(): string
    {
        return Config::settingString('general', 'default_currency', 'INR');
    }
}
