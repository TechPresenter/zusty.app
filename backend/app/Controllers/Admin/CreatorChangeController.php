<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Services\CreatorChangeService;

/**
 * The profile change queue.
 *
 * Two screens over the tables migration 038 built: a list of what is waiting,
 * and a detail view that puts the old value beside the new one with a decision
 * on each.
 *
 * Kept apart from CreatorProfileController, which owns what is TRUE about a
 * profile — badges and locks — and from UserController, which owns the account
 * itself. This owns one question only: may this proposed value become true?
 *
 * Permissions are the ones that already exist. Reading the queue is
 * `creators.view`; every decision is `creators.approve`, because approving a
 * new display name and approving a profile are the same authority; withdrawing
 * is `creators.edit`, because it changes nothing about the creator and only
 * takes a moot question out of the queue.
 */
final class CreatorChangeController extends BaseAdminController
{
    /** Where the screens live. Repeated in redirects, so it is written once. */
    private const BASE = '/admin/creator-changes';

    // -----------------------------------------------------------------
    // Screens
    // -----------------------------------------------------------------

    /**
     * GET /admin/creator-changes
     *
     * One screen, two tabs: proposed field changes, and gallery or portfolio
     * items awaiting a verdict. They are the same job — an operator deciding
     * what a creator may publish — and splitting them across two menu entries
     * would hide whichever one had nothing in it that morning.
     */
    public function index(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $tab = $request->string('tab') === 'items' ? 'items' : 'changes';

        $params = $this->listParams($request, ['created_at'], 'created_at');

        if ($tab === 'items') {
            $status = $request->string('status', 'pending');
            $items  = CreatorChangeService::itemQueue($status, $params['per_page'], $params['offset']);

            $this->view($request, 'admin.changes.index', [
                'pageTitle'    => 'Profile changes',
                'pageSubtitle' => 'Gallery and portfolio items awaiting a verdict',
                'tab'          => $tab,
                'status'       => $status,
                'requests'     => [],
                'items'        => $items['rows'],
                'pagination'   => $this->paginationMeta($items['total'], $params['page'], $params['per_page']),
                'counts'       => CreatorChangeService::counts(),
                'itemCounts'   => CreatorChangeService::itemCounts(),
                'reasons'      => CreatorChangeService::reasons('gallery'),
                'enabled'      => CreatorChangeService::enabled(),
                'slaHours'     => CreatorChangeService::slaHours(),
                'filters'      => $request->all(),
            ]);
        }

        $status = $request->string('status', 'pending');

        $queue = CreatorChangeService::queue([
            'status'   => $status,
            'search'   => $params['search'],
            'per_page' => $params['per_page'],
            'offset'   => $params['offset'],
        ]);

        $this->view($request, 'admin.changes.index', [
            'pageTitle'    => 'Profile changes',
            'pageSubtitle' => number_format($queue['total']) . ' submission(s) in this queue',
            'tab'          => $tab,
            'status'       => $status,
            'requests'     => $queue['rows'],
            'items'        => [],
            'pagination'   => $this->paginationMeta($queue['total'], $params['page'], $params['per_page']),
            'counts'       => CreatorChangeService::counts(),
            'itemCounts'   => CreatorChangeService::itemCounts(),
            'reasons'      => CreatorChangeService::reasons(CreatorChangeService::REASON_CONTEXT_CHANGE),
            'enabled'      => CreatorChangeService::enabled(),
            'slaHours'     => CreatorChangeService::slaHours(),
            'filters'      => $request->all(),
        ]);
    }

    /**
     * GET /admin/creator-changes/{id}
     *
     * Old beside new, per field, plus the version comparison against the
     * snapshot taken when the request was raised.
     */
    public function show(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $requestId = $request->routeParamInt('id');
        $changeSet = CreatorChangeService::find($requestId);

        if ($changeSet === null) {
            throw HttpException::notFound('Change request not found.');
        }

        $creatorId = (int) $changeSet['creator_id'];

        // The left-hand side is the snapshot stored when this submission was
        // raised, so the comparison keeps meaning something after the request
        // has been approved and the live row has moved on. A request whose
        // snapshot could not be written degrades to the newest version there
        // is, which is what compare() does with a null.
        $comparison = CreatorChangeService::compare(
            $creatorId,
            $changeSet['revision_id'] === null ? null : (int) $changeSet['revision_id'],
            $request->int('against') > 0 ? $request->int('against') : null
        );

        $this->view($request, 'admin.changes.show', [
            'pageTitle'    => 'Change request #' . $requestId,
            'pageSubtitle' => (string) $changeSet['display_name'],
            'request'      => $changeSet,
            'comparison'   => $comparison,
            'versions'     => CreatorChangeService::versions($creatorId, 20),
            'reasons'      => CreatorChangeService::reasons(CreatorChangeService::REASON_CONTEXT_CHANGE),
            'enabled'      => CreatorChangeService::enabled(),
        ]);
    }

