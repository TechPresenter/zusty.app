<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Str;

/**
 * Reports: a user flags a profile, a message, a review, a booking, a
 * portfolio item or a campaign brief for the moderation queue.
 *
 * The target is resolved here, not trusted from the client: it has to exist,
 * the reporter has to be someone who could see it (a message only by a
 * participant, a booking only by a party to it, a draft brief by nobody but
 * those it was sent to), and the account behind it is recorded so the queue
 * can link to it. A copy of what was reported is kept as it read at the
 * time, because a message, a review or a brief can be edited or removed
 * before a moderator gets to it.
 *
 * One report per reporter and target is open at a time: a second while the
 * first is still open or under investigation is refused (409), which keeps
 * one person from flooding the queue about one thing. The route throttle
 * caps how many reports an account files in an hour.
 *
 * A moderator acts on a report from the queue: a message, a brief, a review
 * or a portfolio item can be taken down there ({@see takeDown()}); an account
 * is suspended from its user page and a booking handled from its own.
 */
final class ReportService
{
    /** What can be reported. `brief` is accepted as another name for `campaign`. */
    public const TYPES = ['user', 'creator', 'booking', 'review', 'message', 'portfolio', 'campaign'];

    /** Reason codes, in the order the app lists them. Keep in step with migration 054. */
    public const REASONS = [
        'spam', 'fraud', 'payment_issue', 'abuse', 'hate_speech', 'sexual_content', 'violence',
        'self_harm', 'child_safety', 'impersonation', 'inappropriate', 'copyright', 'other',
    ];

    /** Reasons the moderation queue paints red: someone may be at risk, or losing money. */
    public const SERIOUS_REASONS = [
        'child_safety', 'self_harm', 'violence', 'hate_speech', 'sexual_content', 'fraud', 'abuse', 'impersonation',
    ];

    /** How a reason reads to a moderator. */
    public const REASON_LABELS = [
        'spam'           => 'Spam',
        'fraud'          => 'Scam or fraud',
        'payment_issue'  => 'Paying outside Zusty',
        'abuse'          => 'Harassment or bullying',
        'hate_speech'    => 'Hate speech',
        'sexual_content' => 'Sexual content',
        'violence'       => 'Violence or threats',
        'self_harm'      => 'Self-harm',
        'child_safety'   => 'Child safety',
        'impersonation'  => 'Impersonation',
        'inappropriate'  => 'Inappropriate content',
        'copyright'      => 'Copyright',
        'other'          => 'Other',
    ];

    /** Longest copy of the reported content kept with a report. */
    private const EXCERPT_LENGTH = 500;

    /**
     * The `moderation_reason` term a portfolio take-down is filed under, by
     * report reason. Anything not listed is filed as `other`, with a note.
     */
    private const PORTFOLIO_REASONS = [
        'spam'           => 'spam_or_ads',
        'payment_issue'  => 'contact_details',
        'impersonation'  => 'impersonation',
        'copyright'      => 'copyright',
        'sexual_content' => 'explicit_content',
        'hate_speech'    => 'violence_or_hate',
        'violence'       => 'violence_or_hate',
        'fraud'          => 'misleading',
    ];

