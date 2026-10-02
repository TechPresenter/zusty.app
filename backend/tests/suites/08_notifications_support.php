<?php

declare(strict_types=1);

/**
 * Notifications and support — the notification centre and the help desk.
 *
 * The notification feed and the support inbox are both single-tenant reads
 * keyed on `user_id`, which is exactly the shape of endpoint where a missing
 * scope clause leaks one account's data to another. So every read, mutation and
 * delete here is run twice: once by the owner, and once by a second actor who
 * must come away with nothing.
 *
 * Fixtures are written straight into `notifications` (through the real
 * `NotificationService::sendRaw` writer, in-app channel only, so no push job is
 * queued) because there is no API for creating a notification — they are a
 * side effect of bookings, payments and admin broadcasts. Tickets are created
 * through the API. Everything this suite writes is removed in the teardown
 * group, and the read flags it flipped on rows it did not own are restored.
 */

use Zuste\Core\Database;
use Zuste\Services\NotificationService;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

/** Notification ids this suite created. @var list<int> */
$seededNotifications = [];

/** Ticket ids this suite opened. @var list<int> */
$openedTickets = [];

/** Notifications that were unread before the suite ran, restored in teardown. @var list<int> */
$wasUnread = [];

/** Pull one row out of a notification list payload by id. */
$rowById = static function (array $rows, int $id): ?array {
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    return null;
};

// -----------------------------------------------------------------
Suite::group('Notifications & support — the auth boundary');

Suite::status('GET /notifications without a token is 401', Http::get('/notifications'), 401);
Suite::status('POST /notifications/1/read without a token is 401', Http::post('/notifications/1/read'), 401);
Suite::status('POST /notifications/read-all without a token is 401', Http::post('/notifications/read-all'), 401);
Suite::status('DELETE /notifications/1 without a token is 401', Http::delete('/notifications/1'), 401);
Suite::status('GET /support/tickets without a token is 401', Http::get('/support/tickets'), 401);
Suite::status(
    'POST /support/tickets without a token is 401',
    Http::post('/support/tickets', ['subject' => 'IT', 'message' => 'no token at all here']),
    401
);
Suite::status('GET /support/tickets/1 without a token is 401', Http::get('/support/tickets/1'), 401);
Suite::status(
    'POST /support/tickets/1/reply without a token is 401',
    Http::post('/support/tickets/1/reply', ['message' => 'no token']),
    401
);

// -----------------------------------------------------------------
$brand   = Actors::create('brand', 'brand');
$creator = Actors::create('creator', 'creator');

if ($brand === null || $creator === null) {
    Suite::skipGroup(
        'Notifications & support — everything',
        'could not obtain both a brand and a creator session: ' . Actors::lastError()
    );

    return;
}

$brandId   = Actors::id('brand');
$creatorId = Actors::id('creator');

if ($brandId <= 0 || $creatorId <= 0) {
    Suite::skipGroup(
        'Notifications & support — everything',
        'the actor payloads carried no user id, so nothing can be scoped or cleaned up'
    );

    return;
}

// Remember which of the actors' existing notifications were unread, so the
// read-all group can be undone and later suites see the state they expect.
$wasUnread = array_map('intval', Database::column(
    'SELECT `id` FROM `notifications` WHERE `user_id` IN (:a, :b) AND `is_read` = 0',
    ['a' => $brandId, 'b' => $creatorId]
));

// -----------------------------------------------------------------
// Fixtures — three for the brand across two categories, one for the creator.
// -----------------------------------------------------------------
$alpha = NotificationService::sendRaw($brandId, 'IT alpha', 'IT notification alpha body.', 'promo', 'none', null, null, ['in_app']);
$beta  = NotificationService::sendRaw($brandId, 'IT beta', 'IT notification beta body.', 'system', 'none', null, null, ['in_app']);
$gamma = NotificationService::sendRaw($brandId, 'IT gamma', 'IT notification gamma body.', 'promo', 'none', null, null, ['in_app']);
$theirs = NotificationService::sendRaw($creatorId, 'IT theirs', 'IT notification for the other actor.', 'system', 'none', null, null, ['in_app']);

$seededNotifications = array_values(array_filter([$alpha, $beta, $gamma, $theirs]));

if (count($seededNotifications) !== 4) {
    Suite::skipGroup(
        'Notifications — everything',
        'could not seed notification fixtures (sendRaw returned ' . json_encode([$alpha, $beta, $gamma, $theirs]) . ')'
    );
}

$haveNotifications = count($seededNotifications) === 4;

