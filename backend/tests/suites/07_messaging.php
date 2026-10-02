<?php

declare(strict_types=1);

/**
 * Messaging — conversations, message history, receipts and presence.
 *
 * Drives the real chat flow between a brand and a creator: open the thread,
 * talk both ways, page back through history, mark read, mute, and type. A third
 * actor stands outside the thread throughout so the participant boundary is
 * proven rather than assumed — `MessageService::assertParticipant()` is the only
 * thing standing between one tenant's chat and everybody else's.
 *
 * Every conversation this suite opens is torn down at the end.
 */

use Zuste\Core\Database;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

// Conversation ids this suite opened, removed in the teardown group below.
/** @var list<int> $openedConversations */
$openedConversations = [];

// -----------------------------------------------------------------
Suite::group('Messaging — the auth boundary');

Suite::status('GET /conversations without a token is 401', Http::get('/conversations'), 401);
Suite::status(
    'POST /conversations/start without a token is 401',
    Http::post('/conversations/start', ['user_id' => 1]),
    401
);
Suite::status('GET /conversations/1/messages without a token is 401', Http::get('/conversations/1/messages'), 401);
Suite::status(
    'POST /conversations/1/messages without a token is 401',
    Http::post('/conversations/1/messages', ['body' => 'hello']),
    401
);

// -----------------------------------------------------------------
$brand    = Actors::create('brand', 'brand');
$creator  = Actors::create('creator', 'creator');
// The third party. A fresh registration costs one hit against the 5-per-15-min
// register throttle, so the account is reused on later runs rather than remade.
$outsider = Actors::create('outsider', 'brand');

$haveParties = $brand !== null && $creator !== null;

if (!$haveParties) {
    Suite::skipGroup(
        'Messaging — everything',
        'could not obtain both a brand and a creator session: ' . Actors::lastError()
    );

    return;
}

$brandId    = Actors::id('brand');
$creatorId  = Actors::id('creator');
$outsiderId = Actors::id('outsider');

// -----------------------------------------------------------------
Suite::group('Messaging — opening a conversation');

$started = Http::post('/conversations/start', ['user_id' => $creatorId], 'brand');
Suite::ok('POST /conversations/start opens a direct thread', $started, 200);

$threadId = (int) ($started->at('data.conversation_id') ?? 0);
Suite::notEmpty('start returns a conversation id', $threadId, $started->describe());

if ($threadId > 0) {
    $openedConversations[] = $threadId;
}

$startedAgain = Http::post('/conversations/start', ['user_id' => $creatorId], 'brand');
Suite::equals(
    'starting the same thread twice reuses it rather than forking',
    (int) ($startedAgain->at('data.conversation_id') ?? 0),
    $threadId
);

$fromCreator = Http::post('/conversations/start', ['user_id' => $brandId], 'creator');
Suite::equals(
    'the other party opening the thread lands in the same conversation',
    (int) ($fromCreator->at('data.conversation_id') ?? 0),
    $threadId
);

Suite::status(
    'starting a conversation with yourself is refused',
    Http::post('/conversations/start', ['user_id' => $brandId], 'brand'),
    422
);

Suite::status(
    'start without a target is refused',
    Http::post('/conversations/start', [], 'brand'),
    422
);

Suite::status(
    'start against an unknown booking is 404',
    Http::post('/conversations/start', ['booking_id' => 999_999_999], 'brand'),
    404
);

// `ensureDirectConversation()` inserts participants with INSERT IGNORE, which
// downgrades the foreign-key violation on a non-existent user to a warning. The
// row is dropped, the conversation is created anyway, and the caller is handed
// a one-sided thread. Assert the behaviour that should happen.
$ghost = Http::post('/conversations/start', ['user_id' => 999_999_999], 'brand');
Suite::status('start against a non-existent user is refused', $ghost, [404, 422]);

$ghostId = (int) ($ghost->at('data.conversation_id') ?? 0);

if ($ghostId > 0) {
    // Leaked — record it so the teardown removes it either way.
    $openedConversations[] = $ghostId;

    Suite::check(
        'a refused start does not leave an orphan conversation behind',
        false,
        'conversation ' . $ghostId . ' was created with a single participant for a user id that does not exist'
    );
}

