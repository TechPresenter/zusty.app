<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\ActivityLogger;
use Zuste\Services\CreatorProfileFieldService;
use Zuste\Services\CreatorProfileLockService;

/**
 * The extended creator profile: skills, credentials, work history, working
 * hours, FAQs and the scalar fields migration 035 added.
 *
 * A separate controller from CreatorController on purpose. CreatorController is
 * already 1,200 lines covering discovery, packages, portfolio, social accounts
 * and the availability calendar; folding thirty more endpoints into it would
 * have made the file the place every creator change collides. These endpoints
 * also share one service and one set of section locks, so they belong together
 * and nowhere else.
 *
 * Every write is the signed-in creator's own — there is no id in any write
 * path. The creator id comes from the token via creatorId(), never from the
 * request body, which is what makes it impossible to address somebody else's
 * rows by guessing a number.
 *
 * Validation here is the shape gate: required, type, present-or-absent. The
 * column-precise rules — lengths, real calendar dates, http(s) URLs, taxonomy
 * membership — live in CreatorProfileFieldService, because the admin panel and
 * the importer write the same tables and a rule only one caller applies is not
 * a rule.
 *
 * Routes: /api/v1/me/creator/* (auth + creator), /api/v1/creators/{id}/fields (public)
 */
final class CreatorProfileFieldController extends BaseApiController
{
    // =================================================================
    // Reading
    // =================================================================

    /**
     * GET /me/creator/fields
     *
     * The whole extended profile as the OWNER sees it — hidden rows included,
     * street address included — plus the catalogues the editor renders from and
     * the sections an administrator has frozen. One call, because an editor
     * screen that needed six would make five of them on a screen that needed
     * one.
     */
    public function index(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success([
            'fields'          => CreatorProfileFieldService::extendedProfile($creatorId, true),
            'catalogues'      => CreatorProfileFieldService::sections(),
            // The apps grey out a frozen section rather than letting a creator
            // fill in a form the API will then refuse.
            'locked_sections' => CreatorProfileLockService::lockedSections($creatorId),
        ]);
    }

    /**
     * GET /creators/{id}/fields — the public view of somebody else's extended
     * profile.
     *
     * Guarded by exactly the rule CreatorService::profile() applies: a profile
     * that is not approved and public does not exist to anyone but its owner.
     * Repeating the predicate here rather than trusting the caller is the point
     * — this endpoint is reachable without a token.
     */
    public function publicFields(Request $request): never
    {
        $creatorId = $request->routeParamInt('id');

        $creator = Database::selectOne(
            'SELECT `id`, `user_id`, `status`, `is_public` FROM `creators`
              WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        );

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        $isOwner = $request->userId() > 0 && (int) $creator['user_id'] === $request->userId();

        if (!$isOwner && ((string) $creator['status'] !== 'approved' || (int) $creator['is_public'] !== 1)) {
            throw HttpException::notFound('Creator not found.');
        }

        Response::success([
            'fields' => CreatorProfileFieldService::extendedProfile($creatorId, $isOwner),
        ]);
    }

    // =================================================================
    // Scalar fields
    // =================================================================