    /**
     * File a report.
     *
     * @return array<string,mixed> The report as the reporter may see it.
     *
     * @throws HttpException 404 (nothing the reporter can see by that id),
     *                       409 (already reported and still open),
     *                       422 (reporting oneself or something one posted)
     */
    public static function file(int $reporterId, string $type, int $id, string $reason, ?string $note): array
    {
        $type = $type === 'brief' ? 'campaign' : $type;

        if (!in_array($type, self::TYPES, true)) {
            throw HttpException::unprocessable('That cannot be reported.');
        }

        if (!in_array($reason, self::REASONS, true)) {
            throw HttpException::unprocessable('Choose a reason for the report.');
        }

        $subject = self::subject($type, $id, $reporterId);

        if ($subject['reported_user_id'] === $reporterId) {
            throw HttpException::unprocessable('You cannot report yourself or something you posted.');
        }

        $duplicate = Database::scalar(
            "SELECT 1 FROM `reports`
              WHERE `reporter_id` = :reporter AND `reportable_type` = :type AND `reportable_id` = :id
                AND `status` IN ('open','investigating')
              LIMIT 1",
            ['reporter' => $reporterId, 'type' => $type, 'id' => $id]
        );

        if ($duplicate !== null) {
            throw HttpException::conflict('You have already reported this. Our team is reviewing it.', 'already_reported');
        }

        $note = trim((string) $note);
        $now  = date('Y-m-d H:i:s');

        $reportId = Database::insert('reports', [
            'reporter_id'      => $reporterId,
            'reportable_type'  => $type,
            'reportable_id'    => $id,
            'reported_user_id' => $subject['reported_user_id'],
            'reason'           => $reason,
            'description'      => $note === '' ? null : mb_substr($note, 0, 2000),
            'subject_excerpt'  => $subject['excerpt'],
            'evidence_urls'    => $subject['evidence'] === []
                ? null
                : json_encode($subject['evidence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at'       => $now,
        ]);

        // A reported review also shows under Reviews → Flagged, where the
        // moderator who opens it from the queue can hide it.
        if ($type === 'review') {
            Database::execute(
                'UPDATE `reviews` SET `is_flagged` = 1 WHERE `id` = :id AND `is_flagged` = 0',
                ['id' => $id]
            );
        }

        return [
            'id'              => $reportId,
            'reportable_type' => $type,
            'reportable_id'   => $id,
            'reason'          => $reason,
            'status'          => 'open',
            'created_at'      => $now,
        ];
    }

    /**
     * Resolve what is being reported, as the reporter is allowed to see it.
     *
     * Anything the reporter could not see answers exactly like something that
     * does not exist, so the endpoint cannot be used to probe for ids.
     *
     * @return array{reported_user_id:int|null,excerpt:string|null,evidence:list<string>}
     */
    public static function subject(string $type, int $id, int $reporterId): array
    {
        $notFound = HttpException::notFound('We could not find what you are reporting. It may have been removed.');

        switch ($type) {
            case 'user':
                $row = Database::selectOne(
                    "SELECT `id`, `name`, `company_name` FROM `users`
                      WHERE `id` = :id AND `deleted_at` IS NULL AND `account_type` <> 'guest'",
                    ['id' => $id]
                ) ?? throw $notFound;

                return self::result((int) $row['id'], (string) ($row['company_name'] ?: $row['name']));

            case 'creator':
                $row = Database::selectOne(
                    'SELECT `user_id`, `display_name`, `handle`, `status`, `is_public` FROM `creators`
                      WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $id]
                ) ?? throw $notFound;

                // The same rule as CreatorService::profile(): an unapproved or
                // switched-off profile, or one whose owner blocked the reporter,
                // is not there for them.
                if (!self::profileVisible($row, $reporterId)) {
                    throw $notFound;
                }

                return self::result((int) $row['user_id'], $row['display_name'] . ' (@' . $row['handle'] . ')');

            case 'booking':
                $row = Database::selectOne(
                    'SELECT `code`, `title`, `brand_user_id`, `creator_user_id` FROM `bookings`
                      WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $id]
                ) ?? throw $notFound;

                $isBrand = (int) $row['brand_user_id'] === $reporterId;

                if (!$isBrand && (int) $row['creator_user_id'] !== $reporterId) {
                    throw $notFound;
                }

                // A booking report is about the other side of it.
                return self::result(
                    $isBrand ? (int) $row['creator_user_id'] : (int) $row['brand_user_id'],
                    $row['code'] . ' · ' . $row['title']
                );

            case 'review':
                $row = Database::selectOne(
                    'SELECT `reviewer_id`, `reviewee_id`, `title`, `comment`, `is_published` FROM `reviews`
                      WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $id]
                ) ?? throw $notFound;

                // A hidden review is visible to nobody but the person it is about.
                if ((int) $row['is_published'] !== 1 && (int) $row['reviewee_id'] !== $reporterId) {
                    throw $notFound;
                }

                return self::result(
                    (int) $row['reviewer_id'],
                    implode(' — ', array_filter([trim((string) $row['title']), trim((string) $row['comment'])]))
                );

            case 'message':
                $row = Database::selectOne(
                    'SELECT `conversation_id`, `sender_id`, `sender_type`, `type`, `body`, `media_url` FROM `messages`
                      WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $id]
                ) ?? throw $notFound;

                $participant = Database::scalar(
                    'SELECT 1 FROM `conversation_participants` WHERE `conversation_id` = :conversation AND `user_id` = :user',
                    ['conversation' => (int) $row['conversation_id'], 'user' => $reporterId]
                );

                if ($participant === null) {
                    throw $notFound;
                }

                if ($row['sender_type'] !== 'user' || $row['sender_id'] === null) {
                    throw HttpException::unprocessable('This is an update from Zusty, not a message from a person.');
                }

                $body = trim((string) $row['body']);

                return self::result(
                    (int) $row['sender_id'],
                    $body !== '' ? $body : '[' . ucfirst((string) $row['type']) . ']',
                    [$row['media_url']]
                );

