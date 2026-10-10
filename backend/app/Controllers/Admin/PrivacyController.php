<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\DataExportService;
use Zuste\Services\PrivacyService;

/**
 * Privacy requests: data exports and account deletions, the ones people make
 * themselves in the apps and the ones support makes for them.
 *
 * People export their data and delete their account from Settings → Privacy
 * & data, after confirming it is them. Someone who cannot use the app writes
 * in instead, and support acts for them from the "Act on a request" tab once
 * it has confirmed the request comes from the account's owner:
 *
 *  - Erase runs AccountDeletionService::delete() in operator mode: the same
 *    erasure, blockers and forfeit rules as the app, recorded with the staff
 *    member and their note on how the request was confirmed.
 *  - Export runs DataExportService::create() in operator mode: the download
 *    link is emailed to the account's own address and is never shown here.
 *
 * The other tabs are the record of both: when an export was made and how its
 * link was used; when accounts were deleted, how it was confirmed, by whom,
 * why people say they left, and whether the clean-up after the erasure has
 * finished. A deletion row holds no name or contact details, because the
 * erasure removed them.
 */
final class PrivacyController extends BaseAdminController
{
    /** After this many failed clean-up attempts a deletion needs a person. */
    private const ATTENTION_AFTER = 8;

    /**
     * How the old self-service closure recorded itself: one audit row, with
     * this exact description, about the account (aliased `u`). Staff
     * deletions never wrote it, which is what tells the two apart.
     */
    private const LEGACY_CLOSURE = "SELECT 1 FROM `activity_logs` l
                                     WHERE l.`action` = 'account.deleted' AND l.`description` = 'Account deletion requested.'
                                       AND l.`subject_type` = 'user' AND l.`subject_id` = u.`id`";

    /** GET /admin/privacy-requests */
    public function index(Request $request): never
    {
        $tab = match ($request->string('tab')) {
            'exports' => 'exports',
            'act'     => 'act',
            default   => 'deletions',
        };

        $params = $this->listParams($request, ['id'], 'id');
        $limit  = (int) $params['per_page'];
        $offset = (int) $params['offset'];
        $rows   = [];
        $total  = 0;
        $act    = null;

        if ($tab === 'deletions') {
            $total = (int) Database::scalar('SELECT COUNT(*) FROM `account_deletion_requests`');
            $rows  = Database::select(
                "SELECT d.`id`, d.`user_id`, d.`account_type`, d.`reason_code`, d.`reason_text`, d.`verified_via`,
                        d.`platform`, d.`summary`, d.`created_at`, d.`completed_at`, d.`pending_steps`, d.`attempts`,
                        d.`next_attempt_at`, d.`last_error`, d.`forfeited_amount`, d.`forfeited_currency`,
                        d.`performed_by_admin_id`, a.`name` AS `performed_by_name`
                   FROM `account_deletion_requests` d
                   LEFT JOIN `admins` a ON a.`id` = d.`performed_by_admin_id`
                  ORDER BY d.`id` DESC
                  LIMIT {$limit} OFFSET {$offset}"
            );

            foreach ($rows as &$row) {
                $summary          = json_decode((string) ($row['summary'] ?? ''), true);
                $row['retained']  = is_array($summary['retained'] ?? null) ? $summary['retained'] : [];
                $row['reapplied'] = !empty($summary['reapplied_after_restore']);
                $row['cleanup']   = self::cleanupState($row);
            }
            unset($row);
        } elseif ($tab === 'exports') {
            // has_link says whether a download link is still live, without
            // ever reading the token hash itself into the page.
            $total = (int) Database::scalar('SELECT COUNT(*) FROM `data_export_requests`');
            $rows  = Database::select(
                "SELECT e.`id`, e.`user_id`, e.`status`, e.`file_size`, e.`download_count`, e.`max_downloads`,
                        e.`verified_via`, e.`requested_by_admin_id`, e.`token_hash` IS NOT NULL AS `has_link`,
                        e.`ip_address`, e.`error`, e.`created_at`, e.`expires_at`, e.`last_downloaded_at`,
                        u.`name`, u.`email`, u.`account_type`, u.`deleted_at` AS `user_deleted_at`,
                        a.`name` AS `requested_by_name`
                   FROM `data_export_requests` e
                   JOIN `users` u ON u.`id` = e.`user_id`
                   LEFT JOIN `admins` a ON a.`id` = e.`requested_by_admin_id`
                  ORDER BY e.`id` DESC
                  LIMIT {$limit} OFFSET {$offset}"
            );

            foreach ($rows as &$row) {
                $row['state'] = DataExportService::state($row);
            }
            unset($row);
        } else {
            $act = $this->actContext($request);
        }

        $since = ['since' => date('Y-m-d H:i:s', strtotime('-30 days'))];

        $this->view($request, 'admin.privacy.index', [
            'pageTitle'    => 'Privacy requests',
            'pageSubtitle' => 'Data exports and account deletions, from the apps and on request',
            'tab'          => $tab,
            'rows'         => $rows,
            'act'          => $act,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'summary'      => [
                'deletions_30d'    => (int) Database::scalar(
                    'SELECT COUNT(*) FROM `account_deletion_requests` WHERE `created_at` >= :since',
                    $since
                ),
                'exports_30d'      => (int) Database::scalar(
                    'SELECT COUNT(*) FROM `data_export_requests` WHERE `created_at` >= :since',
                    $since
                ),
                // A link someone could still use right now: ready, not
                // expired, downloads left, and not revoked.
                'live_exports'     => (int) Database::scalar(
                    "SELECT COUNT(*) FROM `data_export_requests`
                      WHERE `status` = 'ready' AND `expires_at` > NOW()
                        AND `download_count` < `max_downloads` AND `token_hash` IS NOT NULL"
                ),
                'cleanups_pending' => (int) Database::scalar(
                    'SELECT COUNT(*) FROM `account_deletion_requests` WHERE `completed_at` IS NULL'
                ),
                'reasons'          => Database::select(
                    'SELECT `reason_code`, COUNT(*) AS `n` FROM `account_deletion_requests`
                      GROUP BY `reason_code` ORDER BY `n` DESC'
                ),
            ],
            'reasonLabels' => self::reasonLabels(),
        ]);
    }

