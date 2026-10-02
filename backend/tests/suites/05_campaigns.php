<?php

declare(strict_types=1);

/**
 * Brand campaigns — CRUD, the publish/close state machine, applications and
 * the ownership boundary.
 *
 * The API this exercises (CampaignController + BrandCampaignService +
 * the `campaign_applications` table) was missing entirely until this pass: the
 * Flutter app declared seven campaign endpoints, the `campaigns` table held
 * rows, and nothing connected them. Every check here is new coverage.
 */

use Zuste\Core\Database;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// A run makes hundreds of calls in seconds; without this the throttle
// fires mid-suite and the cascade of 429s hides real failures.
Suite::clearRateLimits();

$brand   = Actors::create('brand', 'brand');
$creator = Actors::create('creator', 'creator');

if ($brand === null) {
    Suite::skipGroup('Campaigns', 'no brand session: ' . Actors::lastError());
    return;
}

/** Ids created here, removed at the end of the run. */
$created = [];

// -----------------------------------------------------------------
Suite::group('Campaigns — brand CRUD');

$create = Http::post('/campaigns', [
    'title'            => 'IT Diwali Skincare Push',
    'description'      => 'Beauty creators in Mumbai for a Diwali gifting campaign. Three reels plus supporting stories.',
    'platform'         => 'instagram',
    'deliverable_type' => 'reel',
    'posts_count'      => 3,
    'budget_min'       => 25000,
    'budget_max'       => 80000,
    'is_public'        => true,
], 'brand');

Suite::ok('POST /campaigns creates a campaign', $create, [200, 201]);

$campaignId = (int) ($create->at('data.campaign.id') ?? 0);

if ($campaignId > 0) {
    $created[] = $campaignId;
}

Suite::notEmpty('the new campaign has an id', $campaignId);
Suite::equals(
    'a new campaign starts as a draft, not published',
    $create->at('data.campaign.status'),
    'draft'
);
Suite::check(
    'the campaign is given a human reference code',
    is_string($create->at('data.campaign.code')) && $create->at('data.campaign.code') !== 'PENDING',
    'code was: ' . json_encode($create->at('data.campaign.code'))
);

$list = Http::get('/campaigns', [], 'brand');
Suite::ok('GET /campaigns lists the caller\'s campaigns', $list);
Suite::check(
    'the list carries pagination meta',
    is_array($list->at('meta.pagination')),
    $list->describe()
);
Suite::check(
    'the new campaign appears in the owner\'s list',
    in_array($campaignId, array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        $list->list()
    ), true),
    'the owner could not see their own campaign'
);

$filtered = Http::get('/campaigns', ['status' => 'draft'], 'brand');
Suite::ok('a status filter is accepted', $filtered);
Suite::check(
    'the status filter genuinely narrows the result',
    static function () use ($filtered): bool {
        foreach ($filtered->list() as $row) {
            if (($row['status'] ?? '') !== 'draft') {
                return false;
            }
        }
        return true;
    },
    'a non-draft campaign came back from ?status=draft'
);

$show = Http::get("/campaigns/{$campaignId}", [], 'brand');
Suite::ok('GET /campaigns/{id} returns the campaign', $show);
Suite::equals(
    'the detail matches what was created',
    $show->at('data.campaign.title'),
    'IT Diwali Skincare Push'
);

$update = Http::put("/campaigns/{$campaignId}", [
    'title'      => 'IT Diwali Skincare Push (revised)',
    'budget_max' => 95000,
], 'brand');
Suite::ok('PUT /campaigns/{id} edits the campaign', $update);

$reread = Http::get("/campaigns/{$campaignId}", [], 'brand');
Suite::equals(
    'the edit actually persisted',
    $reread->at('data.campaign.title'),
    'IT Diwali Skincare Push (revised)'
);
Suite::equals(
    'the budget change persisted too',
    (float) ($reread->at('data.campaign.budget_max') ?? 0),
    95000.0
);
Suite::equals(
    'a partial update did not blank the untouched description',
    $reread->at('data.campaign.description'),
    $create->at('data.campaign.description')
);

// -----------------------------------------------------------------
Suite::group('Campaigns — validation');

$noTitle = Http::post('/campaigns', [
    'description' => 'A brief with no title at all, long enough to pass the length rule.',
], 'brand');
Suite::status('a campaign with no title is refused', $noTitle, 422);

$shortBrief = Http::post('/campaigns', ['title' => 'IT Short', 'description' => 'too short'], 'brand');
Suite::status('a too-short description is refused', $shortBrief, 422);