if ($threadId <= 0) {
    Suite::skipGroup('Messaging — messages', 'no conversation could be opened, so nothing can be sent');

    return;
}

// -----------------------------------------------------------------
Suite::group('Messaging — sending and reading');

$brandSay = Http::post("/conversations/{$threadId}/messages", ['body' => 'Brief attached — can you shoot Thursday?'], 'brand');
Suite::ok('a participant can post into the thread', $brandSay, 201);
Suite::equals('the send echoes the caller\'s own text', $brandSay->at('data.message.body'), 'Brief attached — can you shoot Thursday?');
Suite::equals('a message the caller sent is flagged is_mine', $brandSay->at('data.message.is_mine'), true);

$brandMessageId = (int) ($brandSay->at('data.message.id') ?? 0);
Suite::notEmpty('the sent message carries an id', $brandMessageId, $brandSay->describe());

$creatorSay = Http::post("/conversations/{$threadId}/messages", ['body' => 'Thursday works. Sending a quote now.'], 'creator');
Suite::ok('the other party can reply', $creatorSay, 201);
$creatorMessageId = (int) ($creatorSay->at('data.message.id') ?? 0);

Suite::status(
    'an empty text message is rejected',
    Http::post("/conversations/{$threadId}/messages", ['body' => '   '], 'brand'),
    422
);

Suite::status(
    'an image message without a media URL is rejected',
    Http::post("/conversations/{$threadId}/messages", ['type' => 'image'], 'brand'),
    422
);

// Client-supplied uuid makes a retry after a dropped connection idempotent.
$clientUuid = sprintf('%s-4%s-8%s-%s', substr(md5('it-msg-a' . $threadId), 0, 8), substr(md5('b'), 0, 3), substr(md5('c'), 0, 3), substr(md5('d' . $threadId), 0, 12));
$clientUuid = substr($clientUuid, 0, 8) . '-' . substr(md5('x'), 0, 4) . '-4' . substr(md5('y'), 0, 3) . '-8' . substr(md5('z'), 0, 3) . '-' . substr(md5('w' . $threadId), 0, 12);

$firstTry  = Http::post("/conversations/{$threadId}/messages", ['uuid' => $clientUuid, 'body' => 'Quote v1'], 'brand');
$retry     = Http::post("/conversations/{$threadId}/messages", ['uuid' => $clientUuid, 'body' => 'Quote v1 (retried)'], 'brand');
Suite::ok('a message with a client uuid is accepted', $firstTry, 201);
Suite::equals(
    'replaying the same client uuid returns the original message instead of duplicating it',
    (int) ($retry->at('data.message.id') ?? -1),
    (int) ($firstTry->at('data.message.id') ?? 0)
);
Suite::equals(
    'the replay does not overwrite the stored body',
    $retry->at('data.message.body'),
    'Quote v1'
);

// -- content fidelity ------------------------------------------------
// Emoji (4-byte), ZWJ sequence, skin tone, regional indicators, Devanagari,
// CJK, Arabic, math alphanumerics, curly quotes, and characters that a naive
// HTML-escaping layer would mangle.
$unicode = 'नमस्ते 👩‍💻 👍🏽 🇮🇳 日本語 مرحبا 𝕫 — “smart quotes” & <b>tags</b> 100% ☕';

$unicodeSend = Http::post("/conversations/{$threadId}/messages", ['body' => $unicode], 'brand');
Suite::ok('a message full of unicode is accepted', $unicodeSend, 201);
Suite::equals('the send response returns the text byte-for-byte', $unicodeSend->at('data.message.body'), $unicode);

$unicodeMessageId = (int) ($unicodeSend->at('data.message.id') ?? 0);

$afterUnicode = Http::get("/conversations/{$threadId}/messages", ['limit' => 5], 'creator');
Suite::ok('GET /conversations/{id}/messages returns the thread', $afterUnicode, 200);

Suite::check(
    'unicode survives the round trip through storage and back out of the list',
    static function () use ($afterUnicode, $unicodeMessageId, $unicode): bool {
        foreach ($afterUnicode->at('data.messages') ?? [] as $row) {
            if ((int) $row['id'] === $unicodeMessageId) {
                return $row['body'] === $unicode;
            }
        }

        return false;
    },
    'expected ' . json_encode($unicode, JSON_UNESCAPED_UNICODE)
        . ' — got ' . json_encode(array_column($afterUnicode->at('data.messages') ?? [], 'body'), JSON_UNESCAPED_UNICODE)
);

