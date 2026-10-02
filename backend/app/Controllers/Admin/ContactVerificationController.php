<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\ContactVerificationService;
use Zuste\Services\CreatorProfileLockService;
use Zuste\Services\TaxonomyService;

/**
 * What an operator can see and do about one account's contact details.
 *
 * Three powers, and no more:
 *
 *   * FORCE-VERIFY, for the case the OTP engine cannot serve — an address
 *     proved to a support agent by other means, or a carrier that will not
 *     deliver. `verified_via` records that it was an operator and not a code.
 *   * REVOKE, when a verification turns out to be wrong. On an alternate this
 *     also releases the address so somebody else can prove it.
 *   * REJECT a pending change. This is the chokepoint for accounts
 *     `creator_profile_locks` cannot reach: locks are keyed to creators(id),
 *     so a brand user has no section to freeze, but their request can still
 *     be stopped with a name and a reason against it.
 *
 * Freezing contact edits outright is NOT here. That is
 * CreatorProfileLockService's 'contact' section, reached from the creator
 * screen, and a second way to lock the same thing is how a lock quietly stops
 * being enforced. The screen below reports the lock state and links to it.
 */
final class ContactVerificationController extends BaseAdminController
{
    /**
     * GET /admin/users/{id}/contacts
     *
     * The full picture: both primary identifiers, every alternate including
     * released ones, every request, and the verification log.
     */
    public function show(Request $request): never
    {
        $this->authorize($request, 'users.view');

        $userId = $this->targetUserId($request);
        $data   = ContactVerificationService::adminOverview($userId, 200);

        $creatorId = Database::scalar(
            'SELECT `id` FROM `creators` WHERE `user_id` = :u AND `deleted_at` IS NULL LIMIT 1',
            ['u' => $userId]
        );

        $user = $this->targetUser($userId);

        $this->view($request, 'admin.contacts.show', [
            'pageTitle'  => 'Contacts · ' . (string) $user['name'],
            'user'       => $user,
            'primary'    => $data['primary'],
            'alternates' => $data['alternates'],
            'requests'   => $data['requests'],
            'history'    => $data['history'],
            'policy'     => $data['policy'],
            'creatorId'  => $creatorId === null ? null : (int) $creatorId,
            'lockLabel'  => CreatorProfileLockService::SECTIONS['contact'],
            'labels'     => TaxonomyService::map(ContactVerificationService::TAXONOMY_LABEL),
            'reasons'    => TaxonomyService::map(ContactVerificationService::TAXONOMY_REASON),
        ]);
    }

    /**
     * GET /admin/users/{id}/contacts/log
     *
     * The log on its own, for the drawer on the user detail screen.
     *
     * Named verificationLog rather than log: BaseAdminController::log() is the
     * activity-audit helper every admin controller calls, and redeclaring it
     * with a different signature fatals the class.
     */
    public function verificationLog(Request $request): never
    {
        $this->authorize($request, 'users.view');

        $userId = $this->targetUserId($request);

        Response::success([
            'history'  => ContactVerificationService::history($userId, $request->perPage(100, 500)),
            'requests' => ContactVerificationService::requests($userId),
        ]);
    }

