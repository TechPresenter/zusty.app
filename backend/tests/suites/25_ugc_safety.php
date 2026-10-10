<?php

declare(strict_types=1);

/**
 * UGC safety — reports on every kind of content, and blocking.
 *
 * Google Play asks an app with user-generated content for a way to report it
 * and to block the people behind it. This suite drives both over the real
 * API: a report on each target type, the duplicate and visibility rules, and
 * a block between a brand and a creator — messages refused both ways, the
 * thread gone from the blocker's inbox, search and profiles, booking
 * requests, offers, invitations and applications refused, notifications
 * suppressed — while a booking that is already paid for can still be
 * delivered, sent back and disputed. Unblocking restores everything. The
 * data export lists the blocks, and an erasure removes them. A moderator can
 * take a reported message, brief, review or portfolio item down from the
 * queue, and a suspended brand's briefs leave the board.
 *
 * Actors are it-us-…@zuste.test and are reused between runs, except the
 * erased one, which stays as an anonymous tombstone like every erased
 * account. Everything else this suite writes is removed at the end, and the
 * fixture changes (an approved KYC profile, the creator's approved public
 * profile) are put back.
 */

use Zuste\Core\Database;
use Zuste\Core\RateLimiter;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\AuthService;
use Zuste\Services\DataExportService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

Suite::clearRateLimits();

$usMigrated = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND ((TABLE_NAME = 'user_blocks' AND COLUMN_NAME = 'blocker_id')
          OR (TABLE_NAME = 'reports' AND COLUMN_NAME IN ('reported_user_id', 'subject_excerpt')))"
) === 3;

if (!$usMigrated) {
    Suite::skipGroup('UGC safety', 'migration 054 is not applied: user_blocks / reports.reported_user_id are missing');

    return;
}

// -----------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------

$usBrand   = Actors::create('us-brand', 'brand');
$usCreator = Actors::create('us-creator', 'creator');
// A bystander: proves a block is between two people only, and that reports
// are de-duplicated per reporter rather than per target.
$usOther   = Actors::create('us-other', 'brand');

$usBrandId   = Actors::id('us-brand');
$usCreatorId = Actors::id('us-creator');
$usOtherId   = Actors::id('us-other');

if ($usBrand === null || $usCreator === null || $usOther === null || $usBrandId <= 0 || $usCreatorId <= 0 || $usOtherId <= 0) {
    Suite::skipGroup('UGC safety', 'could not register the brand, creator and bystander: ' . Actors::lastError());

    return;
}

$usActorIds = [$usBrandId, $usCreatorId, $usOtherId];

/** Booking, conversation and campaign ids this suite created, removed at the end. */
$usBookings      = [];
$usCampaigns     = [];
$usPortfolio     = 0;
$usKycInserted   = [];
$usErased        = [];

// Rows a failed earlier run may have left between these accounts.
[$usIn, $usInBindings] = Database::inClause($usActorIds);
Database::execute(
    'DELETE FROM `user_blocks` WHERE `blocker_id` IN ' . $usIn . ' OR `blocked_id` IN ' . $usIn,
    array_merge($usInBindings, $usInBindings)
);
Database::execute('DELETE FROM `reports` WHERE `reporter_id` IN ' . $usIn, $usInBindings);

/** Fresh budgets for this suite's own accounts, between groups. */
$usFreshBudgets = static function () use ($usActorIds): void {
    foreach ($usActorIds as $id) {
        foreach (['global', 'report', 'block', 'booking', 'chat', 'campaign', 'campaign_apply'] as $bucket) {
            RateLimiter::clear('throttle:' . $bucket . ':user:' . $id);
        }
    }
};

$usApproveKyc = static function (int $userId, string $kind) use (&$usKycInserted): void {
    $now      = date('Y-m-d H:i:s');
    $existing = Database::selectOne(
        'SELECT `id` FROM `kyc_profiles` WHERE `user_id` = :u AND `kind` = :k',
        ['u' => $userId, 'k' => $kind]
    );

    if ($existing === null) {
        Database::insert('kyc_profiles', [
            'user_id'              => $userId,
            'kind'                 => $kind,
            'status'               => 'approved',
            'reference'            => 'KYC-IT-US-' . strtoupper($kind[0]) . '-' . $userId,
            'submitted_at'         => $now,
            'approved_at'          => $now,
            'submission_count'     => 1,
            'completeness'         => 100,
            'is_identity_verified' => 1,
            'created_at'           => $now,
        ]);
        $usKycInserted[] = $userId;

        return;
    }

    Database::update('kyc_profiles', ['status' => 'approved', 'approved_at' => $now, 'expires_on' => null], ['id' => (int) $existing['id']]);
};

$usApproveKyc($usBrandId, 'brand');
$usApproveKyc($usCreatorId, 'creator');

// The creator has to be live to be found, booked or messaged as a creator.
$usCreatorRow = Database::selectOne(
    'SELECT `id`, `handle`, `display_name`, `status`, `is_public`, `availability_status` FROM `creators`
      WHERE `user_id` = :u AND `deleted_at` IS NULL',
    ['u' => $usCreatorId]
);

if ($usCreatorRow === null) {
    Suite::skipGroup('UGC safety', 'the creator account has no creator profile');

    return;
}

$usCreatorProfileId = (int) $usCreatorRow['id'];
$usHandle           = (string) $usCreatorRow['handle'];

Database::update(
    'creators',
    ['status' => 'approved', 'is_public' => 1, 'availability_status' => 'available'],
    ['id' => $usCreatorProfileId]
);

$usFreshBudgets();

/** A booking from the brand to the creator, remembered for the teardown. */
$usBook = static function () use (&$usBookings, $usCreatorProfileId): Res {
    $res = Http::post('/bookings', [
        'creator_id'       => $usCreatorProfileId,
        'title'            => 'IT-US booking ' . bin2hex(random_bytes(3)),
        'description'      => 'Integration test booking, safe to delete.',
        'platform'         => 'instagram',
        'deliverable_type' => 'post',
        'posts_count'      => 1,
        'amount'           => 25000,
    ], 'us-brand');

    $id = (int) ($res->at('data.booking.id') ?? 0);

    if ($id > 0) {
        $usBookings[] = $id;
    }

    return $res;
};

$usReport = static fn (string $actor, string $type, int $id, string $reason = 'abuse', ?string $note = null): Res => Http::post(
    '/reports',
    array_filter([
        'reportable_type' => $type,
        'reportable_id'   => $id,
        'reason'          => $reason,
        'note'            => $note,
    ], static fn (mixed $value): bool => $value !== null),
    $actor
);