    /**
     * POST /admin/privacy-requests/erase
     *
     * Erase an account on a request support has confirmed. The erasure is the
     * same one the app runs: anything still in progress refuses it, a small
     * balance that cannot be paid out must be given up explicitly, and the
     * owner gets the usual confirmation email.
     */
    public function erase(Request $request): never
    {
        // The route checks this too; a second check here keeps the action
        // safe if it is ever routed without the middleware.
        $this->authorize($request, 'users.delete');

        $data = $this->validate($request, [
            'user_id'      => 'required|integer|min_value:1',
            'confirmation' => 'required|string|max:20',
            'note'         => 'required|string|min:3|max:500',
            'forfeit'      => 'nullable|numeric|min_value:0',
        ], [
            'confirmation.required' => 'Type ' . PrivacyService::CONFIRM_PHRASE . ' to confirm the erasure.',
            'note.required'         => 'Say how you confirmed that the request comes from the account\'s owner.',
        ]);

        $userId = (int) $data['user_id'];
        $back   = '/admin/privacy-requests?tab=act&user=' . $userId;

        if (strtoupper(trim((string) $data['confirmation'])) !== PrivacyService::CONFIRM_PHRASE) {
            $this->back('error', 'Type ' . PrivacyService::CONFIRM_PHRASE . ' to confirm the erasure. Nothing was changed.', $back);
        }

        $user = self::account($userId);

        if ($user === null) {
            $this->back('error', 'No account has the ID ' . $userId . '.', '/admin/privacy-requests?tab=act');
        }

        if (!in_array((string) $user['account_type'], ['brand', 'creator'], true)) {
            $this->back('error', 'Only brand and creator accounts can be erased here. A guest account holds no name or contact details.', $back);
        }

        // Ticking the box gives up the amount the page showed. The erasure
        // re-reads the wallet and refuses if the balance has changed since.
        $forfeit = isset($data['forfeit']) && $data['forfeit'] !== null && $data['forfeit'] !== ''
            ? (float) $data['forfeit']
            : null;

        try {
            $summary = AccountDeletionService::delete($user, 'operator', 'other', null, [
                'admin_id'        => $this->adminId($request),
                'platform'        => 'admin',
                'note'            => trim((string) $data['note']),
                'via'             => 'panel',
                'forfeit_balance' => $forfeit,
            ]);
        } catch (HttpException $e) {
            $this->back('error', self::refusal($e), $back);
        }

        $this->back(
            'success',
            !empty($summary['already_deleted']) ? 'This account was already erased.' : 'Account #' . $userId . ' erased.',
            $back
        );
    }