// -----------------------------------------------------------------
if ($haveNotifications) {
    Suite::group('Notifications — listing');

    $list = Http::get('/notifications', ['per_page' => 50], 'brand');
    Suite::ok('GET /notifications returns the caller\'s feed', $list, 200);

    $rows = $list->list();
    Suite::check('the feed is returned as a bare list', array_is_list($list->data()), $list->describe());

    Suite::check(
        'the pagination envelope is complete',
        static function () use ($list): bool {
            $page = $list->at('meta.pagination');

            if (!is_array($page)) {
                return false;
            }

            foreach (['total', 'per_page', 'current_page', 'last_page', 'from', 'to', 'has_more'] as $key) {
                if (!array_key_exists($key, $page)) {
                    return false;
                }
            }

            return true;
        },
        'meta.pagination was: ' . json_encode($list->at('meta.pagination'))
    );

    Suite::equals('the requested per_page is honoured', (int) ($list->at('meta.pagination.per_page') ?? 0), 50);

    Suite::check(
        'every row in the feed belongs to the caller',
        static function () use ($rows, $brandId): bool {
            if ($rows === []) {
                return false;
            }

            foreach ($rows as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $brandId) {
                    return false;
                }
            }

            return true;
        },
        'the feed contained rows owned by somebody else: ' . json_encode(array_column($rows, 'user_id'))
    );

    Suite::check(
        'the three seeded notifications are all present',
        static function () use ($rows, $alpha, $beta, $gamma): bool {
            $ids = array_map('intval', array_column($rows, 'id'));

            return in_array($alpha, $ids, true)
                && in_array($beta, $ids, true)
                && in_array($gamma, $ids, true);
        },
        'ids returned: ' . json_encode(array_column($rows, 'id'))
    );

    Suite::check(
        'the other actor\'s notification never appears in this feed',
        !in_array($theirs, array_map('intval', array_column($rows, 'id')), true),
        'notification ' . $theirs . ' belongs to user ' . $creatorId . ' but was served to user ' . $brandId
    );

    Suite::check(
        'the feed is ordered newest first',
        static function () use ($rows): bool {
            $ids = array_map('intval', array_column($rows, 'id'));

            $sorted = $ids;
            rsort($sorted);

            return $ids === $sorted;
        },
        'ids were: ' . json_encode(array_column($rows, 'id'))
    );

    Suite::check(
        'a row is decorated for the client (bool is_read, decoded data, time_ago)',
        static function () use ($rowById, $rows, $gamma): bool {
            $row = $rowById($rows, $gamma);

            return $row !== null
                && is_bool($row['is_read'] ?? null)
                && is_array($row['data'] ?? null)
                && is_string($row['time_ago'] ?? null)
                && $row['time_ago'] !== '';
        },
        'row was: ' . json_encode($rowById($rows, $gamma))
    );

    Suite::check(
        'a notification never carries another user\'s identity fields',
        static function () use ($list): bool {
            $body = strtolower($list->body);

            return !str_contains($body, 'password') && !str_contains($body, '$2y$');
        },
        'the feed body contained credential material'
    );

    // -- pagination actually pages ------------------------------------
    $unfilteredTotal = (int) ($list->at('meta.pagination.total') ?? 0);

    $firstPage = Http::get('/notifications', ['per_page' => 1, 'page' => 1], 'brand');
    Suite::ok('GET /notifications?per_page=1 returns a single row', $firstPage, 200);
    Suite::equals('per_page=1 really returns one item', count($firstPage->list()), 1);
    Suite::equals(
        'the pagination total is independent of the page size',
        (int) ($firstPage->at('meta.pagination.total') ?? 0),
        $unfilteredTotal
    );
    Suite::check(
        'has_more is true while pages remain',
        ($firstPage->at('meta.pagination.has_more') ?? null) === true,
        'meta.pagination was: ' . json_encode($firstPage->at('meta.pagination'))
    );

    $secondPage = Http::get('/notifications', ['per_page' => 1, 'page' => 2], 'brand');
    Suite::ok('page 2 is served', $secondPage, 200);
    Suite::check(
        'page 2 holds a different notification from page 1',
        static function () use ($firstPage, $secondPage): bool {
            $a = array_map('intval', array_column($firstPage->list(), 'id'));
            $b = array_map('intval', array_column($secondPage->list(), 'id'));

            return $a !== [] && $b !== [] && $a !== $b;
        },
        'page 1 ' . json_encode(array_column($firstPage->list(), 'id'))
            . ' vs page 2 ' . json_encode(array_column($secondPage->list(), 'id'))
    );

    // -- the category filter narrows ----------------------------------
    $promo = Http::get('/notifications', ['category' => 'promo', 'per_page' => 50], 'brand');
    Suite::ok('GET /notifications?category=promo is served', $promo, 200);

    Suite::check(
        '?category=promo returns only promo rows',
        static function () use ($promo): bool {
            $rows = $promo->list();

            if ($rows === []) {
                return false;
            }

            foreach ($rows as $row) {
                if (($row['category'] ?? null) !== 'promo') {
                    return false;
                }
            }

            return true;
        },
        'categories returned: ' . json_encode(array_column($promo->list(), 'category'))
    );

    Suite::check(
        '?category=promo actually narrows the result set',
        (int) ($promo->at('meta.pagination.total') ?? 0) < $unfilteredTotal,
        'filtered total ' . json_encode($promo->at('meta.pagination.total'))
            . ' was not smaller than the unfiltered ' . $unfilteredTotal
    );

    Suite::check(
        'the system-category notification is excluded by ?category=promo',
        !in_array($beta, array_map('intval', array_column($promo->list(), 'id')), true),
        'notification ' . $beta . ' has category system but survived the promo filter'
    );

    // -----------------------------------------------------------------
    Suite::group('Notifications — unread count and marking read');

    $before        = Http::get('/notifications', ['per_page' => 1], 'brand');
    $unreadBefore  = $before->at('meta.unread_count');

    Suite::check(
        'the feed reports an unread count in its meta',
        is_int($unreadBefore),
        'meta.unread_count was: ' . json_encode($unreadBefore)
    );

    $unreadBefore = (int) $unreadBefore;

    Suite::check(
        'the unread count covers the freshly seeded notifications',
        $unreadBefore >= 3,
        'expected at least the 3 seeded unread notifications, meta.unread_count was ' . $unreadBefore
    );

    Suite::equals(
        'the reported unread count matches the database',
        $unreadBefore,
        (int) Database::scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `is_read` = 0',
            ['id' => $brandId]
        )
    );

    $markRead = Http::post("/notifications/{$alpha}/read", null, 'brand');
    Suite::ok('POST /notifications/{id}/read succeeds for the owner', $markRead, 200);
    Suite::equals(
        'marking one read decrements the unread count by exactly one',
        (int) ($markRead->at('data.unread_count') ?? -1),
        $unreadBefore - 1
    );

    $afterMark = Http::get('/notifications', ['per_page' => 50], 'brand');
    Suite::check(
        'the notification now reads back as read',
        static function () use ($rowById, $afterMark, $alpha): bool {
            $row = $rowById($afterMark->list(), $alpha);

            return $row !== null && ($row['is_read'] ?? null) === true;
        },
        'row was: ' . json_encode($rowById($afterMark->list(), $alpha))
    );

    Suite::check(
        'read_at is stamped when a notification is marked read',
        static function () use ($rowById, $afterMark, $alpha): bool {
            $row = $rowById($afterMark->list(), $alpha);

            return $row !== null && !empty($row['read_at']);
        },
        'read_at was: ' . json_encode($rowById($afterMark->list(), $alpha)['read_at'] ?? null)
    );

    Suite::check(
        'the sibling notification is untouched by marking one read',
        static function () use ($rowById, $afterMark, $beta): bool {
            $row = $rowById($afterMark->list(), $beta);

            return $row !== null && ($row['is_read'] ?? null) === false;
        },
        'row was: ' . json_encode($rowById($afterMark->list(), $beta))
    );

    $unreadAfterMark = (int) Database::scalar(
        'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `is_read` = 0',
        ['id' => $brandId]
    );

    $markAgain = Http::post("/notifications/{$alpha}/read", null, 'brand');
    Suite::ok('marking an already-read notification read again is accepted', $markAgain, 200);
    Suite::equals(
        're-marking does not double-decrement the unread count',
        (int) ($markAgain->at('data.unread_count') ?? -1),
        $unreadAfterMark
    );

    // -- ?unread_only now has something to hide ------------------------
    $unreadOnly = Http::get('/notifications', ['unread_only' => 1, 'per_page' => 50], 'brand');
    Suite::ok('GET /notifications?unread_only=1 is served', $unreadOnly, 200);

    Suite::check(
        '?unread_only=1 returns only unread rows',
        static function () use ($unreadOnly): bool {
            $rows = $unreadOnly->list();

            if ($rows === []) {
                return false;
            }

            foreach ($rows as $row) {
                if (($row['is_read'] ?? null) !== false) {
                    return false;
                }
            }

            return true;
        },
        'is_read flags returned: ' . json_encode(array_column($unreadOnly->list(), 'is_read'))
    );

    Suite::check(
        'the notification just marked read drops out of ?unread_only=1',
        !in_array($alpha, array_map('intval', array_column($unreadOnly->list(), 'id')), true),
        'notification ' . $alpha . ' is read but still appears under unread_only'
    );

    Suite::equals(
        '?unread_only=1 reports the unread count as its total',
        (int) ($unreadOnly->at('meta.pagination.total') ?? -1),
        (int) ($unreadOnly->at('meta.unread_count') ?? -2)
    );

    // -----------------------------------------------------------------
    Suite::group('Notifications — one actor against another');

    // The throwaway actors are shared with every other suite in the run, so
    // confirm the fixture is still where it was put before treating its absence
    // as a broken tenancy boundary.
    $fixtureIntact = (int) Database::scalar(
        'SELECT COUNT(*) FROM `notifications` WHERE `id` = :id AND `user_id` = :user_id AND `is_read` = 0',
        ['id' => $theirs, 'user_id' => $creatorId]
    ) === 1;

    Suite::check(
        'the second actor\'s notification fixture is in place before the boundary is probed',
        $fixtureIntact,
        'notification ' . $theirs . ' is no longer an unread row owned by user ' . $creatorId
            . ' — another suite sharing these accounts may have swept it'
    );

    if (!$fixtureIntact) {
        Suite::skipGroup(
            'Notifications — one actor against another',
            'the fixture notification vanished before the cross-actor probes could run'
        );
    } else {
        // The controller scopes every write by `user_id`, so a cross-actor call
        // is a silent no-op rather than a refusal. The status is asserted as it
        // is; the state of the victim's row is what proves the boundary.
        $foreignRead = Http::post("/notifications/{$theirs}/read", null, 'brand');
        Suite::status(
            'POST /notifications/{id}/read on another actor\'s notification answers 200 (no-op)',
            $foreignRead,
            200
        );

        Suite::equals(
            'the cross-actor read attempt left the owner\'s notification unread',
            (int) Database::scalar(
                'SELECT `is_read` FROM `notifications` WHERE `id` = :id',
                ['id' => $theirs]
            ),
            0
        );

        Suite::equals(
            'the cross-actor read attempt only ever reports the caller\'s own unread count',
            (int) ($foreignRead->at('data.unread_count') ?? -1),
            (int) Database::scalar(
                'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `is_read` = 0',
                ['id' => $brandId]
            )
        );

        $foreignDelete = Http::delete("/notifications/{$theirs}", null, 'brand');
        Suite::status(
            'DELETE /notifications/{id} on another actor\'s notification answers 200 (no-op)',
            $foreignDelete,
            200
        );

        Suite::equals(
            'the cross-actor delete attempt did not remove the owner\'s notification',
            (int) Database::scalar('SELECT COUNT(*) FROM `notifications` WHERE `id` = :id', ['id' => $theirs]),
            1
        );

        $theirFeed = Http::get('/notifications', ['per_page' => 50], 'creator');
        Suite::ok('the other actor can still read their own feed', $theirFeed, 200);
        Suite::check(
            'the other actor still sees their notification, and none of the caller\'s',
            static function () use ($theirFeed, $theirs, $alpha, $beta, $gamma): bool {
                $ids = array_map('intval', array_column($theirFeed->list(), 'id'));

                return in_array($theirs, $ids, true)
                    && !in_array($alpha, $ids, true)
                    && !in_array($beta, $ids, true)
                    && !in_array($gamma, $ids, true);
            },
            'ids returned: ' . json_encode(array_column($theirFeed->list(), 'id'))
        );
    }

    // -----------------------------------------------------------------
    Suite::group('Notifications — mark all read');

    $creatorUnreadBefore = (int) Database::scalar(
        'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `is_read` = 0',
        ['id' => $creatorId]
    );

    $pendingForBrand = (int) Database::scalar(
        'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `is_read` = 0',
        ['id' => $brandId]
    );

    $readAll = Http::post('/notifications/read-all', null, 'brand');
    Suite::ok('POST /notifications/read-all succeeds', $readAll, 200);
    Suite::equals(
        'read-all reports zero unread afterwards',
        (int) ($readAll->at('data.unread_count') ?? -1),
        0
    );
    Suite::equals(
        'read-all reports how many rows it flipped',
        (int) ($readAll->at('data.marked') ?? -1),
        $pendingForBrand
    );

    $afterAll = Http::get('/notifications', ['per_page' => 50], 'brand');
    Suite::equals('the feed now reports zero unread', (int) ($afterAll->at('meta.unread_count') ?? -1), 0);

    Suite::check(
        'every row in the feed is read after read-all',
        static function () use ($afterAll): bool {
            foreach ($afterAll->list() as $row) {
                if (($row['is_read'] ?? null) !== true) {
                    return false;
                }
            }

            return true;
        },
        'is_read flags: ' . json_encode(array_column($afterAll->list(), 'is_read'))
    );

    Suite::equals(
        '?unread_only=1 now returns nothing',
        count(Http::get('/notifications', ['unread_only' => 1, 'per_page' => 50], 'brand')->list()),
        0
    );

    Suite::equals(
        'read-all did not touch the other actor\'s unread notifications',
        (int) Database::scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :id AND `is_read` = 0',
            ['id' => $creatorId]
        ),
        $creatorUnreadBefore
    );

    $readAllAgain = Http::post('/notifications/read-all', null, 'brand');
    Suite::ok('read-all is idempotent', $readAllAgain, 200);
    Suite::equals('a second read-all flips nothing', (int) ($readAllAgain->at('data.marked') ?? -1), 0);

    // -----------------------------------------------------------------
    Suite::group('Notifications — deleting one');

    $totalBeforeDelete = (int) (Http::get('/notifications', ['per_page' => 1], 'brand')
        ->at('meta.pagination.total') ?? 0);

    $deleted = Http::delete("/notifications/{$beta}", null, 'brand');
    Suite::ok('DELETE /notifications/{id} succeeds for the owner', $deleted, 200);

    Suite::equals(
        'the deleted notification is gone from the database',
        (int) Database::scalar('SELECT COUNT(*) FROM `notifications` WHERE `id` = :id', ['id' => $beta]),
        0
    );

    $afterDelete = Http::get('/notifications', ['per_page' => 50], 'brand');
    Suite::check(
        'the deleted notification is gone from the feed',
        !in_array($beta, array_map('intval', array_column($afterDelete->list(), 'id')), true),
        'notification ' . $beta . ' is still listed after a successful delete'
    );
    Suite::equals(
        'the total drops by exactly one',
        (int) ($afterDelete->at('meta.pagination.total') ?? -1),
        $totalBeforeDelete - 1
    );
    Suite::check(
        'the sibling notifications survive the delete',
        static function () use ($afterDelete, $alpha, $gamma): bool {
            $ids = array_map('intval', array_column($afterDelete->list(), 'id'));

            return in_array($alpha, $ids, true) && in_array($gamma, $ids, true);
        },
        'ids returned: ' . json_encode(array_column($afterDelete->list(), 'id'))
    );

    Suite::status(
        'deleting the same notification twice is accepted (no-op)',
        Http::delete("/notifications/{$beta}", null, 'brand'),
        200
    );

    $seededNotifications = array_values(array_diff($seededNotifications, [$beta]));
}

