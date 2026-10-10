<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\ContactVerificationService;
use Zuste\Services\MailService;
use Zuste\Services\PrivacyService;

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
     *
     * The owner proves it is them first, the same way as for an export or a
     * deletion: the password, a code from POST /me/privacy/verification-code
     * (purpose confirm_contact_change) sent to the CURRENT email or phone, or
     * a fresh Google or Apple sign-in. Otherwise a stolen session could move
     * the email to a mailbox it reads, and every later code and notice would
     * go there. `code_channel` carries the code's channel, because `channel`
     * already names the contact being changed.
     *
     * An account that has no way to prove anything right now (no proven
     * password, no code channel — a phone-only account while SMS is off, or a
     * single channel inside its hold after a change — and no usable Google or
     * Apple sign-in) is refused with 409 contact_change_unverifiable and told
     * to write to support. A change is never applied unproven: that would be
     * exactly the takeover this proof exists to stop.
     */
    public function requestChange(Request $request): never
    {
        $data = $this->validate($request, [
            'channel'      => 'required|in:email,phone',
            'value'        => 'required|string|max:191',
            'reason_code'  => 'nullable|string|max:60',
            'reason_note'  => 'nullable|string|max:500',
            'password'     => 'nullable|string|max:128',
            'code'         => 'nullable|string|min:4|max:10',
            'code_channel' => 'nullable|in:email,sms',
            'id_token'     => 'nullable|string|max:4096',
        ]);

        $user    = $this->user($request);
        $userId  = (int) $user['id'];
        $channel = (string) $data['channel'];
        $value   = (string) $data['value'];

        // A change that is going to be refused is refused before the proof is
        // checked, so it does not spend the code the owner just typed.
        ContactVerificationService::preflightPrimaryChange(
            $userId,
            $channel,
            $value,
            isset($data['reason_code']) ? (string) $data['reason_code'] : null
        );

        $options = PrivacyService::verificationOptions($user);

        if (!$options['password'] && $options['channels'] === [] && $options['providers'] === []) {
            throw self::unverifiable($options);
        }

        PrivacyService::verify(
            $user,
            PrivacyService::PURPOSE_CONTACT,
            [
                'password' => $data['password'] ?? null,
                'code'     => $data['code'] ?? null,
                'channel'  => $data['code_channel'] ?? 'email',
                'id_token' => $data['id_token'] ?? null,
            ],
            $this->deviceContext($request)
        );

        $result = ContactVerificationService::requestPrimaryChange(
            $userId,
            $channel,
            $value,
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
     * The refusal for a primary change nobody can prove right now.
     *
     * 409, because nothing in the request is wrong: the account's state is.
     * When a channel is only held after a recent change, the message says
     * when it comes back; either way it names the support address to write to.
     *
     * @param array{unavailable:list<array{channel:string,reason:string,available_at:?string}>} $options
     */
    private static function unverifiable(array $options): HttpException
    {
        $support = MailService::supportAddress();
        $held    = array_values(array_filter(array_map(
            static fn (array $entry): string => ($entry['reason'] ?? '') === 'contact_recently_changed'
                ? (string) ($entry['available_at'] ?? '')
                : '',
            $options['unavailable']
        )));
        $until   = $held === [] ? false : strtotime((string) min($held));

        $message = ($until !== false
                ? 'Your email address or mobile number changed recently, so we can\'t confirm it\'s you until '
                    . date('j M Y, g:i A', $until) . '. '
                : 'We can\'t confirm it\'s you here: your account has no password and no way to receive a code right now. ')
            . 'To change your email address or mobile number, write to ' . $support
            . ' from an address on your account.';

        return HttpException::conflict($message, 'contact_change_unverifiable');
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