$badPlatform = Http::post('/campaigns', [
    'title'       => 'IT Bad Platform',
    'description' => 'A brief long enough to pass the length rule for description.',
    'platform'    => 'myspace',
], 'brand');
Suite::status('a platform outside the ENUM is refused before it reaches MySQL', $badPlatform, 422);

$badBudget = Http::post('/campaigns', [
    'title'       => 'IT Inverted Budget',
    'description' => 'A brief long enough to pass the length rule for description.',
    'budget_min'  => 90000,
    'budget_max'  => 10000,
], 'brand');
Suite::status('budget_min above budget_max is refused', $badBudget, 422);

Suite::check(
    'a validation failure names the offending field',
    static function () use ($noTitle): bool {
        $errors = $noTitle->at('data.errors') ?? $noTitle->at('errors');
        return is_array($errors) && array_key_exists('title', $errors);
    },
    'expected an errors map keyed by field, got: ' . $noTitle->describe()
);

// A client must not be able to publish by writing the column directly.
Http::put("/campaigns/{$campaignId}", ['status' => 'open'], 'brand');
$afterForce = Http::get("/campaigns/{$campaignId}", [], 'brand');
Suite::equals(
    'status is server-owned — a client cannot publish via PUT',
    $afterForce->at('data.campaign.status'),
    'draft'
);

// -----------------------------------------------------------------
Suite::group('Campaigns — publish and close transitions');

$publish = Http::post("/campaigns/{$campaignId}/publish", null, 'brand');
Suite::ok('POST /publish moves a draft to open', $publish);
Suite::equals('the campaign is now open', $publish->at('data.campaign.status'), 'open');

$republish = Http::post("/campaigns/{$campaignId}/publish", null, 'brand');
Suite::check(
    'publishing an already-open campaign is idempotent, not a 500',
    $republish->status < 500,
    $republish->describe()
);

// A brief with no budget is not ready for creators.
$empty = Http::post('/campaigns', [
    'title'       => 'IT No Budget',
    'description' => 'A brief long enough to pass the length rule for description.',
], 'brand');

$emptyId = (int) ($empty->at('data.campaign.id') ?? 0);

if ($emptyId > 0) {
    $created[] = $emptyId;

    $publishEmpty = Http::post("/campaigns/{$emptyId}/publish", null, 'brand');
    Suite::status('publishing a campaign with no budget is refused', $publishEmpty, 422);
}

$close = Http::post("/campaigns/{$campaignId}/close", ['status' => 'completed'], 'brand');
Suite::ok('POST /close completes the campaign', $close);
Suite::equals('the campaign is now completed', $close->at('data.campaign.status'), 'completed');

$reopen = Http::post("/campaigns/{$campaignId}/publish", null, 'brand');
Suite::status('a completed campaign cannot be republished', $reopen, [409, 422]);

// -----------------------------------------------------------------
Suite::group('Campaigns — invitations and applications');

$inviteTarget = (int) (Database::scalar(
    "SELECT `id` FROM `creators` WHERE `status` = 'approved' AND `deleted_at` IS NULL ORDER BY `id` LIMIT 1"
) ?? 0);

