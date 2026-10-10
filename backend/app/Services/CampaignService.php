<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Helpers\Str;

/**
 * Marketing campaigns.
 *
 * The module has three jobs and this service owns all three:
 *
 *  1. Resolve an audience into real rows from the database.
 *  2. Materialise those rows into `campaign_recipients` exactly once.
 *  3. Hand each recipient to the delivery queue that its channel actually uses
 *     — `email_queue`, `sms_queue` or `push_queue`, all drained by the existing
 *     `queue:dispatch` cron job.
 *
 * Nothing here pretends to send. A channel is only offered once it has a queue
 * behind it; anything else is refused with an explanation.
 */
final class CampaignService
{
    /**
     * Channels a campaign can be created on. Each one has a real queue and a
     * real worker behind it, so everything offered here can be delivered.
     */
    public const CHANNELS  = ['email', 'sms', 'push'];

    /**
     * Channels that can only appear on a row saved by an earlier build. They are
     * never offered on the form and never accepted on a new campaign, but a
     * stored one still has to open, display and resolve its audience exactly as
     * it was saved rather than being silently rewritten to email.
     */
    public const RETIRED_CHANNELS = ['whatsapp'];

    public const AUDIENCES = ['all', 'brands', 'creators', 'verified_creators', 'subscribers', 'custom'];
    public const STATUSES  = ['draft', 'scheduled', 'sending', 'sent', 'paused', 'failed'];

    /** Keys accepted inside `audience_filter` for the `custom` audience. */
    public const FILTER_KEYS = [
        'user_type', 'status', 'is_verified', 'created_after', 'created_before', 'category_id',
    ];

    /**
     * Legal status moves. Anything not listed is refused with an explanation
     * rather than quietly ignored.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        'draft'     => ['scheduled', 'sending'],
        'scheduled' => ['draft', 'sending', 'paused'],
        'sending'   => ['paused', 'sent', 'failed'],
        'paused'    => ['sending', 'draft', 'failed'],
        'sent'      => [],
        'failed'    => ['draft', 'sending'],
    ];

    /**
     * Upper bound on a single audience resolution. Beyond this the admin is
     * told the audience was capped rather than being handed a silent truncation.
     */
    public const MAX_AUDIENCE = 100000;

    private const ACCOUNT_TYPES = ['brand', 'creator'];

    /**
     * Account states a custom filter may ask for. `banned` and `deleted` are
     * deliberately absent: the suppression rules exclude them from every
     * audience, so offering them would only ever resolve to nobody. This list
     * is also exactly what the form offers, so a stored filter can never
     * describe something the form cannot show back.
     */
    private const USER_STATUSES = ['pending', 'active', 'suspended'];

    /** How many recipient rows go into one INSERT. */
    private const INSERT_CHUNK = 500;

    /**
     * How many pasted lines one bulk import will process.
     *
     * Each address costs a lookup and a write. Measured against this database,
     * a thousand lines takes about five seconds, so this ceiling keeps a single
     * import comfortably inside a default 30-second execution limit.
     */
    public const MAX_IMPORT_LINES = 2000;