Suite::check(
    'the stored row itself holds the unicode, not a mojibake of it',
    static function () use ($unicodeMessageId, $unicode): bool {
        return Database::scalar('SELECT `body` FROM `messages` WHERE `id` = :id', ['id' => $unicodeMessageId]) === $unicode;
    },
    'the messages.body column did not match what was sent'
);

// -- list shape ------------------------------------------------------
$thread = Http::get("/conversations/{$threadId}/messages", ['limit' => 50], 'brand');
Suite::ok('the message list responds with the success envelope', $thread, 200);

$threadMessages = $thread->at('data.messages');
Suite::check('data.messages is a list', is_array($threadMessages) && array_is_list($threadMessages), $thread->describe());
Suite::check('the list reports has_more as a boolean', is_bool($thread->at('data.has_more')), $thread->describe());
Suite::check('the list carries a typing roster', is_array($thread->at('data.typing')), $thread->describe());

Suite::check(
    'history is ordered newest first',
    static function () use ($threadMessages): bool {
        $ids = array_map('intval', array_column((array) $threadMessages, 'id'));

        if (count($ids) < 2) {
            return false;
        }

        $sorted = $ids;
        rsort($sorted);

        return $ids === $sorted;
    },
    'ids were ' . implode(',', array_column((array) $threadMessages, 'id'))
);

Suite::check(
    'both sides of the conversation appear in the history',
    static function () use ($threadMessages, $brandMessageId, $creatorMessageId): bool {
        $ids = array_map('intval', array_column((array) $threadMessages, 'id'));

        return in_array($brandMessageId, $ids, true) && in_array($creatorMessageId, $ids, true);
    },
    'expected both ' . $brandMessageId . ' and ' . $creatorMessageId . ' in the history'
);

Suite::check(
    'is_mine is computed per viewer, not baked into the row',
    static function () use ($threadMessages, $brandMessageId, $creatorMessageId): bool {
        $mine = [];

        foreach ((array) $threadMessages as $row) {
            $mine[(int) $row['id']] = (bool) $row['is_mine'];
        }

        // Read as the brand: the brand's own message is mine, the creator's is not.
        return ($mine[$brandMessageId] ?? null) === true && ($mine[$creatorMessageId] ?? null) === false;
    },
    'is_mine map was ' . json_encode(array_map(
        static fn (array $r): array => [(int) $r['id'], $r['is_mine']],
        (array) $threadMessages
    ))
);

// -----------------------------------------------------------------
Suite::group('Messaging — the inbox');

$inbox = Http::get('/conversations', [], 'brand');
Suite::ok('GET /conversations lists the caller\'s threads', $inbox, 200);
Suite::check('data.conversations is a list', is_array($inbox->at('data.conversations')), $inbox->describe());
Suite::check('the inbox reports a total unread count in meta', is_int($inbox->at('meta.total_unread')), $inbox->describe());

$inboxRow = null;
foreach ($inbox->at('data.conversations') ?? [] as $row) {
    if ((int) $row['id'] === $threadId) {
        $inboxRow = $row;
    }
}

Suite::notEmpty('the open thread appears in the brand\'s inbox', $inboxRow, $inbox->describe());

if (is_array($inboxRow)) {
    Suite::equals(
        'the inbox names the other party, not the caller',
        (int) ($inboxRow['participant']['user_id'] ?? 0),
        $creatorId
    );
    Suite::equals('the inbox preview is the latest message', $inboxRow['last_message'], $unicode);
    Suite::check('the inbox exposes a mute flag', is_bool($inboxRow['is_muted']), json_encode($inboxRow));
    Suite::check('the inbox exposes an unread count', is_int($inboxRow['unread_count']), json_encode($inboxRow));
}

// -----------------------------------------------------------------
Suite::group('Messaging — unread counts and read receipts');

// Reset both sides so the arithmetic below starts from a known floor.
Suite::ok('POST /conversations/{id}/read clears the caller\'s unread', Http::post("/conversations/{$threadId}/read", [], 'creator'), 200);
Http::post("/conversations/{$threadId}/read", [], 'brand');

