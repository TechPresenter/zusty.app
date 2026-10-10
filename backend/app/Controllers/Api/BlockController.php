<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\ActivityLogger;
use Zuste\Services\BlockService;

/**
 * The accounts the signed-in user has blocked.
 *
 * What a block does, and the escrow rule it never breaks, is documented on
 * {@see BlockService}.
 *
 * Routes: /api/v1/me/blocks
 */
final class BlockController extends BaseApiController
{
    /** GET /me/blocks — the Blocked accounts screen, newest first. */
    public function index(Request $request): never
    {
        $pagination = $this->pagination($request, 50, 200);
        $userId     = $this->userId($request);

        Response::paginated(
            BlockService::list($userId, $pagination['per_page'], $pagination['offset']),
            BlockService::count($userId),
            $pagination['page'],
            $pagination['per_page']
        );
    }

    /** POST /me/blocks {user_id} */
    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'user_id' => 'required|integer|min_value:1',
        ]);

        $user      = $this->user($request);
        $blockedId = (int) $data['user_id'];
        $result    = BlockService::block((int) $user['id'], $blockedId);

        if (!$result['created']) {
            Response::success(['block' => $result['block']], 'You have already blocked this account.');
        }

        ActivityLogger::user($user, 'user.blocked', 'user', $blockedId, 'Blocked an account.');

        Response::created(
            ['block' => $result['block']],
            'Blocked. They can no longer message you or send you requests.'
        );
    }

    /** DELETE /me/blocks/{userId} — idempotent. */
    public function destroy(Request $request): never
    {
        $user      = $this->user($request);
        $blockedId = $request->routeParamInt('userId');

        if (BlockService::unblock((int) $user['id'], $blockedId)) {
            ActivityLogger::user($user, 'user.unblocked', 'user', $blockedId, 'Unblocked an account.');
        }

        Response::success(['user_id' => $blockedId, 'blocked' => false], 'Unblocked.');
    }
}
