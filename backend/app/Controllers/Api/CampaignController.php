<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Core\ValidationException;
use Zuste\Services\BrandCampaignService;

/**
 * Brand influencer campaigns.
 *
 * A brand publishes a brief; creators browse open briefs and apply with a rate
 * and a pitch; the brand shortlists, hires or declines.
 *
 * Ownership is enforced in {@see BrandCampaignService}, which answers 404 —
 * not 403 — for a campaign the caller does not own, so the endpoint cannot be
 * used to enumerate campaign ids.
 */
final class CampaignController extends BaseApiController
{
    /**
     * GET /campaigns — the caller's own campaigns.
     * GET /campaigns?scope=open — the public brief board creators browse.
     */
    public function index(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);

        // A brand defaults to its own campaigns; a creator has none of its own,
        // so it always sees the public board.
        $accountType = (string) ($this->user($request)['account_type'] ?? 'brand');
        $scope       = $request->string('scope', $accountType === 'creator' ? 'open' : 'mine');

        $result = BrandCampaignService::list(
            [
                'q'           => $request->string('q'),
                'status'      => $request->string('status'),
                'category_id' => $request->int('category_id'),
                'platform'    => $request->string('platform'),
                'sort'        => $request->string('sort', 'newest'),
                'page'        => $pagination['page'],
                'per_page'    => $pagination['per_page'],
            ],
            $this->userId($request),
            $scope === 'mine'
        );