            case 'portfolio':
                $row = Database::selectOne(
                    'SELECT c.`user_id`, c.`status`, c.`is_public`,
                            p.`title`, p.`media_url`, p.`external_url`, p.`is_active`, p.`moderation_status`
                       FROM `creator_portfolio` p
                       INNER JOIN `creators` c ON c.`id` = p.`creator_id` AND c.`deleted_at` IS NULL
                      WHERE p.`id` = :id AND p.`deleted_at` IS NULL',
                    ['id' => $id]
                ) ?? throw $notFound;

                // Only what is on the public profile: an item the creator hid,
                // or one still waiting for (or refused) approval, is not.
                $live = (int) $row['is_active'] === 1 && (string) $row['moderation_status'] === 'approved';

                if ((int) $row['user_id'] !== $reporterId && (!$live || !self::profileVisible($row, $reporterId))) {
                    throw $notFound;
                }

                return self::result(
                    (int) $row['user_id'],
                    (string) ($row['title'] ?? ''),
                    [$row['media_url'], $row['external_url']]
                );

            case 'campaign':
                $row = Database::selectOne(
                    'SELECT `code`, `title`, `description`, `brand_user_id`, `status` FROM `campaigns`
                      WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $id]
                ) ?? throw $notFound;

                // A brief is public while it is open. After that, and before it
                // is published, only the creators it was sent to have seen it.
                $published = in_array((string) $row['status'], ['open', 'in_progress'], true);

                if (!$published && (int) $row['brand_user_id'] !== $reporterId) {
                    $recipient = Database::scalar(
                        'SELECT 1 FROM `campaign_applications`
                          WHERE `campaign_id` = :id AND `creator_user_id` = :user AND `deleted_at` IS NULL',
                        ['id' => $id, 'user' => $reporterId]
                    );

                    if ($recipient === null) {
                        throw $notFound;
                    }
                }

                return self::result(
                    (int) $row['brand_user_id'],
                    $row['code'] . ' · ' . $row['title'] . ' — ' . trim((string) $row['description'])
                );
        }

        throw $notFound;
    }

    /**
     * How many reports are about an account, and how many of those are still
     * open, for the admin panel's user page.
     *
     * @return array{open:int,total:int}
     */
    public static function countsAbout(int $userId): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS `total`,
                    COALESCE(SUM(`status` IN ('open','investigating')), 0) AS `open`
               FROM `reports` WHERE `reported_user_id` = :id",
            ['id' => $userId]
        ) ?? [];

        return ['open' => (int) ($row['open'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    }

    /**
     * Take down what a report is about, and close every open report on it.
     *
     * What "take down" means depends on the content:
     *
     *   - message:   removed from the thread for both people (soft-deleted,
     *                so the report's copy and the audit trail still say what
     *                it was), and the inbox preview no longer shows it;
     *   - campaign:  the brief is cancelled and taken off the board, and the
     *                creators waiting on it are told it closed;
     *   - review:    hidden, and no longer counted in the rating;
     *   - portfolio: refused, so it leaves the public profile.
     *
     * The person who posted it is told, without being told who reported it.
     * A profile, an account or a booking is not "content" here: a moderator
     * suspends the account or acts on the booking from its own page.
     *
     * @return array{type:string,label:string,resolved:int}
     *
     * @throws HttpException 404 (no such report), 422 (nothing to take down)
     */
    public static function takeDown(int $reportId, int $adminId, string $note): array
    {
        $report = Database::selectOne('SELECT * FROM `reports` WHERE `id` = :id', ['id' => $reportId])
            ?? throw HttpException::notFound('Report not found.');

        $type  = (string) $report['reportable_type'];
        $id    = (int) $report['reportable_id'];
        $note  = trim($note);
        $owner = null;

        switch ($type) {
            case 'message':
                $message = MessageService::removeByModerator($id);
                $owner   = $message === null ? null : (int) $message['sender_id'];
                $label   = 'Message removed';
                $notice  = 'A message you sent on Zusty was removed because it broke our community guidelines.';
                break;

            case 'campaign':
                $campaign = BrandCampaignService::takeDown($id, $adminId);
                // Nobody to tell when it was already closed and private.
                $owner    = $campaign === null
                    || ((string) $campaign['status'] === 'cancelled' && (int) $campaign['is_public'] === 0)
                    ? null
                    : (int) $campaign['brand_user_id'];
                $label    = 'Campaign brief taken down';
                $notice   = 'Your campaign brief "' . Str::limit((string) ($campaign['title'] ?? ''), 80, '…')
                    . '" was taken down because it broke our community guidelines, and creators can no longer see or apply to it.';
                break;

            case 'review':
                $review = Database::selectOne(
                    'SELECT `id`, `reviewer_id`, `creator_id`, `is_published` FROM `reviews` WHERE `id` = :id AND `deleted_at` IS NULL',
                    ['id' => $id]
                );

                if ($review !== null) {
                    Database::update('reviews', [
                        'is_published' => 0,
                        'is_flagged'   => 0,
                        'moderated_by' => $adminId,
                        'moderated_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $id]);

                    // A hidden review stops counting toward the rating.
                    if ($review['creator_id'] !== null) {
                        CreatorService::refreshStatistics((int) $review['creator_id']);
                    }
                }

                $owner  = $review === null || (int) $review['is_published'] !== 1 ? null : (int) $review['reviewer_id'];
                $label  = 'Review hidden';
                $notice = 'A review you wrote on Zusty was hidden because it broke our community guidelines.';
                break;

            case 'portfolio':
                $item = Database::selectOne(
                    'SELECT p.`id`, p.`moderation_status`, c.`user_id`
                       FROM `creator_portfolio` p
                       INNER JOIN `creators` c ON c.`id` = p.`creator_id`
                      WHERE p.`id` = :id AND p.`deleted_at` IS NULL',
                    ['id' => $id]
                );

                if ($item !== null && (string) $item['moderation_status'] !== 'rejected') {
                    CreatorChangeService::rejectItem(
                        $id,
                        self::PORTFOLIO_REASONS[(string) $report['reason']] ?? 'other',
                        [
                            'actor_type' => 'admin',
                            'actor_id'   => $adminId,
                            'note'       => 'Taken down after a user report (#' . $reportId . ').'
                                . ($note === '' ? '' : ' ' . mb_substr($note, 0, 400)),
                        ]
                    );
                    $owner = (int) $item['user_id'];
                }

                $label  = 'Portfolio item taken down';
                $notice = 'An item in your Zusty portfolio was taken off your profile because it broke our community guidelines.';
                break;

            default:
                throw HttpException::unprocessable(
                    'There is no content to take down for this report. Suspend the account from its user page, or act on the booking from its own page.'
                );
        }

        $resolution = $label . '.' . ($note === '' ? '' : ' ' . $note);

        // Every report on the same content is answered by the one take-down.
        $resolved = Database::execute(
            "UPDATE `reports`
                SET `status` = 'resolved', `resolution_note` = :note, `handled_by` = :admin, `handled_at` = NOW()
              WHERE `reportable_type` = :type AND `reportable_id` = :id
                AND (`id` = :report OR `status` IN ('open','investigating'))",
            [
                'note'   => mb_substr($resolution, 0, 1000),
                'admin'  => $adminId,
                'type'   => $type,
                'id'     => $id,
                'report' => $reportId,
            ]
        );

        if ($owner !== null && $owner > 0) {
            NotificationService::sendRaw(
                $owner,
                'Content removed',
                $notice . ' If you think this was a mistake, write to support@zusty.app.',
                'system'
            );
        }

        return ['type' => $type, 'label' => $label, 'resolved' => $resolved];
    }

    /**
     * Whether a reporter can see a creator profile: it is approved and public
     * (unless it is their own), and its owner has not blocked them.
     *
     * @param array<string,mixed> $creator `user_id`, `status`, `is_public`
     */
    private static function profileVisible(array $creator, int $reporterId): bool
    {
        $ownerId = (int) $creator['user_id'];

        if ($ownerId === $reporterId) {
            return true;
        }

        if ((string) $creator['status'] !== 'approved' || (int) $creator['is_public'] !== 1) {
            return false;
        }

        return !BlockService::hasBlocked($ownerId, $reporterId);
    }

    /**
     * @param  list<mixed> $evidence
     * @return array{reported_user_id:int|null,excerpt:string|null,evidence:list<string>}
     */
    private static function result(int $reportedUserId, string $excerpt, array $evidence = []): array
    {
        $excerpt = trim(preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt);

        // Only web links and this site's own upload paths are kept as evidence.
        // A message's `media_url` is what the sender's client sent, and the
        // moderation queue turns evidence into links a moderator clicks: a
        // `javascript:` or `data:` value must never get that far.
        return [
            'reported_user_id' => $reportedUserId > 0 ? $reportedUserId : null,
            'excerpt'          => $excerpt === '' ? null : mb_substr($excerpt, 0, self::EXCERPT_LENGTH),
            'evidence'         => array_values(array_filter(
                array_map(static fn (mixed $url): string => trim((string) $url), $evidence),
                static fn (string $url): bool => $url !== ''
                    && mb_strlen($url) <= 500
                    && self::isSafeLink($url)
            )),
        ];
    }

    /** An absolute http(s) URL, or a path on this site (`/uploads/...`), never `//host`. */
    public static function isSafeLink(string $url): bool
    {
        if (preg_match('#^/(?![/\\\\])\S*$#', $url) === 1) {
            return true;
        }

        return preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