if ($inviteTarget === 0) {
    Suite::skipGroup('Campaigns — invitations and applications', 'no approved creator is seeded');
} else {
    $open = Http::post('/campaigns', [
        'title'       => 'IT Open Brief',
        'description' => 'An open brief used to exercise invitations and applications end to end.',
        'budget_min'  => 10000,
        'budget_max'  => 50000,
        'is_public'   => true,
    ], 'brand');

    $openId = (int) ($open->at('data.campaign.id') ?? 0);

    if ($openId === 0) {
        Suite::check('the invitation fixture campaign was created', false, $open->describe());
    } else {
        $created[] = $openId;
        Http::post("/campaigns/{$openId}/publish", null, 'brand');

        $invite = Http::post("/campaigns/{$openId}/invite", ['creator_id' => $inviteTarget], 'brand');
        Suite::ok('POST /invite invites a creator', $invite);

        $recipients = Http::get("/campaigns/{$openId}/recipients", [], 'brand');
        Suite::ok('GET /recipients lists the applications', $recipients);

        $applicants = $recipients->list('recipients');
        Suite::check('the invited creator appears', count($applicants) >= 1, $recipients->describe());

        $first = $applicants[0] ?? [];
        Suite::equals('an invitation starts as invited', $first['status'] ?? null, 'invited');
        Suite::check(
            'the applicant payload carries a full creator card',
            isset($first['creator']['display_name'], $first['creator']['followers']),
            'creator block was: ' . json_encode($first['creator'] ?? null)
        );

        Http::post("/campaigns/{$openId}/invite", ['creator_id' => $inviteTarget], 'brand');
        Suite::equals(
            'inviting the same creator twice does not duplicate them',
            count(Http::get("/campaigns/{$openId}/recipients", [], 'brand')->list('recipients')),
            1
        );

        $applicationId = (int) ($first['id'] ?? 0);

        if ($applicationId > 0) {
            // Hiring somebody who never quoted a rate is meaningless.
            $hireInvited = Http::put(
                "/campaigns/{$openId}/recipients/{$applicationId}",
                ['status' => 'hired'],
                'brand'
            );
            Suite::status('an invited creator who has not applied cannot be hired', $hireInvited, 422);

            $shortlist = Http::put(
                "/campaigns/{$openId}/recipients/{$applicationId}",
                ['status' => 'shortlisted'],
                'brand'
            );
            Suite::ok('a brand can shortlist an application', $shortlist);

            $bogusStatus = Http::put(
                "/campaigns/{$openId}/recipients/{$applicationId}",
                ['status' => 'invited'],
                'brand'
            );
            Suite::status('a brand cannot set an application back to invited', $bogusStatus, 422);
        }

        // A creator applying on its own account. The route is gated on `kyc`,
        // so a 403 from the gate is a legitimate outcome for a fresh actor —
        // asserted explicitly rather than treated as a pass.
        if ($creator !== null) {
            $apply = Http::post("/campaigns/{$openId}/apply", [
                'quoted_amount' => 30000,
                'pitch'         => 'I have run three skincare launches with strong saves and shares.',
            ], 'creator');

            Suite::check(
                'a creator can apply, or is stopped by a gate that says why',
                in_array($apply->status, [200, 201, 403], true),
                $apply->describe()
            );

            if ($apply->status === 403) {
                $reason = strtolower($apply->message() . ' ' . $apply->code());

                Suite::check(
                    'the 403 on apply is an explained gate, not an accident',
                    str_contains($reason, 'kyc')
                        || str_contains($reason, 'verif')
                        || str_contains($reason, 'profile'),
                    'unexplained 403: ' . $apply->describe()
                );
            }
        }
    }
}

// -----------------------------------------------------------------
Suite::group('Campaigns — authorization boundary');

if ($creator === null) {
    Suite::skipGroup('Campaigns — authorization boundary', 'no creator session available');
} else {
    $target = $created[0] ?? 0;

    if ($target > 0) {
        $put = Http::put("/campaigns/{$target}", ['title' => 'hijacked'], 'creator');
        Suite::status('a creator cannot edit a brand\'s campaign', $put, [403, 404]);

        $del = Http::delete("/campaigns/{$target}", null, 'creator');
        Suite::status('a creator cannot delete a brand\'s campaign', $del, [403, 404]);

        $publishAsCreator = Http::post("/campaigns/{$target}/publish", null, 'creator');
        Suite::status('a creator cannot publish a brand\'s campaign', $publishAsCreator, [403, 404]);

        $recipientsAsCreator = Http::get("/campaigns/{$target}/recipients", [], 'creator');
        Suite::status(
            'a creator cannot read a brand\'s applicant list',
            $recipientsAsCreator,
            [403, 404]
        );

        // The edit must not have landed.
        $intact = Http::get("/campaigns/{$target}", [], 'brand');
        Suite::check(
            'the refused edit changed nothing',
            $intact->at('data.campaign.title') !== 'hijacked',
            'the creator\'s PUT was refused but still mutated the row'
        );
    }

    // Another brand's draft must not be readable at all.
    $foreignDraft = (int) (Database::scalar(
        "SELECT `id` FROM `campaigns`
          WHERE `status` = 'draft' AND `deleted_at` IS NULL AND `brand_user_id` <> :me
          ORDER BY `id` LIMIT 1",
        ['me' => Actors::id('brand')]
    ) ?? 0);

    if ($foreignDraft > 0) {
        $peek = Http::get("/campaigns/{$foreignDraft}", [], 'brand');
        Suite::status('another brand\'s draft is not readable', $peek, 404);
    }
}

$anonymous = Http::get('/campaigns');
Suite::status('an unauthenticated caller is refused', $anonymous, 401);

// -----------------------------------------------------------------
Suite::group('Campaigns — cleanup');

foreach ($created as $id) {
    Database::execute('DELETE FROM `campaign_applications` WHERE `campaign_id` = :id', ['id' => $id]);
    Database::execute('DELETE FROM `campaigns` WHERE `id` = :id', ['id' => $id]);
}

$survivors = (int) (Database::scalar(
    'SELECT COUNT(*) FROM `campaigns` WHERE `brand_user_id` = :id AND `deleted_at` IS NULL',
    ['id' => Actors::id('brand')]
) ?? 0);

Suite::equals('the suite left no campaign rows behind', $survivors, 0);