$usReportRow = static fn (int $id): array => Database::selectOne('SELECT * FROM `reports` WHERE `id` = :id', ['id' => $id]) ?? [];

$usInboxIds = static fn (string $actor): array => array_map(
    'intval',
    array_column(Http::get('/conversations', ['per_page' => 100], $actor)->at('data.conversations') ?? [], 'id')
);

/** One thread's inbox row as [$actor] sees it, or [] when it is not there. */
$usInboxRow = static function (string $actor, int $conversationId): array {
    foreach (Http::get('/conversations', ['per_page' => 100], $actor)->at('data.conversations') ?? [] as $row) {
        if ((int) ($row['id'] ?? 0) === $conversationId) {
            return (array) $row;
        }
    }

    return [];
};

$usSearchFinds = static function (string $actor) use ($usCreatorProfileId, $usHandle): bool {
    $ids = array_map('intval', array_column(Http::get('/creators', ['q' => $usHandle, 'per_page' => 50], $actor)->list(), 'id'));

    return in_array($usCreatorProfileId, $ids, true);
};

// A refusal is checked for its code, so a 403 for some other reason (a
// missing verification, a switched-off feature) cannot pass for a block.
$usRefusedAsBlock = static fn (string $name, Res $res): bool => Suite::check(
    $name,
    $res->status === 403 && $res->code() === 'blocked',
    'expected 403 blocked, got ' . $res->describe()
);

$usNotified = static fn (int $userId, string $type, int $bookingId): int => (int) Database::scalar(
    'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :u AND `type` = :t AND `action_value` = :b',
    ['u' => $userId, 't' => $type, 'b' => (string) $bookingId]
);

// The content that gets reported: a direct thread with a message each way,
// a booking, a published brief, a portfolio item and a review.
$usThread   = (int) (Http::post('/conversations/start', ['user_id' => $usCreatorId], 'us-brand')->at('data.conversation_id') ?? 0);
$usFromCrt  = Http::post('/conversations/' . $usThread . '/messages', ['body' => 'IT-US hello from the creator'], 'us-creator');
$usFromBrd  = Http::post('/conversations/' . $usThread . '/messages', ['body' => 'IT-US hello from the brand'], 'us-brand');
$usMsgId    = (int) ($usFromCrt->at('data.message.id') ?? 0);
$usOwnMsgId = (int) ($usFromBrd->at('data.message.id') ?? 0);

$usPending   = $usBook();
$usPendingId = (int) ($usPending->at('data.booking.id') ?? 0);
$usPaid      = $usBook();
$usPaidId    = (int) ($usPaid->at('data.booking.id') ?? 0);
$usDone      = $usBook();
$usDoneId    = (int) ($usDone->at('data.booking.id') ?? 0);

// The paid booking is in progress with its money in escrow, and the other is
// finished: the states a block must never strand.
if ($usPaidId > 0) {
    Database::update('bookings', ['status' => 'in_progress', 'payment_status' => 'paid', 'escrow_status' => 'held'], ['id' => $usPaidId]);
}

if ($usDoneId > 0) {
    Database::update('bookings', ['status' => 'completed', 'payment_status' => 'paid', 'completed_at' => date('Y-m-d H:i:s')], ['id' => $usDoneId]);
}

$usCampaign   = Http::post('/campaigns', [
    'title'       => 'IT-US brief ' . bin2hex(random_bytes(3)),
    'description' => 'An integration test brief. Safe to delete.',
    'platform'    => 'instagram',
    'budget_min'  => 5000,
    'budget_max'  => 20000,
    'is_public'   => true,
], 'us-brand');
$usCampaignId = (int) ($usCampaign->at('data.campaign.id') ?? 0);

if ($usCampaignId > 0) {
    $usCampaigns[] = $usCampaignId;
    Http::post('/campaigns/' . $usCampaignId . '/publish', [], 'us-brand');
}

$usPortfolio = Database::insert('creator_portfolio', [
    'creator_id' => $usCreatorProfileId,
    'media_kind' => 'post',
    'title'      => 'IT-US portfolio item',
    'media_url'  => 'https://example.test/it-us-portfolio.jpg',
    'created_at' => date('Y-m-d H:i:s'),
]);

$usReviewId = $usDoneId > 0 ? Database::insert('reviews', [
    'booking_id'  => $usDoneId,
    'direction'   => 'brand_to_creator',
    'reviewer_id' => $usBrandId,
    'reviewee_id' => $usCreatorId,
    'creator_id'  => $usCreatorProfileId,
    'rating'      => 2,
    'title'       => 'IT-US review title',
    'comment'     => 'IT-US review comment',
    'created_at'  => date('Y-m-d H:i:s'),
]) : 0;

Suite::group('UGC safety — fixtures');

Suite::check('a direct thread with a message each way', $usThread > 0 && $usMsgId > 0 && $usOwnMsgId > 0, $usFromCrt->describe());
Suite::check('a pending, a paid and a completed booking', $usPendingId > 0 && $usPaidId > 0 && $usDoneId > 0, $usPending->describe());
Suite::check('a published brief', $usCampaignId > 0, $usCampaign->describe());
Suite::check('a portfolio item and a review', $usPortfolio > 0 && $usReviewId > 0);

// The control for the presence check under a block: the brand was active a
// moment ago, and without a block the creator's inbox says so.
Database::update('users', ['last_seen_at' => date('Y-m-d H:i:s')], ['id' => $usBrandId]);
Suite::equals(
    'without a block, the creator sees when the brand is online',
    $usInboxRow('us-creator', $usThread)['participant']['is_online'] ?? null,
    true
);

// =================================================================
Suite::group('Reports — every kind of content');
// =================================================================

Suite::status('POST /reports without a token is 401', Http::post('/reports', ['reportable_type' => 'user', 'reportable_id' => 1, 'reason' => 'spam']), 401);

$usTargets = [
    // [reporter, type, id, who is behind it, what the copy must contain]
    ['us-brand', 'user', $usCreatorId, $usCreatorId, 'IT Us-creator'],
    ['us-brand', 'creator', $usCreatorProfileId, $usCreatorId, '@' . $usHandle],
    ['us-brand', 'message', $usMsgId, $usCreatorId, 'IT-US hello from the creator'],
    ['us-brand', 'portfolio', $usPortfolio, $usCreatorId, 'IT-US portfolio item'],
    ['us-creator', 'review', $usReviewId, $usBrandId, 'IT-US review comment'],
    ['us-creator', 'booking', $usPendingId, $usBrandId, 'IT-US booking'],
    ['us-creator', 'campaign', $usCampaignId, $usBrandId, 'IT-US brief'],
];

