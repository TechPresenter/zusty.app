<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Services\AccountDeletionService;

/**
 * Privacy requests: the self-service data exports and account deletions
 * users make from the apps.
 *
 * Read-only on purpose. Both actions belong to the user and are
 * re-authenticated in the app. This screen is the operator's record of them:
 * when an export was made and how often its link was used, and when accounts
 * were deleted and why people say they left. A deletion row holds no name or
 * contact details, because the erasure removed them.
 */
final class PrivacyController extends BaseAdminController
{
    /** GET /admin/privacy-requests */
    public function index(Request $request): never
    {
        $tab    = $request->string('tab') === 'exports' ? 'exports' : 'deletions';
        $params = $this->listParams($request, ['id'], 'id');
        $limit  = (int) $params['per_page'];
        $offset = (int) $params['offset'];

        if ($tab === 'deletions') {
            $total = (int) Database::scalar('SELECT COUNT(*) FROM `account_deletion_requests`');
            $rows  = Database::select(
                "SELECT `id`, `user_id`, `account_type`, `reason_code`, `reason_text`, `verified_via`, `platform`,
                        `summary`, `created_at`, `completed_at`
                   FROM `account_deletion_requests`
                  ORDER BY `id` DESC
                  LIMIT {$limit} OFFSET {$offset}"
            );

            foreach ($rows as &$row) {
                $summary         = json_decode((string) ($row['summary'] ?? ''), true);
                $row['retained'] = is_array($summary['retained'] ?? null) ? $summary['retained'] : [];
            }
            unset($row);
        } else {
            $total = (int) Database::scalar('SELECT COUNT(*) FROM `data_export_requests`');
            $rows  = Database::select(
                "SELECT e.`id`, e.`user_id`, e.`status`, e.`file_size`, e.`download_count`, e.`max_downloads`,
                        e.`verified_via`, e.`ip_address`, e.`error`, e.`created_at`, e.`expires_at`, e.`last_downloaded_at`,
                        u.`name`, u.`email`, u.`account_type`, u.`deleted_at` AS `user_deleted_at`
                   FROM `data_export_requests` e
                   JOIN `users` u ON u.`id` = e.`user_id`
                  ORDER BY e.`id` DESC
                  LIMIT {$limit} OFFSET {$offset}"
            );
        }

        $since = ['since' => date('Y-m-d H:i:s', strtotime('-30 days'))];

        $this->view($request, 'admin.privacy.index', [
            'pageTitle'    => 'Privacy requests',
            'pageSubtitle' => 'Data exports and account deletions made from the apps',
            'tab'          => $tab,
            'rows'         => $rows,
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'summary'      => [
                'deletions_30d' => (int) Database::scalar(
                    'SELECT COUNT(*) FROM `account_deletion_requests` WHERE `created_at` >= :since',
                    $since
                ),
                'exports_30d'   => (int) Database::scalar(
                    'SELECT COUNT(*) FROM `data_export_requests` WHERE `created_at` >= :since',
                    $since
                ),
                'live_exports'  => (int) Database::scalar(
                    "SELECT COUNT(*) FROM `data_export_requests` WHERE `status` = 'ready' AND `expires_at` > NOW()"
                ),
                'reasons'       => Database::select(
                    'SELECT `reason_code`, COUNT(*) AS `n` FROM `account_deletion_requests`
                      GROUP BY `reason_code` ORDER BY `n` DESC'
                ),
            ],
            'reasonLabels' => self::reasonLabels(),
        ]);
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