    /**
     * POST /admin/privacy-requests/export
     *
     * Prepare a copy of an account's data on a confirmed request. The link is
     * emailed to the account's own address; this screen only says where it
     * went and until when it works.
     */
    public function export(Request $request): never
    {
        $this->authorize($request, 'users.edit');

        $data   = $this->validate($request, ['user_id' => 'required|integer|min_value:1']);
        $userId = (int) $data['user_id'];
        $back   = '/admin/privacy-requests?tab=act&user=' . $userId;
        $user   = self::account($userId);

        if ($user === null) {
            $this->back('error', 'No account has the ID ' . $userId . '.', '/admin/privacy-requests?tab=act');
        }

        if (!in_array((string) $user['account_type'], ['brand', 'creator'], true)) {
            $this->back('error', 'Only brand and creator accounts have data to export.', $back);
        }

        if ($user['deleted_at'] !== null) {
            $this->back('error', 'This account has been deleted, so there is no copy of its data to make.', $back);
        }

        try {
            $export = DataExportService::create(
                $user,
                'operator',
                ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
                $this->adminId($request)
            );
        } catch (HttpException $e) {
            $this->back('error', $e->getMessage(), $back);
        }

        // Never the download link: it is a credential, and it goes only to
        // the account's own email address.
        $notice  = is_array($export['security_notice'] ?? null) ? $export['security_notice'] : null;
        $expires = !empty($export['expires_at']) ? strtotime((string) $export['expires_at']) : false;
        $until   = $expires !== false ? ' It works until ' . date('j M Y, g:i A', $expires) . '.' : '';

        $this->back(
            'success',
            $notice !== null && (string) ($notice['destination'] ?? '') !== ''
                ? 'A copy of this account\'s data is ready. The download link was emailed to ' . $notice['destination'] . '.' . $until
                : 'A copy of this account\'s data is ready, but the email with its download link could not be sent. The link is never shown here.',
            $back
        );
    }

    // -----------------------------------------------------------------
    // The "Act on a request" tab
    // -----------------------------------------------------------------

    /**
     * Everything the tab shows: the account asked for (by ID or email), what
     * an erasure would do to it, whether an export can be made now, and the
     * accounts staff deleted without erasing them.
     *
     * @return array<string,mixed>
     */
    private function actContext(Request $request): array
    {
        $query   = trim($request->string('user'));
        $context = [
            'query'      => $query,
            'user'       => null,
            'error'      => null,
            'erasure'    => null,
            'preflight'  => null,
            'export'     => null,
            'not_erased' => self::notErased(),
            'legacy'     => self::legacyCount(),
        ];

        if ($query === '') {
            return $context;
        }

        $user = ctype_digit($query) ? self::account((int) $query) : self::accountByEmail($query);

        if ($user === null) {
            $context['error'] = 'No account matches "' . Str::limit($query, 80) . '".';

            return $context;
        }

        if (!in_array((string) $user['account_type'], ['brand', 'creator'], true)) {
            $context['error'] = 'Account #' . (int) $user['id'] . ' is a guest account. Guests give no name or contact details, so there is nothing to export or erase.';

            return $context;
        }

        $userId = (int) $user['id'];

        $context['user'] = [
            'id'            => $userId,
            'name'          => (string) $user['name'],
            'email'         => (string) ($user['email'] ?? ''),
            'email_masked'  => (string) ($user['email'] ?? '') !== '' ? Str::maskEmail((string) $user['email']) : '',
            'phone'         => !empty($user['phone']) ? trim((string) ($user['phone_country_code'] ?? '') . ' ' . (string) $user['phone']) : '',
            'account_type'  => (string) $user['account_type'],
            'status'        => (string) $user['status'],
            'created_at'    => (string) $user['created_at'],
            'deleted_at'    => $user['deleted_at'] === null ? null : (string) $user['deleted_at'],
        ];

        $context['erasure'] = Database::selectOne(
            'SELECT `id`, `verified_via`, `created_at`, `completed_at`
               FROM `account_deletion_requests` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 1',
            ['id' => $userId]
        );

        if ($context['erasure'] === null) {
            $context['preflight'] = AccountDeletionService::preflight($user);
        }

        if ($user['deleted_at'] === null) {
            $context['export'] = DataExportService::status($userId);
        }

        return $context;
    }

