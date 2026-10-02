<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;

/**
 * The extended creator profile — everything migration 035 added and nothing
 * ever read or wrote.
 *
 * 035 created five repeating-row tables and eleven scalar columns on
 * `creators`, seeded nine taxonomies behind them, and stopped there. Until this
 * service existed a creator could not record a skill, a degree, a brand they
 * had worked with, the hours they work or a single FAQ: the schema was in the
 * database and the product could not reach it.
 *
 * WHY ONE SERVICE AND NOT FIVE
 *
 * The five tables were deliberately built to one shape — creator_id,
 * sort_order, is_visible, created_at, updated_at — so that one reorder, one
 * visibility rule and one ownership check serve all of them. Splitting them
 * across five services would have meant five copies of that check, and the
 * copies would have drifted: the first table to forget `WHERE creator_id = ?`
 * hands one creator another creator's rows. Here the ownership predicate is
 * written once, in loadOwned().
 *
 * WHAT IS ENFORCED HERE RATHER THAN IN THE CONTROLLER
 *
 * Validation lives in this service, not only in the API controller, because
 * the admin panel and the importer will write these tables too and a rule that
 * only exists in one caller is not a rule. Every string is bounded to its
 * column, every date is a real calendar date in a sane range, and every URL
 * has to be http(s) — VARCHAR(500) truncating silently under a non-strict SQL
 * mode is how half-written URLs end up on a public profile.
 *
 * PROFILE SECTION LOCKS
 *
 * Every write goes through assertUnlocked(). An administrator freezing a
 * section must freeze it everywhere, and a creator whose skills are locked
 * because they were keyword-stuffing must not be able to reach the same terms
 * through a different endpoint.
 */
final class CreatorProfileFieldService
{
    // =================================================================
    // Vocabulary
    // =================================================================

    /**
     * Which lockable profile section governs each taxonomy-driven section, and
     * the API name that section is published under.
     *
     * `sections` is a LIST because two vocabularies exist and they do not yet
     * agree. `profile_section` (seeded by 038) is the finer-grained one and
     * names a `languages` section of its own; CreatorProfileLockService::SECTIONS
     * — the list an operator can actually lock against — has no such entry.
     * Asserting both means the guard is real today under the coarse name AND
     * becomes exact the moment the finer name is added to SECTIONS, with no
     * change here. Asserting a section that does not exist is harmless: no lock
     * row can ever carry it, because lock() refuses to write one.
     *
     * @var array<string,array{key:string,label:string,sections:list<string>}>
     */
    public const TERM_SECTIONS = [
        // 038 puts skill, interest AND profession under one `skills` section.
        // CreatorProfileLockService labels its own `categories` section
        // "Categories & profession", which suggests otherwise — but that
        // section's metadata points at `creator_categories`, a different table
        // that this service does not touch. The newer, table-level mapping wins.
        'skills'         => ['key' => 'skill',        'label' => 'Skills',            'sections' => ['skills']],
        'interests'      => ['key' => 'interest',     'label' => 'Interests',         'sections' => ['skills']],
        'professions'    => ['key' => 'profession',   'label' => 'Professions',       'sections' => ['skills']],
        'languages'      => ['key' => 'language',     'label' => 'Languages',         'sections' => ['languages', 'skills']],
        // Coverage bands and city-level coverage are both "where will you
        // work", which is the location section however it is expressed.
        'service_areas'  => ['key' => 'service_area', 'label' => 'Service areas',     'sections' => ['location']],
        'service_cities' => ['key' => 'city',         'label' => 'Cities served',     'sections' => ['location']],
    ];

    /**
     * The lock sections guarding each repeating-row collection.
     *
     * Same pairing rule as above: the first name is the section this content
     * would be locked under if the vocabulary had one, the second is the
     * section that exists today and covers it.
     *
     *   credentials   -> documents  Certificates, licences and their scans are
     *                               documents; the section is already labelled
     *                               "Documents & media kit".
     *   experience    -> portfolio  A brand collaboration is showcased work,
     *                               and a fabricated "worked with Nike" is
     *                               exactly what an operator freezes.
     *   working hours -> availability  The section is literally called
     *                               "Availability & working hours".
     *   faqs          -> bio        Creator-authored prose about their own work.
     *
     * @var array<string,list<string>>
     */
    private const COLLECTION_SECTIONS = [
        'credentials'   => ['credentials', 'documents'],
        'experience'    => ['experience', 'portfolio'],
        'working_hours' => ['availability'],
        'faqs'          => ['faqs', 'bio'],
    ];

    /**
     * The lock sections guarding each scalar column 035 added to `creators`.
     *
     * `timezone` is guarded by availability first and location second, and the
     * order is the point: the zone is what the weekly working hours are read
     * in, so a creator whose availability is frozen could otherwise shift every
     * advertised hour by moving their zone.
     *
     * @var array<string,list<string>>
     */
    private const DETAIL_SECTIONS = [
        'intro_video_url'           => ['photos'],
        'intro_video_thumbnail_url' => ['photos'],
        'media_kit_url'             => ['documents'],
        'resume_url'                => ['documents'],
        'address_line1'             => ['location'],
        'address_line2'             => ['location'],
        'pin_code'                  => ['location'],
        'address_is_public'         => ['location'],
        'timezone'                  => ['availability', 'location'],
        'career_start_year'         => ['experience', 'categories'],
        'experience_level'          => ['experience', 'categories'],
    ];

    /**
     * ISO-8601 weekday numbering, matching `creator_working_hours`.`weekday`.
     *
     * A constant and not a taxonomy: every other catalogue behind this module
     * is a list an operator may extend, and the week is the one list that will
     * never grow.
     *
     * @var array<int,string>
     */
    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /** The tables this service owns, and the extra column that scopes ordering within each. */
    private const ORDER_SCOPES = [
        'creator_profile_terms' => 'taxonomy_key',
        'creator_credentials'   => null,
        'creator_experience'    => null,
        'creator_working_hours' => 'weekday',
        'creator_faqs'          => null,
    ];

    // =================================================================
    // The whole extended profile
    // =================================================================

    /**
     * Everything this module owns for one creator, in one call.
     *
     * This is the method CreatorService::profile() calls. It is one method and
     * not six because a profile render that made six round trips through six
     * public entry points would eventually make five of them on a page that
     * needed one, and because the caller should not have to know which of these
     * sections exist.
     *
     * $includePrivate is the owner/administrator view. With it false — the
     * public profile — the street address is withheld unless the creator has
     * published it, hidden rows are dropped, and unverified nothing is implied.
     * The address rule is not decoration: a full street address on a public
     * influencer profile is a safety problem, which is why `address_is_public`
     * defaults to 0 and why the default here is the public view.
     *
     * @return array<string,mixed>
     */
    public static function extendedProfile(int $creatorId, bool $includePrivate = false): array
    {
        $creator = self::creator($creatorId);

        return [
            'details'       => self::details($creator, $includePrivate),
            'terms'         => self::termsGrouped($creatorId, $includePrivate),
            'credentials'   => self::credentials($creatorId, ['include_hidden' => $includePrivate]),
            'experience'    => self::experience($creatorId, ['include_hidden' => $includePrivate]),
            'working_hours' => self::workingHours($creatorId, $includePrivate),
            'faqs'          => self::faqs($creatorId, $includePrivate),
        ];
    }

    /**
     * The catalogue side of this module: which sections exist, what they are
     * called, and how many rows a creator may put in each.
     *
     * The apps render the editor from this rather than holding their own list,
     * which is what lets an operator add a taxonomy term — a new skill, a new
     * credential type — and have it appear without a store release.
     *
     * @return array<string,mixed>
     */
    public static function sections(): array
    {
        $terms = [];

        foreach (self::TERM_SECTIONS as $section => $spec) {
            $terms[$section] = [
                'section'      => $section,
                'label'        => $spec['label'],
                'taxonomy_key' => $spec['key'],
                'max'          => self::maxTerms(),
                'options'      => TaxonomyService::terms($spec['key']),
            ];
        }

        return [
            'terms'       => $terms,
            'credentials' => [
                'kinds' => TaxonomyService::terms('credential_type'),
                'max'   => self::limit('max_credentials', 40),
            ],
            'experience'  => [
                'kinds' => TaxonomyService::terms('work_type'),
                'max'   => self::limit('max_experience', 40),
            ],
            'working_hours' => [
                'weekdays'      => self::weekdayOptions(),
                'max_per_day'   => self::limit('max_working_windows_per_day', 4),
            ],
            'faqs' => [
                'max' => self::limit('max_faqs', 30),
            ],
            'experience_levels' => TaxonomyService::terms('experience_level'),
        ];
    }

    // =================================================================
    // 1. Scalar profile fields on `creators`
    // =================================================================

    /**
     * The scalar fields 035 added, presented for the API.
     *
     * `career_start_year` is stored and `years_of_experience` is derived, never
     * the other way round — a stored count of years is wrong from the day after
     * it is entered and nobody ever goes back to correct it.
     *
     * @param  array<string,mixed> $creator A row from `creators`.
     * @return array<string,mixed>
     */
    public static function details(array $creator, bool $includePrivate = false): array
    {
        $addressIsPublic = (int) ($creator['address_is_public'] ?? 0) === 1;
        $showStreet      = $includePrivate || $addressIsPublic;

        $startYear = $creator['career_start_year'] === null ? null : (int) $creator['career_start_year'];

        return [
            'intro_video_url'           => $creator['intro_video_url'] ?? null,
            'intro_video_thumbnail_url' => $creator['intro_video_thumbnail_url'] ?? null,
            'media_kit_url'             => $creator['media_kit_url'] ?? null,

            // The media kit is a marketing document a creator uploads to be
            // read by strangers; a CV is not. A resume conventionally carries
            // the home address, the personal phone number and often a date of
            // birth — the very things the address block two lines below is
            // careful to withhold until the creator publishes them. Handing the
            // whole document to any anonymous caller on
            // GET /creators/{id}/fields would make that care pointless, so the
            // link is owner-and-administrator only. There is no
            // `resume_is_public` column to opt into and the schema is fixed, so
            // the safe answer is the only answer: a creator who wants a CV in
            // public puts it in the media kit, which is what that field is for.
            'resume_url'                => $includePrivate ? ($creator['resume_url'] ?? null) : null,

            'address' => [
                // City, state and country are public on every profile and are
                // repeated here so a client rendering the address block does
                // not have to reach into two payloads to assemble one address.
                'city'      => $creator['city'] ?? null,
                'state'     => $creator['state'] ?? null,
                'country'   => $creator['country'] ?? null,
                'line1'     => $showStreet ? ($creator['address_line1'] ?? null) : null,
                'line2'     => $showStreet ? ($creator['address_line2'] ?? null) : null,
                'pin_code'  => $showStreet ? ($creator['pin_code'] ?? null) : null,
                'is_public' => $addressIsPublic,
            ],

            'timezone' => (string) ($creator['timezone'] ?? 'Asia/Kolkata'),

            'career_start_year'   => $startYear,
            'years_of_experience' => $startYear === null ? null : max(0, (int) date('Y') - $startYear),
            'experience_level'    => $creator['experience_level'] ?? null,
            'experience_level_label' => $creator['experience_level'] === null
                ? null
                : TaxonomyService::label('experience_level', (string) $creator['experience_level']),
        ];
    }

