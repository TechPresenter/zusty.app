<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Cache;
use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\Session;
use Zuste\Helpers\Str;
use Zuste\Services\CampaignService;

/**
 * Marketing: outbound campaigns across email, SMS and push, plus the
 * newsletter list they draw on.
 *
 * Reading needs `marketing.view`, editing needs `marketing.manage`, and
 * anything that puts a message on a delivery queue needs `marketing.send`.
 */
final class MarketingController extends BaseAdminController
{
    private const SORTABLE = [
        'created_at', 'name', 'status', 'channel', 'recipients_count', 'sent_count', 'scheduled_at',
    ];

    private const RECIPIENT_SORTABLE = ['id', 'destination', 'status', 'sent_at'];

    private const SUBSCRIBER_SORTABLE = ['subscribed_at', 'email', 'status', 'source'];

    private const RECIPIENT_STATUSES = [
        'pending', 'sent', 'failed', 'opened', 'clicked', 'bounced', 'unsubscribed',
    ];

    /** How many recipients one "Send now" or "Send next batch" click handles. */
    private const BATCH_SIZE = 50;

    // =================================================================
    // Campaign list
    // =================================================================

    /** GET /admin/marketing */
    public function index(Request $request): never
    {
        $this->authorize($request, 'marketing.view');

        $params  = $this->listParams($request, self::SORTABLE, 'created_at');
        $status  = $request->string('status');
        $channel = $request->string('channel');

        $clauses  = ['mc.`deleted_at` IS NULL'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = '(mc.`name` LIKE :search OR mc.`subject` LIKE :search)';
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if (in_array($status, CampaignService::STATUSES, true)) {
            $clauses[]          = 'mc.`status` = :status';
            $bindings['status'] = $status;
        }

        // Retired channels are filterable because rows saved on an earlier build
        // still carry them; they are simply never offered on the form.
        if (in_array($channel, CampaignService::knownChannels(), true)) {
            $clauses[]           = 'mc.`channel` = :channel';
            $bindings['channel'] = $channel;
        }

        $this->applyDateRange($clauses, $bindings, 'mc.`created_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                "SELECT mc.`id`, mc.`name`, mc.`channel`, mc.`subject`, mc.`audience`, mc.`status`,
                        mc.`scheduled_at`, mc.`recipients_count`, mc.`sent_count`, mc.`failed_count`,
                        mc.`opened_count`, mc.`clicked_count`, mc.`unsubscribed_count`,
                        mc.`created_at`, mc.`completed_at`, a.`name` AS `created_by_name`
                   FROM `marketing_campaigns` mc
                   LEFT JOIN `admins` a ON a.`id` = mc.`created_by`
                  WHERE $where
                  ORDER BY mc.`created_at` DESC
                  LIMIT 10000",
                $bindings
            );

            $this->exportCsv(
                'marketing-campaigns',
                ['ID', 'Name', 'Channel', 'Subject', 'Audience', 'Status', 'Scheduled', 'Recipients',
                    'Sent', 'Failed', 'Opened', 'Clicked', 'Unsubscribed', 'Created', 'Completed', 'Created by'],
                $all,
                ['id', 'name', 'channel', 'subject', 'audience', 'status', 'scheduled_at', 'recipients_count',
                    'sent_count', 'failed_count', 'opened_count', 'clicked_count', 'unsubscribed_count',
                    'created_at', 'completed_at', 'created_by_name']
            );
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM `marketing_campaigns` mc WHERE $where", $bindings);

        $rows = Database::select(
            "SELECT mc.*, a.`name` AS `created_by_name`
               FROM `marketing_campaigns` mc
               LEFT JOIN `admins` a ON a.`id` = mc.`created_by`
              WHERE $where
              ORDER BY mc.`{$params['sort_by']}` {$params['sort_dir']}, mc.`id` DESC
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        $summary = Database::selectOne(
            "SELECT COUNT(*)                                        AS `total`,
                    COALESCE(SUM(`status` = 'draft'), 0)            AS `draft`,
                    COALESCE(SUM(`status` = 'scheduled'), 0)        AS `scheduled`,
                    COALESCE(SUM(`status` = 'sending'), 0)          AS `sending`,
                    COALESCE(SUM(`status` = 'sent'), 0)             AS `sent`,
                    COALESCE(SUM(`sent_count`), 0)                  AS `messages_sent`,
                    COALESCE(SUM(`opened_count`), 0)                AS `messages_opened`,
                    COALESCE(SUM(`clicked_count`), 0)               AS `messages_clicked`
               FROM `marketing_campaigns`
              WHERE `deleted_at` IS NULL"
        ) ?? [];

        $this->view($request, 'admin.marketing.index', [
            'pageTitle'    => 'Campaigns',
            'pageSubtitle' => 'Email, SMS and push campaigns, and who they reached.',
            'rows'         => $rows,
            'summary'      => $summary,
            'schedulerOn'  => CampaignService::schedulerConfigured(),
            // Open and click figures are only live while a tracking endpoint is
            // configured. Without one the list would otherwise present stale
            // counters as a current open rate.
            'trackingOn'   => CampaignService::trackingConfigured(),
            'subscribers'  => (int) Database::scalar("SELECT COUNT(*) FROM `newsletter_subscribers` WHERE `status` = 'subscribed'"),
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            'filters'      => [
                'q'        => $params['search'],
                'status'   => $status,
                'channel'  => $channel,
                'from'     => $params['from'],
                'to'       => $params['to'],
                'sort_by'  => $params['sort_by'],
                'sort_dir' => $params['sort_dir'],
            ],
        ]);
    }

    // =================================================================
    // Create / edit
    // =================================================================

    /** GET /admin/marketing/create */
    public function create(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $this->view($request, 'admin.marketing.form', [
            'pageTitle'    => 'New campaign',
            'pageSubtitle' => 'Write it, choose who receives it, then preview the real audience.',
            'campaign'      => $this->blankCampaign(),
            'filter'        => [],
            'categories'    => $this->categories(),
            'isNew'         => true,
            'trackingOn'    => CampaignService::trackingConfigured(),
            'unsubscribeOn' => CampaignService::unsubscribeConfigured(),
            'schedulerOn'   => CampaignService::schedulerConfigured(),
        ]);
    }

    /** GET /admin/marketing/{id}/edit */
    public function edit(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $campaign = CampaignService::find($request->routeParamInt('id'));

        if ($campaign === null) {
            $this->back('error', 'That campaign no longer exists.', '/admin/marketing');
        }

        if (in_array((string) $campaign['status'], ['sending', 'sent'], true)) {
            $this->back(
                'error',
                'A campaign that is ' . CampaignService::statusLabel((string) $campaign['status']) . ' can no longer be edited.',
                '/admin/marketing/' . (int) $campaign['id']
            );
        }

        $this->view($request, 'admin.marketing.form', [
            'pageTitle'    => 'Edit campaign',
            'pageSubtitle' => $campaign['name'],
            'campaign'      => $campaign,
            'filter'        => CampaignService::decodeFilter($campaign['audience_filter'] ?? null),
            'categories'    => $this->categories(),
            'isNew'         => false,
            'trackingOn'    => CampaignService::trackingConfigured(),
            'unsubscribeOn' => CampaignService::unsubscribeConfigured(),
            'schedulerOn'   => CampaignService::schedulerConfigured(),
        ]);
    }

    /** POST /admin/marketing */
    public function store(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $data      = $this->campaignInput($request);
        $adminId   = $this->adminId($request);
        $createdAt = date('Y-m-d H:i:s');

        Database::insert('marketing_campaigns', [
            'name'            => $data['name'],
            'channel'         => $data['channel'],
            'subject'         => $data['subject'] === '' ? null : $data['subject'],
            'preheader'       => $data['preheader'] === '' ? null : $data['preheader'],
            'content'         => $data['content'],
            'audience'        => $data['audience'],
            'audience_filter' => $data['audience_filter'],
            'status'          => 'draft',
            'scheduled_at'    => null,
            'created_by'      => $adminId,
            'created_at'      => $createdAt,
            'updated_at'      => $createdAt,
        ]);

        // The id is read back rather than taken from the insert. Database::statement()
        // runs the slow-query recorder after every statement, and that recorder can
        // issue an INSERT of its own — which replaces the connection's LAST_INSERT_ID()
        // with a row id from another table before insert() ever reads it. That returns a
        // plausible but wrong number, so checking for zero would not catch it. Looking
        // the row up by the values just written is exact either way.
        $campaignId = (int) (Database::scalar(
            'SELECT `id` FROM `marketing_campaigns`
              WHERE `created_by` = :admin_id AND `created_at` = :created_at AND `name` = :name
              ORDER BY `id` DESC LIMIT 1',
            ['admin_id' => $adminId, 'created_at' => $createdAt, 'name' => $data['name']]
        ) ?? 0);

        if ($campaignId === 0) {
            $this->back('error', 'The campaign could not be saved. Try again.', '/admin/marketing');
        }

        $note = '';

        if ($data['scheduled_at'] !== null) {
            $moved = CampaignService::transition($campaignId, 'scheduled', ['scheduled_at' => $data['scheduled_at']]);
            $note  = $moved['ok'] ? ' It is scheduled for ' . $data['scheduled_at'] . '.' : ' ' . $moved['message'];
        }

        $this->log($request, 'campaign.created', 'marketing_campaign', $campaignId, 'Created campaign "' . $data['name'] . '".', [
            'channel'  => $data['channel'],
            'audience' => $data['audience'],
        ]);

        $this->respond($request, 'Campaign saved.' . $note, '/admin/marketing/' . $campaignId);
    }

    /** POST /admin/marketing/{id} */
    public function update(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $id       = $request->routeParamInt('id');
        $campaign = CampaignService::find($id);

        if ($campaign === null) {
            $this->back('error', 'That campaign no longer exists.', '/admin/marketing');
        }

        $status = (string) $campaign['status'];

        if (in_array($status, ['sending', 'sent'], true)) {
            $this->back(
                'error',
                'A campaign that is ' . CampaignService::statusLabel($status) . ' can no longer be edited.',
                '/admin/marketing/' . $id
            );
        }

        $data = $this->campaignInput($request, (string) $campaign['channel']);

        Database::update('marketing_campaigns', [
            'name'            => $data['name'],
            'channel'         => $data['channel'],
            'subject'         => $data['subject'] === '' ? null : $data['subject'],
            'preheader'       => $data['preheader'] === '' ? null : $data['preheader'],
            'content'         => $data['content'],
            'audience'        => $data['audience'],
            'audience_filter' => $data['audience_filter'],
            'updated_at'      => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        $note = '';

        // The schedule is a state change, so it goes through the state machine
        // rather than being written straight onto the row.
        if ($data['scheduled_at'] !== null && $status !== 'scheduled') {
            $moved = CampaignService::transition($id, 'scheduled', ['scheduled_at' => $data['scheduled_at']]);
            $note  = $moved['ok'] ? ' It is scheduled for ' . $data['scheduled_at'] . '.' : ' ' . $moved['message'];
        } elseif ($data['scheduled_at'] !== null) {
            Database::update('marketing_campaigns', ['scheduled_at' => $data['scheduled_at']], ['id' => $id]);
            $note = ' The schedule was moved to ' . $data['scheduled_at'] . '.';
        } elseif ($status === 'scheduled') {
            $moved = CampaignService::transition($id, 'draft', ['scheduled_at' => null]);
            $note  = $moved['ok'] ? ' The schedule was cleared, so it is a draft again.' : '';
        }

        $this->log($request, 'campaign.updated', 'marketing_campaign', $id, 'Updated campaign "' . $data['name'] . '".');

        $this->respond($request, 'Campaign saved.' . $note, '/admin/marketing/' . $id);
    }

    /** POST /admin/marketing/{id}/delete */
    public function destroy(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $id       = $request->routeParamInt('id');
        $campaign = CampaignService::find($id);

        if ($campaign === null) {
            $this->back('error', 'That campaign no longer exists.', '/admin/marketing');
        }

        // A paused campaign still has pending recipients waiting on it. Deleting
        // it would hide those from every screen while leaving them queued, and
        // restoring it from the recycle bin would start the send again.
        if (in_array((string) $campaign['status'], ['sending', 'paused'], true)) {
            $this->back(
                'error',
                'Cancel the campaign first. It is ' . CampaignService::statusLabel((string) $campaign['status'])
                    . ' and still has recipients waiting on it.',
                '/admin/marketing/' . $id
            );
        }

        Database::update('marketing_campaigns', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);

        $this->log($request, 'campaign.deleted', 'marketing_campaign', $id, 'Deleted campaign "' . $campaign['name'] . '".');

        $this->respond($request, 'Campaign moved to the recycle bin.', '/admin/marketing');
    }

    // =================================================================
    // Campaign detail
    // =================================================================

    /** GET /admin/marketing/{id} */
    public function show(Request $request): never
    {
        $this->authorize($request, 'marketing.view');

        $id       = $request->routeParamInt('id');
        $campaign = CampaignService::find($id);

        if ($campaign === null) {
            $this->back('error', 'That campaign no longer exists.', '/admin/marketing');
        }

        $params           = $this->listParams($request, self::RECIPIENT_SORTABLE, 'id');
        $recipientStatus  = $request->string('rstatus');

        $clauses  = ['cr.`campaign_id` = :campaign_id'];
        $bindings = ['campaign_id' => $id];

        if ($params['search'] !== '') {
            $clauses[]          = 'cr.`destination` LIKE :search';
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if (in_array($recipientStatus, self::RECIPIENT_STATUSES, true)) {
            $clauses[]           = 'cr.`status` = :rstatus';
            $bindings['rstatus'] = $recipientStatus;
        }

        $where = implode(' AND ', $clauses);

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                "SELECT cr.`destination`, cr.`status`, cr.`sent_at`, cr.`opened_at`, cr.`clicked_at`,
                        cr.`error_message`, u.`name` AS `user_name`
                   FROM `campaign_recipients` cr
                   LEFT JOIN `users` u ON u.`id` = cr.`user_id`
                  WHERE $where
                  ORDER BY cr.`id` ASC
                  LIMIT 50000",
                $bindings
            );

            $this->exportCsv(
                'campaign-' . $id . '-recipients',
                ['Destination', 'Name', 'Status', 'Sent', 'Opened', 'Clicked', 'Error'],
                $all,
                ['destination', 'user_name', 'status', 'sent_at', 'opened_at', 'clicked_at', 'error_message']
            );
        }

        $totalRecipients = (int) Database::scalar(
            "SELECT COUNT(*) FROM `campaign_recipients` cr WHERE $where",
            $bindings
        );

        $recipients = Database::select(
            "SELECT cr.*, u.`name` AS `user_name`
               FROM `campaign_recipients` cr
               LEFT JOIN `users` u ON u.`id` = cr.`user_id`
              WHERE $where
              ORDER BY cr.`{$params['sort_by']}` {$params['sort_dir']}
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        $breakdown = Database::select(
            'SELECT `status`, COUNT(*) AS `total` FROM `campaign_recipients`
              WHERE `campaign_id` = :campaign_id GROUP BY `status`',
            ['campaign_id' => $id]
        );

        $counts = [];
        foreach ($breakdown as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        // The audience can drift after a campaign is written, so this is
        // recomputed rather than read off the row — cached briefly because it
        // is a COUNT(DISTINCT …) over the user table.
        $audienceToday = Cache::remember(
            'marketing:audience:' . $id . ':' . (string) $campaign['updated_at'],
            120,
            static fn (): int => CampaignService::countAudience($campaign),
            'marketing'
        );

        $this->view($request, 'admin.marketing.show', [
            'pageTitle'       => $campaign['name'],
            'pageSubtitle'    => ucfirst((string) $campaign['channel']) . ' campaign · ' . CampaignService::statusLabel((string) $campaign['status']),
            'campaign'        => $campaign,
            'stats'           => CampaignService::stats($id),
            'counts'          => $counts,
            'recipients'      => $recipients,
            'pagination'      => $this->paginationMeta($totalRecipients, $params['page'], $params['per_page']),
            'support'         => CampaignService::channelSupport((string) $campaign['channel']),
            'audienceLabel'   => CampaignService::describeAudience($campaign),
            'audienceToday'   => (int) $audienceToday,
            'allowed'         => CampaignService::allowedTransitions((string) $campaign['status']),
            'trackingOn'      => CampaignService::trackingConfigured(),
            'schedulerOn'     => CampaignService::schedulerConfigured(),
            'batchSize'       => self::BATCH_SIZE,
            'filters'         => [
                'q'        => $params['search'],
                'rstatus'  => $recipientStatus,
                'sort_by'  => $params['sort_by'],
                'sort_dir' => $params['sort_dir'],
            ],
        ]);
    }

    // =================================================================
    // Audience preview (AJAX)
    // =================================================================

    /** POST /admin/marketing/preview-audience */
    public function previewAudience(Request $request): never
    {
        // Stricter than the route's `marketing.view` on purpose: the response
        // carries real addresses and phone numbers, and the only place that
        // offers this button is the campaign form, which needs manage anyway.
        $this->authorize($request, 'marketing.manage');

        $channel  = $request->string('channel', 'email');
        $audience = $request->string('audience', 'all');

        $preview = CampaignService::previewAudience([
            'channel'         => in_array($channel, CampaignService::knownChannels(), true) ? $channel : 'email',
            'audience'        => in_array($audience, CampaignService::AUDIENCES, true) ? $audience : 'all',
            'audience_filter' => $this->filterJson($request),
        ]);

        $sample = [];

        foreach ($preview['sample'] as $row) {
            $sample[] = [
                'name'        => $row['name'] === '' ? 'No name on file' : $row['name'],
                'destination' => $row['destination'],
            ];
        }

        Response::success([
            'total'       => $preview['total'],
            'sample'      => $sample,
            'blocked'     => $preview['blocked'],
            'warnings'    => $preview['warnings'],
            'description' => $preview['description'],
        ], $preview['blocked'] === ''
            ? number_format($preview['total']) . ' recipient' . ($preview['total'] === 1 ? '' : 's') . ' match right now.'
            : $preview['blocked']);
    }

    // =================================================================
    // Dispatch
    // =================================================================

    /** POST /admin/marketing/{id}/send */
    public function send(Request $request): never
    {
        $this->authorize($request, 'marketing.send');

        $id       = $request->routeParamInt('id');
        $campaign = CampaignService::find($id);

        if ($campaign === null) {
            $this->back('error', 'That campaign no longer exists.', '/admin/marketing');
        }

        // Retrying a stopped campaign has to give its failed recipients another
        // go, otherwise queue() would skip every destination already on the
        // list. The reset and the re-queue share one transaction, so a refused
        // retry leaves every original failure reason exactly as it was.
        $queued  = CampaignService::retryAndQueue($id);
        $retried = $queued['retried'];

        if (!$queued['ok']) {
            $this->back('error', $queued['message'], '/admin/marketing/' . $id);
        }

        $this->log($request, 'campaign.queued', 'marketing_campaign', $id, 'Queued campaign "' . $campaign['name'] . '" for sending.', [
            'inserted' => $queued['inserted'],
            'total'    => $queued['total'],
            'retried'  => $retried,
        ]);

        $batch = CampaignService::dispatchBatch($id, self::BATCH_SIZE);

        $this->log($request, 'campaign.dispatched', 'marketing_campaign', $id, 'Dispatched a batch for "' . $campaign['name'] . '".', [
            'sent'      => $batch['sent'],
            'failed'    => $batch['failed'],
            'remaining' => $batch['remaining'],
        ]);

        $retryNote = $retried > 0
            ? number_format($retried) . ' previously failed recipient' . ($retried === 1 ? '' : 's') . ' reset to pending. '
            : '';

        $this->respond($request, $retryNote . $queued['message'] . ' ' . $batch['message'], '/admin/marketing/' . $id);
    }

    /** POST /admin/marketing/{id}/dispatch */
    public function dispatch(Request $request): never
    {
        $this->authorize($request, 'marketing.send');

        $id     = $request->routeParamInt('id');
        $result = CampaignService::dispatchBatch($id, self::BATCH_SIZE);

        if (!$result['ok']) {
            $this->back('error', $result['message'], '/admin/marketing/' . $id);
        }

        $this->log($request, 'campaign.dispatched', 'marketing_campaign', $id, 'Dispatched the next batch.', [
            'sent'      => $result['sent'],
            'failed'    => $result['failed'],
            'remaining' => $result['remaining'],
        ]);

        $this->respond($request, $result['message'], '/admin/marketing/' . $id);
    }

    /** POST /admin/marketing/{id}/pause */
    public function pause(Request $request): never
    {
        $this->authorize($request, 'marketing.send');

        $id     = $request->routeParamInt('id');
        $result = CampaignService::transition($id, 'paused');

        if (!$result['ok']) {
            $this->back('error', $result['message'], '/admin/marketing/' . $id);
        }

        $this->log($request, 'campaign.paused', 'marketing_campaign', $id, 'Paused the campaign.');

        $this->respond($request, 'Campaign paused. Nothing more will be queued until you resume it.', '/admin/marketing/' . $id);
    }

    /** POST /admin/marketing/{id}/resume */
    public function resume(Request $request): never
    {
        $this->authorize($request, 'marketing.send');

        $id       = $request->routeParamInt('id');
        $campaign = CampaignService::find($id);

        if ($campaign === null) {
            $this->back('error', 'That campaign no longer exists.', '/admin/marketing');
        }

        // Resume only ever means "carry on with the recipient list that is
        // already there". The state machine also allows draft -> sending and
        // failed -> sending, but neither of those has pending recipients, so
        // dispatchBatch() would immediately stamp the campaign 'failed' and
        // strand it. Anything that is not paused has to go through Send now,
        // which resolves the audience first.
        $status = (string) $campaign['status'];

        if ($status !== 'paused') {
            $this->back(
                'error',
                'Only a paused campaign can be resumed. This one is ' . CampaignService::statusLabel($status)
                    . ' — use Send now to queue and dispatch it.',
                '/admin/marketing/' . $id
            );
        }

        $result = CampaignService::transition($id, 'sending');

        if (!$result['ok']) {
            $this->back('error', $result['message'], '/admin/marketing/' . $id);
        }

        $this->log($request, 'campaign.resumed', 'marketing_campaign', $id, 'Resumed the campaign.');

        $batch = CampaignService::dispatchBatch($id, self::BATCH_SIZE);

        $this->log($request, 'campaign.dispatched', 'marketing_campaign', $id, 'Dispatched a batch after resuming.', [
            'sent'      => $batch['sent'],
            'failed'    => $batch['failed'],
            'remaining' => $batch['remaining'],
        ]);

        $this->respond($request, 'Campaign resumed. ' . $batch['message'], '/admin/marketing/' . $id);
    }

    /**
     * POST /admin/marketing/{id}/cancel
     *
     * The schema has no `cancelled` state, so cancelling means one of two real
     * things: an unsent campaign goes back to draft, and one that has already
     * started is stopped for good and marked failed, with every remaining
     * recipient carrying the reason.
     */
    public function cancel(Request $request): never
    {
        $this->authorize($request, 'marketing.send');

        $id       = $request->routeParamInt('id');
        $campaign = CampaignService::find($id);

        if ($campaign === null) {
            $this->back('error', 'That campaign no longer exists.', '/admin/marketing');
        }

        $status = (string) $campaign['status'];

        if (in_array($status, ['draft', 'scheduled'], true)) {
            Database::execute(
                "DELETE FROM `campaign_recipients` WHERE `campaign_id` = :campaign_id AND `status` = 'pending'",
                ['campaign_id' => $id]
            );

            if ($status === 'scheduled') {
                CampaignService::transition($id, 'draft', ['scheduled_at' => null]);
            }

            CampaignService::syncCounters($id);

            $this->log($request, 'campaign.cancelled', 'marketing_campaign', $id, 'Cancelled the campaign before it started.');

            $this->respond($request, 'Campaign returned to draft. The schedule and any pending recipients were cleared.', '/admin/marketing/' . $id);
        }

        if (in_array($status, ['sending', 'paused'], true)) {
            $stopped = Database::execute(
                "UPDATE `campaign_recipients`
                    SET `status` = 'failed', `error_message` = 'Campaign cancelled by an administrator.'
                  WHERE `campaign_id` = :campaign_id AND `status` = 'pending'",
                ['campaign_id' => $id]
            );

            $result = CampaignService::transition($id, 'failed', ['completed_at' => date('Y-m-d H:i:s')]);

            if (!$result['ok']) {
                $this->back('error', $result['message'], '/admin/marketing/' . $id);
            }

            CampaignService::syncCounters($id);

            $this->log($request, 'campaign.cancelled', 'marketing_campaign', $id, 'Stopped the campaign mid-send.', [
                'stopped_recipients' => $stopped,
            ]);

            $this->respond(
                $request,
                'Campaign stopped. ' . number_format($stopped) . ' pending recipient'
                    . ($stopped === 1 ? '' : 's') . ' will not be sent to. Messages already on the delivery queue still go out.',
                '/admin/marketing/' . $id
            );
        }

        $this->back('error', 'A campaign that is ' . CampaignService::statusLabel($status) . ' cannot be cancelled.', '/admin/marketing/' . $id);
    }

    // =================================================================
    // Newsletter subscribers
    // =================================================================

    /** GET /admin/marketing/subscribers */
    public function subscribers(Request $request): never
    {
        $this->authorize($request, 'marketing.view');

        $params = $this->listParams($request, self::SUBSCRIBER_SORTABLE, 'subscribed_at');
        $status = $request->string('status');
        $source = $request->string('source');

        $clauses  = ['1 = 1'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = '(ns.`email` LIKE :search OR ns.`name` LIKE :search)';
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if (in_array($status, ['subscribed', 'unsubscribed', 'bounced'], true)) {
            $clauses[]          = 'ns.`status` = :status';
            $bindings['status'] = $status;
        }

        if (in_array($source, ['app', 'web', 'import', 'admin'], true)) {
            $clauses[]          = 'ns.`source` = :source';
            $bindings['source'] = $source;
        }

        $this->applyDateRange($clauses, $bindings, 'ns.`subscribed_at`', $params['from'], $params['to']);

        $where = implode(' AND ', $clauses);

        if ($request->string('export') === 'csv') {
            $this->authorize($request, 'export.data');

            $all = Database::select(
                "SELECT ns.`email`, ns.`name`, ns.`source`, ns.`status`, ns.`subscribed_at`,
                        ns.`unsubscribed_at`, u.`name` AS `account_name`
                   FROM `newsletter_subscribers` ns
                   LEFT JOIN `users` u ON u.`id` = ns.`user_id`
                  WHERE $where
                  ORDER BY ns.`subscribed_at` DESC
                  LIMIT 50000",
                $bindings
            );

            $this->exportCsv(
                'newsletter-subscribers',
                ['Email', 'Name', 'Source', 'Status', 'Subscribed', 'Unsubscribed', 'Linked account'],
                $all,
                ['email', 'name', 'source', 'status', 'subscribed_at', 'unsubscribed_at', 'account_name']
            );
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM `newsletter_subscribers` ns WHERE $where", $bindings);

        $rows = Database::select(
            "SELECT ns.*, u.`name` AS `account_name`
               FROM `newsletter_subscribers` ns
               LEFT JOIN `users` u ON u.`id` = ns.`user_id`
              WHERE $where
              ORDER BY ns.`{$params['sort_by']}` {$params['sort_dir']}, ns.`id` DESC
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bindings
        );

        $summary = Database::selectOne(
            "SELECT COUNT(*)                                                              AS `total`,
                    COALESCE(SUM(`status` = 'subscribed'), 0)                             AS `subscribed`,
                    COALESCE(SUM(`status` = 'unsubscribed'), 0)                           AS `unsubscribed`,
                    COALESCE(SUM(`status` = 'bounced'), 0)                                AS `bounced`,
                    COALESCE(SUM(`subscribed_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)), 0)  AS `new_30d`
               FROM `newsletter_subscribers`"
        ) ?? [];

        $this->view($request, 'admin.marketing.subscribers', [
            'pageTitle'    => 'Newsletter list',
            'pageSubtitle' => 'Everyone who can receive a subscriber campaign.',
            'rows'         => $rows,
            'summary'      => $summary,
            'growth'       => CampaignService::subscriberGrowth(30),
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            // Set by importSubscribers() so the exact outcome survives the redirect.
            'importResult' => Session::getFlash('subscriber_import'),
            'filters'      => [
                'q'        => $params['search'],
                'status'   => $status,
                'source'   => $source,
                'from'     => $params['from'],
                'to'       => $params['to'],
                'sort_by'  => $params['sort_by'],
                'sort_dir' => $params['sort_dir'],
            ],
        ]);
    }

    /** POST /admin/marketing/subscribers */
    public function storeSubscriber(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $data = $this->validate($request, [
            'email' => 'required|email|max:191',
            'name'  => 'nullable|string|max:120',
        ]);

        $result = CampaignService::addSubscriber(
            (string) $data['email'],
            (string) ($data['name'] ?? ''),
            'admin'
        );

        if (!$result['ok']) {
            $this->back('error', $result['message'], '/admin/marketing/subscribers');
        }

        $this->log($request, 'subscriber.added', 'newsletter_subscriber', $result['id'], 'Added ' . Str::maskEmail((string) $data['email']) . ' to the newsletter list.');

        $this->respond($request, $result['message'], '/admin/marketing/subscribers');
    }

    /** POST /admin/marketing/subscribers/import */
    public function importSubscribers(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $raw = (string) $request->input('list', '');

        if (trim($raw) === '') {
            $this->back('error', 'Paste at least one line in the form "email,name".', '/admin/marketing/subscribers');
        }

        if (strlen($raw) > 1000000) {
            $this->back('error', 'That paste is too large. Split it into batches of a few thousand lines.', '/admin/marketing/subscribers');
        }

        $result = CampaignService::importSubscribers($raw);

        Session::flash('subscriber_import', $result);

        $this->log($request, 'subscriber.imported', 'newsletter_subscriber', null, 'Imported subscribers from a paste.', [
            'added'        => $result['added'],
            'resubscribed' => $result['resubscribed'],
            'skipped'      => $result['skipped'],
            'rejected'     => $result['rejected'],
        ]);

        $this->respond(
            $request,
            sprintf(
                '%s added, %s re-subscribed, %s skipped, %s rejected out of %s lines.%s',
                number_format($result['added']),
                number_format($result['resubscribed']),
                number_format($result['skipped']),
                number_format($result['rejected']),
                number_format($result['total']),
                $result['unprocessed'] > 0
                    ? ' ' . number_format($result['unprocessed']) . ' further lines were left untouched — one import handles '
                        . number_format($result['limit']) . ' lines. Paste the rest and import again.'
                    : ''
            ),
            '/admin/marketing/subscribers'
        );
    }

    /** POST /admin/marketing/subscribers/{id}/unsubscribe */
    public function unsubscribeSubscriber(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $this->changeSubscriberStatus($request, 'unsubscribed', 'unsubscribed', 'Unsubscribed');
    }

    /** POST /admin/marketing/subscribers/{id}/resubscribe */
    public function resubscribeSubscriber(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $this->changeSubscriberStatus($request, 'subscribed', 'resubscribed', 'Re-subscribed');
    }

    /** POST /admin/marketing/subscribers/{id}/delete */
    public function destroySubscriber(Request $request): never
    {
        $this->authorize($request, 'marketing.manage');

        $id  = $request->routeParamInt('id');
        $row = Database::selectOne('SELECT `id`, `email` FROM `newsletter_subscribers` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            $this->back('error', 'That subscriber no longer exists.', '/admin/marketing/subscribers');
        }

        Database::execute('DELETE FROM `newsletter_subscribers` WHERE `id` = :id', ['id' => $id]);

        $this->log($request, 'subscriber.deleted', 'newsletter_subscriber', $id, 'Removed ' . Str::maskEmail((string) $row['email']) . ' from the newsletter list.');

        $this->respond($request, 'Subscriber removed. Note that deleting an address also removes the record that they had unsubscribed.', '/admin/marketing/subscribers');
    }

    // =================================================================
    // Internals
    // =================================================================

    private function changeSubscriberStatus(Request $request, string $status, string $action, string $label): never
    {
        $id  = $request->routeParamInt('id');
        $row = Database::selectOne('SELECT `id`, `email`, `status` FROM `newsletter_subscribers` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            $this->back('error', 'That subscriber no longer exists.', '/admin/marketing/subscribers');
        }

        if ((string) $row['status'] === $status) {
            $this->back('info', 'That address is already ' . $status . '.', '/admin/marketing/subscribers');
        }

        CampaignService::setSubscriberStatus($id, $status);

        $this->log($request, 'subscriber.' . $action, 'newsletter_subscriber', $id, $label . ' ' . Str::maskEmail((string) $row['email']) . '.');

        $this->respond($request, $label . '.', '/admin/marketing/subscribers');
    }

    /**
     * Validate and normalise a campaign form submission.
     *
     * `$storedChannel` is what the row already carries. A retired channel is
     * accepted back unchanged so that editing a campaign saved on an earlier
     * build cannot silently rewrite its channel — and with it the whole
     * destination type — to email.
     *
     * @return array{name:string,channel:string,subject:string,preheader:string,content:string,
     *               audience:string,audience_filter:?string,scheduled_at:?string}
     */
    private function campaignInput(Request $request, string $storedChannel = ''): array
    {
        $channels = CampaignService::CHANNELS;

        if (in_array($storedChannel, CampaignService::RETIRED_CHANNELS, true)) {
            $channels[] = $storedChannel;
        }

        $data = $this->validate($request, [
            'name'         => 'required|string|min:2|max:150',
            'channel'      => 'required|in:' . implode(',', $channels),
            'subject'      => 'nullable|string|max:191',
            'preheader'    => 'nullable|string|max:191',
            'content'      => 'required|string|max:1000000',
            'audience'     => 'required|in:all,brands,creators,verified_creators,subscribers,custom',
            'scheduled_at' => 'nullable|datetime',
        ], [
            'content.required' => 'Write the message body before saving.',
        ]);

        $audience = (string) $data['audience'];
        $channel  = (string) $data['channel'];

        if ($audience === 'subscribers' && $channel !== 'email') {
            $this->back('error', 'The newsletter list holds email addresses only, so it cannot be used for a ' . $channel . ' campaign.');
        }

        $filterJson = $audience === 'custom' ? $this->filterJson($request) : null;

        $scheduledAt = trim((string) ($data['scheduled_at'] ?? ''));

        if ($scheduledAt !== '') {
            $timestamp = strtotime($scheduledAt);

            if ($timestamp === false) {
                $this->back('error', 'That schedule date could not be read.');
            }

            if ($timestamp < time() - 60) {
                $this->back('error', 'Pick a schedule in the future, or leave it blank and use Send now.');
            }

            $scheduledAt = date('Y-m-d H:i:s', $timestamp);
        }

        return [
            'name'            => (string) $data['name'],
            'channel'         => $channel,
            'subject'         => trim((string) ($data['subject'] ?? '')),
            'preheader'       => trim((string) ($data['preheader'] ?? '')),
            'content'         => (string) $data['content'],
            'audience'        => $audience,
            'audience_filter' => $filterJson,
            'scheduled_at'    => $scheduledAt === '' ? null : $scheduledAt,
        ];
    }

    /**
     * Build the audience_filter JSON from the discrete form fields. Every key is
     * from a fixed list and every value is re-validated by the service before it
     * ever reaches SQL, so nothing free-form gets through.
     */
    private function filterJson(Request $request): ?string
    {
        $filter = [];

        foreach (CampaignService::FILTER_KEYS as $key) {
            $value = $request->string('filter_' . $key);

            if ($value !== '') {
                $filter[$key] = $value;
            }
        }

        if ($filter === []) {
            return null;
        }

        $encoded = json_encode($filter, JSON_UNESCAPED_SLASHES);

        // Keep only what the service will actually honour, so the stored JSON
        // and the resolved audience always agree.
        $kept = CampaignService::decodeFilter($encoded === false ? null : $encoded);

        return $kept === [] ? null : (string) json_encode($kept, JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array<string,mixed>> */
    private function categories(): array
    {
        return Database::select(
            'SELECT `id`, `name` FROM `categories`
              WHERE `deleted_at` IS NULL AND `is_active` = 1
              ORDER BY `name` ASC
              LIMIT 300'
        );
    }

    /** @return array<string,mixed> */
    private function blankCampaign(): array
    {
        return [
            'id'              => 0,
            'name'            => '',
            'channel'         => 'email',
            'subject'         => '',
            'preheader'       => '',
            'content'         => '',
            'audience'        => 'all',
            'audience_filter' => null,
            'status'          => 'draft',
            'scheduled_at'    => null,
        ];
    }
}