$creatorInboxBefore = Http::get('/conversations', [], 'creator');
$unreadBefore       = (int) ($creatorInboxBefore->at('meta.total_unread') ?? -1);
Suite::equals('the creator starts from zero unread', $unreadBefore, 0);

$receiptMessage = Http::post("/conversations/{$threadId}/messages", ['body' => 'Unread probe one'], 'brand');
$receiptId      = (int) ($receiptMessage->at('data.message.id') ?? 0);
Http::post("/conversations/{$threadId}/messages", ['body' => 'Unread probe two'], 'brand');

$creatorInboxAfter = Http::get('/conversations', [], 'creator');
Suite::equals(
    'two inbound messages raise the recipient\'s total unread to two',
    (int) ($creatorInboxAfter->at('meta.total_unread') ?? -1),
    2
);

Suite::check(
    'the per-conversation unread count tracks the total',
    static function () use ($creatorInboxAfter, $threadId): bool {
        foreach ($creatorInboxAfter->at('data.conversations') ?? [] as $row) {
            if ((int) $row['id'] === $threadId) {
                return (int) $row['unread_count'] === 2;
            }
        }

        return false;
    },
    $creatorInboxAfter->describe()
);

$senderInbox = Http::get('/conversations', [], 'brand');
Suite::equals(
    'the sender\'s own unread count does not move when they post',
    (int) ($senderInbox->at('meta.total_unread') ?? -1),
    0
);

$read = Http::post("/conversations/{$threadId}/read", [], 'creator');
Suite::ok('marking the thread read succeeds', $read, 200);
Suite::equals('marking read returns the caller\'s new total unread', (int) ($read->at('data.total_unread') ?? -1), 0);

$creatorInboxRead = Http::get('/conversations', [], 'creator');
Suite::equals(
    'the inbox reflects the cleared unread count',
    (int) ($creatorInboxRead->at('meta.total_unread') ?? -1),
    0
);

Suite::check(
    'the sender sees the read receipt once the recipient has read',
    static function () use ($threadId, $receiptId): bool {
        $seen = Http::get("/conversations/{$threadId}/messages", ['limit' => 20], 'brand');

        foreach ($seen->at('data.messages') ?? [] as $row) {
            if ((int) $row['id'] === $receiptId) {
                return (int) $row['read_count'] >= 1 && $row['is_read'] === true;
            }
        }

        return false;
    },
    'message ' . $receiptId . ' still reports read_count 0 after the recipient marked the thread read'
);

// Opening the thread is itself a read acknowledgement.
Http::post("/conversations/{$threadId}/messages", ['body' => 'Opening clears unread'], 'brand');
$openThread = Http::get("/conversations/{$threadId}/messages", ['limit' => 5], 'creator');
Suite::ok('opening the thread succeeds', $openThread, 200);
Suite::equals(
    'opening the thread clears the unread count without an explicit read call',
    (int) (Http::get('/conversations', [], 'creator')->at('meta.total_unread') ?? -1),
    0
);

// -----------------------------------------------------------------
Suite::group('Messaging — message list pagination');

// Enough traffic that a three-per-page walk needs several pages.
for ($i = 1; $i <= 6; $i++) {
    Http::post("/conversations/{$threadId}/messages", ['body' => 'Pagination probe ' . $i], 'creator');
}

$everything = Http::get("/conversations/{$threadId}/messages", ['limit' => 100], 'brand');
$allIds     = array_map('intval', array_column($everything->at('data.messages') ?? [], 'id'));
Suite::check('the unpaged fetch returns the whole thread', count($allIds) >= 9, 'got ' . count($allIds) . ' messages');
Suite::equals('a thread that fits in one page reports has_more false', $everything->at('data.has_more'), false);
Suite::equals('a final page carries a null cursor', $everything->at('data.next_cursor'), null);

$page1    = Http::get("/conversations/{$threadId}/messages", ['limit' => 3], 'brand');
$page1Ids = array_map('intval', array_column($page1->at('data.messages') ?? [], 'id'));