    // -----------------------------------------------------------------
    // Decisions on a whole request
    // -----------------------------------------------------------------

    /** POST /admin/creator-changes/{id}/approve */
    public function approve(Request $request): never
    {
        $this->authorize($request, 'creators.approve');

        $data = $this->validate($request, ['note' => 'nullable|string|max:1000']);

        $result = CreatorChangeService::approve(
            $request->routeParamInt('id'),
            $this->context($request, $data['note'] ?? null)
        );

        $this->respond(
            $request,
            'Change approved and applied to the profile.',
            self::BASE,
            ['request' => $result]
        );
    }

    /** POST /admin/creator-changes/{id}/reject */
    public function reject(Request $request): never
    {
        $this->authorize($request, 'creators.approve');

        $data = $this->validate($request, [
            'reason' => 'required|string|max:60',
            'note'   => 'nullable|string|max:1000',
        ]);

        $result = CreatorChangeService::reject(
            $request->routeParamInt('id'),
            (string) $data['reason'],
            $this->context($request, $data['note'] ?? null)
        );

        $this->respond(
            $request,
            'Change rejected. The creator keeps their current values.',
            self::BASE,
            ['request' => $result]
        );
    }

    /** POST /admin/creator-changes/{id}/withdraw */
    public function withdraw(Request $request): never
    {
        $this->authorize($request, 'creators.edit');

        $data = $this->validate($request, ['note' => 'nullable|string|max:1000']);

        $result = CreatorChangeService::withdraw(
            $request->routeParamInt('id'),
            $this->context($request, $data['note'] ?? null)
        );

        $this->respond($request, 'Request withdrawn.', self::BASE, ['request' => $result]);
    }

    // -----------------------------------------------------------------
    // Decisions on one field
    // -----------------------------------------------------------------

    /** POST /admin/creator-changes/fields/{id}/approve */
    public function approveField(Request $request): never
    {
        $this->authorize($request, 'creators.approve');

        $data = $this->validate($request, ['note' => 'nullable|string|max:500']);

        $field = CreatorChangeService::approveField(
            $request->routeParamInt('id'),
            $this->context($request, $data['note'] ?? null)
        );

        $this->respond(
            $request,
            'Field approved.',
            self::BASE . '/' . (int) $field['request_id'],
            ['field' => $field]
        );
    }

    /** POST /admin/creator-changes/fields/{id}/reject */
    public function rejectField(Request $request): never
    {
        $this->authorize($request, 'creators.approve');

        $data = $this->validate($request, [
            'reason' => 'required|string|max:60',
            'note'   => 'nullable|string|max:500',
        ]);

        $field = CreatorChangeService::rejectField(
            $request->routeParamInt('id'),
            (string) $data['reason'],
            $this->context($request, $data['note'] ?? null)
        );

        $this->respond(
            $request,
            'Field rejected.',
            self::BASE . '/' . (int) $field['request_id'],
            ['field' => $field]
        );
    }

    // -----------------------------------------------------------------
    // Gallery and portfolio item moderation
    // -----------------------------------------------------------------

    /** POST /admin/creator-changes/items/{id}/approve */
    public function approveItem(Request $request): never
    {
        $this->authorize($request, 'creators.approve');

        $data = $this->validate($request, ['note' => 'nullable|string|max:500']);

        $item = CreatorChangeService::approveItem(
            $request->routeParamInt('id'),
            $this->context($request, $data['note'] ?? null)
        );

        $this->respond(
            $request,
            'Item approved.',
            self::BASE . '?tab=items',
            ['item' => $item]
        );
    }

    /** POST /admin/creator-changes/items/{id}/reject */
    public function rejectItem(Request $request): never
    {
        $this->authorize($request, 'creators.approve');

        $data = $this->validate($request, [
            'reason' => 'required|string|max:60',
            'note'   => 'nullable|string|max:500',
        ]);

        $item = CreatorChangeService::rejectItem(
            $request->routeParamInt('id'),
            (string) $data['reason'],
            $this->context($request, $data['note'] ?? null)
        );

        $this->respond(
            $request,
            'Item rejected and taken off the profile.',
            self::BASE . '?tab=items',
            ['item' => $item]
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Who is deciding, in the shape every service method here expects.
     *
     * The decision is written to `activity_logs` by the service rather than by
     * $this->log(), so that a decision taken by a cron sweep or by the API
     * produces the same audit line as one taken here. Logging it twice would
     * put two rows in the trail for one action.
     *
     * @return array<string,mixed>
     */
    private function context(Request $request, ?string $note): array
    {
        $admin = $this->admin($request);

        return [
            'actor_type' => 'admin',
            'actor_id'   => $this->adminId($request),
            'actor_name' => (string) ($admin['name'] ?? ''),
            'note'       => $note,
            'ip'         => $request->ip(),
        ];
    }
}