    /**
     * Accounts staff deleted from the panel before Delete became an erasure:
     * hidden, but with their personal data still in place. Each one is
     * erased deliberately, from this tab, rather than in bulk. Accounts
     * closed in the app before erasure existed are counted apart
     * ({@see legacyCount()}), because a script erases those.
     *
     * @return list<array<string,mixed>>
     */
    private static function notErased(): array
    {
        return Database::select(
            "SELECT u.`id`, u.`name`, u.`email`, u.`account_type`, u.`deleted_at`
               FROM `users` u
              WHERE u.`status` = 'deleted' AND u.`deleted_at` IS NOT NULL AND u.`email` IS NOT NULL
                AND u.`account_type` IN ('brand', 'creator')
                AND NOT EXISTS (SELECT 1 FROM `account_deletion_requests` d WHERE d.`user_id` = u.`id`)
                AND NOT EXISTS (" . self::LEGACY_CLOSURE . ")
              ORDER BY u.`deleted_at` DESC, u.`id` DESC
              LIMIT 50"
        );
    }

    /** Accounts their owners closed in the app before erasure existed, still unerased. */
    private static function legacyCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM `users` u
              WHERE u.`status` = 'deleted' AND u.`account_type` IN ('brand', 'creator') AND u.`name` <> 'Deleted user'
                AND NOT EXISTS (SELECT 1 FROM `account_deletion_requests` d WHERE d.`user_id` = u.`id`)
                AND EXISTS (" . self::LEGACY_CLOSURE . ')'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A users row by ID, deleted or not: an account staff soft-deleted is
     * still erased from here.
     *
     * @return array<string,mixed>|null
     */
    private static function account(int $userId): ?array
    {
        return $userId > 0
            ? Database::selectOne('SELECT * FROM `users` WHERE `id` = :id', ['id' => $userId])
            : null;
    }

    /**
     * A users row by email. A deleted account frees its address for a new
     * sign-up, so two rows can share one: the live account wins.
     *
     * @return array<string,mixed>|null
     */
    private static function accountByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `users` WHERE `email` = :email ORDER BY (`deleted_at` IS NULL) DESC, `id` DESC LIMIT 1',
            ['email' => $email]
        );
    }

    /**
     * Where the clean-up after an erasure stands. The erasure itself commits
     * at once; removing the files, data exports, KYC documents and the
     * Firebase sign-in record runs after it, and privacy:finish-deletions
     * retries whatever failed.
     *
     * @param  array<string,mixed> $row
     * @return array{state:string,steps:list<string>}
     */
    private static function cleanupState(array $row): array
    {
        if (!empty($row['completed_at'])) {
            return ['state' => 'completed', 'steps' => []];
        }

        $labels = [
            'files'    => 'uploaded files',
            'exports'  => 'data exports',
            'kyc'      => 'identity documents',
            'firebase' => 'Google/Apple sign-in record',
        ];

        $steps = [];

        foreach ((array) (json_decode((string) ($row['pending_steps'] ?? ''), true) ?: []) as $step) {
            $steps[] = $labels[(string) $step] ?? (string) $step;
        }

        return [
            'state' => (int) ($row['attempts'] ?? 0) >= self::ATTENTION_AFTER ? 'attention' : 'pending',
            'steps' => $steps,
        ];
    }

    /**
     * What to tell the operator when the erasure refuses. A blocked account
     * lists everything in the way at once, as the app does.
     */
    private static function refusal(HttpException $e): string
    {
        $messages = [];

        foreach ($e->errors() as $lines) {
            foreach ((array) $lines as $line) {
                $messages[] = (string) $line;
            }
        }

        $detail = $messages === [] ? $e->getMessage() : implode(' ', array_unique($messages));

        return 'The account was not erased. ' . $detail;
    }

    /**
     * Reason codes to the words users chose from. The one code whose label
     * differs by side of the marketplace gets a label that covers both.
     *
     * @return array<string,string>
     */
    private static function reasonLabels(): array
    {
        $labels = [];

        foreach (AccountDeletionService::reasons('brand') as $reason) {
            $labels[$reason['code']] = $reason['label'];
        }

        $labels['opportunities'] = 'Not enough bookings, or couldn\'t find the right creators';

        return $labels;
    }
}