Suite::equals('limit=3 returns exactly three messages', count($page1Ids), 3);
Suite::equals('a partial page reports has_more true', $page1->at('data.has_more'), true);
Suite::equals(
    'next_cursor is the oldest id on the page',
    (int) ($page1->at('data.next_cursor') ?? 0),
    $page1Ids === [] ? -1 : (int) end($page1Ids)
);

$cursor   = (int) ($page1->at('data.next_cursor') ?? 0);
$page2    = Http::get("/conversations/{$threadId}/messages", ['limit' => 3, 'before_id' => $cursor], 'brand');
$page2Ids = array_map('intval', array_column($page2->at('data.messages') ?? [], 'id'));

Suite::check(
    'before_id walks strictly backwards through history',
    $page2Ids !== [] && max($page2Ids) < $cursor,
    'cursor ' . $cursor . ', page 2 ids ' . implode(',', $page2Ids)
);
Suite::check(
    'consecutive pages do not overlap',
    array_intersect($page1Ids, $page2Ids) === [],
    'overlap: ' . implode(',', array_intersect($page1Ids, $page2Ids))
);

// Walk the whole thread three at a time and prove the pages reconstruct it.
$walked = [];
$next   = null;
$guard  = 0;

do {
    $query = ['limit' => 3];
    if ($next !== null) {
        $query['before_id'] = $next;
    }

    $page   = Http::get("/conversations/{$threadId}/messages", $query, 'brand');
    $walked = array_merge($walked, array_map('intval', array_column($page->at('data.messages') ?? [], 'id')));
    $next   = $page->at('data.next_cursor');
} while ($page->at('data.has_more') === true && $next !== null && ++$guard < 20);

Suite::equals('walking the cursor reconstructs the thread exactly once', $walked, $allIds);
Suite::check('the walk terminated rather than looping', $guard < 20, 'the cursor walk hit its guard');

$beyond = Http::get("/conversations/{$threadId}/messages", ['limit' => 3, 'before_id' => 1], 'brand');
Suite::equals('a cursor past the beginning returns an empty page', $beyond->at('data.messages'), []);
Suite::equals('an empty page reports has_more false', $beyond->at('data.has_more'), false);

$oversize = Http::get("/conversations/{$threadId}/messages", ['limit' => 5000], 'brand');
Suite::check(
    'an absurd page size is clamped rather than honoured',
    count($oversize->at('data.messages') ?? []) <= 100,
    'returned ' . count($oversize->at('data.messages') ?? []) . ' rows'
);

// -----------------------------------------------------------------
Suite::group('Messaging — typing presence');

Suite::ok('POST /conversations/{id}/typing publishes a typing flag', Http::post("/conversations/{$threadId}/typing", ['is_typing' => true], 'brand'), 200);

$typingSeen = Http::get("/conversations/{$threadId}/typing", [], 'creator');
Suite::ok('GET /conversations/{id}/typing responds', $typingSeen, 200);
Suite::check(
    'the other party sees who is typing',
    in_array($brandId, array_map('intval', array_column($typingSeen->at('data.typing') ?? [], 'user_id')), true),
    $typingSeen->describe()
);

$typingSelf = Http::get("/conversations/{$threadId}/typing", [], 'brand');
Suite::equals('the typing roster excludes the caller themselves', $typingSelf->at('data.typing'), []);

Suite::ok('typing can be switched off', Http::post("/conversations/{$threadId}/typing", ['is_typing' => false], 'brand'), 200);
Suite::equals(
    'clearing the flag empties the roster',
    Http::get("/conversations/{$threadId}/typing", [], 'creator')->at('data.typing'),
    []
);

// -----------------------------------------------------------------
Suite::group('Messaging — mute');

$muted = Http::post("/conversations/{$threadId}/mute", ['is_muted' => true], 'creator');
Suite::ok('POST /conversations/{id}/mute mutes the thread', $muted, 200);
Suite::equals('the mute response reports the new state', $muted->at('data.is_muted'), true);

Suite::check(
    'the inbox reports the thread as muted',
    static function () use ($threadId): bool {
        foreach (Http::get('/conversations', [], 'creator')->at('data.conversations') ?? [] as $row) {
            if ((int) $row['id'] === $threadId) {
                return $row['is_muted'] === true;
            }
        }

        return false;
    },
    'the muted thread did not come back flagged'
);