$usFiled = [];

foreach ($usTargets as [$usActor, $usType, $usId, $usBehind, $usCopy]) {
    $usRes = $usReport($usActor, $usType, $usId, 'abuse', 'IT-US note about a ' . $usType);
    Suite::ok("a {$usType} can be reported", $usRes, 201);

    $usRow = $usReportRow((int) ($usRes->at('data.report.id') ?? 0));
    $usFiled[$usType] = (int) ($usRow['id'] ?? 0);

    Suite::check(
        "…the queue knows which account is behind the {$usType}, and what it said",
        (int) ($usRow['reported_user_id'] ?? 0) === $usBehind
            && str_contains((string) ($usRow['subject_excerpt'] ?? ''), $usCopy)
            && ($usRow['status'] ?? '') === 'open'
            && ($usRow['description'] ?? '') === 'IT-US note about a ' . $usType,
        json_encode(array_intersect_key($usRow, array_flip(['reportable_type', 'reported_user_id', 'subject_excerpt', 'description'])))
    );
}

Suite::equals(
    'a reported review is flagged, so it shows under Reviews → Flagged too',
    (int) Database::scalar('SELECT `is_flagged` FROM `reviews` WHERE `id` = :id', ['id' => $usReviewId]),
    1
);

$usBrief = $usReport('us-other', 'brief', $usCampaignId, 'fraud');
Suite::ok('`brief` is accepted as the app\'s name for a campaign', $usBrief, 201);
Suite::equals('…and filed as a campaign report', (string) ($usReportRow((int) ($usBrief->at('data.report.id') ?? 0))['reportable_type'] ?? ''), 'campaign');

Suite::check(
    'a reported photo keeps its link as evidence',
    str_contains((string) ($usReportRow($usFiled['portfolio'] ?? 0)['evidence_urls'] ?? ''), 'it-us-portfolio.jpg')
);

// A message's media_url is whatever the sender's client sent. Kept as
// evidence it becomes a link in the moderation queue, so a script URL must
// not survive.
$usScripted = Http::post(
    '/conversations/' . $usThread . '/messages',
    ['type' => 'image', 'media_url' => 'javascript:alert(document.cookie)'],
    'us-creator'
);
$usScriptedReport = $usReport('us-brand', 'message', (int) ($usScripted->at('data.message.id') ?? 0), 'sexual_content');
$usScriptedRow    = $usReportRow((int) ($usScriptedReport->at('data.report.id') ?? 0));
Suite::ok('a photo message can be reported', $usScriptedReport, 201);
Suite::check(
    '…but a script URL is never kept as evidence',
    $usScriptedRow !== [] && $usScriptedRow['evidence_urls'] === null,
    json_encode($usScriptedRow['evidence_urls'] ?? 'no report row')
);

// =================================================================
Suite::group('Reports — duplicates, visibility and validation');
// =================================================================

$usFreshBudgets();

$usDuplicate = $usReport('us-brand', 'message', $usMsgId);
Suite::status('reporting the same thing twice while it is open is refused', $usDuplicate, 409);
Suite::equals('…with its own code', $usDuplicate->code(), 'already_reported');

Suite::ok('another person can still report the same profile', $usReport('us-other', 'creator', $usCreatorProfileId, 'spam'), 201);

Database::update('reports', ['status' => 'resolved', 'handled_at' => date('Y-m-d H:i:s')], ['id' => $usFiled['message'] ?? 0]);
Suite::ok('once the first report is resolved, the same person can report again', $usReport('us-brand', 'message', $usMsgId, 'violence'), 201);

Suite::status('a message in someone else\'s conversation cannot be reported', $usReport('us-other', 'message', $usMsgId), 404);
Suite::status('nor a booking one is not a party to', $usReport('us-other', 'booking', $usPendingId), 404);

// Only what is on the public profile can be reported by someone else. The
// bystander already has an open report on this profile, so a 404 here (not
// a 409) proves the visibility rule is applied first.
Database::update('creators', ['is_public' => 0], ['id' => $usCreatorProfileId]);
$usHiddenProfile = $usReport('us-other', 'creator', $usCreatorProfileId, 'spam');
Database::update('creators', ['is_public' => 1], ['id' => $usCreatorProfileId]);
Suite::status('nor a profile its creator switched off', $usHiddenProfile, 404);

Database::update('creator_portfolio', ['moderation_status' => 'pending'], ['id' => $usPortfolio]);
$usPendingItem = $usReport('us-other', 'portfolio', $usPortfolio, 'copyright');
Database::update('creator_portfolio', ['moderation_status' => 'approved'], ['id' => $usPortfolio]);
Suite::status('nor a portfolio item still waiting for approval', $usPendingItem, 404);
Suite::status('something that does not exist is a 404', $usReport('us-brand', 'review', 999_999_999), 404);
Suite::status('reporting yourself is refused', $usReport('us-brand', 'user', $usBrandId), 422);
Suite::status('reporting your own message is refused', $usReport('us-brand', 'message', $usOwnMsgId), 422);
Suite::status('an unknown reason is refused', $usReport('us-other', 'user', $usCreatorId, 'boredom'), 422);
Suite::status('an unknown kind of target is refused', $usReport('us-other', 'wallet', 1), 422);
Suite::status('a note over 2000 characters is refused', $usReport('us-other', 'user', $usCreatorId, 'spam', str_repeat('x', 2001)), 422);

Suite::check(
    'nothing was filed by the refused attempts',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM `reports` WHERE `reporter_id` = :id AND `reportable_type` IN ('message','booking','wallet','portfolio')",
        ['id' => $usOtherId]
    ) === 0
);

// The route caps reports at 10 an hour per account; the bystander has two.
$usLimited = null;

for ($usI = 0; $usI < 12; $usI++) {
    $usLimited = $usReport('us-other', 'user', $usCreatorId, 'boredom');

    if ($usLimited->status === 429) {
        break;
    }
}

Suite::status('reports are rate limited per account', $usLimited, 429);

// =================================================================
Suite::group('Blocking — the API');
// =================================================================

$usFreshBudgets();

// Ties a block removes, both ways.
Database::insertIgnore('follows', ['follower_id' => $usBrandId, 'creator_id' => $usCreatorProfileId, 'created_at' => date('Y-m-d H:i:s')]);
Database::insertIgnore('favorites', ['user_id' => $usBrandId, 'creator_id' => $usCreatorProfileId, 'created_at' => date('Y-m-d H:i:s')]);