        Response::paginated(
            $result['items'],
            $result['total'],
            $pagination['page'],
            $pagination['per_page']
        );
    }

    /** GET /campaigns/{id} */
    public function show(Request $request): never
    {
        Response::success([
            'campaign' => BrandCampaignService::show(
                $request->routeParamInt('id'),
                $this->userId($request)
            ),
        ]);
    }

    /** POST /campaigns */
    public function store(Request $request): never
    {
        $data = $this->validateCampaign($request, true);

        Response::created(
            ['campaign' => BrandCampaignService::create($this->userId($request), $data)],
            'Campaign created as a draft. Publish it when the brief is ready.'
        );
    }

    /** PUT /campaigns/{id} */
    public function update(Request $request): never
    {
        $data = $this->validateCampaign($request, false);

        // Only persist keys the client actually sent, so a partial save cannot
        // blank a field the form never showed.
        $submitted = array_intersect_key($data, $request->all());

        Response::success(
            [
                'campaign' => BrandCampaignService::update(
                    $request->routeParamInt('id'),
                    $this->userId($request),
                    $submitted
                ),
            ],
            'Saved.'
        );
    }

    /** DELETE /campaigns/{id} */
    public function destroy(Request $request): never
    {
        BrandCampaignService::delete($request->routeParamInt('id'), $this->userId($request));

        Response::success(null, 'Campaign deleted.');
    }

    /** POST /campaigns/{id}/publish */
    public function publish(Request $request): never
    {
        Response::success(
            [
                'campaign' => BrandCampaignService::transition(
                    $request->routeParamInt('id'),
                    $this->userId($request),
                    'open'
                ),
            ],
            'Campaign published. Creators can now apply.'
        );
    }

    /** POST /campaigns/{id}/close */
    public function close(Request $request): never
    {
        $data = $this->validate($request, [
            'status' => 'nullable|in:completed,cancelled',
        ]);

        Response::success(
            [
                'campaign' => BrandCampaignService::transition(
                    $request->routeParamInt('id'),
                    $this->userId($request),
                    (string) ($data['status'] ?? 'completed')
                ),
            ],
            'Campaign closed.'
        );
    }

    /** GET /campaigns/{id}/recipients — the applications on a campaign. */
    public function recipients(Request $request): never
    {
        $campaignId = $request->routeParamInt('id');

        // Reading the campaign first enforces ownership; a non-owner never
        // reaches the applications at all.
        BrandCampaignService::show($campaignId, $this->userId($request));

        Response::success(['recipients' => BrandCampaignService::applications($campaignId)]);
    }

    /** POST /campaigns/{id}/invite — a brand invites a creator to apply. */
    public function invite(Request $request): never
    {
        $data = $this->validate($request, [
            'creator_id' => 'required|integer|min_value:1',
        ]);

        Response::success(
            [
                'campaign' => BrandCampaignService::invite(
                    $request->routeParamInt('id'),
                    $this->userId($request),
                    (int) $data['creator_id']
                ),
            ],
            'Invitation sent.'
        );
    }

    /** POST /campaigns/{id}/apply — a creator applies to an open campaign. */
    public function apply(Request $request): never
    {
        $data = $this->validate($request, [
            'quoted_amount'   => 'nullable|numeric|min_value:0',
            'pitch'           => 'nullable|string|max:2000',
            // Optional. Only the container is checked here; the Validator has
            // no per-element rules, so each URL is checked by the service and
            // reported under `portfolio_links.N`.
            'portfolio_links' => 'nullable|array',
        ]);

        $links = BrandCampaignService::normalisePortfolioLinks($data['portfolio_links'] ?? null);

        Response::created(
            [
                'application' => BrandCampaignService::apply(
                    $request->routeParamInt('id'),
                    $this->userId($request),
                    $this->creatorId($request),
                    isset($data['quoted_amount']) ? (float) $data['quoted_amount'] : null,
                    $data['pitch'] ?? null,
                    $links
                ),
            ],
            'Application sent.'
        );
    }

    /**
     * GET /me/applications — the creator's own applications and invitations.
     *
     * `status` takes one status or a comma-separated list
     * (`?status=invited,applied,shortlisted`), so a tab that groups several
     * statuses is one request. `meta.counts` carries the per-status totals of
     * the unfiltered set for tab badges.
     */
    public function myApplications(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);

        $statuses = array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', strtolower($request->string('status')))
        ), static fn (string $s): bool => $s !== '')));

        $unknown = array_diff($statuses, BrandCampaignService::APPLICATION_STATUSES);

        // An unknown status is a client bug; answering with an empty list
        // would make it look like the creator simply has no applications.
        if ($unknown !== []) {
            throw ValidationException::forField(
                'status',
                'Status must be one of: ' . implode(', ', BrandCampaignService::APPLICATION_STATUSES) . '.'
            );
        }

        $result = BrandCampaignService::forCreator(
            $this->userId($request),
            $statuses,
            $pagination['page'],
            $pagination['per_page']
        );

        Response::paginated(
            $result['items'],
            $result['total'],
            $pagination['page'],
            $pagination['per_page'],
            'OK',
            ['counts' => $result['counts']]
        );
    }

    /**
     * PUT /campaigns/{id}/recipients/{recipientId}
     *
     * The brand shortlists, hires or declines one application.
     */
    public function decide(Request $request): never
    {
        $data = $this->validate($request, [
            'status' => 'required|in:shortlisted,hired,declined',
            'reason' => 'nullable|string|max:500',
        ]);

        Response::success(
            [
                'campaign' => BrandCampaignService::decide(
                    $request->routeParamInt('id'),
                    $request->routeParamInt('recipientId'),
                    $this->userId($request),
                    (string) $data['status'],
                    $data['reason'] ?? null
                ),
            ],
            'Application updated.'
        );
    }

    // -----------------------------------------------------------------

    /**
     * Shared validation for create and update.
     *
     * `status`, `code`, `applications_count` and `hired_count` are absent by
     * design — they are server-owned, and BrandCampaignService's column
     * whitelist drops them even if a client sends them.
     *
     * @return array<string,mixed>
     */
    private function validateCampaign(Request $request, bool $isCreate): array
    {
        $required = $isCreate ? 'required' : 'nullable';

        return $this->validate($request, [
            'title'            => $required . '|string|min:3|max:191',
            'description'      => $required . '|string|min:10|max:5000',
            'category_id'      => 'nullable|integer|min_value:1',
            'platform'         => 'nullable|in:instagram,youtube,tiktok,facebook,twitter,linkedin,multi',
            'deliverable_type' => 'nullable|in:post,reel,story,video,short,live,ugc,blog,review,bundle',
            'posts_count'      => 'nullable|integer|min_value:1|max_value:100',
            'budget_min'       => 'nullable|numeric|min_value:0',
            'budget_max'       => 'nullable|numeric|min_value:0',
            'currency'         => 'nullable|string|max:3',
            'brief_url'        => 'nullable|url|max:500',
            'reference_urls'   => 'nullable|array',
            'requirements'     => 'nullable|array',
            'starts_on'        => 'nullable|date',
            'ends_on'          => 'nullable|date',
            'is_public'        => 'nullable|boolean',
        ]);
    }
}