    /**
     * PUT /me/creator/fields/details
     *
     * Introduction video, media kit, resume, street address, PIN, time zone and
     * the career-start year. Partial: only the keys actually sent are written,
     * so a client editing the address cannot blank a media kit it never
     * rendered. A key sent empty DOES clear its column — that is how a creator
     * removes a resume.
     */
    public function updateDetails(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'intro_video_url'           => 'nullable|string|max:500',
            'intro_video_thumbnail_url' => 'nullable|string|max:500',
            'media_kit_url'             => 'nullable|string|max:500',
            'resume_url'                => 'nullable|string|max:500',
            'address_line1'             => 'nullable|string|max:255',
            'address_line2'             => 'nullable|string|max:255',
            'pin_code'                  => 'nullable|string|max:12',
            'address_is_public'         => 'nullable|boolean',
            'timezone'                  => 'nullable|string|max:64',
            'career_start_year'         => 'nullable|integer',
            'experience_level'          => 'nullable|string|max:30',
        ]);

        $details = CreatorProfileFieldService::updateDetails($creatorId, $data);

        $this->record($request, 'creator.fields_updated', $creatorId, 'Updated extended profile details.', array_keys($data));

        Response::success(['details' => $details], 'Saved.');
    }

    // =================================================================
    // Taxonomy sections — skills, interests, professions, languages,
    // service areas, cities
    // =================================================================

    /**
     * GET /me/creator/fields/terms
     *
     * Every section, including the empty ones, with the option lists beside
     * them so a picker needs no second request.
     */
    public function terms(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success([
            'terms'      => CreatorProfileFieldService::termsGrouped($creatorId, true),
            'catalogues' => CreatorProfileFieldService::sections()['terms'],
        ]);
    }

    /**
     * PUT /me/creator/fields/terms/{section}
     *
     * The section becomes exactly these values, in this order. This is what a
     * multi-select actually does, and doing it in one call rather than a diff
     * of adds and removes is what makes it atomic.
     */
    public function syncTerms(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $section   = $this->section($request);

        // `required` would refuse an empty list, and clearing a section is a
        // legitimate edit — a creator who no longer wants any interests listed
        // has to be able to say so.
        $data = $this->validate($request, ['values' => 'nullable|array']);

        $terms = CreatorProfileFieldService::syncTerms($creatorId, $section, (array) ($data['values'] ?? []));

        $this->record($request, 'creator.terms_updated', $creatorId, "Updated {$section}.", ['count' => count($terms)]);

        Response::success(['section' => $section, 'terms' => $terms], 'Saved.');
    }

    /**
     * POST /me/creator/fields/terms/{section}
     *
     * Add one term without disturbing the rest of the section.
     */
    public function attachTerm(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $section   = $this->section($request);

        $data = $this->validate($request, [
            'value'      => 'required|string|max:100',
            'is_primary' => 'nullable|boolean',
            'is_visible' => 'nullable|boolean',
        ]);

        $term = CreatorProfileFieldService::attachTerm($creatorId, $section, (string) $data['value'], $data);

        $this->record($request, 'creator.term_added', $creatorId, "Added a {$section} entry.", ['value' => $data['value']]);

        Response::created(['section' => $section, 'term' => $term], 'Added.');
    }

    /**
     * DELETE /me/creator/fields/terms/{section}/{value}
     */
    public function detachTerm(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $section   = $this->section($request);
        $value     = (string) $request->routeParam('value', '');

        CreatorProfileFieldService::detachTerm($creatorId, $section, $value);

        $this->record($request, 'creator.term_removed', $creatorId, "Removed a {$section} entry.", ['value' => $value]);

        Response::success(
            [
                'section' => $section,
                'terms'   => CreatorProfileFieldService::terms($creatorId, ['section' => $section, 'include_hidden' => true]),
            ],
            'Removed.'
        );
    }

    /**
     * PUT /me/creator/fields/terms/{section}/order
     *
     * Takes the term VALUES in the order they should appear. Values the caller
     * omits keep their relative order and follow the ones that were sent.
     */
    public function reorderTerms(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $section   = $this->section($request);

        $data = $this->validate($request, ['values' => 'required|array']);

        $terms = CreatorProfileFieldService::reorderTerms($creatorId, $section, (array) $data['values']);

        $this->record($request, 'creator.terms_reordered', $creatorId, "Reordered {$section}.");

        Response::success(['section' => $section, 'terms' => $terms], 'Order saved.');
    }

    /**
     * PUT /me/creator/fields/terms/{section}/primary
     *
     * Nominate the term that heads the section — the lead profession, the
     * headline skill. Send no value to clear the nomination.
     */
    public function setPrimaryTerm(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $section   = $this->section($request);

        $data = $this->validate($request, ['value' => 'nullable|string|max:100']);

        $value = isset($data['value']) ? (string) $data['value'] : null;

        $terms = CreatorProfileFieldService::setPrimaryTerm($creatorId, $section, $value);

        $this->record($request, 'creator.term_primary_set', $creatorId, "Set the primary {$section} entry.", ['value' => $value]);

        Response::success(['section' => $section, 'terms' => $terms], 'Saved.');
    }

    // =================================================================
    // Credentials — education, certifications, awards
    // =================================================================

    /** GET /me/creator/credentials */
    public function credentials(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success([
            'credentials' => CreatorProfileFieldService::credentials($creatorId, [
                'include_hidden' => true,
                'kind'           => $request->string('kind') ?: null,
            ]),
            'kinds' => CreatorProfileFieldService::sections()['credentials'],
        ]);
    }

    /** POST /me/creator/credentials */
    public function createCredential(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $credential = CreatorProfileFieldService::createCredential(
            $creatorId,
            $this->validate($request, self::CREDENTIAL_RULES + ['title' => 'required|string|max:191'])
        );

        $this->record($request, 'creator.credential_added', $creatorId, 'Added a credential.', [
            'kind' => $credential['kind'],
        ]);

        Response::created(['credential' => $credential], 'Added.');
    }

    /** PUT /me/creator/credentials/{id} */
    public function updateCredential(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        $credential = CreatorProfileFieldService::updateCredential(
            $creatorId,
            $id,
            $this->validate($request, self::CREDENTIAL_RULES + ['title' => 'nullable|string|max:191'])
        );

        $this->record($request, 'creator.credential_updated', $creatorId, 'Updated a credential.', ['id' => $id]);

        Response::success(['credential' => $credential], 'Saved.');
    }

    /** DELETE /me/creator/credentials/{id} */
    public function deleteCredential(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        CreatorProfileFieldService::deleteCredential($creatorId, $id);

        $this->record($request, 'creator.credential_deleted', $creatorId, 'Removed a credential.', ['id' => $id]);

        Response::success(
            ['credentials' => CreatorProfileFieldService::credentials($creatorId, ['include_hidden' => true])],
            'Removed.'
        );
    }

    /** PUT /me/creator/credentials/order */
    public function reorderCredentials(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, ['ids' => 'required|array']);

        $credentials = CreatorProfileFieldService::reorderCredentials($creatorId, array_map('intval', (array) $data['ids']));

        $this->record($request, 'creator.credentials_reordered', $creatorId, 'Reordered credentials.');

        Response::success(['credentials' => $credentials], 'Order saved.');
    }

    // =================================================================
    // Experience — work history and brand collaborations
    // =================================================================

    /** GET /me/creator/experience */
    public function experience(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success([
            'experience' => CreatorProfileFieldService::experience($creatorId, [
                'include_hidden' => true,
                'kind'           => $request->string('kind') ?: null,
                'section'        => $request->string('section') ?: null,
            ]),
            'kinds' => CreatorProfileFieldService::sections()['experience'],
        ]);
    }

    /** POST /me/creator/experience */
    public function createExperience(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $entry = CreatorProfileFieldService::createExperience(
            $creatorId,
            $this->validate($request, self::EXPERIENCE_RULES + ['organisation' => 'required|string|max:191'])
        );

        $this->record($request, 'creator.experience_added', $creatorId, 'Added a work history entry.', [
            'kind' => $entry['kind'],
        ]);

        Response::created(['experience' => $entry], 'Added.');
    }

    /** PUT /me/creator/experience/{id} */
    public function updateExperience(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        $entry = CreatorProfileFieldService::updateExperience(
            $creatorId,
            $id,
            $this->validate($request, self::EXPERIENCE_RULES + ['organisation' => 'nullable|string|max:191'])
        );

        $this->record($request, 'creator.experience_updated', $creatorId, 'Updated a work history entry.', ['id' => $id]);

        Response::success(['experience' => $entry], 'Saved.');
    }

    /** DELETE /me/creator/experience/{id} */
    public function deleteExperience(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        CreatorProfileFieldService::deleteExperience($creatorId, $id);

        $this->record($request, 'creator.experience_deleted', $creatorId, 'Removed a work history entry.', ['id' => $id]);

        Response::success(
            ['experience' => CreatorProfileFieldService::experience($creatorId, ['include_hidden' => true])],
            'Removed.'
        );
    }

    /** PUT /me/creator/experience/order */
    public function reorderExperience(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, ['ids' => 'required|array']);

        $entries = CreatorProfileFieldService::reorderExperience($creatorId, array_map('intval', (array) $data['ids']));

        $this->record($request, 'creator.experience_reordered', $creatorId, 'Reordered work history.');

        Response::success(['experience' => $entries], 'Order saved.');
    }

    // =================================================================
    // Working hours
    // =================================================================

    /** GET /me/creator/working-hours */
    public function workingHours(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success(['working_hours' => CreatorProfileFieldService::workingHours($creatorId, true)]);
    }

    /**
     * PUT /me/creator/working-hours
     *
     * Replace the whole weekly schedule. The editor is a seven-row grid, so
     * this is the shape the product actually saves in; days the caller omits
     * are left exactly as they were, which is what makes "clear Sunday" and
     * "I did not send Sunday" different requests.
     *
     * Payload: {"days":[{"weekday":1,"is_working":true,
     *                    "slots":[{"starts_at":"10:00","ends_at":"14:00"}]}]}
     */
    public function setWorkingHours(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, ['days' => 'required|array']);

        $hours = CreatorProfileFieldService::setWorkingHours($creatorId, array_values((array) $data['days']));

        $this->record($request, 'creator.working_hours_updated', $creatorId, 'Updated the weekly working hours.');

        Response::success(['working_hours' => $hours], 'Saved.');
    }

    /** POST /me/creator/working-hours */
    public function addWorkingHour(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'weekday'    => 'required|integer|min_value:1|max_value:7',
            'starts_at'  => 'nullable|string|max:8',
            'ends_at'    => 'nullable|string|max:8',
            'is_working' => 'nullable|boolean',
            'note'       => 'nullable|string|max:255',
            'is_visible' => 'nullable|boolean',
        ]);

        $hours = CreatorProfileFieldService::addWorkingHour($creatorId, $data);

        $this->record($request, 'creator.working_hours_updated', $creatorId, 'Added a working time range.', [
            'weekday' => $data['weekday'],
        ]);

        Response::created(['working_hours' => $hours], 'Added.');
    }

    /** PUT /me/creator/working-hours/{id} */
    public function updateWorkingHour(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        $data = $this->validate($request, [
            'weekday'    => 'nullable|integer|min_value:1|max_value:7',
            'starts_at'  => 'nullable|string|max:8',
            'ends_at'    => 'nullable|string|max:8',
            'is_working' => 'nullable|boolean',
            'note'       => 'nullable|string|max:255',
            'is_visible' => 'nullable|boolean',
        ]);

        $hours = CreatorProfileFieldService::updateWorkingHour($creatorId, $id, $data);

        $this->record($request, 'creator.working_hours_updated', $creatorId, 'Updated a working time range.', ['id' => $id]);

        Response::success(['working_hours' => $hours], 'Saved.');
    }

    /** DELETE /me/creator/working-hours/{id} */
    public function deleteWorkingHour(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        CreatorProfileFieldService::deleteWorkingHour($creatorId, $id);

        $this->record($request, 'creator.working_hours_updated', $creatorId, 'Removed a working time range.', ['id' => $id]);

        Response::success(
            ['working_hours' => CreatorProfileFieldService::workingHours($creatorId, true)],
            'Removed.'
        );
    }

    /**
     * PUT /me/creator/working-hours/{weekday}/order
     *
     * A creator who works mornings and evenings decides which reads first.
     */
    public function reorderWorkingHours(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $weekday   = $request->routeParamInt('weekday');

        $data = $this->validate($request, ['ids' => 'required|array']);

        $hours = CreatorProfileFieldService::reorderWorkingHours(
            $creatorId,
            $weekday,
            array_map('intval', (array) $data['ids'])
        );

        $this->record($request, 'creator.working_hours_reordered', $creatorId, 'Reordered a day of working hours.', [
            'weekday' => $weekday,
        ]);

        Response::success(['working_hours' => $hours], 'Order saved.');
    }

    // =================================================================
    // FAQs
    // =================================================================

    /** GET /me/creator/faqs */
    public function faqs(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        Response::success(['faqs' => CreatorProfileFieldService::faqs($creatorId, true)]);
    }

    /** POST /me/creator/faqs */
    public function createFaq(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, [
            'question'   => 'required|string|min:5|max:300',
            'answer'     => 'required|string|min:2|max:2000',
            'is_visible' => 'nullable|boolean',
        ]);

        $faq = CreatorProfileFieldService::createFaq($creatorId, $data);

        $this->record($request, 'creator.faq_added', $creatorId, 'Added a profile FAQ.', ['id' => $faq['id']]);

        Response::created(['faq' => $faq], 'Added.');
    }

    /** PUT /me/creator/faqs/{id} */
    public function updateFaq(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        $data = $this->validate($request, [
            'question'   => 'nullable|string|min:5|max:300',
            'answer'     => 'nullable|string|min:2|max:2000',
            'is_visible' => 'nullable|boolean',
        ]);

        $faq = CreatorProfileFieldService::updateFaq($creatorId, $id, $data);

        $this->record($request, 'creator.faq_updated', $creatorId, 'Updated a profile FAQ.', ['id' => $id]);

        Response::success(['faq' => $faq], 'Saved.');
    }

    /** DELETE /me/creator/faqs/{id} */
    public function deleteFaq(Request $request): never
    {
        $creatorId = $this->creatorId($request);
        $id        = $request->routeParamInt('id');

        CreatorProfileFieldService::deleteFaq($creatorId, $id);

        $this->record($request, 'creator.faq_deleted', $creatorId, 'Removed a profile FAQ.', ['id' => $id]);

        Response::success(['faqs' => CreatorProfileFieldService::faqs($creatorId, true)], 'Removed.');
    }

    /** PUT /me/creator/faqs/order */
    public function reorderFaqs(Request $request): never
    {
        $creatorId = $this->creatorId($request);

        $data = $this->validate($request, ['ids' => 'required|array']);

        $faqs = CreatorProfileFieldService::reorderFaqs($creatorId, array_map('intval', (array) $data['ids']));

        $this->record($request, 'creator.faqs_reordered', $creatorId, 'Reordered profile FAQs.');

        Response::success(['faqs' => $faqs], 'Order saved.');
    }

    // =================================================================
    // Shared
    // =================================================================

    /**
     * The optional half of a credential submission.
     *
     * `title` is added per-method because it is required on create and optional
     * on update, and everything else is optional in both. `is_verified` is
     * deliberately absent from this list and from the service's payload
     * builder: verification is an administrator's judgement about a document,
     * and a creator who could set it would have a self-service tick.
     */
    private const CREDENTIAL_RULES = [
        'kind'           => 'nullable|string|max:30',
        'issuer'         => 'nullable|string|max:191',
        'field_of_study' => 'nullable|string|max:150',
        'grade'          => 'nullable|string|max:60',
        'credential_id'  => 'nullable|string|max:120',
        'credential_url' => 'nullable|string|max:500',
        'media_url'      => 'nullable|string|max:500',
        'started_on'     => 'nullable|string|max:32',
        'ended_on'       => 'nullable|string|max:32',
        'is_current'     => 'nullable|boolean',
        'description'    => 'nullable|string|max:1000',
        'is_visible'     => 'nullable|boolean',
    ];

    /**
     * The optional half of an experience submission.
     *
     * `booking_id` IS accepted, unlike `is_verified`: pointing at a booking is
     * a claim the platform can check for itself, and the service refuses any
     * booking that is not this creator's and completed. That check is what
     * turns "I worked with them" into something the profile can vouch for.
     */
    private const EXPERIENCE_RULES = [
        'kind'                  => 'nullable|string|max:30',
        'organisation_logo_url' => 'nullable|string|max:500',
        'organisation_url'      => 'nullable|string|max:500',
        'role'                  => 'nullable|string|max:150',
        'location'              => 'nullable|string|max:150',
        'started_on'            => 'nullable|string|max:32',
        'ended_on'              => 'nullable|string|max:32',
        'is_current'            => 'nullable|boolean',
        'description'           => 'nullable|string|max:1000',
        'reference_url'         => 'nullable|string|max:500',
        'booking_id'            => 'nullable|integer',
        'is_featured'           => 'nullable|boolean',
        'is_visible'            => 'nullable|boolean',
    ];

    /**
     * The section named in the path.
     *
     * Read here rather than in each method so an unknown section fails the same
     * way everywhere; the service raises the 422 with the list of valid names,
     * so a client is told what it should have sent.
     */
    private function section(Request $request): string
    {
        $section = trim((string) $request->routeParam('section', ''));

        if ($section === '') {
            throw HttpException::unprocessable('Name the profile section.');
        }

        return $section;
    }

    /**
     * Record the change against the creator, matching what
     * CreatorController::logCreatorChange writes for every other creator edit.
     *
     * The properties carry field names and ids, never values: enough to answer
     * "what did they touch and when" without copying a creator's prose into a
     * second table.
     *
     * @param list<string>|array<string,mixed> $properties
     */
    private function record(
        Request $request,
        string $action,
        int $creatorId,
        string $description,
        array $properties = []
    ): void {
        $user = $request->user();

        if (!is_array($user)) {
            return;
        }

        ActivityLogger::user($user, $action, 'creator', $creatorId, $description, $properties);
    }
}