Suite::status('GET /me/blocks without a token is 401', Http::get('/me/blocks'), 401);
Suite::status('blocking needs an account', Http::post('/me/blocks', [], 'us-brand'), 422);
Suite::status('blocking yourself is refused', Http::post('/me/blocks', ['user_id' => $usBrandId], 'us-brand'), 422);
Suite::status('an account that does not exist cannot be blocked', Http::post('/me/blocks', ['user_id' => 999_999_999], 'us-brand'), 404);

$usBlock = Http::post('/me/blocks', ['user_id' => $usCreatorId], 'us-brand');
Suite::ok('the brand blocks the creator', $usBlock, 201);
Suite::equals('…and is told who it blocked', (int) ($usBlock->at('data.block.user_id') ?? 0), $usCreatorId);
Suite::equals('…under the name they go by on Zusty', (string) ($usBlock->at('data.block.name') ?? ''), (string) $usCreatorRow['display_name']);

$usAgain = Http::post('/me/blocks', ['user_id' => $usCreatorId], 'us-brand');
Suite::ok('blocking again is harmless', $usAgain, 200);
Suite::equals('…and still one row', (int) Database::scalar(
    'SELECT COUNT(*) FROM `user_blocks` WHERE `blocker_id` = :a AND `blocked_id` = :b',
    ['a' => $usBrandId, 'b' => $usCreatorId]
), 1);

$usList = Http::get('/me/blocks', [], 'us-brand');
Suite::ok('GET /me/blocks lists the blocked accounts', $usList, 200);
Suite::equals('…the creator, and only them', array_map('intval', array_column($usList->list(), 'user_id')), [$usCreatorId]);
Suite::equals('…with a total', (int) ($usList->at('meta.pagination.total') ?? -1), 1);
Suite::equals('the blocked side\'s own list is its own: empty', Http::get('/me/blocks', [], 'us-creator')->list(), []);

Suite::check(
    'blocking removed the follow and the saved creator',
    Database::scalar('SELECT 1 FROM `follows` WHERE `follower_id` = :u AND `creator_id` = :c', ['u' => $usBrandId, 'c' => $usCreatorProfileId]) === null
        && Database::scalar('SELECT 1 FROM `favorites` WHERE `user_id` = :u AND `creator_id` = :c', ['u' => $usBrandId, 'c' => $usCreatorProfileId]) === null
);
$usRefusedAsBlock('…and following again across the block is refused', Http::post('/creators/' . $usCreatorProfileId . '/follow', [], 'us-brand'));
$usRefusedAsBlock('…as is saving them', Http::post('/creators/' . $usCreatorProfileId . '/favorite', [], 'us-brand'));

// =================================================================
Suite::group('Blocking — messages, both ways');
// =================================================================

$usBlockerSends = Http::post('/conversations/' . $usThread . '/messages', ['body' => 'IT-US after the block'], 'us-brand');
Suite::status('the blocker cannot send a message', $usBlockerSends, 403);
Suite::equals('…the refusal says it is a block', $usBlockerSends->code(), 'blocked');
Suite::check('…and how to undo it', str_contains($usBlockerSends->message(), 'You blocked this account'), $usBlockerSends->message());

$usBlockedSends = Http::post('/conversations/' . $usThread . '/messages', ['body' => 'IT-US reply to a blocker'], 'us-creator');
Suite::status('the blocked side cannot send one either', $usBlockedSends, 403);
Suite::equals('…refused as a block', $usBlockedSends->code(), 'blocked');

Suite::check(
    'neither message was stored',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM `messages` WHERE `conversation_id` = :c AND `body` IN ('IT-US after the block', 'IT-US reply to a blocker')",
        ['c' => $usThread]
    ) === 0
);

Suite::check('the thread is gone from the blocker\'s inbox', !in_array($usThread, $usInboxIds('us-brand'), true));
Suite::check('the blocked side still has it', in_array($usThread, $usInboxIds('us-creator'), true));

Suite::check(
    '…marked as one they can no longer write in',
    static function () use ($usThread): bool {
        foreach (Http::get('/conversations', ['per_page' => 100], 'us-creator')->at('data.conversations') ?? [] as $row) {
            if ((int) $row['id'] === $usThread) {
                return $row['can_message'] === false;
            }
        }

        return false;
    }
);

$usHistory = Http::get('/conversations/' . $usThread . '/messages', [], 'us-brand');
Suite::ok('the history stays readable', $usHistory, 200);
Suite::equals('…read-only', $usHistory->at('data.can_message'), false);
// The inbox leaves the thread out for the blocker, so the chat screen takes
// its header and its Unblock item from the thread itself.
Suite::equals('…and still says who it is with', (int) ($usHistory->at('data.conversation.participant.user_id') ?? 0), $usCreatorId);
Suite::equals('…that the caller blocked them', $usHistory->at('data.conversation.participant.is_blocked'), true);
Suite::equals('…and that nobody can write in it', $usHistory->at('data.conversation.can_message'), false);

$usBlockedHistory = Http::get('/conversations/' . $usThread . '/messages', [], 'us-creator');
Suite::equals('the blocked side is not told it was blocked by name', $usBlockedHistory->at('data.conversation.participant.is_blocked'), false);

// Being blocked must not leave a window onto the blocker's activity.
Database::update('users', ['last_seen_at' => date('Y-m-d H:i:s')], ['id' => $usBrandId]);
Suite::equals(
    'the blocked side no longer sees when the blocker is online',
    $usInboxRow('us-creator', $usThread)['participant']['is_online'] ?? null,
    false
);
Suite::equals('…nor in the chat header', $usBlockedHistory->at('data.conversation.participant.is_online'), false);

$usRefusedAsBlock('the blocker cannot start a new conversation', Http::post('/conversations/start', ['user_id' => $usCreatorId], 'us-brand'));
$usRefusedAsBlock('nor can the blocked side', Http::post('/conversations/start', ['user_id' => $usBrandId], 'us-creator'));
Suite::ok('the bystander can still message the creator', Http::post('/conversations/start', ['user_id' => $usCreatorId], 'us-other'), 200);

Http::post('/conversations/' . $usThread . '/typing', ['is_typing' => true], 'us-creator');
Suite::equals('nobody is shown typing across a block', Http::get('/conversations/' . $usThread . '/typing', [], 'us-brand')->at('data.typing'), []);

// =================================================================
Suite::group('Blocking — search, profiles and the brief board');
// =================================================================

Suite::check('the blocked creator is gone from the blocker\'s search', !$usSearchFinds('us-brand'));
Suite::check('…but not from anyone else\'s', $usSearchFinds('us-other'));
Suite::check(
    '…nor from the blocker\'s suggestions',
    !in_array($usCreatorProfileId, array_map('intval', array_column(
        Http::get('/search/suggestions', ['q' => $usHandle], 'us-brand')->at('data.suggested_creators') ?? [],
        'id'
    )), true)
);