// Mute is a notification preference. It must not change what the thread
// actually contains, nor what the sender is told about delivery.
$whileMuted   = Http::post("/conversations/{$threadId}/messages", ['body' => 'Sent while muted'], 'brand');
$whileMutedId = (int) ($whileMuted->at('data.message.id') ?? 0);
Suite::ok('a message to a muted participant is still delivered', $whileMuted, 201);

Suite::equals(
    'muting does not suppress the recipient\'s unread badge',
    (int) (Http::get('/conversations', [], 'creator')->at('meta.total_unread') ?? -1),
    1
);

Http::post("/conversations/{$threadId}/read", [], 'creator');

// `MessageService::send()` builds its receipt list from the same `is_muted = 0`
// query it uses for push notifications, so a muted participant never gets a
// `message_receipts` row and the sender's tick never turns.
Suite::check(
    'a muted recipient still produces a read receipt for the sender',
    static function () use ($threadId, $whileMutedId): bool {
        foreach (Http::get("/conversations/{$threadId}/messages", ['limit' => 5], 'brand')->at('data.messages') ?? [] as $row) {
            if ((int) $row['id'] === $whileMutedId) {
                return (int) $row['read_count'] >= 1;
            }
        }

        return false;
    },
    'message ' . $whileMutedId . ' has no receipt at all, so it reads as unread forever'
);

$unmuted = Http::post("/conversations/{$threadId}/mute", ['is_muted' => false], 'creator');
Suite::ok('a muted thread can be unmuted', $unmuted, 200);
Suite::equals('the unmute response reports the new state', $unmuted->at('data.is_muted'), false);

Suite::check(
    'the inbox reports the thread as unmuted again',
    static function () use ($threadId): bool {
        foreach (Http::get('/conversations', [], 'creator')->at('data.conversations') ?? [] as $row) {
            if ((int) $row['id'] === $threadId) {
                return $row['is_muted'] === false;
            }
        }

        return false;
    },
    'the thread stayed muted'
);

