<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\CreatorBadgeService;
use Zuste\Services\KycService;
use Zuste\Services\CreatorProfileLockService;

/**
 * Creator profile management for the admin panel.
 *
 * Verification badges and per-section profile locks. Both were built in
 * migration 029 and never wired to anything, so an operator's only levers over
 * a creator profile were "approve", "reject" and two booleans.
 *
 * Kept apart from UserController, which owns account moderation (approve,
 * suspend, delete). This owns what is true ABOUT a profile — what has been
 * verified, and what the creator may still change.
 */
final class CreatorProfileController extends BaseAdminController
{
    // -----------------------------------------------------------------
    // Badges
    // -----------------------------------------------------------------

    /**
     * GET /admin/creators/{id}/badges
     *
     * Named badgeList rather than badges: BaseAdminController::badges() is the
     * sidebar's unread-count helper, and overriding it here would break every
     * admin page's navigation.
     */
    public function badgeList(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $creatorId = $this->creatorId($request);

        Response::success([
            'badges'  => CreatorBadgeService::adminOverview($creatorId),
            'history' => CreatorBadgeService::history($creatorId, 50),
        ]);
    }

    /** POST /admin/creators/{id}/badges/award */
    public function awardBadge(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'badge'      => 'required|string|max:60',
            'note'       => 'nullable|string|max:500',
            'expires_at' => 'nullable|date',
        ]);

        $badge = (string) $data['badge'];

        // Featuring a creator is a promotional decision with its own
        // permission; every other badge is a verification decision.
        $this->authorize($request, $badge === 'featured_creator' ? 'creators.feature' : 'creators.verify');

        $admin = $this->admin($request);

        // `creator_verified` is the public blue tick. It has one path —
        // KycService::grantBadge(), which requires an approved KYC and the
        // kyc.badge permission — so it cannot be handed out from here.
        if ($badge === 'creator_verified') {
            $this->authorize($request, 'kyc.badge');

            try {
                $profileId = KycService::creatorProfileId($creatorId)
                    ?? throw HttpException::conflict('This creator has not completed KYC; the blue tick needs an approved KYC.');

                KycService::grantBadge($profileId, $this->adminId($request), $data['note'] ?? null, [
                    'ip'    => $request->ip(),
                    'agent' => $request->userAgent(),
                ]);
            } catch (HttpException $e) {
                $this->refuse($request, $e);
            }

            $this->respond($request, 'Blue tick granted.', null, [
                'badges' => CreatorBadgeService::adminOverview($creatorId),
            ]);
        }

        try {
            // KYC-derived badges ("KYC verified", "Identity verified",
            // "Business verified") follow the KYC review alone, and automatic
            // badges follow their rule; neither is handed out from here. This
            // route is gated by creators.verify, which roles with no kyc.*
            // permission at all (manager, moderator) hold.
            CreatorBadgeService::assertManuallyManageable($badge);

            CreatorBadgeService::award($creatorId, $badge, [
                'actor_type' => 'admin',
                'actor_id'   => $this->adminId($request),
                'actor_name' => (string) ($admin['name'] ?? ''),
                'note'       => $data['note'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'ip'         => $request->ip(),
            ]);
        } catch (HttpException $e) {
            $this->refuse($request, $e);
        }

        $this->log(
            $request,
            'creator.badge.awarded',
            'creator',
            $creatorId,
            "Awarded the {$badge} badge.",
            ['badge' => $badge]
        );

        $this->respond($request, 'Badge awarded.', null, [
            'badges' => CreatorBadgeService::adminOverview($creatorId),
        ]);
    }

    /** POST /admin/creators/{id}/badges/revoke */
    public function revokeBadge(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'badge'  => 'required|string|max:60',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $badge = (string) $data['badge'];

        $this->authorize($request, $badge === 'featured_creator' ? 'creators.feature' : 'creators.verify');

        $admin = $this->admin($request);

        // The blue tick is withdrawn through KYC so the profile, the users
        // flag and the badge all agree (see awardBadge()).
        if ($badge === 'creator_verified') {
            $this->authorize($request, 'kyc.badge');

            $profileId = KycService::creatorProfileId($creatorId);

            if ($profileId !== null) {
                try {
                    KycService::revokeBadge($profileId, $this->adminId($request), (string) $data['reason'], [
                        'ip'    => $request->ip(),
                        'agent' => $request->userAgent(),
                    ]);
                } catch (HttpException $e) {
                    $this->refuse($request, $e);
                }

                $this->respond($request, 'Blue tick removed.', null, [
                    'badges' => CreatorBadgeService::adminOverview($creatorId),
                ]);
            }

            // No KYC profile: only a legacy tick (a badge row with no KYC
            // behind it) can exist, and it must still be removable.
            CreatorBadgeService::revoke($creatorId, $badge, [
                'actor_type' => 'admin',
                'actor_id'   => $this->adminId($request),
                'actor_name' => (string) ($admin['name'] ?? ''),
                'reason'     => (string) $data['reason'],
                'ip'         => $request->ip(),
            ]);
        } else {
            try {
                // Same rule as awardBadge(): KYC-derived and automatic badges
                // are not removed by hand. A KYC badge goes when the KYC is
                // suspended or rejected; an automatic one on "Re-check".
                CreatorBadgeService::assertManuallyManageable($badge);

                CreatorBadgeService::revoke($creatorId, $badge, [
                    'actor_type' => 'admin',
                    'actor_id'   => $this->adminId($request),
                    'actor_name' => (string) ($admin['name'] ?? ''),
                    'reason'     => (string) $data['reason'],
                    'ip'         => $request->ip(),
                ]);
            } catch (HttpException $e) {
                $this->refuse($request, $e);
            }
        }

        $this->log(
            $request,
            'creator.badge.revoked',
            'creator',
            $creatorId,
            "Revoked the {$badge} badge.",
            ['badge' => $badge, 'reason' => $data['reason']]
        );

        $this->respond($request, 'Badge revoked.', null, [
            'badges' => CreatorBadgeService::adminOverview($creatorId),
        ]);
    }

    /**
     * POST /admin/creators/{id}/badges/sync
     *
     * Re-evaluate the automatic badges for one creator on demand, so an
     * operator who has just approved a KYC profile does not have to wait for
     * the nightly job to see the badge appear.
     */
    public function syncBadges(Request $request): never
    {
        $this->authorize($request, 'creators.verify');

        $creatorId = $this->creatorId($request);
        $changed   = CreatorBadgeService::syncAutomatic($creatorId);

        $this->log(
            $request,
            'creator.badge.synced',
            'creator',
            $creatorId,
            "Re-evaluated automatic badges ({$changed} changed)."
        );

        $this->respond(
            $request,
            $changed === 0 ? 'Automatic badges are already up to date.' : "{$changed} badge(s) updated.",
            null,
            ['badges' => CreatorBadgeService::adminOverview($creatorId)]
        );
    }

    // -----------------------------------------------------------------
    // Section locks
    // -----------------------------------------------------------------

    /** GET /admin/creators/{id}/locks */
    public function locks(Request $request): never
    {
        $this->authorize($request, 'creators.view');

        $creatorId = $this->creatorId($request);

        Response::success(['locks' => CreatorProfileLockService::overview($creatorId)]);
    }

    /** POST /admin/creators/{id}/locks */
    public function lock(Request $request): never
    {
        $this->authorize($request, 'creators.edit');

        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'section' => 'required|string|max:40',
            'reason'  => 'nullable|string|max:500',
        ]);

        $admin = $this->admin($request);

        CreatorProfileLockService::lock($creatorId, (string) $data['section'], [
            'actor_id'   => $this->adminId($request),
            'actor_name' => (string) ($admin['name'] ?? ''),
            'reason'     => $data['reason'] ?? null,
            'ip'         => $request->ip(),
        ]);

        $this->log(
            $request,
            'creator.section.locked',
            'creator',
            $creatorId,
            "Locked the {$data['section']} section.",
            ['section' => $data['section'], 'reason' => $data['reason'] ?? null]
        );

        $this->respond($request, 'Section locked.', null, [
            'locks' => CreatorProfileLockService::overview($creatorId),
        ]);
    }

    /** DELETE /admin/creators/{id}/locks */
    public function unlock(Request $request): never
    {
        $this->authorize($request, 'creators.edit');

        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, ['section' => 'required|string|max:40']);

        $admin = $this->admin($request);

        CreatorProfileLockService::unlock($creatorId, (string) $data['section'], [
            'actor_id'   => $this->adminId($request),
            'actor_name' => (string) ($admin['name'] ?? ''),
            'ip'         => $request->ip(),
        ]);

        $this->log(
            $request,
            'creator.section.unlocked',
            'creator',
            $creatorId,
            "Unlocked the {$data['section']} section.",
            ['section' => $data['section']]
        );

        $this->respond($request, 'Section unlocked.', null, [
            'locks' => CreatorProfileLockService::overview($creatorId),
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Report a refused badge change: the error payload for an AJAX call, a
     * toast on the creator screen for a form post (rather than an error page).
     */
    private function refuse(Request $request, HttpException $e): never
    {
        if ($request->expectsJson()) {
            Response::error($e->getMessage(), $e->status(), $e->errorCode(), $e->errors());
        }

        $this->back('error', $e->getMessage());
    }

    /**
     * The creator this request addresses.
     *
     * Resolved and checked here rather than in each handler so a request for a
     * deleted or non-existent creator fails the same way everywhere.
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