$usProfile = Http::get('/creators/' . $usHandle, [], 'us-brand');
Suite::ok('the blocker can still open the profile', $usProfile, 200);
Suite::equals('…flagged as blocked, so the app offers to unblock', $usProfile->at('data.creator.is_blocked'), true);
Suite::equals('anyone else sees it unflagged', Http::get('/creators/' . $usHandle, [], 'us-other')->at('data.creator.is_blocked'), false);

$usBoard = static fn (string $actor): array => array_map(
    'intval',
    array_column(Http::get('/campaigns', ['scope' => 'open', 'per_page' => 50], $actor)->list(), 'id')
);

Database::execute('DELETE FROM `user_blocks` WHERE `blocker_id` = :a AND `blocked_id` = :b', ['a' => $usBrandId, 'b' => $usCreatorId]);
$usBoardBefore = in_array($usCampaignId, $usBoard('us-creator'), true);
Database::insert('user_blocks', ['blocker_id' => $usBrandId, 'blocked_id' => $usCreatorId, 'created_at' => date('Y-m-d H:i:s')]);
Suite::check('the brief is on the creator\'s board without a block', $usBoardBefore);
Suite::check('…and off it with one', !in_array($usCampaignId, $usBoard('us-creator'), true));

// =================================================================
Suite::group('Blocking — nothing new can start');
// =================================================================

$usFreshBudgets();

$usRefusedAsBlock('the brand cannot send the creator a booking request', $usBook());
$usRefusedAsBlock('the creator cannot accept a request made before the block', Http::post('/bookings/' . $usPendingId . '/accept', [], 'us-creator'));
$usRefusedAsBlock('…nor counter it', Http::post('/bookings/' . $usPendingId . '/counter-offer', ['amount' => 30000], 'us-creator'));
$usRefusedAsBlock('the brand cannot invite the creator to a brief', Http::post('/campaigns/' . $usCampaignId . '/invite', ['creator_id' => $usCreatorProfileId], 'us-brand'));
$usRefusedAsBlock('the creator cannot apply to the brand\'s brief', Http::post('/campaigns/' . $usCampaignId . '/apply', ['pitch' => 'IT-US pitch'], 'us-creator'));

Suite::check(
    'no booking request or application was written',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM `campaign_applications` WHERE `campaign_id` = :c",
        ['c' => $usCampaignId]
    ) === 0
        && (string) Database::scalar('SELECT `status` FROM `bookings` WHERE `id` = :id', ['id' => $usPendingId]) === 'pending'
);

// =================================================================
Suite::group('Blocking — a booking that exists still settles');
// =================================================================

$usDeliveredBefore = $usNotified($usBrandId, 'booking.delivered', $usPaidId);

$usDeliver = Http::post('/bookings/' . $usPaidId . '/deliver', ['content_url' => 'https://example.test/it-us-post'], 'us-creator');
Suite::ok('the creator can still deliver paid work', $usDeliver, 200);
Suite::equals('…and the booking moves to delivered', (string) ($usDeliver->at('data.booking.status') ?? ''), 'delivered');
Suite::equals('…and the platform still tells the brand', $usNotified($usBrandId, 'booking.delivered', $usPaidId), $usDeliveredBefore + 1);

Suite::ok('the brand can still send it back for changes', Http::post('/bookings/' . $usPaidId . '/request-revision', ['note' => 'IT-US please change it'], 'us-brand'), 200);
Suite::ok('…and the creator can deliver again', Http::post('/bookings/' . $usPaidId . '/deliver', ['content_url' => 'https://example.test/it-us-post-2'], 'us-creator'), 200);

$usDispute = Http::post('/bookings/' . $usPaidId . '/dispute', ['reason' => 'poor_quality', 'detail' => 'IT-US dispute'], 'us-brand');
Suite::ok('the brand can still raise a dispute, with the money in escrow', $usDispute, 201);

Suite::ok('both sides can still read the booking', Http::get('/bookings/' . $usPaidId, [], 'us-creator'), 200);
Suite::ok('its thread still opens from the booking, read-only', Http::post('/conversations/start', ['booking_id' => $usPaidId], 'us-brand'), 200);
Suite::ok('an unpaid request can still be turned down', Http::post('/bookings/' . $usPendingId . '/reject', ['reason' => 'IT-US blocked'], 'us-creator'), 200);

// A review finishes a booking and is allowed, but the other side is not told.
$usReviewBefore = $usNotified($usCreatorId, 'review.received', $usDoneId);
Database::execute('DELETE FROM `reviews` WHERE `id` = :id', ['id' => $usReviewId]);
Suite::ok('the brand can still review the finished booking', Http::post('/bookings/' . $usDoneId . '/review', ['rating' => 3, 'comment' => 'IT-US review across a block'], 'us-brand'), 201);
Suite::equals('…but no notification crosses the block', $usNotified($usCreatorId, 'review.received', $usDoneId), $usReviewBefore);

// =================================================================
Suite::group('Blocking — the data export lists it');
// =================================================================

$usExport = DataExportService::collect(AuthService::loadUser($usBrandId), 0);
$usListed = array_column((array) ($usExport['activity.json']['blocked_accounts'] ?? []), 'name');
Suite::check('activity.json lists the accounts the user blocked', in_array((string) $usCreatorRow['display_name'], $usListed, true), json_encode($usListed));

$usCreatorExport = DataExportService::collect(AuthService::loadUser($usCreatorId), 0);
Suite::equals(
    'the blocked side\'s export does not say who blocked them',
    $usCreatorExport['activity.json']['blocked_accounts'] ?? null,
    []
);

// =================================================================
Suite::group('Admin panel — the queue and the user page');
// =================================================================

// A throwaway super admin, signed in through the real panel (cookie jar and
// CSRF), so the views are rendered exactly as a moderator sees them.
$usPanelBase     = (string) preg_replace('#/api/v\d+/?$#', '', Http::baseUrl());
$usJar           = (string) tempnam(sys_get_temp_dir(), 'it-us-jar');
$usAdminEmail    = 'it-us-admin@zuste.test';
$usAdminPassword = 'IntegrationTest#2024';

/** @return array{status:int,body:string,location:string} */
$usPanel = static function (string $method, string $path, array $fields = []) use ($usPanelBase, $usJar): array {
    $location = '';
    $handle   = curl_init($usPanelBase . $path);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $usJar,
        CURLOPT_COOKIEFILE     => $usJar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$location): int {
            if (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }

            return strlen($line);
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body   = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'location' => $location];
};

