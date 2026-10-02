<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\CreatorEngagementService;
use Zuste\Services\TaxonomyService;

/**
 * Public sharing, engagement recording, and the creator's own visibility
 * controls.
 *
 * Split from CreatorController on purpose. That controller owns discovery and
 * profile editing; this owns what happens AROUND a profile — who shared it,
 * what got clicked, and whether it is shown at all. They are read and written
 * by different screens at very different rates: the record endpoint fires on a
 * tap from anonymous traffic, while everything else is a signed-in creator
 * looking at their own dashboard.
 */
final class CreatorEngagementController extends BaseApiController
{
    // -----------------------------------------------------------------
    // Public
    // -----------------------------------------------------------------

    /**
     * POST /creators/{id}/engagement
     *
     * Records a share, a QR download, a portfolio download or a social link
     * click. Open to anonymous callers because most of this traffic is: a
     * profile opened from a shared link by someone with no account is the
     * single most common source of a share event.
     *
     * There is no canView() call here any more, and its absence is deliberate.
     * The visibility gate now lives inside record(), which has to read the
     * creator row regardless to refuse a soft-deleted profile and to spot the
     * owner acting on themselves. Checking here as well would be a second read
     * of the same row for the same decision — and, worse, would leave the
     * service safe only for callers who remember to ask first. A 404 still
     * comes back for a profile this caller may not see; it is simply thrown
     * one layer down.
     */
    public function record(Request $request): never
    {
        $creatorId = $request->routeParamInt('id');

        $data = $this->validate($request, [
            'event_type' => 'required|string|max:60',
            'channel'    => 'nullable|string|max:60',
            'target_id'  => 'nullable|integer|min_value:1',
        ]);

        $eventType = (string) $data['event_type'];

        $id = CreatorEngagementService::record($creatorId, $eventType, [
            'channel'   => $data['channel'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'actor_id'  => $request->userId() ?: null,
        ]);

        // A creator acting on their own profile gets a success, not an error:
        // the share sheet on their own preview screen must work. It simply
        // does not move the numbers, and the response says so rather than
        // returning a fabricated id the client could never look up. This is
        // the same rule CreatorService::recordView() applies to a creator
        // viewing themselves.
        if ($id === 0) {
            Response::success([
                'id'         => null,
                'event_type' => $eventType,
                'recorded'   => false,
            ], 'Not counted — this is your own profile.');
        }

        Response::created([
            'id'         => $id,
            'event_type' => $eventType,
            'recorded'   => true,
        ], 'Recorded.');
    }

    /**
     * GET /creators/{id}/share?qr=svg,matrix
     *
     * The share URL, the pre-filled share text, the QR code and the list of
     * destinations. Everything is derived from `handle`, so there is nothing
     * to create first and nothing that can go stale.
     *
     * `qr` names which representations of the code to serialise. It defaults
     * to the one a browser can render, because this is an anonymous GET that
     * nothing caches and returning all three of them shipped ~52 KB per call
     * to describe a single QR. `qr=none` skips the encoding altogether.
     */
    public function share(Request $request): never
    {
        $payload = CreatorEngagementService::sharePayload(
            $request->routeParamInt('id'),
            $request->userId() ?: null,
            $request->int('module_size', 6),
            CreatorEngagementService::qrRepresentations($request->string('qr'))
        );

        Response::success(['share' => $payload]);
    }

    // -----------------------------------------------------------------
    // The creator's own profile
    // -----------------------------------------------------------------

    /**
     * GET /me/creator/share
     *
     * The same payload for the signed-in creator, which works while the
     * profile is switched off — a creator has to be able to fetch their QR
     * code before they go live, not only after.
     */
    public function myShare(Request $request): never
    {
        $payload = CreatorEngagementService::sharePayload(
            $this->creatorId($request),
            $this->userId($request),
            $request->int('module_size', 6),
            CreatorEngagementService::qrRepresentations($request->string('qr'))
        );

        Response::success(['share' => $payload]);
    }

    /** GET /me/creator/visibility */
    public function visibility(Request $request): never
    {
        Response::success([
            'visibility' => CreatorEngagementService::visibility($this->creatorId($request)),
        ]);
    }

    /**
     * PUT /me/creator/visibility
     *
     * The creator's own switch. It never touches `status`, so switching a
     * profile off and on again does not send it back through review.
     */
    public function updateVisibility(Request $request): never
    {
        $data = $this->validate($request, [
            'is_public' => 'required|boolean',
        ]);

        $user = $this->user($request);

        $state = CreatorEngagementService::setVisibility(
            $this->creatorId($request),
            (bool) $data['is_public'],
            [
                'actor_type' => 'creator',
                'actor_id'   => (int) $user['id'],
                'actor_name' => (string) ($user['name'] ?? ''),
            ]
        );

        Response::success(
            ['visibility' => $state],
            $state['is_live']
                ? 'Your profile is live.'
                : 'Your profile is hidden from the public directory.'
        );
    }

    /**
     * GET /me/creator/preview
     *
     * The owner's view of their own public profile, gate skipped. This is the
     * real public payload rather than a preview-shaped copy of it, so what a
     * creator checks here is what a visitor gets.
     */
    public function preview(Request $request): never
    {
        Response::success(
            CreatorEngagementService::preview($this->creatorId($request), $this->userId($request))
        );
    }

    /**
     * GET /me/creator/engagement?from=2026-07-01&to=2026-07-31
     *
     * Totals, the daily series, the channel breakdowns and the per-item
     * reports in one response, because the dashboard draws them on one screen
     * and four round trips would let the four panels disagree about the
     * window.
     */
    public function dashboard(Request $request): never
    {
        Response::success([
            'engagement' => CreatorEngagementService::dashboard(
                $this->creatorId($request),
                $request->string('from') ?: null,
                $request->string('to') ?: null
            ),
        ]);
    }

    /**
     * GET /me/creator/engagement/targets?event_type=portfolio_download
     *
     * The deep version of one per-item report — the dashboard carries the top
     * ten, this pages a creator through more of them.
     */
    public function targets(Request $request): never
    {
        $eventType = $request->string('event_type');

        if ($eventType === '') {
            throw HttpException::unprocessable('Name the engagement event to break down.');
        }

        Response::success([
            'event_type' => $eventType,
            'targets'    => CreatorEngagementService::topTargets(
                $this->creatorId($request),
                $eventType,
                $request->perPage(10, 50),
                $request->string('from') ?: null,
                $request->string('to') ?: null
            ),
        ]);
    }

    /**
     * GET /engagement/options
     *
     * The event catalogue and the channel vocabularies, so a client knows what
     * it may send without a hardcoded list. Served publicly for the same
     * reason /taxonomies is: an app that cannot read it cannot record
     * anything.
     */
    public function options(Request $request): never
    {
        $events = [];

        foreach (CreatorEngagementService::catalogue() as $value => $definition) {
            // `target_table` is the name of an internal table — this used to
            // hand every anonymous caller "creator_portfolio" and
            // "creator_social_accounts" for free. A client needs to know THAT
            // an event must name a target, never where that target is stored,
            // so the answer is a boolean and the table name stays inside.
            $definition['requires_target'] = $definition['target_table'] !== null;

            unset($definition['target_table']);

            $events[] = $definition + [
                'channels' => CreatorEngagementService::channelsFor($value),
            ];
        }

        Response::success([
            'events'       => $events,
            'view_sources' => TaxonomyService::terms(CreatorEngagementService::VIEW_SOURCE_TAXONOMY),
        ]);
    }
}