    /** POST /admin/users/{id}/contacts/force-verify */
    public function forceVerify(Request $request): never
    {
        $this->authorize($request, 'creators.verify');

        $userId = $this->targetUserId($request);

        $data = $this->validate($request, [
            'channel'          => 'required|in:email,phone',
            'contact_point_id' => 'nullable|integer',
            'note'             => 'nullable|string|max:1000',
        ]);

        $pointId = isset($data['contact_point_id']) && (int) $data['contact_point_id'] > 0
            ? (int) $data['contact_point_id']
            : null;

        $admin = $this->admin($request);

        ContactVerificationService::forceVerify($userId, (string) $data['channel'], $pointId, [
            'actor_type' => 'admin',
            'actor_id'   => $this->adminId($request),
            'actor_name' => (string) ($admin['name'] ?? ''),
            'note'       => $data['note'] ?? 'Verified by an administrator.',
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->log(
            $request,
            'user.contact.force_verified',
            'user',
            $userId,
            'Marked a ' . $data['channel'] . ' contact verified.',
            ['channel' => $data['channel'], 'contact_point_id' => $pointId]
        );

        $this->respond($request, 'Contact marked verified.', null, [
            'contacts' => ContactVerificationService::adminOverview($userId, 200),
        ]);
    }

    /** POST /admin/users/{id}/contacts/revoke */
    public function revokeVerification(Request $request): never
    {
        $this->authorize($request, 'creators.verify');

        $userId = $this->targetUserId($request);

        $data = $this->validate($request, [
            'channel'          => 'required|in:email,phone',
            'contact_point_id' => 'nullable|integer',
            // Required, not optional: an unexplained revocation is
            // indistinguishable from a mistake when somebody reads the log
            // six months later.
            'reason'           => 'required|string|min:3|max:1000',
        ]);

        $pointId = isset($data['contact_point_id']) && (int) $data['contact_point_id'] > 0
            ? (int) $data['contact_point_id']
            : null;

        $admin = $this->admin($request);

        ContactVerificationService::revokeVerification($userId, (string) $data['channel'], $pointId, [
            'actor_type' => 'admin',
            'actor_id'   => $this->adminId($request),
            'actor_name' => (string) ($admin['name'] ?? ''),
            'reason'     => (string) $data['reason'],
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->log(
            $request,
            'user.contact.verification_revoked',
            'user',
            $userId,
            'Revoked a ' . $data['channel'] . ' verification.',
            ['channel' => $data['channel'], 'contact_point_id' => $pointId, 'reason' => $data['reason']]
        );

        $this->respond($request, 'Verification revoked.', null, [
            'contacts' => ContactVerificationService::adminOverview($userId, 200),
        ]);
    }

    /** POST /admin/users/{id}/contacts/requests/{requestId}/reject */
    public function rejectRequest(Request $request): never
    {
        $this->authorize($request, 'users.edit');

        $userId    = $this->targetUserId($request);
        $requestId = $request->routeParamInt('requestId');

        $data = $this->validate($request, [
            'note' => 'required|string|min:3|max:500',
        ]);

        $admin = $this->admin($request);

        // The account id from the URL is handed to the service rather than
        // checked here: rejectChange() scopes the request to that account
        // itself, so an operator editing the request id in the URL gets a 404
        // instead of closing a stranger's request. Keeping a second copy of the
        // check in the controller is how the two answers eventually diverge.
        ContactVerificationService::rejectChange($userId, $requestId, [
            'actor_id'   => $this->adminId($request),
            'actor_name' => (string) ($admin['name'] ?? ''),
            'note'       => (string) $data['note'],
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->log(
            $request,
            'user.contact.change_rejected',
            'user',
            $userId,
            'Rejected a pending contact change.',
            ['request_id' => $requestId, 'note' => $data['note']]
        );

        $this->respond($request, 'Change request rejected.', null, [
            'contacts' => ContactVerificationService::adminOverview($userId, 200),
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The account this request addresses.
     *
     * Soft-deleted accounts are still reachable on purpose: releasing an
     * identifier held by a closed account is exactly the kind of thing an
     * operator opens this screen to do, and refusing to load the row would
     * make it impossible.
     */
    private function targetUserId(Request $request): int
    {
        $id = $request->routeParamInt('id');

        $exists = (int) Database::scalar(
            'SELECT COUNT(*) FROM `users` WHERE `id` = :id',
            ['id' => $id]
        );

        if ($exists === 0) {
            throw HttpException::notFound('User not found.');
        }

        return $id;
    }

    /** @return array<string,mixed> */
    private function targetUser(int $userId): array
    {
        $user = Database::selectOne(
            'SELECT `id`, `uuid`, `name`, `account_type`, `status`, `avatar_url`, `created_at`, `deleted_at`
               FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        );

        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }

        return $user;
    }
}
