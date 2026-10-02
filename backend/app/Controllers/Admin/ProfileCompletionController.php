<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Helpers\Str;
use Zuste\Services\ProfileCompletionService;

/**
 * Profile completion and feature gating for the admin panel.
 *
 * Answers the two questions migration 037 stored the breakdown in order to
 * make cheap: "show me approved creators stuck below sixty percent" and "who
 * has never finished KYC". Both are ORDER BY and WHERE against
 * `creator_profile_completion`, and both are full table scans in PHP without
 * it.
 *
 * Kept apart from CreatorProfileController, which owns badges and section
 * locks — what has been VERIFIED about a profile and what the creator may still
 * change. This owns how FINISHED a profile is and what that unlocks.
 *
 * The list and the per-creator breakdown share one view. The two screens read
 * the same section catalogue, the same bars and the same colours, and the
 * module owns a single template; branching on `mode` inside it keeps the bar
 * rendering in one place rather than duplicated in two files that would drift.
 */
final class ProfileCompletionController extends BaseAdminController
{
    /** Columns the list may be sorted by. Anything else is ignored. */
    private const SORTS = ['profile_score', 'display_name', 'created_at', 'id'];

    /**
     * GET /admin/creators/completion
     *
     * Worst-first by default. An operator opening this screen is looking for
     * the profiles that need chasing, not the ones that are finished.
     */
    public function index(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $params   = $this->listParams($request, self::SORTS, 'profile_score');
        $sortDir  = $request->string('sort_dir') === '' ? 'ASC' : $params['sort_dir'];

        $clauses  = ['c.`deleted_at` IS NULL'];
        $bindings = [];

        if ($params['search'] !== '') {
            $clauses[]          = "(c.`display_name` LIKE :search ESCAPE '\\\\' OR c.`handle` LIKE :search ESCAPE '\\\\'"
                                . " OR u.`email` LIKE :search ESCAPE '\\\\')";
            $bindings['search'] = Str::likeTerm($params['search']);
        }

        if ($request->filled('status')) {
            $clauses[]          = 'c.`status` = :status';
            $bindings['status'] = $request->string('status');
        }

        $maxPercent = $request->filled('below') ? max(0, min(100, $request->int('below'))) : null;
        $section    = $request->string('section');
        $sections   = ProfileCompletionService::sections();

        if ($section !== '' && !isset($sections[$section])) {
            $section = '';
        }

        // A section filter with no percentage means "not finished", which is
        // the question an operator actually asks about a section. Without the
        // default the filter would silently match everybody.
        if ($section !== '') {
            $threshold = $maxPercent ?? 100;

            // LEFT JOIN, not INNER: a creator with no stored row for the
            // section has not completed it either, and an inner join would
            // quietly hide exactly the profiles that need attention most.
            $clauses[]             = '(s.`percent` IS NULL OR s.`percent` < :section_max)';
            $bindings['section_max'] = $threshold;
        } elseif ($maxPercent !== null) {
            $clauses[]              = 'c.`profile_score` < :overall_max';
            $bindings['overall_max'] = $maxPercent;
        }

        // ONE parameter, not two. "Missing something mandatory" and "never
        // scored" are mutually exclusive by construction — the first needs a
        // stored row, the second needs there to be none — so they were never
        // two independent filters, and as two they could not survive a search:
        // the shared toolbar's GET form re-emits only what it renders, and it
        // renders selects. Folded into one select the toolbar carries it, and
        // typing in the search box no longer silently clears the chip.
        $attention = $request->string('attention');

        if ($attention === 'mandatory') {
            $clauses[] = 'EXISTS (SELECT 1 FROM `creator_profile_completion` m
                                   WHERE m.`creator_id` = c.`id` AND m.`mandatory_missing` > 0)';
        } elseif ($attention === 'never_scored') {
            $clauses[] = 'NOT EXISTS (SELECT 1 FROM `creator_profile_completion` n WHERE n.`creator_id` = c.`id`)';
        } else {
            $attention = '';
        }

        $join = 'INNER JOIN `users` u ON u.`id` = c.`user_id`';