$usDropAdmin = static function (string $email): void {
    foreach (Database::column('SELECT `id` FROM `admins` WHERE `email` = :e', ['e' => $email]) as $id) {
        try {
            Database::execute('DELETE FROM `admin_sessions` WHERE `admin_id` = :id', ['id' => (int) $id]);
            Database::execute('DELETE FROM `admin_permissions` WHERE `admin_id` = :id', ['id' => (int) $id]);
            Database::execute('DELETE FROM `admins` WHERE `id` = :id', ['id' => (int) $id]);
        } catch (\Throwable) {
            // Referenced from an audit row: retire it under another address.
            Database::update('admins', [
                'email'      => 'retired-' . (int) $id . '-' . bin2hex(random_bytes(3)) . '@zuste.test',
                'status'     => 'inactive',
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $id]);
        }
    }
};

$usDropAdmin($usAdminEmail);

Database::insert('admins', [
    'name'                 => 'IT UGC Safety',
    'email'                => $usAdminEmail,
    'password_hash'        => \Zuste\Core\Crypto::hashPassword($usAdminPassword),
    'role_id'              => (int) (Database::scalar('SELECT `id` FROM `roles` ORDER BY `level` DESC LIMIT 1') ?? 0),
    'status'               => 'active',
    'must_change_password' => 0,
    'created_at'           => date('Y-m-d H:i:s'),
]);

$usLoginPage = $usPanel('GET', '/admin/login');
$usCsrfToken = preg_match('/name="_csrf"\s+value="([^"]+)"/', $usLoginPage['body'], $usMatch) === 1
    ? html_entity_decode($usMatch[1], ENT_QUOTES)
    : '';
$usSignIn = $usPanel('POST', '/admin/login', ['_csrf' => $usCsrfToken, 'email' => $usAdminEmail, 'password' => $usAdminPassword]);

if ($usSignIn['status'] !== 302 || str_contains($usSignIn['location'], '/admin/login')) {
    Suite::skipGroup('Admin panel — the queue and the user page', 'the probe admin could not sign in (HTTP ' . $usSignIn['status'] . ')');
} else {
    $usQueue = $usPanel('GET', '/admin/reports?status=all&reported_user_id=' . $usCreatorId);
    Suite::equals('the moderation queue opens on one account\'s reports', $usQueue['status'], 200);
    Suite::check(
        '…showing what was reported, as it read',
        str_contains($usQueue['body'], 'IT-US hello from the creator'),
        mb_substr(strip_tags($usQueue['body']), 0, 300)
    );
    Suite::check(
        '…with the reason in words and a link to the reported account',
        str_contains($usQueue['body'], 'Harassment or bullying')
            && str_contains($usQueue['body'], 'href="/admin/users/' . $usCreatorId . '"')
    );
    Suite::check(
        '…and how many people have blocked that account',
        str_contains($usQueue['body'], 'blocked by 1')
    );

    $usBriefs = $usPanel('GET', '/admin/reports?status=all&reportable_type=campaign');
    Suite::check('a reported brief is listed as a campaign brief', str_contains($usBriefs['body'], 'Campaign brief'));

    $usBrandPage = $usPanel('GET', '/admin/users/' . $usBrandId);
    Suite::equals('the brand\'s user page opens', $usBrandPage['status'], 200);
    Suite::check(
        '…and lists the account it blocked',
        str_contains($usBrandPage['body'], 'Blocked by this account (1)')
            && str_contains($usBrandPage['body'], htmlspecialchars((string) $usCreatorRow['display_name'], ENT_QUOTES))
    );

    $usCreatorPage = $usPanel('GET', '/admin/users/' . $usCreatorId);
    Suite::check(
        'the creator\'s page lists who blocked them and the reports about them',
        str_contains($usCreatorPage['body'], 'Accounts that blocked this one (1)')
            && str_contains($usCreatorPage['body'], 'reported_user_id=' . $usCreatorId)
    );

    // ---- Taking content down from the queue ----------------------------
    Suite::group('Admin panel — taking reported content down');

    $usFreshBudgets();

    $usCsrf = preg_match('/name="_csrf"\s+value="([^"]+)"/', $usQueue['body'], $usMatch) === 1
        ? html_entity_decode($usMatch[1], ENT_QUOTES)
        : '';
    $usTakeDown = static fn (int $reportId): array => $usPanel(
        'POST',
        '/admin/reports/' . $reportId . '/take-down',
        ['_csrf' => $usCsrf, 'note' => 'IT-US take-down']
    );
    $usOpenReport = static fn (string $type, int $id): int => (int) (Database::scalar(
        "SELECT `id` FROM `reports`
          WHERE `reportable_type` = :t AND `reportable_id` = :id AND `status` IN ('open','investigating')
          ORDER BY `id` DESC LIMIT 1",
        ['t' => $type, 'id' => $id]
    ) ?? 0);
    $usToldRemoved = static fn (int $userId): int => (int) Database::scalar(
        "SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :u AND `title` = 'Content removed'",
        ['u' => $userId]
    );

    Suite::check(
        'the queue offers to remove a reported message',
        str_contains($usQueue['body'], 'Remove message') && str_contains($usQueue['body'], '/take-down')
    );

    // A message: gone from the thread for both people.
    $usMsgReport  = $usOpenReport('message', $usMsgId);
    $usToldBefore = $usToldRemoved($usCreatorId);
    $usDown       = $usTakeDown($usMsgReport);
    Suite::check(
        'a moderator removes a reported message',
        $usMsgReport > 0 && $usDown['status'] === 302
            && Database::scalar('SELECT `deleted_at` FROM `messages` WHERE `id` = :id', ['id' => $usMsgId]) !== null,
        'report #' . $usMsgReport . ', HTTP ' . $usDown['status']
    );
    Suite::check(
        '…it is gone from the thread',
        !in_array($usMsgId, array_map('intval', array_column(
            Http::get('/conversations/' . $usThread . '/messages', [], 'us-creator')->at('data.messages') ?? [],
            'id'
        )), true)
    );
    Suite::equals('…the report is resolved', (string) ($usReportRow($usMsgReport)['status'] ?? ''), 'resolved');
    Suite::equals('…and the sender is told it was removed (not who reported it)', $usToldRemoved($usCreatorId), $usToldBefore + 1);

    // A brief: cancelled, off the board, every report on it answered.
    $usDown     = $usTakeDown($usFiled['campaign'] ?? 0);
    $usBriefRow = Database::selectOne('SELECT `status`, `is_public` FROM `campaigns` WHERE `id` = :id', ['id' => $usCampaignId]) ?? [];
    Suite::check(
        'a moderator takes a reported brief down',
        $usDown['status'] === 302 && ($usBriefRow['status'] ?? '') === 'cancelled' && (int) ($usBriefRow['is_public'] ?? 1) === 0,
        json_encode($usBriefRow)
    );
    Suite::equals(
        '…which answers every open report on it',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM `reports` WHERE `reportable_type` = 'campaign' AND `reportable_id` = :id AND `status` IN ('open','investigating')",
            ['id' => $usCampaignId]
        ),
        0
    );
    Suite::status('…and it no longer opens for anyone but its brand', Http::get('/campaigns/' . $usCampaignId, [], 'us-other'), 404);
    Suite::check('…who is told why', $usToldRemoved($usBrandId) >= 1);

    // A review: the one the brand wrote across the block.
    $usLateReview = (int) (Database::scalar(
        'SELECT `id` FROM `reviews` WHERE `booking_id` = :b AND `reviewer_id` = :u AND `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 1',
        ['b' => $usDoneId, 'u' => $usBrandId]
    ) ?? 0);
    $usLateReport = $usReport('us-creator', 'review', $usLateReview, 'abuse');
    Suite::ok('the reviewed creator reports the review', $usLateReport, 201);

    $usReviewPage = $usPanel('GET', '/admin/reviews?id=' . $usLateReview);
    Suite::check(
        '…and the queue\'s link opens that one review, flagged',
        $usReviewPage['status'] === 200
            && str_contains($usReviewPage['body'], 'Showing review #' . $usLateReview)
            && str_contains($usReviewPage['body'], 'IT-US review across a block')
            && str_contains($usReviewPage['body'], 'chip--error">Flagged')
    );

    $usDown = $usTakeDown((int) ($usLateReport->at('data.report.id') ?? 0));
    Suite::equals(
        'a moderator hides a reported review',
        (int) Database::scalar('SELECT `is_published` FROM `reviews` WHERE `id` = :id', ['id' => $usLateReview]),
        0
    );

    // A portfolio item: refused, so it leaves the public profile.
    $usDown = $usTakeDown($usFiled['portfolio'] ?? 0);
    Suite::equals(
        'a moderator takes a reported portfolio item off the profile',
        (string) Database::scalar('SELECT `moderation_status` FROM `creator_portfolio` WHERE `id` = :id', ['id' => $usPortfolio]),
        'rejected'
    );

    // An account is not content: it is suspended from its user page.
    $usTakeDown($usFiled['user'] ?? 0);
    Suite::equals(
        'an account report has nothing to take down, and stays open',
        (string) ($usReportRow($usFiled['user'] ?? 0)['status'] ?? ''),
        'open'
    );
}