    /** The cron job key that has to be registered for a schedule to fire. */
    public const SCHEDULER_JOB = 'marketing:dispatch';

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT mc.*, a.`name` AS `created_by_name`
               FROM `marketing_campaigns` mc
               LEFT JOIN `admins` a ON a.`id` = mc.`created_by`
              WHERE mc.`id` = :id AND mc.`deleted_at` IS NULL',
            ['id' => $id]
        );
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'     => 'a draft',
            'scheduled' => 'scheduled',
            'sending'   => 'sending',
            'sent'      => 'sent',
            'paused'    => 'paused',
            'failed'    => 'stopped',
            default     => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'sent'      => 'success',
            'sending'   => 'info',
            'scheduled' => 'warning',
            'paused'    => 'warning',
            'failed'    => 'error',
            default     => 'neutral',
        };
    }

    /**
     * Every channel a stored row may legitimately carry: the ones that can be
     * sent, plus the retired ones. Use this for reads — filters, previews and
     * display — and `CHANNELS` alone for anything that creates or sends.
     *
     * @return list<string>
     */
    public static function knownChannels(): array
    {
        return array_merge(self::CHANNELS, self::RETIRED_CHANNELS);
    }

    /**
     * What actually happens when this channel dispatches.
     *
     * @return array{wired:bool,queue:string,worker:string,note:string,ready:bool,blocker:string}
     */
    public static function channelSupport(string $channel): array
    {
        $smsOn = SmsService::enabled();

        return match ($channel) {
            'email' => [
                'wired'   => true,
                'queue'   => 'email_queue',
                'worker'  => 'queue:dispatch',
                'note'    => 'Each recipient is written to the email queue and delivered over SMTP by the queue:dispatch cron job.',
                'ready'   => true,
                'blocker' => '',
            ],
            'sms' => [
                'wired'   => true,
                'queue'   => 'sms_queue',
                'worker'  => 'queue:dispatch',
                'note'    => 'Each recipient is written to the SMS queue and delivered by the configured provider through the queue:dispatch cron job.',
                'ready'   => $smsOn,
                'blocker' => $smsOn ? '' : 'SMS is switched off, so the SMS queue rejects every message. Turn it on under Settings before dispatching.',
            ],
            'push' => [
                'wired'   => true,
                'queue'   => 'push_queue',
                'worker'  => 'queue:dispatch',
                'note'    => 'Each recipient is written to the push queue and delivered to the account\'s registered devices through Firebase by the queue:dispatch cron job.',
                'ready'   => true,
                'blocker' => '',
            ],
            'whatsapp' => [
                'wired'   => false,
                'queue'   => '',
                'worker'  => '',
                'note'    => 'WhatsApp has no delivery queue or provider in this build, so it is no longer offered as a channel. This campaign was saved on an earlier build: it can still be read and edited, but it cannot be dispatched. Switch it to email, SMS or push to send it.',
                'ready'   => false,
                'blocker' => 'WhatsApp delivery is not wired. Nothing will be sent.',
            ],
            default => [
                'wired'   => false,
                'queue'   => '',
                'worker'  => '',
                'note'    => 'There is no delivery queue for the "' . $channel . '" channel in this build, so it cannot be dispatched.',
                'ready'   => false,
                'blocker' => 'That channel is not wired. Nothing will be sent.',
            ],
        };
    }

    /**
     * Is the scheduled-campaign job actually registered with the cron runner?
     *
     * A schedule is only worth promising when something runs `runDue()`. When
     * this is false the UI says so instead of implying an unattended send.
     */
    public static function schedulerConfigured(): bool
    {
        return CronRunner::exists(self::SCHEDULER_JOB);
    }

    /**
     * Decode a stored audience_filter into the subset that is both whitelisted
     * and usable. A key with a value the query cannot honour is dropped here,
     * so the SQL, the description and the preview can never disagree.
     *
     * @return array<string,string>
     */
    public static function decodeFilter(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $clean = [];

        foreach (self::FILTER_KEYS as $key) {
            if (!isset($decoded[$key]) || is_array($decoded[$key]) || $decoded[$key] === '') {
                continue;
            }

            $value = trim((string) $decoded[$key]);

            $valid = match ($key) {
                'user_type'                     => in_array($value, self::ACCOUNT_TYPES, true),
                'status'                        => in_array($value, self::USER_STATUSES, true),
                'is_verified'                   => in_array($value, ['0', '1'], true),
                'created_after', 'created_before' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
                'category_id'                   => ctype_digit($value) && (int) $value > 0,
                default                         => false,
            };

            if ($valid) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Keys present in a submitted filter that this module cannot honour, so the
     * admin is told instead of silently losing them.
     *
     * @return list<string>
     */
    public static function unusableFilterKeys(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $kept    = self::decodeFilter($json);
        $ignored = [];

        foreach (array_keys($decoded) as $key) {
            if (!isset($kept[(string) $key])) {
                $ignored[] = (string) $key;
            }
        }

        return $ignored;
    }

    /** Human sentence describing who a campaign is aimed at. */
    public static function describeAudience(array $campaign): string
    {
        $audience = (string) ($campaign['audience'] ?? 'all');

        if ($audience !== 'custom') {
            return match ($audience) {
                'brands'            => 'Every brand account that is not suspended.',
                'creators'          => 'Every account with a creator profile, unless it is suspended.',
                'verified_creators' => 'Verified creator profiles, unless the account is suspended.',
                'subscribers'       => 'The newsletter list, subscribed addresses only.',
                default             => 'Every active account.',
            };
        }

        $filter = self::decodeFilter($campaign['audience_filter'] ?? null);

        if ($filter === []) {
            return 'Custom, with no filters set — that matches every eligible account.';
        }

        $parts = [];

        foreach ($filter as $key => $value) {
            $parts[] = match ($key) {
                'user_type'      => 'account type ' . $value,
                'status'         => 'status ' . $value,
                'is_verified'    => $value === '1' ? 'verified creators' : 'unverified creators',
                'created_after'  => 'joined on or after ' . $value,
                'created_before' => 'joined on or before ' . $value,
                'category_id'    => 'category #' . $value,
                default          => $key . ' ' . $value,
            };
        }

        return 'Custom: ' . implode(', ', $parts) . '.';
    }

    // -----------------------------------------------------------------
    // Status transitions
    // -----------------------------------------------------------------

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * Move a campaign to a new status, refusing anything illegal.
     *
     * @param  array<string,mixed> $extra Additional columns to write with the move.
     * @return array{ok:bool,message:string}
     */
    public static function transition(int $campaignId, string $to, array $extra = []): array
    {
        $campaign = self::find($campaignId);

        if ($campaign === null) {
            return ['ok' => false, 'message' => 'That campaign no longer exists.'];
        }

        $from = (string) $campaign['status'];

        if ($from === $to) {
            return ['ok' => false, 'message' => 'The campaign is already ' . self::statusLabel($to) . '.'];
        }

        if (!self::canTransition($from, $to)) {
            $allowed = self::allowedTransitions($from);

            return [
                'ok'      => false,
                'message' => $allowed === []
                    ? 'This campaign is ' . self::statusLabel($from) . ' and cannot change state again.'
                    : sprintf(
                        'A campaign that is %s cannot become %s. From here it can only become: %s.',
                        self::statusLabel($from),
                        self::statusLabel($to),
                        implode(', ', $allowed)
                    ),
            ];
        }

        Database::update(
            'marketing_campaigns',
            array_merge(['status' => $to, 'updated_at' => date('Y-m-d H:i:s')], $extra),
            ['id' => $campaignId]
        );

        return ['ok' => true, 'message' => 'Campaign is now ' . self::statusLabel($to) . '.'];
    }

    // -----------------------------------------------------------------
    // Audience resolution
    // -----------------------------------------------------------------

    /**
     * The real recipient rows for a campaign, de-duplicated by destination.
     *
     * @param  array<string,mixed> $campaign
     * @return list<array{user_id:int|null,name:string,destination:string}>
     */
    public static function resolveAudience(array $campaign, int $limit = self::MAX_AUDIENCE): array
    {
        $query = self::audienceQuery($campaign);

        if ($query['blocked'] !== '') {
            return [];
        }

        $limit = max(1, min(self::MAX_AUDIENCE, $limit));

        $rows = Database::select(
            $query['select'] . ' ORDER BY ' . $query['order'] . ' LIMIT ' . $limit,
            $query['bindings']
        );

        $seen   = [];
        $unique = [];

        foreach ($rows as $row) {
            // Truncated once, here, so the de-duplication key and the value
            // written to the 191-char column can never disagree.
            $destination = mb_substr(trim((string) $row['destination']), 0, 191);

            if ($destination === '' || isset($seen[$destination])) {
                continue;
            }

            $seen[$destination] = true;

            $unique[] = [
                'user_id'     => $row['user_id'] === null ? null : (int) $row['user_id'],
                'name'        => (string) ($row['name'] ?? ''),
                'destination' => $destination,
            ];
        }

        return $unique;
    }

    /** How many distinct destinations the audience really has. */
    public static function countAudience(array $campaign): int
    {
        $query = self::audienceQuery($campaign);

        if ($query['blocked'] !== '') {
            return 0;
        }

        return (int) Database::scalar($query['count'], $query['bindings']);
    }

    /**
     * Count plus the first ten rows — what the "Preview audience" button shows.
     *
     * @return array{total:int,sample:list<array{user_id:int|null,name:string,destination:string}>,
     *               capped:bool,blocked:string,warnings:list<string>,description:string,
     *               channel:string,audience:string}
     */
    public static function previewAudience(array $campaign): array
    {
        $channel  = (string) ($campaign['channel'] ?? 'email');
        $audience = (string) ($campaign['audience'] ?? 'all');
        $query    = self::audienceQuery($campaign);
        $support  = self::channelSupport($channel);

        $warnings = [];

        if (!$support['wired']) {
            $warnings[] = $support['note'];
        } elseif (!$support['ready']) {
            $warnings[] = $support['blocker'];
        }

        if (!self::schedulerConfigured() && (string) ($campaign['status'] ?? '') === 'scheduled') {
            $warnings[] = 'No scheduled-campaign job is registered with the cron runner, so this will not go out on its own. Use Send now.';
        }

        if ($audience === 'custom') {
            if (self::decodeFilter($campaign['audience_filter'] ?? null) === []) {
                $warnings[] = 'No usable custom filters are set, so this matches every eligible account.';
            }

            $ignored = self::unusableFilterKeys($campaign['audience_filter'] ?? null);

            if ($ignored !== []) {
                $warnings[] = 'Ignored, because they are not supported or the value is not valid: '
                    . implode(', ', array_slice($ignored, 0, 8)) . '.';
            }
        }

        if ($query['blocked'] !== '') {
            return [
                'total'       => 0,
                'sample'      => [],
                'capped'      => false,
                'blocked'     => $query['blocked'],
                'warnings'    => $warnings,
                'description' => self::describeAudience($campaign),
                'channel'     => $channel,
                'audience'    => $audience,
            ];
        }

        $total  = (int) Database::scalar($query['count'], $query['bindings']);
        $sample = self::resolveAudience($campaign, 10);

        if ($total > self::MAX_AUDIENCE) {
            $warnings[] = sprintf(
                'Only the first %s recipients will be queued — that is the per-campaign ceiling.',
                number_format(self::MAX_AUDIENCE)
            );
        }

        if ($total === 0) {
            $warnings[] = 'Nobody matches. Check the audience, and remember that banned and deleted accounts, '
                . 'unsubscribed addresses and accounts that have not opted in to marketing are always excluded.';
        }

        return [
            'total'       => $total,
            'sample'      => $sample,
            'capped'      => $total > self::MAX_AUDIENCE,
            'blocked'     => '',
            'warnings'    => $warnings,
            'description' => self::describeAudience($campaign),
            'channel'     => $channel,
            'audience'    => $audience,
        ];
    }

    /**
     * Build the audience SELECT and its COUNT twin.
     *
     * Every value is bound. Every column name is a literal in this method — no
     * part of the SQL is ever assembled from request input.
     *
     * @param  array<string,mixed> $campaign
     * @return array{select:string,count:string,order:string,bindings:array<string,mixed>,blocked:string}
     */
    private static function audienceQuery(array $campaign): array
    {
        // Retired channels count here: a stored WhatsApp campaign must still
        // resolve to phone numbers, not be quietly re-read as an email list.
        $channel  = in_array((string) ($campaign['channel'] ?? ''), self::knownChannels(), true)
            ? (string) $campaign['channel']
            : 'email';

        $audience = in_array((string) ($campaign['audience'] ?? ''), self::AUDIENCES, true)
            ? (string) $campaign['audience']
            : 'all';

        // ---- The newsletter list is a different table entirely. ----------
        if ($audience === 'subscribers') {
            if ($channel !== 'email') {
                return [
                    'select'   => '',
                    'count'    => '',
                    'order'    => '',
                    'bindings' => [],
                    'blocked'  => 'The newsletter list holds email addresses only, so it cannot be used for a '
                        . $channel . ' campaign. Pick a different audience or switch the channel to email.',
                ];
            }

            $where = "ns.`status` = 'subscribed' AND ns.`email` <> ''";

            return [
                'select' => 'SELECT ns.`user_id` AS `user_id`,
                                    COALESCE(NULLIF(ns.`name`, \'\'), ns.`email`) AS `name`,
                                    ns.`email` AS `destination`
                               FROM `newsletter_subscribers` ns
                              WHERE ' . $where,
                'count'  => 'SELECT COUNT(DISTINCT ns.`email`) FROM `newsletter_subscribers` ns WHERE ' . $where,
                'order'    => 'ns.`id` ASC',
                'bindings' => [],
                'blocked'  => '',
            ];
        }

        // ---- Everything else starts from `users`. ------------------------
        $destination = match ($channel) {
            'sms', 'whatsapp' => 'CONCAT(COALESCE(u.`phone_country_code`, \'\'), u.`phone`)',
            'push'            => 'CONCAT(\'user:\', u.`id`)',
            default           => 'u.`email`',
        };

        $joins    = [];
        $bindings = [];

        // Never market to an account that is gone, banned, or has not opted
        // in. Marketing is opt-in (NotificationService::DEFAULT_PREFERENCES),
        // so an account with no stored choice is left out, not counted in.
        $clauses = [
            'u.`deleted_at` IS NULL',
            "u.`status` NOT IN ('banned','deleted')",
            "(JSON_VALID(u.`notification_prefs`) = 1
              AND JSON_UNQUOTE(JSON_EXTRACT(u.`notification_prefs`, '$.marketing')) IN ('true','1'))",
        ];

        if ($channel === 'email') {
            $clauses[] = 'u.`email` IS NOT NULL AND u.`email` <> \'\'';
            // An address that has unsubscribed or hard-bounced is off limits,
            // whichever audience selected it.
            $clauses[] = "NOT EXISTS (SELECT 1 FROM `newsletter_subscribers` ns
                                       WHERE ns.`email` = u.`email` AND ns.`status` <> 'subscribed')";
        } elseif ($channel === 'sms' || $channel === 'whatsapp') {
            $clauses[] = 'u.`phone` IS NOT NULL AND u.`phone` <> \'\'';
        }

        $needsCreator  = false;
        $statusHandled = false;

        switch ($audience) {
            case 'brands':
                $clauses[]              = 'u.`account_type` = :aud_account_type';
                $bindings['aud_account_type'] = 'brand';
                break;

            case 'creators':
                $needsCreator = true;
                break;

            case 'verified_creators':
                $needsCreator = true;
                $clauses[]    = 'c.`is_verified` = 1';
                break;

            case 'custom':
                $filter = self::decodeFilter($campaign['audience_filter'] ?? null);

                foreach ($filter as $key => $value) {
                    switch ($key) {
                        case 'user_type':
                            if (in_array($value, self::ACCOUNT_TYPES, true)) {
                                $clauses[]                     = 'u.`account_type` = :f_account_type';
                                $bindings['f_account_type']    = $value;
                            }
                            break;

                        case 'status':
                            if (in_array($value, self::USER_STATUSES, true)) {
                                $clauses[]            = 'u.`status` = :f_status';
                                $bindings['f_status'] = $value;
                                $statusHandled        = true;
                            }
                            break;

                        case 'is_verified':
                            $needsCreator              = true;
                            $clauses[]                 = 'c.`is_verified` = :f_is_verified';
                            $bindings['f_is_verified'] = $value === '1' ? 1 : 0;
                            break;

                        case 'created_after':
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                                $clauses[]                   = 'u.`created_at` >= :f_created_after';
                                $bindings['f_created_after'] = $value . ' 00:00:00';
                            }
                            break;

                        case 'created_before':
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                                $clauses[]                    = 'u.`created_at` <= :f_created_before';
                                $bindings['f_created_before'] = $value . ' 23:59:59';
                            }
                            break;

                        case 'category_id':
                            if (ctype_digit($value) && (int) $value > 0) {
                                $needsCreator              = true;
                                $clauses[]                 = '(c.`primary_category_id` = :f_category_id
                                     OR EXISTS (SELECT 1 FROM `creator_categories` cc
                                                 WHERE cc.`creator_id` = c.`id`
                                                   AND cc.`category_id` = :f_category_id))';
                                $bindings['f_category_id'] = (int) $value;
                            }
                            break;
                    }
                }
                break;

            case 'all':
            default:
                $clauses[]              = 'u.`status` = :aud_status';
                $bindings['aud_status'] = 'active';
                $statusHandled          = true;
                break;
        }

        // A suspended account is one the platform has restricted, so it is
        // suppressed everywhere unless a custom filter asks for it by name.
        if (!$statusHandled) {
            $clauses[] = "u.`status` <> 'suspended'";
        }

        if ($needsCreator) {
            $joins[] = 'INNER JOIN `creators` c ON c.`user_id` = u.`id` AND c.`deleted_at` IS NULL';
        }

        $from  = '`users` u' . ($joins === [] ? '' : ' ' . implode(' ', $joins));
        $where = implode(' AND ', $clauses);

        return [
            'select' => 'SELECT u.`id` AS `user_id`, u.`name` AS `name`, ' . $destination . ' AS `destination`
                           FROM ' . $from . '
                          WHERE ' . $where,
            'count'    => 'SELECT COUNT(DISTINCT ' . $destination . ') FROM ' . $from . ' WHERE ' . $where,
            'order'    => 'u.`id` ASC',
            'bindings' => $bindings,
            'blocked'  => '',
        ];
    }

    // -----------------------------------------------------------------
    // Queueing recipients
    // -----------------------------------------------------------------

    /**
     * Materialise `campaign_recipients` and move the campaign into `sending`.
     *
     * Safe to run twice: destinations already on the list are skipped, and the
     * unique (campaign_id, destination) index is the race-condition backstop.
     *
     * @return array{ok:bool,message:string,inserted:int,skipped:int,total:int,capped:bool}
     */
    public static function queue(int $campaignId): array
    {
        $campaign = self::find($campaignId);

        if ($campaign === null) {
            return ['ok' => false, 'message' => 'That campaign no longer exists.', 'inserted' => 0, 'skipped' => 0, 'total' => 0, 'capped' => false];
        }

        $status = (string) $campaign['status'];

        // `sending` is allowed through so a stalled run can be topped up.
        if ($status !== 'sending' && !self::canTransition($status, 'sending')) {
            return [
                'ok'       => false,
                'message'  => 'A campaign that is ' . self::statusLabel($status) . ' cannot be queued for sending.',
                'inserted' => 0, 'skipped' => 0, 'total' => 0, 'capped' => false,
            ];
        }

        $query = self::audienceQuery($campaign);

        if ($query['blocked'] !== '') {
            return ['ok' => false, 'message' => $query['blocked'], 'inserted' => 0, 'skipped' => 0, 'total' => 0, 'capped' => false];
        }

        $support = self::channelSupport((string) $campaign['channel']);

        if (!$support['wired']) {
            return ['ok' => false, 'message' => $support['note'], 'inserted' => 0, 'skipped' => 0, 'total' => 0, 'capped' => false];
        }

        // The true size of the audience, before the per-campaign ceiling, so the
        // "capped" flag reports reality rather than the size of the slice taken.
        $available = (int) Database::scalar($query['count'], $query['bindings']);
        $audience  = self::resolveAudience($campaign);

        if ($audience === []) {
            return [
                'ok'       => false,
                'message'  => 'Nobody matches this audience, so there is nothing to queue.',
                'inserted' => 0, 'skipped' => 0, 'total' => 0, 'capped' => false,
            ];
        }

        $before = (int) Database::scalar(
            'SELECT COUNT(*) FROM `campaign_recipients` WHERE `campaign_id` = :campaign_id',
            ['campaign_id' => $campaignId]
        );

        // INSERT IGNORE against the unique (campaign_id, destination) index is
        // what makes a second run a no-op, even under a concurrent dispatch.
        // Rows go in a chunk at a time: a hundred thousand single-row inserts
        // inside one request would run for minutes and time out.
        foreach (array_chunk($audience, self::INSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $values       = [];

            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?)';
                $values[]       = $campaignId;
                $values[]       = $row['user_id'];
                $values[]       = $row['destination'];
                $values[]       = 'pending';
                $values[]       = bin2hex(random_bytes(16));
            }

            Database::execute(
                'INSERT IGNORE INTO `campaign_recipients`
                     (`campaign_id`, `user_id`, `destination`, `status`, `tracking_token`)
                 VALUES ' . implode(', ', $placeholders),
                $values
            );
        }

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `campaign_recipients` WHERE `campaign_id` = :campaign_id',
            ['campaign_id' => $campaignId]
        );

        // Counted from the table rather than from insert ids, so a token
        // collision or a concurrent run is reflected honestly.
        $inserted = max(0, $total - $before);
        $skipped  = max(0, count($audience) - $inserted);

        Database::execute(
            "UPDATE `marketing_campaigns`
                SET `recipients_count` = :total,
                    `status`           = 'sending',
                    `started_at`       = COALESCE(`started_at`, NOW()),
                    `updated_at`       = NOW()
              WHERE `id` = :campaign_id",
            ['total' => $total, 'campaign_id' => $campaignId]
        );

        self::syncCounters($campaignId);

        $capped = $available > self::MAX_AUDIENCE;

        return [
            'ok'       => true,
            'message'  => sprintf(
                '%s recipient%s queued, %s already on the list. The campaign is now sending.%s',
                number_format($inserted),
                $inserted === 1 ? '' : 's',
                number_format($skipped),
                $capped
                    ? sprintf(
                        ' %s accounts matched, which is over the %s ceiling for one campaign — the rest were not queued.',
                        number_format($available),
                        number_format(self::MAX_AUDIENCE)
                    )
                    : ''
            ),
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'total'    => $total,
            'capped'   => $capped,
        ];
    }

    /**
     * Queue a campaign, first giving a stopped one's failed recipients another
     * go — otherwise queue() would skip every destination already on the list.
     *
     * The reset and the re-queue happen inside one transaction. If the re-queue
     * is refused, the rollback restores each recipient's original failure
     * reason exactly, instead of overwriting it with an invented one.
     *
     * @return array{ok:bool,message:string,inserted:int,skipped:int,total:int,capped:bool,retried:int}
     */
    public static function retryAndQueue(int $campaignId): array
    {
        $campaign = self::find($campaignId);

        if ($campaign === null) {
            return ['ok' => false, 'message' => 'That campaign no longer exists.', 'inserted' => 0, 'skipped' => 0, 'total' => 0, 'capped' => false, 'retried' => 0];
        }

        if ((string) $campaign['status'] !== 'failed') {
            return self::queue($campaignId) + ['retried' => 0];
        }

        try {
            /** @var array{ok:bool,message:string,inserted:int,skipped:int,total:int,capped:bool,retried:int} $result */
            $result = Database::transaction(static function () use ($campaignId): array {
                $retried = Database::execute(
                    "UPDATE `campaign_recipients`
                        SET `status` = 'pending', `error_message` = NULL
                      WHERE `campaign_id` = :campaign_id AND `status` = 'failed'",
                    ['campaign_id' => $campaignId]
                );

                $queued = self::queue($campaignId);

                if (!$queued['ok']) {
                    // Unwinds the reset above along with everything else.
                    throw new \DomainException($queued['message']);
                }

                return $queued + ['retried' => $retried];
            }, 1);

            return $result;
        } catch (\DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'inserted' => 0, 'skipped' => 0, 'total' => 0, 'capped' => false, 'retried' => 0];
        }
    }

    // -----------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------

    /**
     * Hand the next slice of pending recipients to their channel queue.
     *
     * Each recipient's row is written the moment its attempt finishes, and the
     * campaign counters are recomputed from those rows afterwards — so an
     * interrupted batch can never leave the totals drifting.
     *
     * @return array{ok:bool,message:string,sent:int,failed:int,remaining:int,completed:bool}
     */
    public static function dispatchBatch(int $campaignId, int $limit = 50): array
    {
        $campaign = self::find($campaignId);

        if ($campaign === null) {
            return ['ok' => false, 'message' => 'That campaign no longer exists.', 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'completed' => false];
        }

        if ((string) $campaign['status'] !== 'sending') {
            return [
                'ok'      => false,
                'message' => 'Only a campaign that is sending can dispatch a batch. This one is ' . self::statusLabel((string) $campaign['status']) . '.',
                'sent'    => 0, 'failed' => 0, 'remaining' => 0, 'completed' => false,
            ];
        }

        $channel = (string) $campaign['channel'];
        $support = self::channelSupport($channel);

        if (!$support['wired']) {
            return ['ok' => false, 'message' => $support['note'], 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'completed' => false];
        }

        if (!$support['ready']) {
            return ['ok' => false, 'message' => $support['blocker'], 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'completed' => false];
        }

        $limit = max(1, min(500, $limit));

        // Two dispatchers — a double-clicked button, or an admin and the cron
        // job at the same moment — would otherwise read the same pending rows
        // and send every one of them twice. This lock is held by the database
        // connection, so it is released even if the request dies mid-batch.
        $lock = 'zuste:campaign:dispatch:' . $campaignId;

        if ((int) Database::scalar('SELECT GET_LOCK(:lock, 0)', ['lock' => $lock]) !== 1) {
            return [
                'ok'      => false,
                'message' => 'Another dispatch for this campaign is already running. Wait for it to finish, then send the next batch.',
                'sent'    => 0, 'failed' => 0, 'remaining' => 0, 'completed' => false,
            ];
        }

        $sent   = 0;
        $failed = 0;
        $now    = date('Y-m-d H:i:s');

        try {
            // The list was built when the campaign was queued. An account
            // deleted or banned since then is never sent to: its pending rows
            // are failed here, inside the lock, before the batch is read.
            Database::execute(
                "UPDATE `campaign_recipients` cr
                   JOIN `users` u ON u.`id` = cr.`user_id`
                    SET cr.`status` = 'failed',
                        cr.`error_message` = 'Not sent: the account was closed or blocked after the list was built.'
                  WHERE cr.`campaign_id` = :campaign_id AND cr.`status` = 'pending'
                    AND (u.`deleted_at` IS NOT NULL OR u.`status` IN ('deleted', 'banned'))",
                ['campaign_id' => $campaignId]
            );

            // Consent is read again at send time, not only when the list was
            // built: someone who switches off "Product updates & offers"
            // while a large campaign drains gets none of the rest, and rows
            // queued under an older, looser rule (one that counted a missing
            // choice as a yes) are held to today's opt-in-only rule. The
            // newsletter list is exempt: there the subscription itself is the
            // consent, and it is re-checked just below.
            if ((string) ($campaign['audience'] ?? '') !== 'subscribers') {
                Database::execute(
                    "UPDATE `campaign_recipients` cr
                       JOIN `users` u ON u.`id` = cr.`user_id`
                        SET cr.`status` = 'failed',
                            cr.`error_message` = 'Not sent: the account has not opted in to marketing.'
                      WHERE cr.`campaign_id` = :campaign_id AND cr.`status` = 'pending'
                        AND NOT COALESCE(
                            JSON_VALID(u.`notification_prefs`) = 1
                            AND JSON_UNQUOTE(JSON_EXTRACT(u.`notification_prefs`, '$.marketing')) IN ('true','1'),
                            0
                        )",
                    ['campaign_id' => $campaignId]
                );
            }

            // An address that unsubscribed or hard-bounced since the list was
            // built is off limits, whichever audience selected it.
            if ($channel === 'email') {
                Database::execute(
                    "UPDATE `campaign_recipients` cr
                       JOIN `newsletter_subscribers` ns ON ns.`email` = cr.`destination`
                        SET cr.`status` = 'failed',
                            cr.`error_message` = 'Not sent: the address unsubscribed after the list was built.'
                      WHERE cr.`campaign_id` = :campaign_id AND cr.`status` = 'pending'
                        AND ns.`status` <> 'subscribed'",
                    ['campaign_id' => $campaignId]
                );
            }

            $batch = Database::select(
                "SELECT cr.`id`, cr.`user_id`, cr.`destination`, cr.`tracking_token`, u.`name` AS `user_name`
                   FROM `campaign_recipients` cr
                   LEFT JOIN `users` u ON u.`id` = cr.`user_id`
                  WHERE cr.`campaign_id` = :campaign_id AND cr.`status` = 'pending'
                  ORDER BY cr.`id` ASC
                  LIMIT " . $limit,
                ['campaign_id' => $campaignId]
            );

            foreach ($batch as $recipient) {
                try {
                    self::deliver($campaign, $recipient);

                    Database::update('campaign_recipients', [
                        'status'        => 'sent',
                        'sent_at'       => $now,
                        'error_message' => null,
                    ], ['id' => (int) $recipient['id']]);

                    $sent++;
                } catch (\Throwable $e) {
                    Database::update('campaign_recipients', [
                        'status'        => 'failed',
                        'error_message' => mb_substr($e->getMessage(), 0, 500),
                    ], ['id' => (int) $recipient['id']]);

                    $failed++;

                    Logger::warning('Campaign recipient failed', [
                        'campaign_id'  => $campaignId,
                        'recipient_id' => $recipient['id'],
                        'error'        => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            Database::scalar('SELECT RELEASE_LOCK(:lock)', ['lock' => $lock]);
        }

        // Recompute from the recipient rows rather than incrementing, so a
        // partially finished batch still leaves accurate totals.
        self::syncCounters($campaignId);

        $remaining = (int) Database::scalar(
            "SELECT COUNT(*) FROM `campaign_recipients`
              WHERE `campaign_id` = :campaign_id AND `status` = 'pending'",
            ['campaign_id' => $campaignId]
        );

        $completed = false;
        $finished  = '';

        if ($remaining === 0) {
            $delivered = (int) Database::scalar(
                "SELECT COUNT(*) FROM `campaign_recipients`
                  WHERE `campaign_id` = :campaign_id AND `status` IN ('sent','opened','clicked')",
                ['campaign_id' => $campaignId]
            );

            // `sent` is a terminal state with no transitions out of it. A run
            // where nothing at all got through must not land there, or the
            // campaign could never be retried. It is stopped instead.
            $finished = $delivered > 0 ? 'sent' : 'failed';

            Database::execute(
                "UPDATE `marketing_campaigns`
                    SET `status` = :finished, `completed_at` = NOW(), `updated_at` = NOW()
                  WHERE `id` = :campaign_id AND `status` = 'sending'",
                ['finished' => $finished, 'campaign_id' => $campaignId]
            );

            $completed = true;
        }

        return [
            'ok'        => true,
            'message'   => sprintf(
                '%s handed to the %s queue, %s failed. %s still pending.%s',
                number_format($sent),
                $channel,
                number_format($failed),
                number_format($remaining),
                $finished === 'failed'
                    ? ' Nothing reached the queue, so the campaign is stopped rather than sent — fix the cause and retry it.'
                    : ''
            ),
            'sent'      => $sent,
            'failed'    => $failed,
            'remaining' => $remaining,
            'completed' => $completed,
        ];
    }

    /**
     * Push one recipient onto the queue its channel really uses.
     * Throws with a readable reason when the channel refuses the message.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $recipient
     */
    private static function deliver(array $campaign, array $recipient): void
    {
        $channel     = (string) $campaign['channel'];
        $destination = (string) $recipient['destination'];
        $name        = (string) ($recipient['user_name'] ?? '');

        $values = [
            'name'          => $name,
            'first_name'    => $name === '' ? '' : (string) strtok($name, ' '),
            'email'         => $channel === 'email' ? $destination : '',
            'campaign_name' => (string) $campaign['name'],
        ];

        if ($channel === 'email') {
            $subject = trim((string) ($campaign['subject'] ?? ''));
            $subject = $subject === '' ? (string) $campaign['name'] : $subject;

            $queued = MailService::queue(
                $destination,
                $name,
                Str::interpolate($subject, $values),
                self::renderEmail($campaign, $recipient, $values),
                null,
                self::replyTo()
            );

            if ($queued <= 0) {
                throw new \RuntimeException('The mail queue rejected this address as invalid.');
            }

            return;
        }

        if ($channel === 'sms') {
            $body = mb_substr(Str::interpolate(self::plainText((string) $campaign['content']), $values), 0, 1000);

            if (trim($body) === '') {
                throw new \RuntimeException('The campaign body is empty once stripped to plain text.');
            }

            $queued = SmsService::queue($destination, $body);

            if ($queued <= 0) {
                throw new \RuntimeException('The SMS queue refused the message — SMS is switched off or the number is not valid.');
            }

            return;
        }

        if ($channel === 'push') {
            $userId = $recipient['user_id'] === null ? 0 : (int) $recipient['user_id'];

            if ($userId <= 0) {
                throw new \RuntimeException('Push needs a linked user account and this recipient has none.');
            }

            $title = trim((string) ($campaign['subject'] ?? ''));
            $title = $title === '' ? (string) $campaign['name'] : $title;
            $title = trim(Str::interpolate($title, $values));

            $body = trim(Str::interpolate(self::plainText((string) $campaign['content']), $values));

            // A push with an empty body is a blank banner on someone's phone.
            // Refuse it here rather than queue something unreadable.
            if ($body === '') {
                throw new \RuntimeException('The campaign body is empty once stripped to plain text, so there is nothing to show in a push notification.');
            }

            if ($title === '') {
                throw new \RuntimeException('The push title is empty once the placeholders are filled in.');
            }

            Database::insert('push_queue', [
                'target_type'  => 'user',
                'target_value' => (string) $userId,
                'title'        => mb_substr($title, 0, 191),
                'body'         => mb_substr($body, 0, 500),
                'data_payload' => (string) json_encode([
                    'type'        => 'marketing.campaign',
                    'campaign_id' => (string) $campaign['id'],
                    'action_type' => 'none',
                ], JSON_UNESCAPED_UNICODE),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            return;
        }

        throw new \RuntimeException('The ' . $channel . ' channel has no delivery queue in this build.');
    }

    /**
     * Campaign HTML with placeholders filled, a footer, and — only when a
     * tracking endpoint is configured — an open pixel.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $recipient
     * @param array<string,string> $values
     */
    private static function renderEmail(array $campaign, array $recipient, array $values): string
    {
        $email          = (string) $recipient['destination'];
        $unsubscribeUrl = self::unsubscribeUrl($email);
        $oneClick       = self::hasTokenUnsubscribe($email);

        $body = Str::interpolate(
            (string) $campaign['content'],
            array_merge($values, ['unsubscribe_url' => $unsubscribeUrl])
        );

        $appName = Config::settingString('general', 'app_name', 'Zusty');
        $support = self::replyTo();

        $footerAction = '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8')
            . '" style="color:#632CE5;text-decoration:underline">'
            . ($oneClick ? 'Unsubscribe' : 'Email us to unsubscribe')
            . '</a>';

        $footer = '<div style="margin-top:28px;padding-top:16px;border-top:1px solid #FADBDE;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;'
            . 'font-size:12px;line-height:18px;color:#8F6F73">'
            . 'You are receiving this because you have an account or subscription with '
            . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '. '
            . $footerAction . '.<br>Questions? Write to '
            . '<a href="mailto:' . htmlspecialchars($support, ENT_QUOTES, 'UTF-8') . '" style="color:#632CE5;text-decoration:none">'
            . htmlspecialchars($support, ENT_QUOTES, 'UTF-8') . '</a>.'
            . '</div>';

        return $body . $footer . self::trackingPixel((string) ($recipient['tracking_token'] ?? ''));
    }

    /**
     * The support address, else support@zusty.app: the one mailbox anyone is
     * told to write to. Never the sender address, which nobody reads.
     */
    private static function replyTo(): string
    {
        return MailService::supportAddress();
    }

    /**
     * Personal unsubscribe link.
     *
     * A tokenised link is only possible for an address that is on the newsletter
     * list, and only when a public unsubscribe endpoint is configured. Everyone
     * else gets a mailto: that a human actually reads — never an empty string,
     * because `{{unsubscribe_url}}` in a body would then render as href="".
     */
    private static function unsubscribeUrl(string $email): string
    {
        $base = Config::settingString('marketing', 'unsubscribe_url', '');

        if ($base !== '') {
            $token = Database::scalar(
                'SELECT `unsubscribe_token` FROM `newsletter_subscribers` WHERE `email` = :email LIMIT 1',
                ['email' => $email]
            );

            if ($token !== null && (string) $token !== '') {
                return rtrim($base, '/') . '/' . (string) $token;
            }
        }

        return 'mailto:' . self::replyTo() . '?subject=' . rawurlencode('Unsubscribe');
    }

    /** Is a tokenised, one-click unsubscribe link possible for this address? */
    private static function hasTokenUnsubscribe(string $email): bool
    {
        if (Config::settingString('marketing', 'unsubscribe_url', '') === '') {
            return false;
        }

        $token = Database::scalar(
            'SELECT `unsubscribe_token` FROM `newsletter_subscribers` WHERE `email` = :email LIMIT 1',
            ['email' => $email]
        );

        return $token !== null && (string) $token !== '';
    }

    /** Open pixel, only when `marketing.tracking_url` names a live endpoint. */
    private static function trackingPixel(string $token): string
    {
        $base = Config::settingString('marketing', 'tracking_url', '');

        if ($base === '' || $token === '') {
            return '';
        }

        return '<img src="' . htmlspecialchars(rtrim($base, '/') . '/' . $token . '.gif', ENT_QUOTES, 'UTF-8')
            . '" width="1" height="1" alt="" style="display:none">';
    }

    /** Is open/click tracking actually going to record anything? */
    public static function trackingConfigured(): bool
    {
        return Config::settingString('marketing', 'tracking_url', '') !== '';
    }

    public static function unsubscribeConfigured(): bool
    {
        return Config::settingString('marketing', 'unsubscribe_url', '') !== '';
    }

    // -----------------------------------------------------------------
    // Tracking callbacks (for a public route, if one is wired)
    // -----------------------------------------------------------------

    /** Record an open against a tracking token. Returns whether anything changed. */
    public static function recordOpen(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }

        $row = Database::selectOne(
            'SELECT `id`, `campaign_id`, `status`, `opened_at` FROM `campaign_recipients` WHERE `tracking_token` = :token',
            ['token' => $token]
        );

        if ($row === null || !in_array((string) $row['status'], ['sent', 'opened', 'clicked'], true)) {
            return false;
        }

        if ($row['opened_at'] !== null) {
            return false;
        }

        Database::update('campaign_recipients', [
            'status'    => (string) $row['status'] === 'clicked' ? 'clicked' : 'opened',
            'opened_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);

        self::syncCounters((int) $row['campaign_id']);

        return true;
    }

    /** Record a click and return the destination URL when it is safe to follow. */
    public static function recordClick(string $token, string $url): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT `id`, `campaign_id`, `status`, `opened_at` FROM `campaign_recipients` WHERE `tracking_token` = :token',
            ['token' => $token]
        );

        if ($row === null || !in_array((string) $row['status'], ['sent', 'opened', 'clicked'], true)) {
            return null;
        }

        $now = date('Y-m-d H:i:s');

        Database::update('campaign_recipients', [
            'status'     => 'clicked',
            'opened_at'  => $row['opened_at'] ?? $now,
            'clicked_at' => $now,
        ], ['id' => (int) $row['id']]);

        self::syncCounters((int) $row['campaign_id']);

        return $url;
    }

    // -----------------------------------------------------------------
    // Counters and statistics
    // -----------------------------------------------------------------

    /** Rebuild every campaign counter from the recipient rows. */
    public static function syncCounters(int $campaignId): void
    {
        Database::execute(
            "UPDATE `marketing_campaigns` mc
                SET mc.`recipients_count`   = (SELECT COUNT(*) FROM `campaign_recipients` r
                                                WHERE r.`campaign_id` = mc.`id`),
                    mc.`sent_count`         = (SELECT COUNT(*) FROM `campaign_recipients` r
                                                WHERE r.`campaign_id` = mc.`id`
                                                  AND r.`status` IN ('sent','opened','clicked')),
                    mc.`failed_count`       = (SELECT COUNT(*) FROM `campaign_recipients` r
                                                WHERE r.`campaign_id` = mc.`id`
                                                  AND r.`status` IN ('failed','bounced')),
                    mc.`opened_count`       = (SELECT COUNT(*) FROM `campaign_recipients` r
                                                WHERE r.`campaign_id` = mc.`id`
                                                  AND r.`status` IN ('opened','clicked')),
                    mc.`clicked_count`      = (SELECT COUNT(*) FROM `campaign_recipients` r
                                                WHERE r.`campaign_id` = mc.`id` AND r.`status` = 'clicked'),
                    mc.`unsubscribed_count` = (SELECT COUNT(*) FROM `campaign_recipients` r
                                                WHERE r.`campaign_id` = mc.`id` AND r.`status` = 'unsubscribed'),
                    mc.`updated_at`         = mc.`updated_at`
              WHERE mc.`id` = :campaign_id",
            ['campaign_id' => $campaignId]
        );
    }

    /**
     * Live delivery statistics, computed from the recipient rows.
     *
     * @return array{total:int,pending:int,sent:int,failed:int,bounced:int,opened:int,
     *               clicked:int,unsubscribed:int,open_rate:float,click_rate:float,
     *               delivery_rate:float,progress:float}
     */
    public static function stats(int $campaignId): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*)                                                    AS `total`,
                    COALESCE(SUM(`status` = 'pending'), 0)                      AS `pending`,
                    COALESCE(SUM(`status` IN ('sent','opened','clicked')), 0)   AS `sent`,
                    COALESCE(SUM(`status` IN ('failed','bounced')), 0)          AS `failed`,
                    COALESCE(SUM(`status` = 'bounced'), 0)                      AS `bounced`,
                    COALESCE(SUM(`status` IN ('opened','clicked')), 0)          AS `opened`,
                    COALESCE(SUM(`status` = 'clicked'), 0)                      AS `clicked`,
                    COALESCE(SUM(`status` = 'unsubscribed'), 0)                 AS `unsubscribed`
               FROM `campaign_recipients`
              WHERE `campaign_id` = :campaign_id",
            ['campaign_id' => $campaignId]
        ) ?? [];

        $total   = (int) ($row['total'] ?? 0);
        $pending = (int) ($row['pending'] ?? 0);
        $sent    = (int) ($row['sent'] ?? 0);
        $opened  = (int) ($row['opened'] ?? 0);
        $clicked = (int) ($row['clicked'] ?? 0);

        return [
            'total'         => $total,
            'pending'       => $pending,
            'sent'          => $sent,
            'failed'        => (int) ($row['failed'] ?? 0),
            'bounced'       => (int) ($row['bounced'] ?? 0),
            'opened'        => $opened,
            'clicked'       => $clicked,
            'unsubscribed'  => (int) ($row['unsubscribed'] ?? 0),
            'open_rate'     => $sent > 0 ? round($opened / $sent * 100, 1) : 0.0,
            'click_rate'    => $sent > 0 ? round($clicked / $sent * 100, 1) : 0.0,
            'delivery_rate' => $total > 0 ? round($sent / $total * 100, 1) : 0.0,
            'progress'      => $total > 0 ? round(($total - $pending) / $total * 100, 1) : 0.0,
        ];
    }

    // -----------------------------------------------------------------
    // Scheduled runs
    // -----------------------------------------------------------------

    /**
     * Queue and dispatch everything that is due. Intended for a cron job —
     * the admin panel calls the same code path through "Send now".
     *
     * @return array{campaigns:int,sent:int,failed:int}
     */
    public static function runDue(int $perCampaign = 100): array
    {
        $due = Database::select(
            "SELECT `id`, `status` FROM `marketing_campaigns`
              WHERE `deleted_at` IS NULL
                AND ((`status` = 'scheduled' AND `scheduled_at` IS NOT NULL AND `scheduled_at` <= NOW())
                     OR `status` = 'sending')
              ORDER BY `scheduled_at` IS NULL, `scheduled_at` ASC
              LIMIT 20"
        );

        $campaigns = 0;
        $sent      = 0;
        $failed    = 0;

        foreach ($due as $row) {
            $campaignId = (int) $row['id'];

            try {
                // A campaign that is already sending has its recipient list;
                // only a newly due scheduled one needs materialising first.
                if ((string) $row['status'] !== 'sending') {
                    $queued = self::queue($campaignId);

                    if (!$queued['ok']) {
                        Logger::warning('Scheduled campaign could not be queued', [
                            'campaign_id' => $campaignId,
                            'reason'      => $queued['message'],
                        ]);

                        continue;
                    }
                }

                $result = self::dispatchBatch($campaignId, $perCampaign);

                if ($result['ok']) {
                    $campaigns++;
                    $sent   += $result['sent'];
                    $failed += $result['failed'];
                }
            } catch (\Throwable $e) {
                Logger::error('Scheduled campaign run failed', [
                    'campaign_id' => $campaignId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return ['campaigns' => $campaigns, 'sent' => $sent, 'failed' => $failed];
    }

    // -----------------------------------------------------------------
    // Newsletter subscribers
    // -----------------------------------------------------------------

    /**
     * Add or re-activate one subscriber.
     *
     * @return array{ok:bool,message:string,id:int,outcome:string}
     */
    public static function addSubscriber(string $email, string $name = '', string $source = 'admin', ?int $userId = null): array
    {
        $email = strtolower(trim($email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            return ['ok' => false, 'message' => 'That is not a valid email address.', 'id' => 0, 'outcome' => 'rejected'];
        }

        if (!in_array($source, ['app', 'web', 'import', 'admin'], true)) {
            $source = 'admin';
        }

        $existing = Database::selectOne(
            'SELECT `id`, `status` FROM `newsletter_subscribers` WHERE `email` = :email',
            ['email' => $email]
        );

        if ($existing !== null) {
            if ((string) $existing['status'] === 'subscribed') {
                return ['ok' => false, 'message' => 'That address is already on the list.', 'id' => (int) $existing['id'], 'outcome' => 'skipped'];
            }

            $changes = [
                'status'          => 'subscribed',
                'subscribed_at'   => date('Y-m-d H:i:s'),
                'unsubscribed_at' => null,
            ];

            // A blank name on the form means "leave it alone", not "erase it".
            if ($name !== '') {
                $changes['name'] = mb_substr($name, 0, 120);
            }

            Database::update('newsletter_subscribers', $changes, ['id' => (int) $existing['id']]);

            return ['ok' => true, 'message' => 'That address was re-subscribed.', 'id' => (int) $existing['id'], 'outcome' => 'resubscribed'];
        }

        Database::insertIgnore('newsletter_subscribers', [
            'email'             => $email,
            'name'              => $name === '' ? null : mb_substr($name, 0, 120),
            'user_id'           => $userId,
            'source'            => $source,
            'status'            => 'subscribed',
            'unsubscribe_token' => bin2hex(random_bytes(16)),
            'subscribed_at'     => date('Y-m-d H:i:s'),
        ]);

        // The id is read back rather than taken from the insert. Database
        // statements run the slow-query recorder afterwards, and that recorder
        // can issue its own query, which resets the connection's
        // LAST_INSERT_ID() before Database::insert() ever reads it. Looking the
        // row up by its unique email column is exact either way.
        $id = (int) (Database::scalar(
            'SELECT `id` FROM `newsletter_subscribers` WHERE `email` = :email',
            ['email' => $email]
        ) ?? 0);

        if ($id === 0) {
            return ['ok' => false, 'message' => 'That address could not be saved.', 'id' => 0, 'outcome' => 'rejected'];
        }

        return ['ok' => true, 'message' => 'Subscriber added.', 'id' => $id, 'outcome' => 'added'];
    }

    /**
     * Bulk import from pasted "email,name" lines.
     *
     * Every address costs a lookup and a write, so the run is capped: a paste
     * longer than the ceiling is processed up to it and the admin is told
     * exactly how many lines were left, rather than the request timing out
     * halfway through with no report at all.
     *
     * @return array{added:int,resubscribed:int,skipped:int,rejected:int,
     *               problems:list<array{line:int,value:string,reason:string}>,
     *               total:int,unprocessed:int,limit:int}
     */
    public static function importSubscribers(string $raw): array
    {
        $added        = 0;
        $resubscribed = 0;
        $skipped      = 0;
        $rejected     = 0;
        $problems     = [];
        $total        = 0;
        $unprocessed  = 0;
        $seen         = [];
        $firstLine    = true;

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // A pasted spreadsheet header should not be treated as data.
            if ($firstLine) {
                $firstLine = false;

                if (preg_match('/^e-?mail\s*[,;\t]/i', $line) === 1) {
                    continue;
                }
            }

            $lineNumber = $index + 1;

            if ($total >= self::MAX_IMPORT_LINES) {
                $unprocessed++;
                continue;
            }

            $total++;

            $parts = preg_split('/[,;\t]/', $line, 2) ?: [];
            $email = strtolower(trim((string) ($parts[0] ?? '')));
            $name  = trim((string) ($parts[1] ?? ''));
            $name  = trim($name, "\"' ");

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $rejected++;
                $problems[] = ['line' => $lineNumber, 'value' => mb_substr($line, 0, 80), 'reason' => 'Not a valid email address'];
                continue;
            }

            if (mb_strlen($email) > 191) {
                $rejected++;
                $problems[] = ['line' => $lineNumber, 'value' => mb_substr($email, 0, 80), 'reason' => 'Address is longer than 191 characters'];
                continue;
            }

            if (isset($seen[$email])) {
                $skipped++;
                $problems[] = ['line' => $lineNumber, 'value' => $email, 'reason' => 'Repeated in this paste'];
                continue;
            }

            $seen[$email] = true;

            $result = self::addSubscriber($email, $name, 'import');

            match ($result['outcome']) {
                'added'        => $added++,
                'resubscribed' => $resubscribed++,
                'rejected'     => $rejected++,
                default        => $skipped++,
            };

            if ($result['outcome'] === 'skipped') {
                $problems[] = ['line' => $lineNumber, 'value' => $email, 'reason' => 'Already subscribed'];
            } elseif ($result['outcome'] === 'rejected') {
                $problems[] = ['line' => $lineNumber, 'value' => $email, 'reason' => $result['message']];
            }
        }

        return [
            'added'        => $added,
            'resubscribed' => $resubscribed,
            'skipped'      => $skipped,
            'rejected'     => $rejected,
            'problems'     => array_slice($problems, 0, 50),
            'total'        => $total,
            'unprocessed'  => $unprocessed,
            'limit'        => self::MAX_IMPORT_LINES,
        ];
    }

    public static function setSubscriberStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['subscribed', 'unsubscribed', 'bounced'], true)) {
            return false;
        }

        $data = ['status' => $status];

        if ($status === 'subscribed') {
            $data['subscribed_at']   = date('Y-m-d H:i:s');
            $data['unsubscribed_at'] = null;
        } else {
            $data['unsubscribed_at'] = date('Y-m-d H:i:s');
        }

        return Database::update('newsletter_subscribers', $data, ['id' => $id]) > 0;
    }

    /**
     * Daily net additions for the last N days, gap-filled so the sparkline has
     * one point per day.
     *
     * @return list<array{day:string,count:int,cumulative:int}>
     */
    public static function subscriberGrowth(int $days = 30): array
    {
        $days = max(2, min(180, $days));

        $rows = Database::select(
            'SELECT DATE(`subscribed_at`) AS `day`, COUNT(*) AS `total`
               FROM `newsletter_subscribers`
              WHERE `subscribed_at` >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
              GROUP BY DATE(`subscribed_at`)
              ORDER BY `day` ASC',
            ['days' => $days - 1]
        );

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['total'];
        }

        $before = (int) Database::scalar(
            'SELECT COUNT(*) FROM `newsletter_subscribers`
              WHERE `subscribed_at` < DATE_SUB(CURDATE(), INTERVAL :days DAY)',
            ['days' => $days - 1]
        );

        $series     = [];
        $cumulative = $before;

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day        = date('Y-m-d', strtotime('-' . $offset . ' days'));
            $count      = $byDay[$day] ?? 0;
            $cumulative += $count;

            $series[] = ['day' => $day, 'count' => $count, 'cumulative' => $cumulative];
        }

        return $series;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** Reduce campaign HTML to readable plain text for SMS and push bodies. */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|div|h[1-6]|li|tr)>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim((string) preg_replace("/\n{2,}/", "\n", $text));
    }
}