    /**
     * Write the scalar fields.
     *
     * Only keys actually present in $input are touched, so a client editing the
     * address does not have to send the media kit back with it — and a partial
     * form cannot blank a column it never rendered. A key present with an empty
     * value DOES clear the column: that is how a creator removes a resume.
     *
     * Locks are checked per field and only where the value really changes,
     * matching CreatorController::updateProfile. The apps PUT whole forms; a
     * creator saving an unrelated change must not be refused for a locked field
     * they never touched.
     *
     * @param  array<string,mixed> $input
     * @return array<string,mixed> The details as they now stand.
     */
    public static function updateDetails(int $creatorId, array $input): array
    {
        $creator = self::creator($creatorId);
        $updates = [];

        foreach (['intro_video_url', 'intro_video_thumbnail_url', 'media_kit_url', 'resume_url'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = self::url($input, $field, 500);
            }
        }

        foreach (['address_line1' => 255, 'address_line2' => 255] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = self::text($input, $field, $max);
            }
        }

        if (array_key_exists('pin_code', $input)) {
            $pin = self::text($input, 'pin_code', 12);

            // Not `integer`: an Indian PIN can lead with a zero, and the column
            // is VARCHAR precisely so it survives that. Letters and spaces are
            // allowed because `country` is not always India.
            if ($pin !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{2,11}$/', $pin) !== 1) {
                throw HttpException::unprocessable('Enter a valid postal code.');
            }

            $updates['pin_code'] = $pin;
        }

        if (array_key_exists('address_is_public', $input)) {
            $updates['address_is_public'] = self::flag($input, 'address_is_public');
        }

        if (array_key_exists('timezone', $input)) {
            $zone = self::text($input, 'timezone', 64);

            // Checked against the IANA database rather than a list of our own.
            // A zone the server cannot resolve makes every working-hours
            // conversion silently wrong rather than loudly broken, and the
            // column is NOT NULL with a default, so a cleared value means
            // "back to the platform default" rather than NULL.
            if ($zone === null) {
                $zone = 'Asia/Kolkata';
            }

            if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                throw HttpException::unprocessable("Not a recognised time zone: {$zone}");
            }

