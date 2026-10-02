<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\ContactVerificationService;

/**
 * The signed-in account's email addresses and mobile numbers.
 *
 * Routes: /api/v1/me/contacts/*
 *
 * Deliberately separate from AccountController::updateProfile(), which is a
 * profile editor: name, bio, city, avatar. An identifier is not a profile
 * field. It is how the platform reaches an account and how an account is
 * recovered, so it moves only through the request/prove/apply cycle in
 * ContactVerificationService and never through a general-purpose PUT.
 *
 * Every handler returns the whole contact picture rather than just the row it
 * touched, because a single change moves several things at once — a proved
 * primary email clears its pending request, may release an alternate that was
 * promoted, and flips a verification badge. Returning one row leaves the app
 * to guess at the rest and the guesses go stale.
 */
final class ContactController extends BaseApiController
{
    /** GET /me/contacts */
    public function index(Request $request): never
    {
        Response::success(ContactVerificationService::overview($this->userId($request)));
    }

    /**
     * POST /me/contacts/alternates
     *
     * Claim an additional address. It is stored unverified and a code goes out
     * at once; nothing about the account changes until that code comes back.
     */
    public function addAlternate(Request $request): never
    {
        $data = $this->validate($request, [
            'channel'     => 'required|in:email,phone',
            'value'       => 'required|string|max:191',
            'label'       => 'nullable|string|max:60',
            'is_recovery' => 'nullable|boolean',
        ]);

        $userId = $this->userId($request);

        $result = ContactVerificationService::addAlternate(
            $userId,
            (string) $data['channel'],
            (string) $data['value'],
            $this->context($request) + [
                'label'       => $data['label'] ?? null,
                'is_recovery' => !empty($data['is_recovery']),
            ]
        );

        Response::created(
            $result + ['overview' => ContactVerificationService::overview($userId)],
            'Verification code sent. Enter it to confirm this contact.'
        );
    }

    /**
     * POST /me/contacts/alternates/{id}/verify
     *
     * Start proving an alternate again — after an administrator revoked its
     * verification, or when the first code never arrived and the request that
     * carried it has since lapsed.
     */
    public function reverifyAlternate(Request $request): never
    {
        $userId = $this->userId($request);

        $result = ContactVerificationService::reverifyAlternate(
            $userId,
            $request->routeParamInt('id'),
            $this->context($request)
        );

        Response::success(
            ['request' => $result, 'overview' => ContactVerificationService::overview($userId)],
            'Verification code sent.'
        );
    }

    /** DELETE /me/contacts/alternates/{id} */
    public function removeAlternate(Request $request): never
    {
        $userId = $this->userId($request);

        ContactVerificationService::removeAlternate(
            $userId,
            $request->routeParamInt('id'),
            $this->context($request)
        );

        Response::success(
            ContactVerificationService::overview($userId),
            'Contact removed.'
        );
    }

    /**
     * POST /me/contacts/change
     *
     * Propose a new primary email address or mobile number. The live value is
     * NOT touched here — that is the whole point of the endpoint.
     */
    public function requestChange(Request $request): never
    {
        $data = $this->validate($request, [
            'channel'     => 'required|in:email,phone',
            'value'       => 'required|string|max:191',
            'reason_code' => 'nullable|string|max:60',
            'reason_note' => 'nullable|string|max:500',
        ]);

        $userId = $this->userId($request);

        $result = ContactVerificationService::requestPrimaryChange(
            $userId,
            (string) $data['channel'],
            (string) $data['value'],
            $this->context($request) + [
                'reason_code' => $data['reason_code'] ?? null,
                'reason_note' => $data['reason_note'] ?? null,
            ]
        );

        Response::created(
            ['request' => $result, 'overview' => ContactVerificationService::overview($userId)],
            $result['status'] === 'applied'
                ? 'Updated. Verification is switched off, so the new value is recorded as unverified.'
                : 'Verification code sent to the new address. It is not in use until you confirm it.'
        );
    }

    /** POST /me/contacts/requests/{id}/resend */
    public function resendCode(Request $request): never
    {
        $userId = $this->userId($request);

        $result = ContactVerificationService::resend(
            $userId,
            $request->routeParamInt('id'),
            $this->context($request)
        );

        Response::success($result, 'Verification code sent.');
    }

    /**
     * POST /me/contacts/requests/{id}/verify
     *
     * Redeem the code. This is the only path by which a new identifier ever
     * reaches `users` or a contact point becomes verified.
     */
    public function confirmChange(Request $request): never
    {
        $data = $this->validate($request, [
            'code' => 'required|string|min:4|max:8',
        ]);

        $result = ContactVerificationService::verify(
            $this->userId($request),
            $request->routeParamInt('id'),
            (string) $data['code'],
            $this->context($request)
        );

        Response::success($result, 'Verified.');
    }

    /** POST /me/contacts/requests/{id}/cancel */
    public function cancelRequest(Request $request): never
    {
        $userId = $this->userId($request);

        ContactVerificationService::cancel(
            $userId,
            $request->routeParamInt('id'),
            $this->context($request)
        );

        Response::success(
            ContactVerificationService::overview($userId),
            'Request cancelled.'
        );
    }

    /**
     * Who did it, and from where.
     *
     * Carried on every call because `contact_verification_events` is the only
     * durable record of an identifier changing hands — otp_verifications is
     * purged after seven days by CronRunner — and an audit row with no actor
     * and no address answers nothing worth asking.
     *
     * @return array<string,mixed>
     */
    private function context(Request $request): array
    {
        $user = $this->user($request);

        return [
            'actor_type' => 'user',
            'actor_id'   => (int) $user['id'],
            'actor_name' => (string) ($user['name'] ?? ''),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }
}