// -----------------------------------------------------------------
if ($outsider === null || $outsiderId <= 0) {
    Suite::skipGroup(
        'Messaging — the participant boundary',
        'no third actor could be registered (register is throttled to 5 per 15 minutes per IP): ' . Actors::lastError()
    );
} else {
    Suite::group('Messaging — the participant boundary');

    Suite::status(
        'a non-participant cannot read the thread',
        Http::get("/conversations/{$threadId}/messages", [], 'outsider'),
        403
    );
    Suite::status(
        'a non-participant cannot post into the thread',
        Http::post("/conversations/{$threadId}/messages", ['body' => 'let me in'], 'outsider'),
        403
    );
    Suite::status(
        'a non-participant cannot mark the thread read',
        Http::post("/conversations/{$threadId}/read", [], 'outsider'),
        403
    );
    Suite::status(
        'a non-participant cannot mute the thread',
        Http::post("/conversations/{$threadId}/mute", ['is_muted' => true], 'outsider'),
        403
    );
    Suite::status(
        'a non-participant cannot publish a typing flag',
        Http::post("/conversations/{$threadId}/typing", ['is_typing' => true], 'outsider'),
        403
    );
    Suite::status(
        'a non-participant cannot read the typing roster',
        Http::get("/conversations/{$threadId}/typing", [], 'outsider'),
        403
    );

    Suite::check(
        'a refused read leaks nothing about the thread',
        static function () use ($threadId): bool {
            $body = Http::get("/conversations/{$threadId}/messages", [], 'outsider')->body;

            return !str_contains($body, 'Sent while muted')
                && !str_contains($body, 'Brief attached')
                && !str_contains($body, 'Pagination probe');
        },
        'the 403 body contained message text'
    );

    $outsiderInbox = Http::get('/conversations', [], 'outsider');
    Suite::ok('the third party has an inbox of their own', $outsiderInbox, 200);
    Suite::check(
        'the third party\'s inbox does not contain the thread',
        !in_array($threadId, array_map('intval', array_column($outsiderInbox->at('data.conversations') ?? [], 'id')), true),
        $outsiderInbox->describe()
    );
    Suite::equals(
        'traffic in somebody else\'s thread does not raise the third party\'s unread count',
        (int) ($outsiderInbox->at('meta.total_unread') ?? -1),
        0
    );

    Suite::status(
        'a conversation id that does not exist is refused, not enumerated',
        Http::get('/conversations/99999999/messages', [], 'brand'),
        [403, 404]
    );

    // -------------------------------------------------------------
    Suite::group('Messaging — inbox ordering');

    $second = Http::post('/conversations/start', ['user_id' => $outsiderId], 'brand');
    Suite::ok('a second thread can be opened with a different party', $second, 200);

    $secondId = (int) ($second->at('data.conversation_id') ?? 0);

    if ($secondId > 0) {
        $openedConversations[] = $secondId;

        // `conversations.last_message_at` is a DATETIME with one-second
        // resolution and the inbox sorts on it with no secondary key, so two
        // threads written inside the same second tie and come back in whatever
        // order the storage engine feels like. Space the writes a second apart
        // so this asserts recency ordering rather than luck.
        sleep(1);
        Http::post("/conversations/{$secondId}/messages", ['body' => 'Newest activity'], 'brand');

        $ordered = Http::get('/conversations', [], 'brand');
        $ids     = array_map('intval', array_column($ordered->at('data.conversations') ?? [], 'id'));
        Suite::equals('the most recently active thread sorts first', $ids[0] ?? 0, $secondId);

        sleep(1);
        Http::post("/conversations/{$threadId}/messages", ['body' => 'And now this one is newest'], 'brand');

        $reordered = Http::get('/conversations', [], 'brand');
        $reIds     = array_map('intval', array_column($reordered->at('data.conversations') ?? [], 'id'));
        Suite::equals('posting into an older thread lifts it back to the top', $reIds[0] ?? 0, $threadId);

        $firstPage = Http::get('/conversations', ['per_page' => 1], 'brand');
        Suite::equals('per_page=1 returns a single conversation', count($firstPage->at('data.conversations') ?? []), 1);

        $secondPage = Http::get('/conversations', ['per_page' => 1, 'page' => 2], 'brand');
        Suite::check(
            'page 2 of the inbox returns a different conversation',
            static function () use ($firstPage, $secondPage): bool {
                $a = array_column($firstPage->at('data.conversations') ?? [], 'id');
                $b = array_column($secondPage->at('data.conversations') ?? [], 'id');

                return $b !== [] && array_intersect($a, $b) === [];
            },
            'page 1 ' . json_encode(array_column($firstPage->at('data.conversations') ?? [], 'id'))
                . ' vs page 2 ' . json_encode(array_column($secondPage->at('data.conversations') ?? [], 'id'))
        );

        Suite::status(
            'the third party still cannot read a thread they were never added to',
            Http::get("/conversations/{$threadId}/messages", [], 'outsider'),
            403
        );
    }
}

// -----------------------------------------------------------------
Suite::group('Messaging — cleanup');

// Sweep by participant as well as by recorded id, so a conversation opened by a
// leak (the orphan start above) never survives the run.
$participantIds = array_values(array_filter([$brandId, $creatorId, $outsiderId]));

if ($participantIds !== []) {
    $placeholders = implode(',', array_map('intval', $participantIds));

    $openedConversations = array_merge($openedConversations, array_map('intval', Database::column(
        'SELECT DISTINCT `conversation_id` FROM `conversation_participants` WHERE `user_id` IN (' . $placeholders . ')'
    )));
}

$openedConversations = array_values(array_unique(array_filter($openedConversations)));

foreach ($openedConversations as $conversationId) {
    // message_receipts, messages and conversation_participants all cascade.
    Database::execute('DELETE FROM `conversations` WHERE `id` = :id', ['id' => $conversationId]);
}

Suite::check(
    'every conversation this suite opened has been removed',
    static function () use ($openedConversations): bool {
        if ($openedConversations === []) {
            return true;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `conversations` WHERE `id` IN (' . implode(',', $openedConversations) . ')'
        ) === 0;
    },
    'conversations ' . implode(',', $openedConversations) . ' are still present'
);

Suite::check(
    'no messages from this suite are left behind',
    static function () use ($openedConversations): bool {
        if ($openedConversations === []) {
            return true;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `messages` WHERE `conversation_id` IN (' . implode(',', $openedConversations) . ')'
        ) === 0;
    },
    'orphaned message rows remain'
);