$usDropAdmin($usAdminEmail);
@unlink($usJar);

// =================================================================
Suite::group('Unblocking restores everything');
// =================================================================

$usFreshBudgets();

$usUnblock = Http::delete('/me/blocks/' . $usCreatorId, null, 'us-brand');
Suite::ok('the brand unblocks the creator', $usUnblock, 200);
Suite::equals('…and the answer says so', $usUnblock->at('data.blocked'), false);
Suite::ok('unblocking again is harmless', Http::delete('/me/blocks/' . $usCreatorId, null, 'us-brand'), 200);
Suite::equals('the list is empty again', Http::get('/me/blocks', [], 'us-brand')->list(), []);

Suite::ok('the brand can message again', Http::post('/conversations/' . $usThread . '/messages', ['body' => 'IT-US unblocked'], 'us-brand'), 201);
Suite::ok('…and so can the creator', Http::post('/conversations/' . $usThread . '/messages', ['body' => 'IT-US welcome back'], 'us-creator'), 201);
Suite::check('the thread is back in the brand\'s inbox', in_array($usThread, $usInboxIds('us-brand'), true));
Suite::check('the creator is back in the brand\'s search', $usSearchFinds('us-brand'));
Suite::equals('the profile is no longer flagged', Http::get('/creators/' . $usHandle, [], 'us-brand')->at('data.creator.is_blocked'), false);

$usRebook = $usBook();
Suite::ok('a booking request goes through again', $usRebook, 201);

$usBrandReviewBefore = $usNotified($usBrandId, 'review.received', $usDoneId);
Suite::ok('the creator reviews the brand', Http::post('/bookings/' . $usDoneId . '/review', ['rating' => 5, 'comment' => 'IT-US review after unblocking'], 'us-creator'), 201);
Suite::equals('…and the brand is told, now there is no block', $usNotified($usBrandId, 'review.received', $usDoneId), $usBrandReviewBefore + 1);

// =================================================================
Suite::group('Blocking — the other direction');
// =================================================================

Suite::ok('the creator blocks the brand', Http::post('/me/blocks', ['user_id' => $usBrandId], 'us-creator'), 201);
Suite::status('the brand no longer finds the creator\'s profile', Http::get('/creators/' . $usHandle, [], 'us-brand'), 404);
Suite::status('…nor can report it, as if it were not there', $usReport('us-brand', 'creator', $usCreatorProfileId, 'spam'), 404);
Suite::check('…nor the creator in search', !$usSearchFinds('us-brand'));

$usTheyBlocked = Http::post('/conversations/' . $usThread . '/messages', ['body' => 'IT-US to someone who blocked me'], 'us-brand');
Suite::status('the brand cannot message the creator who blocked it', $usTheyBlocked, 403);
Suite::check('…and is not told how to unblock, since it was not their block', !str_contains($usTheyBlocked->message(), 'You blocked'), $usTheyBlocked->message());

Suite::ok('the creator unblocks the brand', Http::delete('/me/blocks/' . $usBrandId, null, 'us-creator'), 200);
Suite::ok('…and the profile is back', Http::get('/creators/' . $usHandle, [], 'us-brand'), 200);

// =================================================================
Suite::group('Moderation — a suspended brand\'s briefs leave the board');
// =================================================================

// Written directly: the bystander brand has no verification to publish
// through the API, and the board only reads the row.
$usOtherBrief = Database::insert('campaigns', [
    'code'          => 'ITUS' . strtoupper(bin2hex(random_bytes(4))),
    'brand_user_id' => $usOtherId,
    'title'         => 'IT-US bystander brief',
    'description'   => 'An integration test brief. Safe to delete.',
    'platform'      => 'instagram',
    'budget_min'    => 1000,
    'budget_max'    => 5000,
    'status'        => 'open',
    'is_public'     => 1,
    'created_at'    => date('Y-m-d H:i:s'),
]);
$usCampaigns[] = $usOtherBrief;