            $updates['timezone'] = $zone;
        }

        if (array_key_exists('career_start_year', $input)) {
            $year = self::int($input, 'career_start_year');

            if ($year !== null && ($year < 1950 || $year > (int) date('Y'))) {
                throw HttpException::unprocessable(
                    'The year you started must be between 1950 and ' . date('Y') . '.'
                );
            }

            $updates['career_start_year'] = $year;
        }

        if (array_key_exists('experience_level', $input)) {
            $level = self::text($input, 'experience_level', 30);

            // The band drives a discovery filter, so an unknown value would
            // make a creator invisible to a filter they believe they match.
            if ($level !== null && !TaxonomyService::isValid('experience_level', $level)) {
                throw HttpException::unprocessable("Not a recognised experience level: {$level}");
            }

            $updates['experience_level'] = $level;
        }

        foreach ($updates as $field => $value) {
            if ((string) $value !== (string) ($creator[$field] ?? '')) {
                self::assertSections($creatorId, self::DETAIL_SECTIONS[$field] ?? []);
            }
        }

        if ($updates !== []) {
            Database::update('creators', $updates, ['id' => $creatorId]);
        }

        return self::details(self::creator($creatorId), true);
    }

    // =================================================================
    // 2. Taxonomy-driven sections — skills, interests, professions,
    //    languages, service areas, cities
    // =================================================================

    /**
     * One creator's chosen terms, decorated from the taxonomy.
     *
     * The label, icon and colour come from the live taxonomy rather than from
     * the link row, so an operator renaming "Reel making" changes it on every
     * profile at once. A term the operator has since deactivated keeps its row
     * — the creator chose it and may want to swap it out — but is flagged
     * `is_active: false` and is dropped from the public profile.
     *
     * @param  array<string,mixed> $options section, include_hidden
     * @return list<array<string,mixed>>
     */
    public static function terms(int $creatorId, array $options = []): array
    {
        $sql = 'SELECT cpt.`id`, cpt.`taxonomy_key`, cpt.`term_id`, cpt.`is_primary`,
                       cpt.`is_visible`, cpt.`sort_order`,
                       tt.`value`, tt.`label`, tt.`icon`, tt.`color_hex`, tt.`description`,
                       tt.`is_active`
                  FROM `creator_profile_terms` cpt
                  INNER JOIN `taxonomy_terms` tt ON tt.`id` = cpt.`term_id`
                 WHERE cpt.`creator_id` = :creator_id';

        $bindings = ['creator_id' => $creatorId];

        if (!empty($options['section'])) {
            $sql .= ' AND cpt.`taxonomy_key` = :taxonomy_key';
            $bindings['taxonomy_key'] = self::taxonomyKey((string) $options['section']);
        }

        if (empty($options['include_hidden'])) {
            $sql .= ' AND cpt.`is_visible` = 1 AND tt.`is_active` = 1';
        }

        // is_primary first: the profession that heads the profile and the skill
        // that leads the card come before the creator's arrangement of the rest.
        $sql .= ' ORDER BY cpt.`taxonomy_key`, cpt.`is_primary` DESC, cpt.`sort_order`, cpt.`id`';

        $out = [];

        foreach (Database::select($sql, $bindings) as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'section'      => self::sectionFor((string) $row['taxonomy_key']),
                'taxonomy_key' => (string) $row['taxonomy_key'],
                'value'        => (string) $row['value'],
                'label'        => (string) $row['label'],
                'description'  => $row['description'],
                'icon'         => $row['icon'],
                'color'        => $row['color_hex'],
                'is_primary'   => (int) $row['is_primary'] === 1,
                'is_visible'   => (int) $row['is_visible'] === 1,
                'is_active'    => (int) $row['is_active'] === 1,
                'sort_order'   => (int) $row['sort_order'],
            ];
        }

        return $out;
    }

    /**
     * The same rows keyed by the API section name, with every known section
     * present even when it is empty.
     *
     * Always emitting the empty sections is deliberate: a client that has to
     * distinguish "no skills" from "the skills key was not in the payload"
     * ends up guessing, and guesses render a blank block instead of an
     * "add your skills" prompt.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function termsGrouped(int $creatorId, bool $includeHidden = false): array
    {
        $grouped = array_fill_keys(array_keys(self::TERM_SECTIONS), []);

        foreach (self::terms($creatorId, ['include_hidden' => $includeHidden]) as $term) {
            // A link row whose taxonomy_key is not one of ours — nothing writes
            // one today, but a future section would — is passed through under
            // its own key rather than dropped on the floor.
            $grouped[$term['section']][] = $term;
        }

        return $grouped;
    }

    /**
     * Add one term to a section.
     *
     * @param  array<string,mixed> $options is_primary, is_visible, sort_order
     * @return array<string,mixed> The stored, decorated row.
     */
    public static function attachTerm(int $creatorId, string $section, string $value, array $options = []): array
    {
        $spec = self::termSection($section);
        $key  = $spec['key'];

        self::creator($creatorId);
        self::assertSections($creatorId, $spec['sections']);

        $termId = self::resolveTermId($key, $value, true);

        $existing = Database::selectOne(
            'SELECT `id` FROM `creator_profile_terms` WHERE `creator_id` = :c AND `term_id` = :t',
            ['c' => $creatorId, 't' => $termId]
        );

        if ($existing === null) {
            self::assertUnderLimit(
                'creator_profile_terms',
                $creatorId,
                self::maxTerms(),
                $spec['label'],
                ['taxonomy_key' => $key]
            );

            Database::insert('creator_profile_terms', [
                'creator_id'   => $creatorId,
                'taxonomy_key' => $key,
                'term_id'      => $termId,
                'is_primary'   => self::flag($options, 'is_primary'),
                'is_visible'   => self::flag($options, 'is_visible', 1),
                'sort_order'   => isset($options['sort_order'])
                    ? (int) $options['sort_order']
                    : self::nextSortOrder('creator_profile_terms', $creatorId, ['taxonomy_key' => $key]),
            ]);
        } else {
            // Re-attaching an existing choice updates its flags rather than
            // failing on uq_cpt_creator_term. A client that cannot tell whether
            // a term is already chosen — an importer, a retried request — then
            // behaves the same either way.
            $update = ['taxonomy_key' => $key];

            if (array_key_exists('is_primary', $options)) {
                $update['is_primary'] = self::flag($options, 'is_primary');
            }

            if (array_key_exists('is_visible', $options)) {
                $update['is_visible'] = self::flag($options, 'is_visible', 1);
            }

            Database::update('creator_profile_terms', $update, ['id' => (int) $existing['id']]);
        }

        if (self::flag($options, 'is_primary') === 1) {
            self::demoteOtherPrimaries($creatorId, $key, $termId);
        }

        self::afterTermChange($creatorId, $key);

        return self::termRow($creatorId, $termId);
    }

    /**
     * Replace a section's terms with exactly this list, in this order.
     *
     * This is what a multi-select actually does, and doing it as one call
     * rather than a diff of attach/detach requests is what makes it atomic: a
     * client that dropped a connection halfway through a diff would leave a
     * creator with a section that is neither the old set nor the new one.
     *
     * @param  list<string> $values
     * @return list<array<string,mixed>>
     */
    public static function syncTerms(int $creatorId, string $section, array $values): array
    {
        $spec = self::termSection($section);
        $key  = $spec['key'];

        self::creator($creatorId);
        self::assertSections($creatorId, $spec['sections']);

        // Order is meaningful and duplicates are not, so the first mention of a
        // value wins its position and later mentions are dropped.
        $wanted = [];

        foreach (array_values($values) as $index => $value) {
            $value = self::termValue($value, $index);

            if ($value !== '' && !in_array($value, $wanted, true)) {
                $wanted[] = $value;
            }
        }

        $max = self::maxTerms();

        if (count($wanted) > $max) {
            throw HttpException::unprocessable(
                sprintf('%s: choose at most %d.', $spec['label'], $max)
            );
        }

        $termIds = [];

        foreach ($wanted as $value) {
            $termIds[] = self::resolveTermId($key, $value, true);
        }

        Database::transaction(static function () use ($creatorId, $key, $termIds): void {
            if ($termIds === []) {
                Database::execute(
                    'DELETE FROM `creator_profile_terms` WHERE `creator_id` = :c AND `taxonomy_key` = :k',
                    ['c' => $creatorId, 'k' => $key]
                );

                return;
            }

            // Named placeholders, not Database::inClause(): that helper emits
            // positional `?` markers, and PDO refuses a statement that mixes
            // positional and named binding — which the creator_id and
            // taxonomy_key conditions here are.
            $placeholders = [];
            $bindings     = ['c' => $creatorId, 'k' => $key];

            foreach (array_values($termIds) as $index => $termId) {
                $placeholders[]             = ':term' . $index;
                $bindings['term' . $index]  = $termId;
            }

            // Rows for terms that are no longer wanted go first, so the
            // re-insert below cannot collide with one about to be removed.
            Database::execute(
                'DELETE FROM `creator_profile_terms`
                  WHERE `creator_id` = :c AND `taxonomy_key` = :k
                    AND `term_id` NOT IN (' . implode(', ', $placeholders) . ')',
                $bindings
            );

            foreach ($termIds as $position => $termId) {
                Database::execute(
                    'INSERT INTO `creator_profile_terms`
                        (`creator_id`, `taxonomy_key`, `term_id`, `sort_order`)
                     VALUES (:c, :k, :t, :sort)
                     ON DUPLICATE KEY UPDATE `taxonomy_key` = VALUES(`taxonomy_key`), `sort_order` = VALUES(`sort_order`)',
                    ['c' => $creatorId, 'k' => $key, 't' => $termId, 'sort' => $position + 1]
                );
            }
        });

        self::afterTermChange($creatorId, $key);

        return self::terms($creatorId, ['section' => $spec['section'], 'include_hidden' => true]);
    }

    /** Remove one term from a section. */
    public static function detachTerm(int $creatorId, string $section, string $value): void
    {
        $spec = self::termSection($section);
        $key  = $spec['key'];

        self::creator($creatorId);
        self::assertSections($creatorId, $spec['sections']);

        // Deliberately resolved WITHOUT the active check that attach applies: a
        // term the operator has since retired is still on the profile and the
        // creator must be able to take it off.
        $termId = self::resolveTermId($key, $value, false);

        $removed = Database::execute(
            'DELETE FROM `creator_profile_terms`
              WHERE `creator_id` = :c AND `taxonomy_key` = :k AND `term_id` = :t',
            ['c' => $creatorId, 'k' => $key, 't' => $termId]
        );

        if ($removed === 0) {
            throw HttpException::notFound('That is not on your profile.');
        }

        self::afterTermChange($creatorId, $key);
    }

    /**
     * Arrange a section. Values not sent keep their relative order and follow
     * the ones that were.
     *
     * @param  list<string> $values
     * @return list<array<string,mixed>>
     */
    public static function reorderTerms(int $creatorId, string $section, array $values): array
    {
        $spec = self::termSection($section);
        $key  = $spec['key'];

        self::creator($creatorId);
        self::assertSections($creatorId, $spec['sections']);

        $ids      = [];
        $submitted = 0;

        foreach (array_values($values) as $index => $value) {
            $value = self::termValue($value, $index);

            if ($value === '') {
                continue;
            }

            $submitted++;

            $row = Database::selectOne(
                'SELECT cpt.`id`
                   FROM `creator_profile_terms` cpt
                   INNER JOIN `taxonomy_terms` tt ON tt.`id` = cpt.`term_id`
                  WHERE cpt.`creator_id` = :c AND cpt.`taxonomy_key` = :k AND tt.`value` = :v',
                ['c' => $creatorId, 'k' => $key, 'v' => $value]
            );

            if ($row !== null) {
                $ids[] = (int) $row['id'];
            }
        }

        // This is the one reorder endpoint that takes term VALUES rather than
        // row ids — a client arranging a multi-select holds the values it just
        // sent, not the link-row ids it never saw. applyOrder() below phrases
        // both of its empty cases in terms of ids, which on this endpoint names
        // something the caller was never given and cannot send. The two cases
        // are told apart because they need different answers: nothing usable
        // was sent, versus what was sent is not on this profile.
        if ($ids === []) {
            throw HttpException::unprocessable(
                $submitted === 0
                    ? 'Send the term values in the order you want them.'
                    : 'None of those are on your profile. Send the term values you already have, in the order you want them.'
            );
        }

        self::applyOrder('creator_profile_terms', $creatorId, $ids, ['taxonomy_key' => $key]);

        self::afterTermChange($creatorId, $key);

        return self::terms($creatorId, ['section' => $spec['section'], 'include_hidden' => true]);
    }

    /**
     * Nominate the term that heads a section — the lead profession, the
     * headline skill. Passing null clears the nomination.
     */
    public static function setPrimaryTerm(int $creatorId, string $section, ?string $value): array
    {
        $spec = self::termSection($section);
        $key  = $spec['key'];

        self::creator($creatorId);
        self::assertSections($creatorId, $spec['sections']);

        if ($value === null || trim($value) === '') {
            Database::execute(
                'UPDATE `creator_profile_terms` SET `is_primary` = 0
                  WHERE `creator_id` = :c AND `taxonomy_key` = :k',
                ['c' => $creatorId, 'k' => $key]
            );

            return self::terms($creatorId, ['section' => $spec['section'], 'include_hidden' => true]);
        }

        $termId = self::resolveTermId($key, trim($value), false);

        $owned = Database::selectOne(
            'SELECT `id` FROM `creator_profile_terms`
              WHERE `creator_id` = :c AND `taxonomy_key` = :k AND `term_id` = :t',
            ['c' => $creatorId, 'k' => $key, 't' => $termId]
        );

        if ($owned === null) {
            throw HttpException::unprocessable('Add it to your profile before making it the primary one.');
        }

        Database::transaction(static function () use ($creatorId, $key, $termId): void {
            Database::execute(
                'UPDATE `creator_profile_terms` SET `is_primary` = 0
                  WHERE `creator_id` = :c AND `taxonomy_key` = :k',
                ['c' => $creatorId, 'k' => $key]
            );

            Database::execute(
                'UPDATE `creator_profile_terms` SET `is_primary` = 1
                  WHERE `creator_id` = :c AND `taxonomy_key` = :k AND `term_id` = :t',
                ['c' => $creatorId, 'k' => $key, 't' => $termId]
            );
        });

        self::afterTermChange($creatorId, $key);

        return self::terms($creatorId, ['section' => $spec['section'], 'include_hidden' => true]);
    }

    // =================================================================
    // 3. Credentials — education, certifications, awards
    // =================================================================

    /**
     * A creator's credentials, decorated with their kind.
     *
     * `date_label` and `fields` come off the credential_type term's metadata,
     * so a client renders "Studied" for a degree and "Issued" for a
     * certificate, and shows the education-only fields only for education,
     * without holding a switch statement over kinds it would have to reship
     * every time an operator adds one.
     *
     * @param  array<string,mixed> $options kind, include_hidden, verified_only
     * @return list<array<string,mixed>>
     */
    public static function credentials(int $creatorId, array $options = []): array
    {
        $sql      = 'SELECT * FROM `creator_credentials` WHERE `creator_id` = :creator_id';
        $bindings = ['creator_id' => $creatorId];

        if (!empty($options['kind'])) {
            $sql .= ' AND `kind` = :kind';
            $bindings['kind'] = (string) $options['kind'];
        }

        if (empty($options['include_hidden'])) {
            $sql .= ' AND `is_visible` = 1';
        }

        if (!empty($options['verified_only'])) {
            $sql .= ' AND `is_verified` = 1';
        }

        $sql .= ' ORDER BY `sort_order`, `id`';

        $kinds = self::keyedTerms('credential_type');
        $out   = [];

        foreach (Database::select($sql, $bindings) as $row) {
            $out[] = self::presentCredential($row, $kinds);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function createCredential(int $creatorId, array $input): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['credentials']);
        self::assertUnderLimit(
            'creator_credentials',
            $creatorId,
            self::limit('max_credentials', 40),
            'Credentials'
        );

        $data = self::credentialPayload($input, null);

        $data['creator_id'] = $creatorId;
        $data['sort_order'] = self::nextSortOrder('creator_credentials', $creatorId);

        $id = Database::insert('creator_credentials', $data);

        return self::credential($creatorId, $id);
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function updateCredential(int $creatorId, int $id, array $input): array
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['credentials']);

        $existing = self::loadOwned('creator_credentials', $creatorId, $id, 'Credential not found.');

        $data = self::credentialPayload($input, $existing);

        if ($data === []) {
            return self::presentCredential($existing, self::keyedTerms('credential_type'));
        }

        // Editing the substance of a checked credential withdraws the check.
        //
        // Without this an administrator's tick is a permanent asset: get
        // "Diploma, Local Institute" verified, then rename it to "MBA, IIM
        // Ahmedabad" and keep the tick. The verifier and the date go with it,
        // because a verification record that no longer describes anything is
        // worse than none — it cannot be defended when someone asks what was
        // actually checked.
        $substantive = ['title', 'issuer', 'credential_id', 'credential_url', 'started_on', 'ended_on', 'field_of_study'];

        foreach ($substantive as $field) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) ($existing[$field] ?? '')) {
                $data['is_verified'] = 0;
                $data['verified_at'] = null;
                $data['verified_by'] = null;
                break;
            }
        }

        Database::update('creator_credentials', $data, ['id' => $id, 'creator_id' => $creatorId]);

        return self::credential($creatorId, $id);
    }

    public static function deleteCredential(int $creatorId, int $id): void
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['credentials']);
        self::loadOwned('creator_credentials', $creatorId, $id, 'Credential not found.');

        Database::delete('creator_credentials', ['id' => $id, 'creator_id' => $creatorId]);
    }

    /**
     * @param  list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function reorderCredentials(int $creatorId, array $ids): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['credentials']);
        self::applyOrder('creator_credentials', $creatorId, $ids);

        return self::credentials($creatorId, ['include_hidden' => true]);
    }

    // =================================================================
    // 4. Experience — work history and brand collaborations
    // =================================================================

    /**
     * A creator's work history and collaborations.
     *
     * Each row carries `section` from its work_type term's metadata —
     * "collaborations" or "work_history" — which is how one table renders as
     * the two blocks the profile shows without any client knowing which kinds
     * belong to which.
     *
     * @param  array<string,mixed> $options kind, section, featured_only, include_hidden
     * @return list<array<string,mixed>>
     */
    public static function experience(int $creatorId, array $options = []): array
    {
        $sql      = 'SELECT * FROM `creator_experience` WHERE `creator_id` = :creator_id';
        $bindings = ['creator_id' => $creatorId];

        if (!empty($options['kind'])) {
            $sql .= ' AND `kind` = :kind';
            $bindings['kind'] = (string) $options['kind'];
        }

        if (!empty($options['featured_only'])) {
            $sql .= ' AND `is_featured` = 1';
        }

        if (empty($options['include_hidden'])) {
            $sql .= ' AND `is_visible` = 1';
        }

        $sql .= ' ORDER BY `sort_order`, `id`';

        $kinds = self::keyedTerms('work_type');
        $out   = [];

        foreach (Database::select($sql, $bindings) as $row) {
            $item = self::presentExperience($row, $kinds);

            if (!empty($options['section']) && $item['section'] !== $options['section']) {
                continue;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function createExperience(int $creatorId, array $input): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['experience']);
        self::assertUnderLimit(
            'creator_experience',
            $creatorId,
            self::limit('max_experience', 40),
            'Work history'
        );

        $data = self::experiencePayload($input, null, $creatorId);

        $data['creator_id'] = $creatorId;
        $data['sort_order'] = self::nextSortOrder('creator_experience', $creatorId);

        $id = Database::insert('creator_experience', $data);

        return self::experienceRow($creatorId, $id);
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function updateExperience(int $creatorId, int $id, array $input): array
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['experience']);

        $existing = self::loadOwned('creator_experience', $creatorId, $id, 'Experience entry not found.');

        $data = self::experiencePayload($input, $existing, $creatorId);

        if ($data === []) {
            return self::presentExperience($existing, self::keyedTerms('work_type'));
        }

        // Same rule as credentials, with the booking link included: a row that
        // the platform vouched for because it came from a real booking stops
        // being that row once the organisation or the dates are rewritten, so
        // both the tick and the link to the booking go.
        //
        // The test is whether this call actually RE-LINKS the row — points it
        // at a different booking, or clears it — and NOT merely whether the
        // payload mentions booking_id. The apps PUT whole forms, so an
        // unchanged booking_id is re-sent on every single save; keying off its
        // presence meant the loop below never ran for a real client, and a
        // verified row could be rewritten field by field while keeping its
        // tick. experiencePayload() has already re-derived is_verified for a
        // row that genuinely was relinked, so those are the only ones skipped.
        $relinked = array_key_exists('booking_id', $data)
            && (string) ($data['booking_id'] ?? '') !== (string) ($existing['booking_id'] ?? '');

        if (!$relinked) {
            $substantive = ['organisation', 'role', 'started_on', 'ended_on', 'is_current'];

            foreach ($substantive as $field) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) ($existing[$field] ?? '')) {
                    $data['is_verified'] = 0;
                    $data['booking_id']  = null;
                    break;
                }
            }
        }

        Database::update('creator_experience', $data, ['id' => $id, 'creator_id' => $creatorId]);

        return self::experienceRow($creatorId, $id);
    }

    public static function deleteExperience(int $creatorId, int $id): void
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['experience']);
        self::loadOwned('creator_experience', $creatorId, $id, 'Experience entry not found.');

        Database::delete('creator_experience', ['id' => $id, 'creator_id' => $creatorId]);
    }

    /**
     * @param  list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function reorderExperience(int $creatorId, array $ids): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['experience']);
        self::applyOrder('creator_experience', $creatorId, $ids);

        return self::experience($creatorId, ['include_hidden' => true]);
    }

    // =================================================================
    // 5. Working hours — the weekly recurring schedule
    // =================================================================

    /**
     * The week, always all seven days.
     *
     * A day with no rows is `is_set: false`, which is NOT the same answer as a
     * day with one row carrying is_working = 0. "Closed on Sunday" and "has not
     * filled in Sunday yet" render differently and a missing row cannot tell
     * them apart, which is exactly why 035 stores a day off as a row.
     *
     * The times are wall-clock in the creator's own zone and are returned with
     * it, because 10:00 means nothing to a brand in another zone without it.
     *
     * @return array<string,mixed>
     */
    public static function workingHours(int $creatorId, bool $includeHidden = false): array
    {
        $creator = self::creator($creatorId);

        $sql = 'SELECT * FROM `creator_working_hours` WHERE `creator_id` = :creator_id';

        if (!$includeHidden) {
            $sql .= ' AND `is_visible` = 1';
        }

        $sql .= ' ORDER BY `weekday`, `sort_order`, `starts_at`, `id`';

        $byDay = [];

        foreach (Database::select($sql, ['creator_id' => $creatorId]) as $row) {
            $byDay[(int) $row['weekday']][] = $row;
        }

        $week = [];

        foreach (self::WEEKDAYS as $weekday => $label) {
            $rows  = $byDay[$weekday] ?? [];
            $slots = [];

            foreach ($rows as $row) {
                $slots[] = [
                    'id'         => (int) $row['id'],
                    'starts_at'  => substr((string) $row['starts_at'], 0, 5),
                    'ends_at'    => substr((string) $row['ends_at'], 0, 5),
                    'is_working' => (int) $row['is_working'] === 1,
                    'note'       => $row['note'],
                    'is_visible' => (int) $row['is_visible'] === 1,
                    'sort_order' => (int) $row['sort_order'],
                ];
            }

            $working = array_values(array_filter($slots, static fn (array $s): bool => $s['is_working']));

            $week[] = [
                'weekday'     => $weekday,
                'label'       => $label,
                'short_label' => substr($label, 0, 3),
                'is_set'      => $rows !== [],
                'is_working'  => $working !== [],
                'slots'       => $slots,
            ];
        }

        return [
            'timezone' => (string) ($creator['timezone'] ?? 'Asia/Kolkata'),
            'week'     => $week,
        ];
    }

    /**
     * Replace the whole weekly schedule.
     *
     * The editor is a seven-row grid, so this is the shape the product actually
     * saves in. Replacing rather than diffing keeps the write atomic, and the
     * UNIQUE (creator_id, weekday, starts_at) means a re-submitted schedule is
     * idempotent rather than doubled.
     *
     * @param  list<array<string,mixed>> $days [{weekday, is_working, slots:[{starts_at, ends_at, note, is_visible}]}]
     * @return array<string,mixed>
     */
    public static function setWorkingHours(int $creatorId, array $days): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['working_hours']);

        $maxPerDay = self::limit('max_working_windows_per_day', 4);
        $prepared  = [];

        foreach ($days as $day) {
            if (!is_array($day)) {
                throw HttpException::unprocessable('Each day must be an object with a weekday and its slots.');
            }

            $weekday = self::weekday($day);

            if (isset($prepared[$weekday])) {
                throw HttpException::unprocessable(
                    self::WEEKDAYS[$weekday] . ' appears twice; send each day once.'
                );
            }

            $isWorking = self::flag($day, 'is_working', 1);
            $slots     = [];

            if ($isWorking === 1) {
                $raw = $day['slots'] ?? [];

                if (!is_array($raw)) {
                    throw HttpException::unprocessable('Slots must be a list of time ranges.');
                }

                // A working day with no ranges given falls back to the column
                // defaults rather than silently becoming a day off — the
                // creator said they work, and 035 chose those defaults for
                // exactly this case.
                if ($raw === []) {
                    $raw = [['starts_at' => '10:00', 'ends_at' => '18:00']];
                }

                if (count($raw) > $maxPerDay) {
                    throw HttpException::unprocessable(
                        sprintf('At most %d time ranges per day.', $maxPerDay)
                    );
                }

                foreach ($raw as $slot) {
                    if (!is_array($slot)) {
                        throw HttpException::unprocessable('Each slot must be an object with a start and an end.');
                    }

                    $startsAt = self::time($slot, 'starts_at', '10:00:00');
                    $endsAt   = self::time($slot, 'ends_at', '18:00:00');

                    // The same rule workingHourPayload() applies to a single
                    // window, repeated here because this path does not go
                    // through it: a range that wraps past midnight is two
                    // ranges, and stored as one row it compares wrong in every
                    // overlap test and every "are you free at 23:00" question.
                    if ($endsAt <= $startsAt) {
                        throw HttpException::unprocessable(sprintf(
                            'Your %s range ends before it starts. A range that runs past midnight has to be split in two.',
                            self::WEEKDAYS[$weekday]
                        ));
                    }

                    $slots[] = [
                        'is_working' => 1,
                        'starts_at'  => $startsAt,
                        'ends_at'    => $endsAt,
                        'note'       => self::text($slot, 'note', 255),
                        'is_visible' => self::flag($slot, 'is_visible', 1),
                    ];
                }

                self::assertNoOverlap($slots, $weekday);
            } else {
                // The day off. One row, is_working = 0, so the answer is
                // recorded rather than merely absent.
                $slots[] = [
                    'is_working' => 0,
                    'starts_at'  => '00:00:00',
                    'ends_at'    => '00:00:00',
                    'note'       => self::text($day, 'note', 255),
                    'is_visible' => self::flag($day, 'is_visible', 1),
                ];
            }

            $prepared[$weekday] = $slots;
        }

        Database::transaction(static function () use ($creatorId, $prepared): void {
            foreach ($prepared as $weekday => $slots) {
                Database::execute(
                    'DELETE FROM `creator_working_hours` WHERE `creator_id` = :c AND `weekday` = :w',
                    ['c' => $creatorId, 'w' => $weekday]
                );

                foreach ($slots as $position => $slot) {
                    Database::insert('creator_working_hours', [
                        'creator_id' => $creatorId,
                        'weekday'    => $weekday,
                        'is_working' => $slot['is_working'],
                        'starts_at'  => $slot['starts_at'],
                        'ends_at'    => $slot['ends_at'],
                        'note'       => $slot['note'],
                        'is_visible' => $slot['is_visible'],
                        'sort_order' => $position + 1,
                    ]);
                }
            }
        });

        return self::workingHours($creatorId, true);
    }

    /**
     * Add one window to one day, leaving the rest of the week alone.
     *
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function addWorkingHour(int $creatorId, array $input): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['working_hours']);

        $weekday = self::weekday($input);
        $data    = self::workingHourPayload($input, null);

        $isWorking = (int) $data['is_working'];

        // "Closed on Sunday" and "I work 10-12 on Sunday" cannot both be true.
        // setWorkingHours() never meets this because it replaces the whole day;
        // this endpoint adds to whatever is there, so the contradiction has to
        // be resolved explicitly. It is resolved in the direction the caller
        // asked for: adding a WINDOW clears the day-off marker, while adding a
        // marker to a day that already has windows is refused rather than
        // silently deleting hours the creator entered.
        if ($isWorking === 0) {
            $working = (int) Database::scalar(
                'SELECT COUNT(*) FROM `creator_working_hours`
                  WHERE `creator_id` = :c AND `weekday` = :w AND `is_working` = 1',
                ['c' => $creatorId, 'w' => $weekday]
            );

            if ($working > 0) {
                throw HttpException::unprocessable(sprintf(
                    'You already have time ranges on %s. Remove them, or use the weekly schedule to mark the day off.',
                    self::WEEKDAYS[$weekday]
                ));
            }
        }

        self::assertDayHasRoom($creatorId, $weekday, null);

        self::assertNoStoredOverlap($creatorId, $weekday, $data['starts_at'], $data['ends_at'], null);

        self::assertStartIsFree($creatorId, $weekday, (string) $data['starts_at'], null);

        $data['creator_id'] = $creatorId;
        $data['weekday']    = $weekday;

        // The clear and the insert are one write: a delete that survived a
        // failed insert would leave the creator's Sunday neither closed nor
        // open, which is the one state the schema exists to rule out.
        Database::transaction(static function () use ($creatorId, $weekday, $isWorking, $data): void {
            if ($isWorking === 1) {
                self::clearDayOff($creatorId, $weekday, null);
            }

            $data['sort_order'] = self::nextSortOrder('creator_working_hours', $creatorId, ['weekday' => $weekday]);

            Database::insert('creator_working_hours', $data);
        });

        return self::workingHours($creatorId, true);
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function updateWorkingHour(int $creatorId, int $id, array $input): array
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['working_hours']);

        $existing = self::loadOwned('creator_working_hours', $creatorId, $id, 'That time range is not on your schedule.');

        $data    = self::workingHourPayload($input, $existing);
        $weekday = array_key_exists('weekday', $input) ? self::weekday($input) : (int) $existing['weekday'];
        $moving  = $weekday !== (int) $existing['weekday'];

        if ($moving) {
            $data['weekday'] = $weekday;
        }

        $starts    = (string) ($data['starts_at'] ?? $existing['starts_at']);
        $ends      = (string) ($data['ends_at'] ?? $existing['ends_at']);
        $isWorking = (int) ($data['is_working'] ?? $existing['is_working']);

        if ($ends <= $starts && $isWorking === 1) {
            throw HttpException::unprocessable('The end time must be after the start time.');
        }

        // The same contradiction addWorkingHour() refuses, reached from the
        // other side: turning a window into a day-off marker while the day
        // still holds other windows would say the creator is both closed and
        // working. Refused rather than resolved, because resolving it means
        // deleting hours the creator entered on a request that only mentioned
        // one row.
        if ($isWorking === 0) {
            $others = (int) Database::scalar(
                'SELECT COUNT(*) FROM `creator_working_hours`
                  WHERE `creator_id` = :c AND `weekday` = :w AND `is_working` = 1 AND `id` <> :id',
                ['c' => $creatorId, 'w' => $weekday, 'id' => $id]
            );

            if ($others > 0) {
                throw HttpException::unprocessable(sprintf(
                    'You still have other time ranges on %s. Remove them, or use the weekly schedule to mark the day off.',
                    self::WEEKDAYS[$weekday]
                ));
            }
        }

        self::assertNoStoredOverlap($creatorId, $weekday, $starts, $ends, $id);
        self::assertStartIsFree($creatorId, $weekday, $starts, $id);

        if ($moving) {
            // A window that moves is a NEW window as far as the day it lands on
            // is concerned, and both of the properties that make a day coherent
            // are per-day. Without the cap check, "move it to Monday" is a way
            // to put five windows on a day whose limit is four — the same
            // request addWorkingHour() refuses. Without a fresh position it
            // keeps the number it held on its old day, which collides with one
            // of the destination's: two rows sharing a sort_order make that
            // day's ORDER BY weekday, sort_order, starts_at, id fall through to
            // ids, so the creator's arrangement quietly stops being the one
            // that renders. $id is excluded from both counts because the row is
            // still filed under its old weekday until this update lands.
            self::assertDayHasRoom($creatorId, $weekday, $id);

            $data['sort_order'] = self::nextSortOrder('creator_working_hours', $creatorId, ['weekday' => $weekday]);
        }

        if ($data !== []) {
            Database::transaction(static function () use ($creatorId, $id, $weekday, $isWorking, $data): void {
                if ($isWorking === 1) {
                    // A working window landing on a day that was marked closed
                    // — by moving days, or by this row itself being flipped
                    // back on — leaves the same phantom slot addWorkingHour()
                    // clears. The row being written is excluded: when it IS the
                    // marker, deleting it would delete what we are updating.
                    self::clearDayOff($creatorId, $weekday, $id);
                }

                Database::update('creator_working_hours', $data, ['id' => $id, 'creator_id' => $creatorId]);
            });
        }

        return self::workingHours($creatorId, true);
    }

    public static function deleteWorkingHour(int $creatorId, int $id): void
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['working_hours']);
        self::loadOwned('creator_working_hours', $creatorId, $id, 'That time range is not on your schedule.');

        Database::delete('creator_working_hours', ['id' => $id, 'creator_id' => $creatorId]);
    }

    /**
     * Arrange the windows within one day — a creator who works mornings and
     * evenings decides which reads first.
     *
     * @param  list<int> $ids
     * @return array<string,mixed>
     */
    public static function reorderWorkingHours(int $creatorId, int $weekday, array $ids): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['working_hours']);

        if (!isset(self::WEEKDAYS[$weekday])) {
            throw HttpException::unprocessable('Weekday must be 1 (Monday) through 7 (Sunday).');
        }

        self::applyOrder('creator_working_hours', $creatorId, $ids, ['weekday' => $weekday]);

        return self::workingHours($creatorId, true);
    }

    // =================================================================
    // 6. FAQs
    // =================================================================

    /** @return list<array<string,mixed>> */
    public static function faqs(int $creatorId, bool $includeHidden = false): array
    {
        $sql = 'SELECT * FROM `creator_faqs` WHERE `creator_id` = :creator_id';

        if (!$includeHidden) {
            $sql .= ' AND `is_visible` = 1';
        }

        $sql .= ' ORDER BY `sort_order`, `id`';

        $out = [];

        foreach (Database::select($sql, ['creator_id' => $creatorId]) as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'question'   => (string) $row['question'],
                'answer'     => (string) $row['answer'],
                'is_visible' => (int) $row['is_visible'] === 1,
                'sort_order' => (int) $row['sort_order'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function createFaq(int $creatorId, array $input): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['faqs']);
        self::assertUnderLimit('creator_faqs', $creatorId, self::limit('max_faqs', 30), 'FAQs');

        $data = self::faqPayload($input, null);

        self::assertQuestionIsNew($creatorId, (string) $data['question'], null);

        $data['creator_id'] = $creatorId;
        $data['sort_order'] = self::nextSortOrder('creator_faqs', $creatorId);

        $id = Database::insert('creator_faqs', $data);

        return self::faq($creatorId, $id);
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function updateFaq(int $creatorId, int $id, array $input): array
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['faqs']);

        $existing = self::loadOwned('creator_faqs', $creatorId, $id, 'FAQ not found.');

        $data = self::faqPayload($input, $existing);

        if (isset($data['question'])) {
            self::assertQuestionIsNew($creatorId, (string) $data['question'], $id);
        }

        if ($data !== []) {
            Database::update('creator_faqs', $data, ['id' => $id, 'creator_id' => $creatorId]);
        }

        return self::faq($creatorId, $id);
    }

    public static function deleteFaq(int $creatorId, int $id): void
    {
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['faqs']);
        self::loadOwned('creator_faqs', $creatorId, $id, 'FAQ not found.');

        Database::delete('creator_faqs', ['id' => $id, 'creator_id' => $creatorId]);
    }

    /**
     * @param  list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function reorderFaqs(int $creatorId, array $ids): array
    {
        self::creator($creatorId);
        self::assertSections($creatorId, self::COLLECTION_SECTIONS['faqs']);
        self::applyOrder('creator_faqs', $creatorId, $ids);

        return self::faqs($creatorId, true);
    }

    // =================================================================
    // Single-row reads
    // =================================================================

    /** @return array<string,mixed> */
    public static function credential(int $creatorId, int $id): array
    {
        return self::presentCredential(
            self::loadOwned('creator_credentials', $creatorId, $id, 'Credential not found.'),
            self::keyedTerms('credential_type')
        );
    }

    /** @return array<string,mixed> */
    public static function experienceRow(int $creatorId, int $id): array
    {
        return self::presentExperience(
            self::loadOwned('creator_experience', $creatorId, $id, 'Experience entry not found.'),
            self::keyedTerms('work_type')
        );
    }

    /** @return array<string,mixed> */
    public static function faq(int $creatorId, int $id): array
    {
        $row = self::loadOwned('creator_faqs', $creatorId, $id, 'FAQ not found.');

        return [
            'id'         => (int) $row['id'],
            'question'   => (string) $row['question'],
            'answer'     => (string) $row['answer'],
            'is_visible' => (int) $row['is_visible'] === 1,
            'sort_order' => (int) $row['sort_order'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    // =================================================================
    // Presentation
    // =================================================================

    /**
     * @param  array<string,mixed> $row
     * @param  array<string,array<string,mixed>> $kinds
     * @return array<string,mixed>
     */
    private static function presentCredential(array $row, array $kinds): array
    {
        $kind = $kinds[(string) $row['kind']] ?? [];
        $meta = is_array($kind['metadata'] ?? null) ? $kind['metadata'] : [];

        return [
            'id'             => (int) $row['id'],
            'kind'           => (string) $row['kind'],
            'kind_label'     => (string) ($kind['label'] ?? TaxonomyService::label('credential_type', (string) $row['kind'])),
            'kind_icon'      => $kind['icon'] ?? 'workspace_premium',
            'kind_color'     => $kind['color'] ?? null,
            'date_label'     => $meta['date_label'] ?? null,
            'fields'         => $meta['fields'] ?? null,
            'has_expiry'     => ($meta['expiry'] ?? false) === true,
            'title'          => (string) $row['title'],
            'issuer'         => $row['issuer'],
            'field_of_study' => $row['field_of_study'],
            'grade'          => $row['grade'],
            'credential_id'  => $row['credential_id'],
            'credential_url' => $row['credential_url'],
            'media_url'      => $row['media_url'],
            'started_on'     => $row['started_on'],
            'ended_on'       => $row['ended_on'],
            'is_current'     => (int) $row['is_current'] === 1,
            // A certificate whose expiry has passed is still a true record of
            // something that was issued, so the row is not hidden — it is
            // flagged, and the client decides whether to grey it out.
            'is_expired'     => $row['ended_on'] !== null
                && ($meta['expiry'] ?? false) === true
                && (string) $row['ended_on'] < date('Y-m-d'),
            'description'    => $row['description'],
            'is_verified'    => (int) $row['is_verified'] === 1,
            'verified_at'    => $row['verified_at'],
            'is_visible'     => (int) $row['is_visible'] === 1,
            'sort_order'     => (int) $row['sort_order'],
        ];
    }

    /**
     * @param  array<string,mixed> $row
     * @param  array<string,array<string,mixed>> $kinds
     * @return array<string,mixed>
     */
    private static function presentExperience(array $row, array $kinds): array
    {
        $kind = $kinds[(string) $row['kind']] ?? [];
        $meta = is_array($kind['metadata'] ?? null) ? $kind['metadata'] : [];

        return [
            'id'                    => (int) $row['id'],
            'kind'                  => (string) $row['kind'],
            'kind_label'            => (string) ($kind['label'] ?? TaxonomyService::label('work_type', (string) $row['kind'])),
            'kind_icon'             => $kind['icon'] ?? 'work',
            'kind_color'            => $kind['color'] ?? null,
            // Which block of the profile this row renders in. Defaulting to
            // work_history rather than throwing keeps a kind an operator added
            // without metadata visible instead of invisible.
            'section'               => (string) ($meta['section'] ?? 'work_history'),
            'organisation'          => (string) $row['organisation'],
            'organisation_logo_url' => $row['organisation_logo_url'],
            'organisation_url'      => $row['organisation_url'],
            'role'                  => $row['role'],
            'location'              => $row['location'],
            'started_on'            => $row['started_on'],
            'ended_on'              => $row['ended_on'],
            'is_current'            => (int) $row['is_current'] === 1,
            'description'           => $row['description'],
            'reference_url'         => $row['reference_url'],
            'booking_id'            => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            // The distinction the booking_id column exists for: a self-declared
            // collaboration is a claim, one that points at a booking taken on
            // this platform is a fact the platform can vouch for.
            'is_platform_verified'  => $row['booking_id'] !== null,
            'is_verified'           => (int) $row['is_verified'] === 1,
            'is_featured'           => (int) $row['is_featured'] === 1,
            'is_visible'            => (int) $row['is_visible'] === 1,
            'sort_order'            => (int) $row['sort_order'],
        ];
    }

    // =================================================================
    // Payload builders
    // =================================================================

    /**
     * @param  array<string,mixed>      $input
     * @param  array<string,mixed>|null $existing null on create
     * @return array<string,mixed>
     */
    private static function credentialPayload(array $input, ?array $existing): array
    {
        $isCreate = $existing === null;
        $out      = [];

        if ($isCreate || array_key_exists('kind', $input)) {
            $kind = self::text($input, 'kind', 30)
                ?? ($existing['kind'] ?? null)
                ?? TaxonomyService::defaultValue('credential_type')
                ?? 'certification';

            if (!TaxonomyService::isValid('credential_type', (string) $kind)) {
                throw HttpException::unprocessable("Not a recognised credential type: {$kind}");
            }

            $out['kind'] = (string) $kind;
        }

        if ($isCreate || array_key_exists('title', $input)) {
            $title = self::text($input, 'title', 191);

            if ($title === null || mb_strlen($title) < 2) {
                throw HttpException::unprocessable('Give the credential a title of at least 2 characters.');
            }

            $out['title'] = $title;
        }

        foreach (['issuer' => 191, 'field_of_study' => 150, 'grade' => 60, 'credential_id' => 120, 'description' => 1000] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $out[$field] = self::text($input, $field, $max);
            }
        }

        foreach (['credential_url', 'media_url'] as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = self::url($input, $field, 500);
            }
        }

        // A start date up to a year ahead is allowed because a course that has
        // been enrolled in but not begun is a real thing a creator lists. An
        // end date runs much further out because it doubles as an expiry, and
        // some licences last decades.
        if (array_key_exists('started_on', $input)) {
            $out['started_on'] = self::date($input, 'started_on', 1900, 1);
        }

        if (array_key_exists('ended_on', $input)) {
            $out['ended_on'] = self::date($input, 'ended_on', 1900, 50);
        }

        foreach (['is_current', 'is_visible'] as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = self::flag($input, $field, $field === 'is_visible' ? 1 : 0);
            }
        }

        self::assertDateRange($out, $existing);

        return $out;
    }

    /**
     * @param  array<string,mixed>      $input
     * @param  array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private static function experiencePayload(array $input, ?array $existing, int $creatorId): array
    {
        $isCreate = $existing === null;
        $out      = [];

        if ($isCreate || array_key_exists('kind', $input)) {
            $kind = self::text($input, 'kind', 30)
                ?? ($existing['kind'] ?? null)
                ?? TaxonomyService::defaultValue('work_type')
                ?? 'brand_collaboration';

            if (!TaxonomyService::isValid('work_type', (string) $kind)) {
                throw HttpException::unprocessable("Not a recognised work type: {$kind}");
            }

            $out['kind'] = (string) $kind;
        }

        if ($isCreate || array_key_exists('organisation', $input)) {
            $organisation = self::text($input, 'organisation', 191);

            if ($organisation === null || mb_strlen($organisation) < 2) {
                throw HttpException::unprocessable('Name the brand or organisation (at least 2 characters).');
            }

            $out['organisation'] = $organisation;
        }

        foreach (['role' => 150, 'location' => 150, 'description' => 1000] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $out[$field] = self::text($input, $field, $max);
            }
        }

        foreach (['organisation_logo_url', 'organisation_url', 'reference_url'] as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = self::url($input, $field, 500);
            }
        }

        if (array_key_exists('started_on', $input)) {
            $out['started_on'] = self::date($input, 'started_on', 1900, 1);
        }

        if (array_key_exists('ended_on', $input)) {
            // A contract can be signed to run for years, so an end date ahead
            // of today is legitimate — but not decades ahead, which is always a
            // typo rather than a plan.
            $out['ended_on'] = self::date($input, 'ended_on', 1900, 10);
        }

        foreach (['is_current', 'is_featured', 'is_visible'] as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = self::flag($input, $field, $field === 'is_visible' ? 1 : 0);
            }
        }

        // Linking a booking is what turns a claim into a fact, so the booking
        // has to be this creator's, has to have finished, has to not be claimed
        // by another row already — and has to be with the brand the row names.
        // Anything less and `is_verified` would mean "the creator typed a
        // number".
        if (array_key_exists('booking_id', $input)) {
            $bookingId = self::int($input, 'booking_id');

            if ($bookingId === null || $bookingId <= 0) {
                $out['booking_id']  = null;
                $out['is_verified'] = 0;
            } else {
                // The brand is carried on the booking as a user id; the name a
                // profile should show is that account's company name, falling
                // back to the account name for a brand that never filled one in.
                $booking = Database::selectOne(
                    'SELECT b.`id`, b.`status`,
                            COALESCE(NULLIF(TRIM(u.`company_name`), ""), TRIM(u.`name`)) AS `brand_name`
                       FROM `bookings` b
                       LEFT JOIN `users` u ON u.`id` = b.`brand_user_id`
                      WHERE b.`id` = :id AND b.`creator_id` = :c AND b.`deleted_at` IS NULL',
                    ['id' => $bookingId, 'c' => $creatorId]
                );

                if ($booking === null) {
                    throw HttpException::unprocessable('That booking is not one of yours.');
                }

                if ((string) $booking['status'] !== 'completed') {
                    throw HttpException::unprocessable('Only a completed booking can be shown as a collaboration.');
                }

                $taken = Database::selectOne(
                    'SELECT `id` FROM `creator_experience` WHERE `booking_id` = :b',
                    ['b' => $bookingId]
                );

                if ($taken !== null && (int) $taken['id'] !== (int) ($existing['id'] ?? 0)) {
                    throw HttpException::conflict('That booking is already listed on your profile.');
                }

                // `organisation` is the one field on a vouched-for row the
                // platform can check for itself, and until it does, the tick
                // means nothing: a creator could link a real skincare booking,
                // type "Nike" beside it, and the profile would render "Nike —
                // platform verified". So the name is either derived from the
                // booking or checked against it; it is never free text sitting
                // next to a verification flag.
                $brand = trim((string) ($booking['brand_name'] ?? ''));

                if ($brand === '') {
                    throw HttpException::unprocessable(
                        'We have no brand name on record for that booking, so it cannot be shown as a verified collaboration.'
                    );
                }

                $claimed = array_key_exists('organisation', $out)
                    ? (string) $out['organisation']
                    : trim((string) ($existing['organisation'] ?? ''));

                if ($claimed === '') {
                    // Nothing claimed: derive it. This is the shape the "add
                    // this booking to my profile" flow wants — the creator
                    // picks a booking and the brand comes with it.
                    $out['organisation'] = mb_substr($brand, 0, 191);
                } elseif (!self::sameOrganisation($claimed, $brand)) {
                    throw HttpException::unprocessable(sprintf(
                        'That booking was with %s, not %s. A collaboration the platform vouches for has to name the brand the booking was actually with.',
                        $brand,
                        $claimed
                    ));
                }

                $out['booking_id']  = $bookingId;
                $out['is_verified'] = 1;
            }
        }

        self::assertDateRange($out, $existing);

        return $out;
    }

    /**
     * @param  array<string,mixed>      $input
     * @param  array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private static function workingHourPayload(array $input, ?array $existing): array
    {
        $isCreate = $existing === null;
        $out      = [];

        if ($isCreate || array_key_exists('is_working', $input)) {
            $out['is_working'] = self::flag($input, 'is_working', 1);
        }

        if ($isCreate || array_key_exists('starts_at', $input)) {
            $out['starts_at'] = self::time($input, 'starts_at', $isCreate ? '10:00:00' : null);
        }

        if ($isCreate || array_key_exists('ends_at', $input)) {
            $out['ends_at'] = self::time($input, 'ends_at', $isCreate ? '18:00:00' : null);
        }

        if (array_key_exists('note', $input)) {
            $out['note'] = self::text($input, 'note', 255);
        }

        if (array_key_exists('is_visible', $input)) {
            $out['is_visible'] = self::flag($input, 'is_visible', 1);
        }

        $isWorking = (int) ($out['is_working'] ?? $existing['is_working'] ?? 1);

        // A day off has no hours, so it is stored with none — the same
        // 00:00:00/00:00:00 setWorkingHours() writes for one. Normalising here
        // rather than leaving whatever times came in is what stops a row like
        // 23:00 → 01:00 with is_working = 0 becoming permanent junk: the
        // midnight-wrap guard below only fires for a working window, so nothing
        // refuses that row on the way in, and updateWorkingHour() then refuses
        // to flip it back to working because its end precedes its start. It
        // could never be fixed and it renders on the public profile.
        if ($isWorking === 0) {
            $out['starts_at'] = '00:00:00';
            $out['ends_at']   = '00:00:00';

            return $out;
        }

        $starts = (string) ($out['starts_at'] ?? $existing['starts_at'] ?? '10:00:00');
        $ends   = (string) ($out['ends_at'] ?? $existing['ends_at'] ?? '18:00:00');

        // A window that wraps past midnight is two windows, not one: stored as
        // a single row it would compare wrong in every overlap and every "are
        // you free at 23:00" test. The creator is told to split it.
        if ($ends <= $starts) {
            throw HttpException::unprocessable(
                'The end time must be after the start time. A range that runs past midnight has to be split in two.'
            );
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>      $input
     * @param  array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private static function faqPayload(array $input, ?array $existing): array
    {
        $isCreate = $existing === null;
        $out      = [];

        if ($isCreate || array_key_exists('question', $input)) {
            $question = self::text($input, 'question', 300);

            if ($question === null || mb_strlen($question) < 5) {
                throw HttpException::unprocessable('Write a question of at least 5 characters.');
            }

            $out['question'] = $question;
        }

        if ($isCreate || array_key_exists('answer', $input)) {
            $answer = self::text($input, 'answer', 2000);

            if ($answer === null || mb_strlen($answer) < 2) {
                throw HttpException::unprocessable('Write an answer.');
            }

            $out['answer'] = $answer;
        }

        if (array_key_exists('is_visible', $input)) {
            $out['is_visible'] = self::flag($input, 'is_visible', 1);
        }

        return $out;
    }

    // =================================================================
    // Guards and shared helpers
    // =================================================================

    /**
     * The creator row, or 404.
     *
     * Soft-deleted creators are not found: their rows survive for the financial
     * trail and nothing may write to them.
     *
     * @return array<string,mixed>
     */
    private static function creator(int $creatorId): array
    {
        $row = Database::selectOne(
            'SELECT * FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        );

        if ($row === null) {
            throw HttpException::notFound('Creator not found.');
        }

        return $row;
    }

    /**
     * One row of one of this module's tables, belonging to this creator.
     *
     * The ownership predicate is written here once. A row belonging to somebody
     * else is 404 and not 403, because "not yours" and "does not exist" must
     * read identically from outside — otherwise the endpoint enumerates other
     * creators' row ids.
     *
     * @return array<string,mixed>
     */
    private static function loadOwned(string $table, int $creatorId, int $id, string $message): array
    {
        if (!array_key_exists($table, self::ORDER_SCOPES)) {
            throw HttpException::server('Unknown profile field table.');
        }

        $row = Database::selectOne(
            'SELECT * FROM ' . Database::quoteIdentifier($table) . ' WHERE `id` = :id AND `creator_id` = :creator_id',
            ['id' => $id, 'creator_id' => $creatorId]
        );

        if ($row === null) {
            throw HttpException::notFound($message);
        }

        return $row;
    }

    /**
     * Refuse the write if any of the governing sections is frozen.
     *
     * @param list<string> $sections
     */
    private static function assertSections(int $creatorId, array $sections): void
    {
        foreach ($sections as $section) {
            CreatorProfileLockService::assertUnlocked($creatorId, $section);
        }
    }

    /**
     * Stop a creator filling a section with hundreds of rows.
     *
     * The caps are settings with defaults rather than constants, so an operator
     * running a different kind of marketplace can raise them without a release;
     * a missing settings row simply uses the default.
     *
     * @param array<string,mixed> $scope
     */
    private static function assertUnderLimit(
        string $table,
        int $creatorId,
        int $max,
        string $label,
        array $scope = []
    ): void {
        $sql      = 'SELECT COUNT(*) FROM ' . Database::quoteIdentifier($table) . ' WHERE `creator_id` = :creator_id';
        $bindings = ['creator_id' => $creatorId];

        foreach ($scope as $column => $value) {
            $sql .= ' AND ' . Database::quoteIdentifier($column) . ' = :scope_' . $column;
            $bindings['scope_' . $column] = $value;
        }

        if ((int) Database::scalar($sql, $bindings) >= $max) {
            throw HttpException::unprocessable(
                sprintf('%s: you can have at most %d. Remove one before adding another.', $label, $max)
            );
        }
    }

    /**
     * Renumber a creator's rows so the ids given lead, in the order given.
     *
     * Ids that are not the caller's are skipped rather than refused — the same
     * rule CreatorController::reorderSocialAccounts documents, so a stale
     * client cannot lock a creator out of reordering everything else.
     *
     * Rows the caller did not mention are pushed after the ones that were,
     * keeping their previous relative order. Without that they would keep their
     * old numbers and collide with the new ones, and the list would come back
     * in an order nobody asked for.
     *
     * @param  list<int>           $ids
     * @param  array<string,mixed> $scope
     * @return int Rows actually placed by the caller.
     */
    private static function applyOrder(string $table, int $creatorId, array $ids, array $scope = []): int
    {
        if (!array_key_exists($table, self::ORDER_SCOPES)) {
            throw HttpException::server('Unknown profile field table.');
        }

        $wanted = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0 && !in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }

        if ($wanted === []) {
            throw HttpException::unprocessable('Send the ids in the order you want them.');
        }

        $sql      = 'SELECT `id` FROM ' . Database::quoteIdentifier($table) . ' WHERE `creator_id` = :creator_id';
        $bindings = ['creator_id' => $creatorId];

        foreach ($scope as $column => $value) {
            $sql .= ' AND ' . Database::quoteIdentifier($column) . ' = :scope_' . $column;
            $bindings['scope_' . $column] = $value;
        }

        $current = array_map('intval', Database::column($sql . ' ORDER BY `sort_order`, `id`', $bindings));

        $placed = array_values(array_filter($wanted, static fn (int $id): bool => in_array($id, $current, true)));

        if ($placed === []) {
            throw HttpException::notFound('None of those rows are yours.');
        }

        $rest  = array_values(array_diff($current, $placed));
        $final = array_merge($placed, $rest);

        Database::transaction(static function () use ($table, $creatorId, $final): void {
            foreach ($final as $position => $id) {
                Database::update(
                    $table,
                    ['sort_order' => $position + 1],
                    ['id' => $id, 'creator_id' => $creatorId]
                );
            }
        });

        return count($placed);
    }

    /**
     * The next free position in a creator's list.
     *
     * @param array<string,mixed> $scope
     */
    private static function nextSortOrder(string $table, int $creatorId, array $scope = []): int
    {
        $sql      = 'SELECT COALESCE(MAX(`sort_order`), 0) FROM ' . Database::quoteIdentifier($table)
                  . ' WHERE `creator_id` = :creator_id';
        $bindings = ['creator_id' => $creatorId];

        foreach ($scope as $column => $value) {
            $sql .= ' AND ' . Database::quoteIdentifier($column) . ' = :scope_' . $column;
            $bindings['scope_' . $column] = $value;
        }

        // SMALLINT tops out at 32767. Clamping rather than wrapping means the
        // worst case is two rows sharing a position, not a negative one.
        return min(32767, (int) Database::scalar($sql, $bindings) + 1);
    }

    // -----------------------------------------------------------------
    // Taxonomy plumbing
    // -----------------------------------------------------------------

    /**
     * The TERM_SECTIONS entry a caller is asking for, by section name OR by the
     * taxonomy key that section publishes.
     *
     * EVERY caller resolves through here rather than indexing TERM_SECTIONS
     * itself, and that is the whole point of the method. The two vocabularies
     * are both live on the wire: `{section}` is a free path segment, and
     * `taxonomy_key` — skill, interest, profession, language, service_area,
     * city — is what terms() stamps on every row this module returns, so a
     * client that routes on the field it was given sends the key. Indexing
     * TERM_SECTIONS with the un-mapped key is an undefined index, which
     * App::boot()'s error handler turns into a 500 for input that is merely in
     * the other vocabulary. Resolution happens once, here, and what comes back
     * carries the canonical section name beside the key so no caller has to map
     * back and forth.
     *
     * @return array{key:string,label:string,sections:list<string>,section:string}
     */
    private static function termSection(string $section): array
    {
        if (isset(self::TERM_SECTIONS[$section])) {
            return self::TERM_SECTIONS[$section] + ['section' => $section];
        }

        // Callers that already hold a taxonomy key rather than a section name
        // are accepted, so an admin screen driven by `profile_section`
        // metadata — which names taxonomy keys, not sections — works unchanged.
        foreach (self::TERM_SECTIONS as $name => $spec) {
            if ($spec['key'] === $section) {
                return $spec + ['section' => $name];
            }
        }

        throw HttpException::unprocessable(
            'Not a profile section: ' . $section . '. Expected one of: '
            . implode(', ', array_keys(self::TERM_SECTIONS)) . '.'
        );
    }

    /** Turn an API section name — or the taxonomy key behind it — into that key. */
    private static function taxonomyKey(string $section): string
    {
        return self::termSection($section)['key'];
    }

    /**
     * One element of a submitted `values` list, as text.
     *
     * The controller validates that `values` IS an array and stops there, so a
     * body of {"values":[["a"]]} — a client that nested its list one level too
     * deep, which is a typo and not an attack — arrives here with an array
     * inside it. `(string) $element` on that is an "Array to string conversion"
     * warning, and App::boot() raises warnings as exceptions, so the creator
     * gets a 500 for a malformed field. A list of term values holds term
     * values; anything that is not one is named and refused.
     */
    private static function termValue(mixed $value, int $index): string
    {
        // null stays permitted and reads as empty — both loops already skip
        // empty entries, and a trailing null in a form array is not an error.
        if ($value !== null && !is_scalar($value)) {
            throw HttpException::unprocessable(sprintf(
                'Entry %d must be a term value, not a list or an object.',
                $index + 1
            ));
        }

        return trim((string) $value);
    }

    /** The API section name for a stored taxonomy key. */
    private static function sectionFor(string $taxonomyKey): string
    {
        foreach (self::TERM_SECTIONS as $section => $spec) {
            if ($spec['key'] === $taxonomyKey) {
                return $section;
            }
        }

        return $taxonomyKey;
    }

    /**
     * The `taxonomy_terms`.`id` behind a term value.
     *
     * The link table stores an id, and TaxonomyService deals in values, so this
     * is the one place the two meet. $mustBeActive is true on the way in — an
     * unknown or retired term may not be chosen — and false on the way out, so
     * a term the operator has since retired can still be removed from a profile.
     */
    private static function resolveTermId(string $taxonomyKey, string $value, bool $mustBeActive): int
    {
        if ($mustBeActive && !TaxonomyService::isValid($taxonomyKey, $value)) {
            throw HttpException::unprocessable(
                sprintf('"%s" is not one of the available %s options.', $value, $taxonomyKey)
            );
        }

        $sql = 'SELECT tt.`id`
                  FROM `taxonomy_terms` tt
                  INNER JOIN `taxonomies` t ON t.`id` = tt.`taxonomy_id`
                 WHERE t.`key` = :key AND tt.`value` = :value';

        if ($mustBeActive) {
            $sql .= ' AND tt.`is_active` = 1 AND t.`is_active` = 1';
        }

        $id = Database::scalar($sql . ' LIMIT 1', ['key' => $taxonomyKey, 'value' => $value]);

        if ($id === null) {
            throw HttpException::unprocessable(
                sprintf('"%s" is not one of the available %s options.', $value, $taxonomyKey)
            );
        }

        return (int) $id;
    }

    /**
     * The stored link row for one term, decorated exactly as terms() decorates
     * a list — one shape whether a client reads one row or all of them.
     *
     * @return array<string,mixed>
     */
    private static function termRow(int $creatorId, int $termId): array
    {
        $row = Database::selectOne(
            'SELECT cpt.`id`, cpt.`taxonomy_key`, cpt.`is_primary`, cpt.`is_visible`, cpt.`sort_order`,
                    tt.`value`, tt.`label`, tt.`icon`, tt.`color_hex`, tt.`description`, tt.`is_active`
               FROM `creator_profile_terms` cpt
               INNER JOIN `taxonomy_terms` tt ON tt.`id` = cpt.`term_id`
              WHERE cpt.`creator_id` = :c AND cpt.`term_id` = :t',
            ['c' => $creatorId, 't' => $termId]
        );

        if ($row === null) {
            throw HttpException::notFound('That is not on your profile.');
        }

        return [
            'id'           => (int) $row['id'],
            'section'      => self::sectionFor((string) $row['taxonomy_key']),
            'taxonomy_key' => (string) $row['taxonomy_key'],
            'value'        => (string) $row['value'],
            'label'        => (string) $row['label'],
            'description'  => $row['description'],
            'icon'         => $row['icon'],
            'color'        => $row['color_hex'],
            'is_primary'   => (int) $row['is_primary'] === 1,
            'is_visible'   => (int) $row['is_visible'] === 1,
            'is_active'    => (int) $row['is_active'] === 1,
            'sort_order'   => (int) $row['sort_order'],
        ];
    }

    private static function demoteOtherPrimaries(int $creatorId, string $taxonomyKey, int $termId): void
    {
        Database::execute(
            'UPDATE `creator_profile_terms` SET `is_primary` = 0
              WHERE `creator_id` = :c AND `taxonomy_key` = :k AND `term_id` <> :t',
            ['c' => $creatorId, 'k' => $taxonomyKey, 't' => $termId]
        );
    }

    /**
     * Everything that has to happen after a section's terms change.
     *
     * Today that is one thing, and it matters: `creators`.`languages` is a JSON
     * array of language names that discovery, the admin screens and the apps
     * all still read. 035 backfilled the link rows FROM that column and left it
     * in place deliberately, so writing languages only into the link table
     * would leave the column frozen at its 2026 contents while the profile
     * showed something else. Rewriting it here keeps one source of truth with
     * one cached copy, rather than two sources that disagree.
     */
    private static function afterTermChange(int $creatorId, string $taxonomyKey): void
    {
        if ($taxonomyKey !== 'language') {
            return;
        }

        $values = Database::column(
            'SELECT tt.`value`
               FROM `creator_profile_terms` cpt
               INNER JOIN `taxonomy_terms` tt ON tt.`id` = cpt.`term_id`
              WHERE cpt.`creator_id` = :c AND cpt.`taxonomy_key` = "language"
              ORDER BY cpt.`is_primary` DESC, cpt.`sort_order`, cpt.`id`',
            ['c' => $creatorId]
        );

        Database::update(
            'creators',
            ['languages' => json_encode(array_values(array_map('strval', $values)), JSON_UNESCAPED_UNICODE)],
            ['id' => $creatorId]
        );
    }

    /**
     * A taxonomy's terms keyed by value.
     *
     * TaxonomyService::map() returns value => LABEL, so indexing it for
     * ['metadata'] yields null against a string and every row falls back to its
     * default icon. terms() carries the whole term.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function keyedTerms(string $taxonomyKey): array
    {
        $keyed = [];

        foreach (TaxonomyService::terms($taxonomyKey) as $term) {
            $keyed[(string) $term['value']] = $term;
        }

        return $keyed;
    }

    /** @return list<array<string,mixed>> */
    private static function weekdayOptions(): array
    {
        $out = [];

        foreach (self::WEEKDAYS as $weekday => $label) {
            $out[] = ['value' => $weekday, 'label' => $label, 'short_label' => substr($label, 0, 3)];
        }

        return $out;
    }

    private static function maxTerms(): int
    {
        return self::limit('max_terms_per_section', 25);
    }

    private static function limit(string $key, int $default): int
    {
        return max(1, Config::settingInt('creator', $key, $default));
    }

    // -----------------------------------------------------------------
    // Input readers
    //
    // These exist rather than a Validator rule set because the same rules have
    // to hold for the admin panel and the importer, neither of which goes
    // through the API validator. A rule that only one caller applies is not a
    // rule.
    // -----------------------------------------------------------------

    /**
     * A trimmed string bounded to its column, or null.
     *
     * An empty string reads as null, which is how a client clears a column: the
     * columns are NULLable and '' would be a second way to say "nothing" that
     * every read would then have to test for.
     *
     * @param array<string,mixed> $input
     */
    private static function text(array $input, string $key, int $max): ?string
    {
        $raw = $input[$key] ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_array($raw) || is_object($raw)) {
            throw HttpException::unprocessable(self::label($key) . ' must be text.');
        }

        if (is_bool($raw)) {
            $raw = $raw ? '1' : '0';
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw HttpException::unprocessable(
                sprintf('%s may not exceed %d characters.', self::label($key), $max)
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private static function url(array $input, string $key, int $max): ?string
    {
        $value = self::text($input, $key, $max);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $value) !== 1) {
            throw HttpException::unprocessable(self::label($key) . ' must be a valid http(s) link.');
        }

        return $value;
    }

    /**
     * A real calendar date in YYYY-MM-DD, inside a sane window.
     *
     * checkdate() rather than strtotime(): strtotime accepts 2026-02-31 and
     * quietly returns the 3rd of March, so a typo becomes a wrong date on a
     * profile instead of an error the creator can fix.
     *
     * @param array<string,mixed> $input
     */
    private static function date(array $input, string $key, int $minYear, int $maxYearsAhead): ?string
    {
        // Bounded at 32 rather than 10 so a client that sent a full timestamp
        // is told the FORMAT is wrong, which is actionable, instead of being
        // told it is too long, which is not.
        $value = self::text($input, $key, 32);

        if ($value === null) {
            return null;
        }

        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw HttpException::unprocessable(self::label($key) . ' must be a real date in YYYY-MM-DD form.');
        }

        $year    = (int) $m[1];
        $maxYear = (int) date('Y') + $maxYearsAhead;

        if ($year < $minYear || $year > $maxYear) {
            throw HttpException::unprocessable(
                sprintf('%s must fall between %d and %d.', self::label($key), $minYear, $maxYear)
            );
        }

        return $value;
    }

    /**
     * A whole number, or null.
     *
     * @param array<string,mixed> $input
     */
    private static function int(array $input, string $key): ?int
    {
        $raw = $input[$key] ?? null;

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        // A JSON float with no fractional part IS a whole number — Dart encodes
        // 2015 as 2015.0 — so integral values in any notation are accepted and
        // fractional ones are not.
        if (is_float($raw) && is_finite($raw) && floor($raw) === $raw) {
            return (int) $raw;
        }

        if (is_string($raw) && preg_match('/^-?\d+(\.0+)?$/', trim($raw)) === 1) {
            return (int) (float) trim($raw);
        }

        throw HttpException::unprocessable(self::label($key) . ' must be a whole number.');
    }

    /**
     * A 0/1 column value.
     *
     * An unrecognised value is refused rather than read as false: a client
     * sending "no" and getting `true` back is the sort of bug that is only
     * found by the creator whose hidden row went public.
     *
     * @param array<string,mixed> $input
     */
    private static function flag(array $input, string $key, int $default = 0): int
    {
        $raw = $input[$key] ?? null;

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return $default;
        }

        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }

        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw;
        }

        $value = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';

        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }

        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        throw HttpException::unprocessable(self::label($key) . ' must be true or false.');
    }

    /**
     * A wall-clock time, normalised to HH:MM:SS for the TIME column.
     *
     * @param array<string,mixed> $input
     */
    private static function time(array $input, string $key, ?string $default): string
    {
        $raw = $input[$key] ?? null;

        if ($raw === null || (is_scalar($raw) && trim((string) $raw) === '')) {
            if ($default !== null) {
                return $default;
            }

            throw HttpException::unprocessable(self::label($key) . ' is required.');
        }

        $value = is_scalar($raw) ? trim((string) $raw) : '';

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m) !== 1) {
            throw HttpException::unprocessable(self::label($key) . ' must be a time such as 09:30.');
        }

        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }

    /** @param array<string,mixed> $input */
    private static function weekday(array $input): int
    {
        $weekday = self::int($input, 'weekday');

        if ($weekday === null || !isset(self::WEEKDAYS[$weekday])) {
            throw HttpException::unprocessable('Weekday must be 1 (Monday) through 7 (Sunday).');
        }

        return $weekday;
    }

    private static function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Do two names refer to the same organisation?
     *
     * Compared on normalised WORDS rather than literally, because "Glow Theory
     * Skincare", "glow theory" and "Glow Theory Skincare Pvt. Ltd." are one
     * brand written three ways and refusing the informal ones would push
     * creators to unlink real bookings — which loses the platform exactly the
     * verified rows it wants. Shortening is allowed only from the END: a name
     * may drop trailing words, never change its leading ones. That is what
     * separates a creator abbreviating the brand they worked with from a
     * creator naming a brand they did not.
     */
    private static function sameOrganisation(string $claimed, string $brand): bool
    {
        $a = self::organisationWords($claimed);
        $b = self::organisationWords($brand);

        if ($a === [] || $b === []) {
            return false;
        }

        $short = count($a) <= count($b) ? $a : $b;
        $long  = $short === $a ? $b : $a;

        return array_slice($long, 0, count($short)) === $short;
    }

    /**
     * An organisation name reduced to its distinguishing words.
     *
     * Case and punctuation go — "Coca-Cola" and "coca cola" are the same
     * company — and so do the company-form suffixes and articles that carry no
     * identity at all, so that "Kora Threads" matches "Kora Threads Private
     * Limited".
     *
     * @return list<string>
     */
    private static function organisationWords(string $name): array
    {
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim($name)));

        $noise = [
            'pvt', 'private', 'ltd', 'limited', 'llp', 'llc', 'inc', 'incorporated',
            'co', 'company', 'corp', 'corporation', 'plc', 'gmbh', 'sa', 'bv',
            'the', 'and',
        ];

        return array_values(array_filter(
            explode(' ', (string) $stripped),
            static fn (string $word): bool => $word !== '' && !in_array($word, $noise, true)
        ));
    }

    /**
     * Start/end coherence for the credential and experience tables.
     *
     * "Still studying" and "ended in 2019" cannot both be true, so is_current
     * wins and the end date is cleared. Doing it here rather than refusing the
     * combination means a client that leaves a stale end date in a form does
     * not have to be taught to clear it.
     *
     * @param array<string,mixed>      $out      Modified in place.
     * @param array<string,mixed>|null $existing
     */
    private static function assertDateRange(array &$out, ?array $existing): void
    {
        $started = array_key_exists('started_on', $out) ? $out['started_on'] : ($existing['started_on'] ?? null);
        $ended   = array_key_exists('ended_on', $out) ? $out['ended_on'] : ($existing['ended_on'] ?? null);
        $current = array_key_exists('is_current', $out)
            ? (int) $out['is_current']
            : (int) ($existing['is_current'] ?? 0);

        if ($current === 1 && $ended !== null) {
            $out['ended_on'] = null;

            return;
        }

        if ($started !== null && $ended !== null && (string) $ended < (string) $started) {
            throw HttpException::unprocessable('The end date cannot be before the start date.');
        }
    }

    /**
     * No two working windows on one day may overlap.
     *
     * @param list<array<string,mixed>> $slots
     */
    private static function assertNoOverlap(array $slots, int $weekday): void
    {
        $count = count($slots);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (
                    (string) $slots[$i]['starts_at'] < (string) $slots[$j]['ends_at']
                    && (string) $slots[$j]['starts_at'] < (string) $slots[$i]['ends_at']
                ) {
                    throw HttpException::unprocessable(
                        sprintf('Your %s time ranges overlap.', self::WEEKDAYS[$weekday])
                    );
                }
            }
        }
    }

    /**
     * Drop the "closed" marker from a weekday that is about to hold a working
     * window.
     *
     * A day off is stored as a row on purpose, so that "closed on Sunday" and
     * "has not filled Sunday in yet" read differently. That row becomes a lie
     * the moment a window lands on the same day — and it is invisible to every
     * other guard, because assertNoStoredOverlap() filters is_working = 1,
     * while workingHours() returns it to the public profile as a phantom
     * 00:00-00:00 slot and it still occupies one of the day's places.
     *
     * Every is_working = 0 row goes, not only the 00:00-00:00 one: any of them
     * on a day that works is stale by the same argument.
     */
    private static function clearDayOff(int $creatorId, int $weekday, ?int $ignoreId): void
    {
        $sql = 'DELETE FROM `creator_working_hours`
                 WHERE `creator_id` = :c AND `weekday` = :w AND `is_working` = 0';

        $bindings = ['c' => $creatorId, 'w' => $weekday];

        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :ignore';
            $bindings['ignore'] = $ignoreId;
        }

        Database::execute($sql, $bindings);
    }

    /**
     * One more window will still fit on this weekday.
     *
     * Shared by addWorkingHour() and by updateWorkingHour() when a window moves
     * days, because the cap is a property of the DAY and not of the endpoint
     * that happened to write it — a limit only one of the two writers enforces
     * is not a limit.
     *
     * Only is_working rows are counted. A day-off marker is not a time range,
     * and addWorkingHour() clears it in the same write anyway, so counting it
     * would refuse the fourth window on a day that has three.
     *
     * $ignoreId is the row being moved, which is still filed under its old
     * weekday and must not count against the day it is moving to.
     */
    private static function assertDayHasRoom(int $creatorId, int $weekday, ?int $ignoreId): void
    {
        $maxPerDay = self::limit('max_working_windows_per_day', 4);

        $sql = 'SELECT COUNT(*) FROM `creator_working_hours`
                 WHERE `creator_id` = :c AND `weekday` = :w AND `is_working` = 1';

        $bindings = ['c' => $creatorId, 'w' => $weekday];

        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :ignore';
            $bindings['ignore'] = $ignoreId;
        }

        if ((int) Database::scalar($sql, $bindings) >= $maxPerDay) {
            throw HttpException::unprocessable(sprintf('At most %d time ranges per day.', $maxPerDay));
        }
    }

    private static function assertNoStoredOverlap(
        int $creatorId,
        int $weekday,
        string $startsAt,
        string $endsAt,
        ?int $ignoreId
    ): void {
        $sql = 'SELECT COUNT(*) FROM `creator_working_hours`
                 WHERE `creator_id` = :c AND `weekday` = :w AND `is_working` = 1
                   AND `starts_at` < :ends AND `ends_at` > :starts';

        $bindings = ['c' => $creatorId, 'w' => $weekday, 'starts' => $startsAt, 'ends' => $endsAt];

        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :ignore';
            $bindings['ignore'] = $ignoreId;
        }

        if ((int) Database::scalar($sql, $bindings) > 0) {
            throw HttpException::conflict(
                sprintf('That overlaps a time range you already have on %s.', self::WEEKDAYS[$weekday])
            );
        }
    }

    /**
     * uq_cwh_creator_slot is UNIQUE on (creator_id, weekday, starts_at): two
     * windows may not begin at the same minute. Caught here so the creator is
     * told what is wrong instead of being handed a driver-level duplicate-key
     * error the API would render as a 500.
     */
    private static function assertStartIsFree(int $creatorId, int $weekday, string $startsAt, ?int $ignoreId): void
    {
        $sql = 'SELECT COUNT(*) FROM `creator_working_hours`
                 WHERE `creator_id` = :c AND `weekday` = :w AND `starts_at` = :s';

        $bindings = ['c' => $creatorId, 'w' => $weekday, 's' => $startsAt];

        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :ignore';
            $bindings['ignore'] = $ignoreId;
        }

        if ((int) Database::scalar($sql, $bindings) > 0) {
            throw HttpException::conflict(
                sprintf('You already have a time range starting at %s on %s.', substr($startsAt, 0, 5), self::WEEKDAYS[$weekday])
            );
        }
    }

    /**
     * Two identical questions on one profile is never what a creator meant, and
     * the table has no unique key to stop it — a VARCHAR(300) UNIQUE would not
     * fit in an index at utf8mb4 anyway.
     */
    private static function assertQuestionIsNew(int $creatorId, string $question, ?int $ignoreId): void
    {
        $sql = 'SELECT COUNT(*) FROM `creator_faqs` WHERE `creator_id` = :c AND LOWER(`question`) = LOWER(:q)';

        $bindings = ['c' => $creatorId, 'q' => $question];

        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :ignore';
            $bindings['ignore'] = $ignoreId;
        }

        if ((int) Database::scalar($sql, $bindings) > 0) {
            throw HttpException::conflict('You have already answered that question.');
        }
    }
}