        if ($section !== '') {
            $join .= ' LEFT JOIN `creator_profile_completion` s
                              ON s.`creator_id` = c.`id` AND s.`section` = :section';
            $bindings['section'] = $section;
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` c ' . $join . $where,
            $bindings
        );

        $rows = Database::select(
            'SELECT c.`id`, c.`handle`, c.`display_name`, c.`status`, c.`profile_score`, c.`created_at`,
                    u.`avatar_url`, u.`email`
               FROM `creators` c ' . $join . $where . '
              ORDER BY c.`' . $params['sort_by'] . '` ' . $sortDir . ', c.`id` ASC
              LIMIT ' . $params['per_page'] . ' OFFSET ' . $params['offset'],
            $bindings
        );

        // The per-section bars for the whole page in one query rather than one
        // per creator. Twenty-five creators times ten sections is 250 rows —
        // cheaper to fetch in a single IN than to run 25 round trips for.
        $breakdowns = [];

        if ($rows !== []) {
            [$fragment, $values] = Database::inClause(array_map(
                static fn (array $row): int => (int) $row['id'],
                $rows
            ));

            foreach (Database::select(
                'SELECT `creator_id`, `section`, `percent`, `mandatory_missing`, `computed_at`
                   FROM `creator_profile_completion` WHERE `creator_id` IN ' . $fragment,
                $values
            ) as $row) {
                $breakdowns[(int) $row['creator_id']][(string) $row['section']] = $row;
            }
        }

        $this->view($request, 'admin.completion.index', [
            'pageTitle'    => 'Profile completion',
            'pageSubtitle' => number_format($total) . ' profiles',
            'mode'         => 'list',
            'creators'     => $rows,
            'breakdowns'   => $breakdowns,
            'sectionList'  => $sections,
            'summary'      => $this->summary(),
            'pagination'   => $this->paginationMeta($total, $params['page'], $params['per_page']),
            // The SANITISED values win over the raw query string, so a section
            // or an attention value the list ignored cannot come back lit in
            // the toolbar and tell the operator a filter is on when it is not.
            'filters'      => ['section' => $section, 'attention' => $attention] + $request->all(),
            'canEdit'      => $this->can($request, 'creators.edit'),
            'canFeature'   => $this->can($request, 'creators.feature'),
        ]);
    }

    /**
     * GET /admin/creators/{id}/completion
     *
     * Evaluated live rather than read from the cache. An operator on this
     * screen is asking "what is missing RIGHT NOW", usually because they are
     * on the phone to the creator, and a breakdown up to a day old is the one
     * thing that would waste that call. The stored figures are shown beside it
     * so a stale cache is visible rather than merely wrong.
     */
    public function show(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $creatorId = $this->creatorId($request);

        $creator = Database::selectOne(
            'SELECT c.`id`, c.`user_id`, c.`handle`, c.`display_name`, c.`status`, c.`profile_score`,
                    u.`avatar_url`, u.`email`
               FROM `creators` c
               INNER JOIN `users` u ON u.`id` = c.`user_id`
              WHERE c.`id` = :id',
            ['id' => $creatorId]
        );

        $live   = ProfileCompletionService::evaluate($creatorId);
        $stored = ProfileCompletionService::breakdown($creatorId);

        $this->view($request, 'admin.completion.index', [
            'pageTitle'    => 'Completion · ' . (string) ($creator['display_name'] ?? ''),
            'pageSubtitle' => '@' . (string) ($creator['handle'] ?? ''),
            'mode'         => 'detail',
            'creator'      => $creator,
            'live'         => $live,
            'stored'       => $stored,
            'gates'        => ProfileCompletionService::gatesFor($creatorId),
            'overrides'    => ProfileCompletionService::overrides($creatorId),
            'handle'       => ProfileCompletionService::handleChangeStatus($creatorId),
            'handleLog'    => ProfileCompletionService::handleHistory($creatorId, 20),
            'canEdit'      => $this->can($request, 'creators.edit'),
            'canFeature'   => $this->can($request, 'creators.feature'),
        ]);
    }

    /** POST /admin/creators/{id}/completion/recompute */
    public function recompute(Request $request): never
    {
        $this->authorize($request, 'creators.edit');

        $creatorId = $this->creatorId($request);
        $result    = ProfileCompletionService::recompute($creatorId);

        $this->log(
            $request,
            'creator.completion.recomputed',
            'creator',
            $creatorId,
            sprintf('Recomputed profile completion: %d%%.', (int) $result['overall']),
            ['overall' => $result['overall'], 'previous' => $result['stored_score']]
        );

        $this->respond(
            $request,
            sprintf('Profile completion recomputed: %d%%.', (int) $result['overall']),
            '/admin/creators/' . $creatorId . '/completion',
            ['completion' => $result]
        );
    }

    /**
     * POST /admin/creators/completion/recompute
     *
     * The nightly job on demand. An operator who has just re-weighted the
     * sections or edited a rule should not have to wait until 00:55 to see
     * what it did.
     */
    public function recomputeAll(Request $request): never
    {
        $this->authorize($request, 'creators.edit');

        $counts = ProfileCompletionService::recomputeAll();

        $this->log(
            $request,
            'creator.completion.swept',
            'creator',
            null,
            sprintf('Recomputed completion for %d creator(s); %d score(s) moved.', $counts['creators'], $counts['changed']),
            $counts
        );

        // A sweep that skipped profiles must say so on the screen. The service
        // no longer stops at the first broken one — silently reporting only
        // the successes would hide a mistyped rule that is holding every
        // creator below every gate.
        $message = sprintf(
            '%d profile(s) rescored. %d moved (%d up, %d down).',
            $counts['creators'],
            $counts['changed'],
            $counts['improved'],
            $counts['declined']
        );

        if ((int) $counts['failed'] > 0) {
            $message .= sprintf(
                ' %d could not be scored — first failure: %s',
                (int) $counts['failed'],
                (string) $counts['first_failure']
            );
        }

        $this->respond(
            $request,
            $message,
            '/admin/creators/completion',
            ['counts' => $counts]
        );
    }

    /**
     * POST /admin/creators/{id}/features
     *
     * Grant or withhold one feature for one creator. Under creators.feature
     * rather than creators.edit: this is an eligibility decision of the same
     * kind as featuring a creator, not an edit to their profile.
     */
    public function setOverride(Request $request): never
    {
        $this->authorize($request, 'creators.feature');

        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'feature_key' => 'required|string|max:80',
            'mode'        => 'required|in:grant,deny',
            'reason'      => 'required|string|min:3|max:500',
            'expires_at'  => 'nullable|date',
        ]);

        ProfileCompletionService::setOverride(
            $creatorId,
            (string) $data['feature_key'],
            (string) $data['mode'],
            [
                'reason'     => (string) $data['reason'],
                'actor_id'   => $this->adminId($request),
                'expires_at' => $data['expires_at'] ?? null,
            ]
        );

        $this->log(
            $request,
            'creator.feature.override',
            'creator',
            $creatorId,
            sprintf('%s the %s feature.', $data['mode'] === 'grant' ? 'Granted' : 'Withheld', $data['feature_key']),
            ['feature_key' => $data['feature_key'], 'mode' => $data['mode'], 'reason' => $data['reason']]
        );

        $this->respond(
            $request,
            $data['mode'] === 'grant' ? 'Feature granted.' : 'Feature withheld.',
            '/admin/creators/' . $creatorId . '/completion',
            ['gates' => ProfileCompletionService::gatesFor($creatorId)]
        );
    }

    /** DELETE /admin/creators/{id}/features */
    public function clearOverride(Request $request): never
    {
        $this->authorize($request, 'creators.feature');

        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, ['feature_key' => 'required|string|max:80']);

        ProfileCompletionService::clearOverride($creatorId, (string) $data['feature_key']);

        $this->log(
            $request,
            'creator.feature.override_cleared',
            'creator',
            $creatorId,
            sprintf('Removed the %s override.', $data['feature_key']),
            ['feature_key' => $data['feature_key']]
        );

        $this->respond(
            $request,
            'Override removed. The gate now applies as configured.',
            '/admin/creators/' . $creatorId . '/completion',
            ['gates' => ProfileCompletionService::gatesFor($creatorId)]
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Headline figures for the list.
     *
     * Every one of these reads the stored breakdown or the indexed
     * `profile_score` column, which is the whole reason the breakdown is
     * stored rather than computed — none of them would survive being derived
     * in PHP over a growing table.
     *
     * @return array<string,mixed>
     */
    private function summary(): array
    {
        $required = Config::settingInt('creator', 'required_completion_percent', 60);

        $row = Database::selectOne(
            'SELECT COUNT(*) AS `total`,
                    AVG(`profile_score`) AS `average`,
                    SUM(`profile_score` >= :required) AS `at_required`,
                    SUM(`status` = "approved" AND `profile_score` < :required) AS `approved_short`
               FROM `creators` WHERE `deleted_at` IS NULL',
            ['required' => $required]
        );

        return [
            'required'       => $required,
            'total'          => (int) ($row['total'] ?? 0),
            'average'        => (int) round((float) ($row['average'] ?? 0)),
            'at_required'    => (int) ($row['at_required'] ?? 0),
            'approved_short' => (int) ($row['approved_short'] ?? 0),
            'mandatory'      => (int) Database::scalar(
                'SELECT COUNT(DISTINCT `creator_id`) FROM `creator_profile_completion` WHERE `mandatory_missing` > 0'
            ),
            'never_scored'   => (int) Database::scalar(
                'SELECT COUNT(*) FROM `creators` c
                  WHERE c.`deleted_at` IS NULL
                    AND NOT EXISTS (SELECT 1 FROM `creator_profile_completion` p WHERE p.`creator_id` = c.`id`)'
            ),
        ];
    }

    /**
     * The creator this request addresses.
     *
     * Resolved here rather than in each handler so a request for a deleted or
     * non-existent creator fails the same way everywhere.
     */
    private function creatorId(Request $request): int
    {
        $id = $request->routeParamInt('id');

        $exists = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $id]
        );

        if ($exists === 0) {
            throw HttpException::notFound('Creator not found.');
        }

        return $id;
    }
}