// -----------------------------------------------------------------
Suite::group('Support — opening a ticket');

$ticketSubject = 'IT support ticket ' . date('His');
$ticketOpening = 'The integration suite opened this ticket to exercise the help desk end to end.';

$created = Http::post('/support/tickets', [
    'subject'  => $ticketSubject,
    'message'  => $ticketOpening,
    'category' => 'technical',
], 'brand');

$ticketId = (int) ($created->at('data.ticket.id') ?? 0);

if ($created->status === 429) {
    Suite::skipGroup(
        'Support — everything',
        'POST /support/tickets is throttled (10 per hour per user) — the bucket is still warm from an earlier run'
    );
} elseif ($ticketId <= 0) {
    Suite::check('POST /support/tickets opens a ticket', false, $created->describe());
    Suite::skipGroup('Support — everything after creation', 'no ticket id was returned: ' . $created->describe());
} else {
    $openedTickets[] = $ticketId;

    Suite::ok('POST /support/tickets opens a ticket', $created, 201);
    Suite::equals('the new ticket is owned by the caller', (int) ($created->at('data.ticket.user_id') ?? 0), $brandId);
    Suite::equals('a new ticket starts open', (string) ($created->at('data.ticket.status') ?? ''), 'open');
    Suite::equals('the subject is stored verbatim', (string) ($created->at('data.ticket.subject') ?? ''), $ticketSubject);
    Suite::equals('the requested category is honoured', (string) ($created->at('data.ticket.category') ?? ''), 'technical');
    Suite::equals('priority defaults to normal', (string) ($created->at('data.ticket.priority') ?? ''), 'normal');
    Suite::check(
        'the ticket is issued a human-readable reference code',
        static function () use ($created): bool {
            $code = (string) ($created->at('data.ticket.code') ?? '');

            return $code !== '' && $code !== 'PENDING' && str_starts_with($code, 'ZT');
        },
        'code was: ' . json_encode($created->at('data.ticket.code'))
    );
    Suite::equals(
        'the opening message is filed as the first reply, from the user',
        (string) ($created->at('data.ticket.last_reply_by') ?? ''),
        'user'
    );

    // -----------------------------------------------------------------
    Suite::group('Support — validation');

    // Ticket creation is throttled to 10 per hour per user, so each rejected
    // attempt is made to earn its place: the validator reports one message per
    // field but every field, so a single malformed request proves several
    // rules at once and leaves budget for the runs that follow.
    $empty = Http::post('/support/tickets', [], 'brand');
    Suite::status('a ticket with neither subject nor message is rejected', $empty, [422, 429]);
    Suite::check(
        'the rejection names the missing subject field',
        static function () use ($empty): bool {
            // A throttled attempt was never validated, so it proves nothing
            // either way — see the skip note on this group.
            return $empty->status === 429 || is_array($empty->at('errors.subject'));
        },
        'expected an errors map keyed by field, got: ' . $empty->describe()
    );
    Suite::check(
        'the rejection names the missing message field',
        static function () use ($empty): bool {
            return $empty->status === 429 || is_array($empty->at('errors.message'));
        },
        'expected an errors map keyed by field, got: ' . $empty->describe()
    );
    Suite::check(
        'the rejection is flagged as a validation failure, not a generic error',
        static function () use ($empty): bool {
            return $empty->status === 429 || $empty->code() === 'validation_failed';
        },
        'code was: ' . json_encode($empty->code())
    );

    // A one-word "help" is not a support request (min:10), and the help desk
    // only files tickets under categories it actually routes.
    $malformed = Http::post('/support/tickets', [
        'subject'  => 'IT malformed ticket',
        'message'  => 'help',
        'category' => 'interpretive-dance',
    ], 'creator');
    Suite::status('a short message with an unknown category is rejected', $malformed, [422, 429]);
    Suite::check(
        'the rejection names the too-short message',
        static function () use ($malformed): bool {
            return $malformed->status === 429 || is_array($malformed->at('errors.message'));
        },
        'expected errors.message, got: ' . $malformed->describe()
    );
    Suite::check(
        'the rejection names the unknown category',
        static function () use ($malformed): bool {
            return $malformed->status === 429 || is_array($malformed->at('errors.category'));
        },
        'expected errors.category, got: ' . $malformed->describe()
    );
    Suite::check(
        'a valid subject on a rejected request is not reported as an error',
        static function () use ($malformed): bool {
            return $malformed->status === 429 || $malformed->at('errors.subject') === null;
        },
        'the validator flagged a subject that satisfies min:3|max:191: ' . $malformed->describe()
    );

    // `booking_id` is accepted as `nullable|integer` and then written straight
    // into the insert — it is never checked against the bookings table, nor
    // against the caller's own bookings. A reference to a booking that does
    // not exist belongs in the validator, not in an unhandled FK violation.
    $strayBooking = Http::post('/support/tickets', [
        'subject'    => 'IT stray booking reference',
        'message'    => 'This ticket references a booking id that does not exist anywhere.',
        'booking_id' => 999999999,
    ], 'brand');

    Suite::status(
        'a ticket referencing a booking that does not exist is refused, not fatal',
        $strayBooking,
        [404, 422, 429]
    );

    Suite::check(
        'a bad booking reference never surfaces the database schema',
        static function () use ($strayBooking): bool {
            $body = strtolower($strayBooking->body);

            return !str_contains($body, 'sqlstate')
                && !str_contains($body, 'foreign key')
                && !str_contains($body, 'constraint');
        },
        'the response carried a raw SQL error: ' . $strayBooking->describe(400)
    );

    if ($empty->status === 429 || $malformed->status === 429 || $strayBooking->status === 429) {
        Suite::skipGroup(
            'Support — validation rules',
            'POST /support/tickets is throttled to 10 per hour per user and the limiter answered '
            . 'before the validator did, so at least one field rule was not exercised on this run '
            . '(empty=' . $empty->status . ', malformed=' . $malformed->status
            . ', stray booking=' . $strayBooking->status . ')'
        );
    }

    Suite::check(
        'no ticket was created by any of the rejected attempts',
        static function () use ($brandId, $creatorId, $ticketId): bool {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM `support_tickets` WHERE `user_id` IN (:a, :b) AND `id` <> :t',
                ['a' => $brandId, 'b' => $creatorId, 't' => $ticketId]
            ) === 0;
        },
        'a rejected request still wrote a support_tickets row'
    );

    // -----------------------------------------------------------------
    Suite::group('Support — listing');

    $tickets = Http::get('/support/tickets', ['per_page' => 50], 'brand');
    Suite::ok('GET /support/tickets lists the caller\'s tickets', $tickets, 200);

    Suite::check(
        'the ticket list carries a pagination envelope',
        is_array($tickets->at('meta.pagination'))
            && array_key_exists('total', (array) $tickets->at('meta.pagination')),
        'meta was: ' . json_encode($tickets->at('meta'))
    );

    Suite::check(
        'the new ticket appears in the list',
        in_array($ticketId, array_map('intval', array_column($tickets->list(), 'id')), true),
        'ids returned: ' . json_encode(array_column($tickets->list(), 'id'))
    );

    Suite::check(
        'the list is a summary — it does not ship the message bodies',
        static function () use ($tickets): bool {
            $rows = $tickets->list();

            if ($rows === []) {
                return false;
            }

            foreach ($rows as $row) {
                if (array_key_exists('messages', $row)) {
                    return false;
                }
            }

            return true;
        },
        'a list row carried a messages key: ' . json_encode($tickets->list()[0] ?? null)
    );

    Suite::check(
        'the summary row carries the fields the inbox screen needs',
        static function () use ($tickets, $ticketId): bool {
            foreach ($tickets->list() as $row) {
                if ((int) ($row['id'] ?? 0) !== $ticketId) {
                    continue;
                }

                foreach (['code', 'subject', 'category', 'priority', 'status', 'created_at'] as $key) {
                    if (!array_key_exists($key, $row)) {
                        return false;
                    }
                }

                return true;
            }

            return false;
        },
        'row was: ' . json_encode($tickets->list()[0] ?? null)
    );

    Suite::equals(
        'per_page=1 returns a single ticket',
        count(Http::get('/support/tickets', ['per_page' => 1], 'brand')->list()),
        1
    );

    // -----------------------------------------------------------------
    Suite::group('Support — reading a ticket and its thread');

    $read = Http::get("/support/tickets/{$ticketId}", [], 'brand');
    Suite::ok('GET /support/tickets/{id} returns the ticket', $read, 200);
    Suite::equals('the ticket read back is the one asked for', (int) ($read->at('data.ticket.id') ?? 0), $ticketId);

    Suite::check(
        'the ticket carries its message thread',
        is_array($read->at('data.ticket.messages')),
        'messages was: ' . json_encode($read->at('data.ticket.messages'))
    );

    Suite::check(
        'the opening message is the first entry in the thread',
        static function () use ($read, $ticketOpening): bool {
            $messages = $read->at('data.ticket.messages');

            return is_array($messages)
                && count($messages) === 1
                && ($messages[0]['message'] ?? null) === $ticketOpening
                && ($messages[0]['sender_type'] ?? null) === 'user';
        },
        'thread was: ' . json_encode($read->at('data.ticket.messages'))
    );

    Suite::check(
        'message attachments are decoded to an array, not left as JSON text',
        static function () use ($read): bool {
            $messages = $read->at('data.ticket.messages');

            return is_array($messages) && is_array($messages[0]['attachments'] ?? null);
        },
        'attachments was: ' . json_encode($read->at('data.ticket.messages.0.attachments') ?? null)
    );

    Suite::status(
        'GET /support/tickets/{id} for a ticket that does not exist is 404',
        Http::get('/support/tickets/999999999', [], 'brand'),
        404
    );

    // -----------------------------------------------------------------
    Suite::group('Support — replying');

    $replyOne = 'First reply from the integration suite.';
    $replyTwo = 'Second reply, sent immediately after the first.';

    $reply = Http::post("/support/tickets/{$ticketId}/reply", ['message' => $replyOne], 'brand');
    Suite::ok('POST /support/tickets/{id}/reply is accepted', $reply, 201);

    $reply2 = Http::post("/support/tickets/{$ticketId}/reply", ['message' => $replyTwo], 'brand');
    Suite::ok('a second reply is accepted', $reply2, 201);

    Suite::status(
        'a reply with an empty message is rejected',
        Http::post("/support/tickets/{$ticketId}/reply", ['message' => ''], 'brand'),
        422
    );

    Suite::status(
        'replying to a ticket that does not exist is 404',
        Http::post('/support/tickets/999999999/reply', ['message' => 'into the void'], 'brand'),
        404
    );

    // An agent answers, plus an internal note the customer must never see.
    Database::insert('support_ticket_messages', [
        'ticket_id'   => $ticketId,
        'sender_type' => 'agent',
        'sender_name' => 'IT Agent',
        'message'     => 'Agent answer, visible to the customer.',
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    Database::insert('support_ticket_messages', [
        'ticket_id'   => $ticketId,
        'sender_type' => 'agent',
        'sender_name' => 'IT Agent',
        'message'     => 'Internal note — escalate to billing.',
        'is_internal' => 1,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    $thread = Http::get("/support/tickets/{$ticketId}", [], 'brand');
    Suite::ok('the ticket reads back after the exchange', $thread, 200);

    Suite::equals(
        'the thread returns in the order it was written',
        array_column((array) $thread->at('data.ticket.messages'), 'message'),
        [$ticketOpening, $replyOne, $replyTwo, 'Agent answer, visible to the customer.']
    );

    Suite::equals(
        'the thread reports who said what',
        array_column((array) $thread->at('data.ticket.messages'), 'sender_type'),
        ['user', 'user', 'user', 'agent']
    );

    Suite::check(
        'the internal agent note is withheld from the customer',
        !str_contains($thread->body, 'escalate to billing'),
        'an is_internal=1 message was served to the ticket owner'
    );

    Suite::equals(
        'a user reply moves the ticket into pending_agent',
        (string) ($thread->at('data.ticket.status') ?? ''),
        'pending_agent'
    );

    Suite::equals(
        'the last reply is attributed to the user',
        (string) ($thread->at('data.ticket.last_reply_by') ?? ''),
        'user'
    );

    Suite::notEmpty('last_reply_at is stamped', $thread->at('data.ticket.last_reply_at'), $thread->describe());

    // -----------------------------------------------------------------
    Suite::group('Support — one actor against another');

    $theirTicket = Http::post('/support/tickets', [
        'subject' => 'IT other actor ticket',
        'message' => 'This ticket belongs to the second actor and nobody else may touch it.',
    ], 'creator');

    $theirTicketId = (int) ($theirTicket->at('data.ticket.id') ?? 0);

    if ($theirTicketId <= 0) {
        Suite::skipGroup(
            'Support — one actor against another',
            'the second actor could not open a ticket: ' . $theirTicket->describe()
        );
    } else {
        $openedTickets[] = $theirTicketId;

        Suite::ok('the second actor opens a ticket of their own', $theirTicket, 201);
        Suite::equals(
            'that ticket is owned by the second actor',
            (int) ($theirTicket->at('data.ticket.user_id') ?? 0),
            $creatorId
        );

        Suite::status(
            'reading another actor\'s ticket is 404, not a leak',
            Http::get("/support/tickets/{$theirTicketId}", [], 'brand'),
            404
        );

        $foreignReply = Http::post("/support/tickets/{$theirTicketId}/reply", [
            'message' => 'Injecting a reply into somebody else\'s ticket.',
        ], 'brand');
        Suite::status('replying to another actor\'s ticket is 404', $foreignReply, 404);

        Suite::equals(
            'the rejected cross-actor reply wrote no message',
            (int) Database::scalar(
                'SELECT COUNT(*) FROM `support_ticket_messages` WHERE `ticket_id` = :id',
                ['id' => $theirTicketId]
            ),
            1
        );

        Suite::status(
            'the boundary holds in the other direction too',
            Http::get("/support/tickets/{$ticketId}", [], 'creator'),
            404
        );

        Suite::check(
            'another actor\'s ticket never appears in the caller\'s list',
            static function () use ($theirTicketId): bool {
                $ids = array_map('intval', array_column(
                    Http::get('/support/tickets', ['per_page' => 50], 'brand')->list(),
                    'id'
                ));

                return !in_array($theirTicketId, $ids, true);
            },
            'ticket ' . $theirTicketId . ' belongs to user ' . $creatorId . ' but was listed for user ' . $brandId
        );

        Suite::check(
            'the second actor can still read their own ticket',
            static function () use ($theirTicketId, $creatorId): bool {
                $res = Http::get("/support/tickets/{$theirTicketId}", [], 'creator');

                return $res->status === 200
                    && (int) ($res->at('data.ticket.id') ?? 0) === $theirTicketId
                    && (int) ($res->at('data.ticket.user_id') ?? 0) === $creatorId;
            },
            'the owner was locked out of their own ticket'
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Notifications & support — cleanup');

// Sweep by owner as well as by recorded id, so anything a leak created is
// still removed. Only the throwaway actors are ever touched.
$openedTickets = array_merge($openedTickets, array_map('intval', Database::column(
    'SELECT `id` FROM `support_tickets` WHERE `user_id` IN (:a, :b)',
    ['a' => $brandId, 'b' => $creatorId]
)));

$openedTickets = array_values(array_unique(array_filter($openedTickets)));

foreach ($openedTickets as $id) {
    Database::execute('DELETE FROM `support_ticket_messages` WHERE `ticket_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `support_tickets` WHERE `id` = :id', ['id' => $id]);
}

// Sweep the notification fixtures by their marker as well as by id, so a run
// that was interrupted between seeding and teardown does not leave residue for
// the next one to trip over. `admin.broadcast` + an `IT ` title is this
// suite's own signature; nothing else writes that pair for these accounts.
$seededNotifications = array_values(array_unique(array_merge(
    $seededNotifications,
    array_map('intval', Database::column(
        "SELECT `id` FROM `notifications`
         WHERE `user_id` IN (:a, :b) AND `type` = 'admin.broadcast' AND `title` LIKE 'IT %'",
        ['a' => $brandId, 'b' => $creatorId]
    ))
)));

foreach ($seededNotifications as $id) {
    Database::execute('DELETE FROM `notifications` WHERE `id` = :id', ['id' => $id]);
}

// Put back the read flags this suite flipped on rows it did not create, so a
// later suite sees the notification state it was handed.
$restore = array_values(array_diff($wasUnread, $seededNotifications));

if ($restore !== []) {
    Database::execute(
        'UPDATE `notifications` SET `is_read` = 0, `read_at` = NULL WHERE `id` IN ('
        . implode(',', array_map('intval', $restore)) . ')'
    );
}

Suite::check(
    'every ticket this suite opened has been removed',
    static function () use ($openedTickets): bool {
        if ($openedTickets === []) {
            return true;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `support_tickets` WHERE `id` IN (' . implode(',', $openedTickets) . ')'
        ) === 0;
    },
    'tickets ' . implode(',', $openedTickets) . ' are still present'
);

Suite::check(
    'no support messages are left orphaned',
    static function () use ($openedTickets): bool {
        if ($openedTickets === []) {
            return true;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `support_ticket_messages` WHERE `ticket_id` IN ('
            . implode(',', $openedTickets) . ')'
        ) === 0;
    },
    'orphaned support_ticket_messages rows remain'
);

Suite::check(
    'every notification this suite seeded has been removed',
    static function () use ($seededNotifications): bool {
        if ($seededNotifications === []) {
            return true;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `id` IN ('
            . implode(',', array_map('intval', $seededNotifications)) . ')'
        ) === 0;
    },
    'seeded notification rows remain'
);

Suite::check(
    'the read state of pre-existing notifications has been restored',
    static function () use ($restore): bool {
        if ($restore === []) {
            return true;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `is_read` = 1 AND `id` IN ('
            . implode(',', array_map('intval', $restore)) . ')'
        ) === 0;
    },
    'notifications this suite did not own are still flagged read'
);

// Queuing a push for a user who has a registered device is CORRECT — this
// used to assert none were queued, which only held while `user_devices` was
// empty. What matters is that the suite removes what it created, so a later
// run (or the queue worker) does not deliver "IT ..." fixtures to anyone.
Database::execute(
    "DELETE FROM `push_queue`
      WHERE `target_type` = 'user' AND `target_value` IN (:a, :b)
        AND `title` LIKE 'IT %'",
    ['a' => (string) $brandId, 'b' => (string) $creatorId]
);

Suite::check(
    'the suite removed every push job its fixtures queued',
    static function () use ($brandId, $creatorId): bool {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM `push_queue`
             WHERE `target_type` = 'user' AND `target_value` IN (:a, :b)
               AND `title` LIKE 'IT %'",
            ['a' => (string) $brandId, 'b' => (string) $creatorId]
        ) === 0;
    },
    'fixture push jobs survived cleanup and could still be delivered'
);