Suite::check('a brief from an active brand is on the creator\'s board', in_array($usOtherBrief, $usBoard('us-creator'), true));
Suite::ok('…and opens', Http::get('/campaigns/' . $usOtherBrief, [], 'us-creator'), 200);

$usOtherStatus = (string) (Database::scalar('SELECT `status` FROM `users` WHERE `id` = :id', ['id' => $usOtherId]) ?? 'active');
Database::update('users', ['status' => 'suspended'], ['id' => $usOtherId]);

Suite::check('once the brand is suspended, its brief is off the board', !in_array($usOtherBrief, $usBoard('us-creator'), true));
Suite::status('…does not open', Http::get('/campaigns/' . $usOtherBrief, [], 'us-creator'), 404);
Suite::status('…and cannot be applied to', Http::post('/campaigns/' . $usOtherBrief . '/apply', ['pitch' => 'IT-US pitch'], 'us-creator'), 404);

Database::update('users', ['status' => $usOtherStatus], ['id' => $usOtherId]);

// =================================================================
Suite::group('Blocking — an erasure removes the blocks');
// =================================================================

Suite::clearRateLimits();

$usGoneName = 'us-gone-' . bin2hex(random_bytes(3));
$usGone     = Actors::create($usGoneName, 'brand');
$usGoneId   = Actors::id($usGoneName);

if ($usGone === null || $usGoneId <= 0) {
    Suite::skipGroup('Blocking — an erasure removes the blocks', 'could not register a fresh account: ' . Actors::lastError());
} else {
    Suite::ok('a fresh account blocks the creator', Http::post('/me/blocks', ['user_id' => $usCreatorId], $usGoneName), 201);
    Suite::ok('…and the creator blocks it back', Http::post('/me/blocks', ['user_id' => $usGoneId], 'us-creator'), 201);

    $usSummary   = AccountDeletionService::delete(
        Database::selectOne('SELECT * FROM `users` WHERE `id` = :id', ['id' => $usGoneId]) ?? [],
        'password'
    );
    $usErased[] = $usGoneId;

    Suite::equals('the erasure removed both blocks', (int) ($usSummary['erased']['user_blocks'] ?? -1), 2);
    Suite::equals(
        '…and none is left either way',
        (int) Database::scalar(
            'SELECT COUNT(*) FROM `user_blocks` WHERE `blocker_id` = :id OR `blocked_id` = :id',
            ['id' => $usGoneId]
        ),
        0
    );
    Suite::equals('the creator\'s list no longer carries a "Deleted user"', Http::get('/me/blocks', [], 'us-creator')->list(), []);
}

// =================================================================
Suite::group('UGC safety — cleanup');
// =================================================================

Database::execute(
    'DELETE FROM `user_blocks` WHERE `blocker_id` IN ' . $usIn . ' OR `blocked_id` IN ' . $usIn,
    array_merge($usInBindings, $usInBindings)
);
Database::execute('DELETE FROM `reports` WHERE `reporter_id` IN ' . $usIn, $usInBindings);

$usConversations = array_map('intval', Database::column(
    'SELECT DISTINCT `conversation_id` FROM `conversation_participants` WHERE `user_id` IN ' . $usIn,
    $usInBindings
));

foreach ($usConversations as $usConversationId) {
    Database::execute('DELETE FROM `conversations` WHERE `id` = :id', ['id' => $usConversationId]);
}

$usBookings = array_values(array_unique(array_filter($usBookings)));

foreach ($usBookings as $usBookingId) {
    foreach ([
        'DELETE FROM `conversations` WHERE `booking_id` = :id',
        'DELETE FROM `platform_earnings` WHERE `booking_id` = :id',
        'DELETE FROM `bookings` WHERE `id` = :id',
    ] as $usStatement) {
        try {
            Database::execute($usStatement, ['id' => $usBookingId]);
        } catch (\Throwable $e) {
            Suite::check('cleanup statement succeeded', false, $e->getMessage());
        }
    }
}

foreach ($usCampaigns as $usId) {
    Database::execute('DELETE FROM `campaign_applications` WHERE `campaign_id` = :id', ['id' => $usId]);
    Database::execute('DELETE FROM `campaigns` WHERE `id` = :id', ['id' => $usId]);
}

if ($usPortfolio > 0) {
    Database::execute('DELETE FROM `creator_portfolio` WHERE `id` = :id', ['id' => $usPortfolio]);
}

Database::execute('DELETE FROM `notifications` WHERE `user_id` IN ' . $usIn, $usInBindings);

foreach ($usKycInserted as $usUserId) {
    Database::execute('DELETE FROM `kyc_profiles` WHERE `user_id` = :u', ['u' => $usUserId]);
}

Database::update(
    'creators',
    [
        'status'              => (string) $usCreatorRow['status'],
        'is_public'           => (int) $usCreatorRow['is_public'],
        'availability_status' => (string) $usCreatorRow['availability_status'],
    ],
    ['id' => $usCreatorProfileId]
);

// The erasure ledger is the restore safety net for real accounts; this
// suite's tombstone is taken back out of it.
$usLedger = AccountDeletionService::ledgerPath();

if ($usErased !== [] && is_file($usLedger)) {
    $usKept = array_filter(
        (array) file($usLedger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        static function (string $line) use ($usErased): bool {
            $entry = json_decode($line, true);

            return !is_array($entry) || !in_array((int) ($entry['user_id'] ?? 0), $usErased, true);
        }
    );
    file_put_contents($usLedger, $usKept === [] ? '' : implode("\n", $usKept) . "\n", LOCK_EX);
}

Suite::equals(
    'no bookings from this suite are left',
    $usBookings === [] ? 0 : (int) Database::scalar(
        'SELECT COUNT(*) FROM `bookings` WHERE `id` IN (' . implode(',', array_map('intval', $usBookings)) . ')'
    ),
    0
);
Suite::equals('no blocks or reports from this suite are left', (int) Database::scalar(
    'SELECT (SELECT COUNT(*) FROM `user_blocks` WHERE `blocker_id` IN ' . $usIn . ')
          + (SELECT COUNT(*) FROM `reports` WHERE `reporter_id` IN ' . $usIn . ')',
    array_merge($usInBindings, $usInBindings)
), 0);
Suite::equals(
    'the creator profile is back as it was',
    (string) Database::scalar('SELECT `status` FROM `creators` WHERE `id` = :id', ['id' => $usCreatorProfileId]),
    (string) $usCreatorRow['status']
);
