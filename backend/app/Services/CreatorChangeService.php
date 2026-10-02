<?php

declare(strict_types=1);

namespace Zuste\Services;

use PDOException;
use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;

/**
 * Profile change approval and item moderation.
 *
 * Migration 038 built `creator_change_requests`, `creator_change_fields`, the
 * five moderation columns on `creator_portfolio` and the actor columns on
 * `revisions`, and nothing read or wrote any of them. This is the code that
 * makes them work.
 *
 * THE PROBLEM THIS EXISTS TO SOLVE
 *
 * CreatorController::updateProfile() writes a creator's new value straight into
 * `creators` and then flips `status` to 'pending_review'. The edit is therefore
 * already LIVE while it waits, and — worse — the old value has been destroyed
 * by the time an administrator opens the queue, so "approve this change" means
 * re-approving a whole profile without ever being shown what the change was.
 *
 * This service gives a proposed value somewhere to WAIT. The old value and the
 * new one sit side by side, per field, with a proposer, a reviewer, a decision
 * and a note, and only an approval writes the new value onto the live row.
 *
 * NOTHING CHANGES UNTIL AN OPERATOR ASKS FOR IT
 *
 * The whole mechanism is behind `creator.hold_changes_for_review`, which 038
 * seeded at 0. With it off, {@see screenUpdates()} hands every field straight
 * back to the caller and no row is written here — updateProfile() behaves
 * exactly as it does today. Turning it on is the only thing that starts holding
 * edits, which is a decision an operator makes, not one a deploy makes.
 *
 * WHICH FIELDS ARE HELD IS CONFIGURATION, NOT CODE
 *
 * Every answer comes from the `profile_section` taxonomy 038 seeded: which
 * column belongs to which section, which sections need an administrator
 * (`metadata.requires_review`), which are moderated one item at a time
 * (`metadata.moderated`), and which are somebody else's business entirely
 * (`metadata.handled_in` for contact — OTP-proved in 036 — `reviewed_in` for
 * documents, `admin_only` for verification). An operator who wants headlines to
 * stop needing review edits one term. There is no list of field names in this
 * file, deliberately: three such lists already disagreed with each other before
 * 038, which is the reason the taxonomy exists.
 *
 * VERSION HISTORY REUSES `revisions`
 *
 * `revisions` is already a general point-in-time store and RevisionService
 * already turns two snapshots into a labelled field-level diff. A second
 * snapshot table is not built here. What this service adds is the writer for
 * entity type 'creator' — RevisionService::record() cannot serve it, because
 * its ENTITIES whitelist has no 'creator' entry and its signature has no way to
 * express a non-admin author, which is the one thing 038 added the
 * `actor_type` / `actor_id` columns for. Reading and diffing go through
 * RevisionService so there is still exactly one diff implementation.
 */
final class CreatorChangeService
{
    /** The vocabulary of profile sections, seeded by 038. */
    public const SECTION_TAXONOMY = 'profile_section';

    /** The vocabulary of rejection reasons, seeded by 038. */
    public const REASON_TAXONOMY = 'moderation_reason';

    /**
     * `revisions`.`entity_type` for a creator profile snapshot.
     *
     * Singular to match the rest of the schema's vocabulary for this record —
     * `reports`.`reportable_type`, `approval_requests`.`entity_type` and every
     * `activity_logs`.`subject_type` written against a creator all say
     * 'creator'.
     */
    public const REVISION_ENTITY = 'creator';

    /**
     * The moderation context a rejection reason is offered in. Terms carry
     * `metadata.applies_to`; a profile change matches this value, an item
     * matches its own `collection`.
     */
    public const REASON_CONTEXT_CHANGE = 'profile_change';

    /**
     * Columns the platform computes for itself. They move without anybody
     * editing anything — a booking completes, a review lands, the nightly score
     * job runs — so leaving them in a profile comparison buries the two fields
     * a human actually changed under eight the platform did.
     */
    private const DERIVED_COLUMNS = [
        'rating_avg', 'rating_count', 'followers_total', 'platform_followers',
        'posts_count', 'collabs_count', 'completion_rate', 'profile_score',
        'search_boost',
    ];

    // =================================================================
    // The vocabulary
    // =================================================================

    /**
     * Every profile section with its rules resolved from the taxonomy.
     *
     * @return array<string,array<string,mixed>> Keyed by section value.
     */
    public static function sections(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $out = [];

        foreach (TaxonomyService::terms(self::SECTION_TAXONOMY) as $term) {
            $meta = is_array($term['metadata'] ?? null) ? $term['metadata'] : [];

            $value = (string) $term['value'];

            $out[$value] = [
                'value'       => $value,
                'label'       => (string) $term['label'],
                'description' => (string) ($term['description'] ?? ''),
                'icon'        => (string) ($term['icon'] ?? 'circle'),
                // TaxonomyService normalises `color_hex` to `color` on the way
                // out. Reading the column name here would give every section
                // the fallback colour.
                'color'       => (string) ($term['color'] ?? '#64748B'),
                'table'       => (string) ($meta['table'] ?? 'creators'),
                'fields'      => array_values(array_map('strval', (array) ($meta['fields'] ?? []))),
                'collection'  => isset($meta['collection']) ? (string) $meta['collection'] : null,
                'requires_review' => (bool) ($meta['requires_review'] ?? false),
                'lockable'    => (bool) ($meta['lockable'] ?? false),
                'moderated'   => (bool) ($meta['moderated'] ?? false),
                'admin_only'  => (bool) ($meta['admin_only'] ?? false),
                'handled_in'  => isset($meta['handled_in']) ? (string) $meta['handled_in'] : null,
                'reviewed_in' => isset($meta['reviewed_in']) ? (string) $meta['reviewed_in'] : null,
            ];
        }

        return $cache = $out;
    }

    /** One section, or null when the taxonomy does not name it. */
    public static function section(string $value): ?array
    {
        return self::sections()[$value] ?? null;
    }

    /**
     * `table.column` => section, for every column this service could actually
     * write back.
     *
     * Three filters, each of which prevents a row that could never be applied
     * from being created in the first place:
     *
     *  - a section whose work belongs to another module is skipped. Contact is
     *    proved by OTP in `contact_change_requests` (036), documents are
     *    decided in the KYC module, and verification is only ever an
     *    administrator's to write. 038's own comments say a change request
     *    raised against any of them is a bug.
     *  - a moderated section — portfolio, gallery — is skipped, because its
     *    items are approved one at a time by {@see moderateItem()} rather than
     *    diffed field by field.
     *  - a column that does not exist on the table the term names is skipped.
     *    `availability` names `creator_availability` and the field
     *    `availability_status`, which lives on `creators`; queueing that pair
     *    would produce a request nobody could ever approve.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fieldIndex(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $out = [];

        foreach (self::sections() as $section) {
            if (
                $section['handled_in'] !== null
                || $section['reviewed_in'] !== null
                || $section['admin_only']
                || $section['moderated']
            ) {
                continue;
            }

            foreach ($section['fields'] as $field) {
                if (self::hasColumn($section['table'], $field)) {
                    $out[$section['table'] . '.' . $field] = $section;
                }
            }
        }

        return $cache = $out;
    }

    /** The section that owns a column, or null when nothing queueable does. */
    public static function sectionForField(string $field, string $table = 'creators'): ?array
    {
        return self::fieldIndex()[$table . '.' . $field] ?? null;
    }

    /**
     * Does an edit to this column need an administrator?
     *
     * This is the whole policy, and it is one taxonomy lookup. It replaces the
     * `creator.reviewed_sections` CSV, which named columns under a key that
     * says sections and could not say anything about where those columns live.
     */
    public static function requiresReview(string $field, string $table = 'creators'): bool
    {
        $section = self::sectionForField($field, $table);

        return $section !== null && $section['requires_review'];
    }

    /**
     * The columns of a table that go through the queue.
     *
     * @return list<string>
     */
    public static function reviewedFields(string $table = 'creators'): array
    {
        $out = [];

        foreach (self::fieldIndex() as $key => $section) {
            if ($section['requires_review'] && str_starts_with($key, $table . '.')) {
                $out[] = substr($key, strlen($table) + 1);
            }
        }

        return $out;
    }

    /** Is the hold-for-review queue switched on? */
    public static function enabled(): bool
    {
        return Config::settingBool('creator', 'hold_changes_for_review', false);
    }

    /** How long a submission may sit before the queue calls it overdue. */
    public static function slaHours(): int
    {
        return max(1, Config::settingInt('creator', 'change_review_sla_hours', 48));
    }

    /**
     * The rejection reasons offered in one moderation context.
     *
     * [$context] is 'profile_change' for a queued field, or a
     * `creator_portfolio`.`collection` value for an item.
     *
     * @return list<array<string,mixed>>
     */
    public static function reasons(string $context): array
    {
        $out = [];

        foreach (TaxonomyService::terms(self::REASON_TAXONOMY) as $term) {
            $meta      = is_array($term['metadata'] ?? null) ? $term['metadata'] : [];
            $appliesTo = array_map('strval', (array) ($meta['applies_to'] ?? []));

            if (!self::reasonApplies($appliesTo, $context)) {
                continue;
            }

            $out[] = [
                'value'         => (string) $term['value'],
                'label'         => (string) $term['label'],
                'description'   => (string) ($term['description'] ?? ''),
                'icon'          => (string) ($term['icon'] ?? 'circle'),
                'color'         => (string) ($term['color'] ?? '#64748B'),
                'notify'        => (bool) ($meta['notify'] ?? false),
                'requires_note' => (bool) ($meta['requires_note'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * A reason offered for a context it does not cover is a reporting error
     * that only shows up months later, when "why were items rejected" returns a
     * reason that was never meant for items. Cheaper to refuse it here.
     *
     * `other` deliberately carries `requires_note`, so refusing without a note
     * while claiming no listed reason applies is not possible either.
     */
    public static function assertReason(string $reason, string $context, ?string $note = null): void
    {
        foreach (self::reasons($context) as $term) {
            if ($term['value'] !== $reason) {
                continue;
            }

            if ($term['requires_note'] && trim((string) $note) === '') {
                throw HttpException::unprocessable(
                    'The "' . $term['label'] . '" reason needs a note explaining the decision.',
                    'note_required'
                );
            }

            return;
        }

        throw HttpException::unprocessable(
            'Not a moderation reason for this decision: ' . $reason,
            'unknown_reason'
        );
    }

    /**
     * A collection an operator adds later — 035 already seeds five — is named by
     * no term's `applies_to`, and refusing every reason for it would leave that
     * collection unmoderatable until somebody edited seventeen taxonomy rows.
     * Media collections therefore fall back to the reasons that apply to
     * portfolio or gallery, which is what "media" means in that list.
     *
     * @param list<string> $appliesTo
     */
    private static function reasonApplies(array $appliesTo, string $context): bool
    {
        if (in_array($context, $appliesTo, true)) {
            return true;
        }

        if ($context === self::REASON_CONTEXT_CHANGE) {
            return false;
        }

        return in_array('portfolio', $appliesTo, true) || in_array('gallery', $appliesTo, true);
    }

    // =================================================================
    // The entry point CreatorController::updateProfile() calls
    // =================================================================

    /**
     * Split a set of proposed updates into what may go live now and what has to
     * wait for an administrator.
     *
     * This is the ONE method the profile write path needs. With the operator's
     * switch off it is a pass-through and touches no table, so wiring it in
     * cannot change today's behaviour; with the switch on, the reviewed fields
     * are diverted into a change request and the caller writes only the rest.
     *
     * The caller keeps its own concerns — locks, handle collision, category
     * sets, the `pending_review` status flip. This decides one thing: which of
     * these values is allowed to be true right now.
     *
     * @param  array<string,mixed> $updates  field => proposed value, already validated
     * @param  array<string,mixed> $existing the live `creators` row
     * @param  array<string,mixed> $context  actor_type, actor_id, actor_name, ip
     * @return array{enabled:bool,apply:array<string,mixed>,held:array<string,mixed>,request_id:int|null,message:string|null}
     */
    public static function screenUpdates(int $creatorId, array $updates, array $existing, array $context = []): array
    {
        if (!self::enabled() || $updates === []) {
            return [
                'enabled'    => self::enabled(),
                'apply'      => $updates,
                'held'       => [],
                'request_id' => null,
                'message'    => null,
            ];
        }

        $apply = [];
        $held  = [];

        foreach ($updates as $field => $value) {
            $name = (string) $field;

            // Unchanged values are not a change to hold. The apps PUT the whole
            // form, so without this every save of an unrelated field would
            // raise a request proposing the display name it already has.
            //
            // Compared through comparable() rather than stringify(): the guard
            // was silently dead for every DECIMAL column, because MySQL returns
            // '22500.00' where PHP sends the float 22500.0.
            if (
                self::requiresReview($name)
                && self::comparable('creators', $name, $value)
                    !== self::comparable('creators', $name, $existing[$name] ?? null)
            ) {
                $held[$name] = $value;
                continue;
            }

            $apply[$name] = $value;
        }

        $requestId = $held === [] ? null : self::raise($creatorId, $held, $context);

        return [
            'enabled'    => true,
            'apply'      => $apply,
            'held'       => $held,
            'request_id' => $requestId,
            'message'    => $held === [] ? null : self::heldMessage(array_keys($held)),
        ];
    }

    /**
     * What the creator is told when part of their save is waiting.
     *
     * Named per SECTION rather than per column, because "Bio and about" is what
     * the creator saw on the form; `about` is a column name they never met.
     *
     * @param list<string> $fields
     */
    private static function heldMessage(array $fields): string
    {
        $labels = self::sectionLabels($fields);

        $list = count($labels) === 1
            ? $labels[0]
            : implode(', ', array_slice($labels, 0, -1)) . ' and ' . end($labels);

        return $list . (count($labels) === 1 ? ' is' : ' are')
            . ' waiting for an administrator to review. Everything else has been saved.';
    }

    /**
     * The distinct section labels covering a set of columns, in taxonomy order.
     *
     * @param  list<string> $fields
     * @return list<string>
     */
    private static function sectionLabels(array $fields, string $table = 'creators'): array
    {
        $labels = [];

        foreach ($fields as $field) {
            $section = self::sectionForField((string) $field, $table);

            $labels[$section['value'] ?? (string) $field] = $section['label'] ?? ucfirst(str_replace('_', ' ', (string) $field));
        }

        return array_values($labels);
    }

    // =================================================================
    // Raising a request
    // =================================================================

    /**
     * Record a set of proposed values as a change request.
     *
     * The old value is read and stored HERE, at submission time, and never
     * rewritten. That is the entire point of the table: today the old value is
     * destroyed by the update before anyone sees it, so the reviewer is shown a
     * "change" with only one side.
     *
     * Returns null when nothing actually differs, so a creator pressing Save on
     * an unchanged form does not fill the queue with empty submissions.
     *
     * @param  array<string,mixed> $proposed field => proposed value
     * @param  array<string,mixed> $context  actor_type, actor_id, actor_name, ip,
     *                                       target_table, target_id, note
     * @return int|null The new request id.
     */
    public static function raise(int $creatorId, array $proposed, array $context = []): ?int
    {
        $creator = self::creator($creatorId);

        $table    = (string) ($context['target_table'] ?? 'creators');
        $targetId = isset($context['target_id']) ? (int) $context['target_id'] : null;

        // Every field name is resolved before the target row is read. A change
        // to a section this queue must never touch — contact, documents,
        // verification — is a malformed request whatever row it names, and
        // checking the row first answered it with "that row does not exist",
        // which sends the reader looking for the wrong bug.
        $sectionOf = [];

        foreach (array_keys($proposed) as $field) {
            $name    = (string) $field;
            $section = self::sectionForField($name, $table);

            if ($section === null) {
                self::refuseUnqueueableField($name, $table);
            }

            $sectionOf[$name] = $section;
        }

        // ONE answer to "which row is this?", used by both halves of the
        // request. See {@see resolveTarget()} for why that has to be one call
        // and not two agreeing conventions.
        [$targetId, $current] = self::resolveTarget($creatorId, $creator, $table, $targetId);

        $candidates = [];

        foreach ($proposed as $field => $newValue) {
            $name    = (string) $field;
            $section = $sectionOf[$name];

            $oldValue = $current[$name] ?? null;

            // Compared in the shape the COLUMN stores, not the shape PHP
            // happens to hand over — see {@see comparable()}.
            $old = self::comparable($table, $name, $oldValue);
            $new = self::comparable($table, $name, $newValue);

            if ($old === $new) {
                continue;
            }

            $candidates[] = [
                'section'    => (string) $section['value'],
                'field'      => $name,
                'value_type' => self::valueType($table, $name, $newValue),
                // Stored in the same normalised shape they were compared in, so
                // the reviewer's "before / after" is like for like and does not
                // read 22500.00 -> 22500 for a price that only moved by 100.
                'old_value'  => $old,
                'new_value'  => $new,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        /** @var int $requestId */
        $requestId = Database::transaction(static function () use ($creatorId, $creator, $candidates, $context, $table, $targetId): int {
            // The profile as it stood before this submission, so the comparison
            // screen has a fixed left-hand side even after the request is
            // approved and the live row has moved on.
            $revisionId = self::snapshot(
                $creatorId,
                $creator,
                'Profile as it stood when a change was proposed.',
                $context
            );

            $now = date('Y-m-d H:i:s');
            $ip  = $context['ip'] ?? null;

            $requestId = Database::insert('creator_change_requests', [
                'creator_id'        => $creatorId,
                'summary'           => self::summarise(array_column($candidates, 'field'), $table),
                'requested_by_type' => self::actorType($context),
                'requested_by_id'   => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
                'requested_by_name' => isset($context['actor_name'])
                    ? mb_substr((string) $context['actor_name'], 0, 150)
                    : null,
                'status'            => 'pending',
                'decision_note'     => isset($context['note']) ? mb_substr((string) $context['note'], 0, 1000) : null,
                'revision_id'       => $revisionId > 0 ? $revisionId : null,
                'submitted_ip'      => is_string($ip) ? (@inet_pton($ip) ?: null) : null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            foreach ($candidates as $candidate) {
                // A creator who edits their bio on Monday and again on Wednesday
                // has two proposals for one column. MySQL cannot express "at
                // most one PENDING row per field" as a constraint, so
                // supersession is the application's job — 038 says as much and
                // adds `idx_ccf_open` to make this lookup a single index hit.
                self::supersedeOpenFields($creatorId, $table, $targetId, $candidate['field'], $requestId);

                Database::insert('creator_change_fields', [
                    'request_id'   => $requestId,
                    'creator_id'   => $creatorId,
                    'section'      => $candidate['section'],
                    'field'        => $candidate['field'],
                    'target_table' => $table,
                    'target_id'    => $targetId,
                    'value_type'   => $candidate['value_type'],
                    'old_value'    => $candidate['old_value'],
                    'new_value'    => $candidate['new_value'],
                    'status'       => 'pending',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }

            // A request whose every field has just been superseded is no longer
            // a decision anybody has to take. Left pending it would sit in the
            // queue for ever, showing an administrator a proposal that a newer
            // one has already replaced.
            self::closeEmptyRequests($creatorId);

            return $requestId;
        });

        self::audit(
            'creator.change_requested',
            $creatorId,
            count($candidates) . ' field(s) proposed for review.',
            ['request_id' => $requestId, 'fields' => array_column($candidates, 'field')],
            $context
        );

        return $requestId;
    }

    /**
     * Decide WHICH ROW a request addresses, and prove it is the creator's own.
     *
     * This used to be answered twice, differently, and the two answers
     * disagreed. raise() read the OLD value off `creators`.`$creatorId`
     * whenever the table was `creators`, ignoring `target_id` entirely, while
     * {@see applyValue()} writes to `target_id` in preference to `creator_id`.
     * A caller passing `target_table` = 'creators' with somebody else's
     * `target_id` therefore recorded one creator's "before" and, on approval,
     * wrote the "after" onto a DIFFERENT creator's profile — while the queue
     * row, the activity log and the `revisions` snapshot all named the first.
     * Both the audit trail and the applied result were wrong.
     *
     * Resolving it once, here, is the only shape in which the two halves cannot
     * drift apart again: the row whose old value is read IS the row the new
     * value will be applied to, because there is only one row.
     *
     * The id is normalised to null for `creators`, because `creator_id` already
     * names that row and {@see supersedeOpenFields()} matches open proposals on
     * `target_id <=> :target_id`.
     *
     * @param  array<string,mixed> $creator The live `creators` row.
     * @return array{0:int|null,1:array<string,mixed>} [target id to store, the row itself]
     */
    private static function resolveTarget(int $creatorId, array $creator, string $table, ?int $targetId): array
    {
        if ($table === 'creators') {
            // Not "ignore it" and not "trust it": a request that names another
            // profile is a request to change somebody else, which is never a
            // thing this queue may express, however it got here.
            if ($targetId !== null && $targetId !== $creatorId) {
                throw HttpException::forbidden(
                    'A change request for creator ' . $creatorId
                    . ' cannot name profile ' . $targetId . '.'
                );
            }

            return [null, $creator];
        }

        // A satellite table this queue writes has to be able to say WHOSE row
        // it is, or "the row belongs to this creator" is a claim nothing can
        // check and the ownership rule above becomes decorative. Refusing is
        // the safe answer; the alternative is trusting the caller's id.
        if (!self::hasColumn($table, 'creator_id')) {
            throw HttpException::unprocessable(
                $table . ' does not record which creator a row belongs to, so a change'
                . ' to it cannot be proved to be this creator\'s to make.',
                'target_unownable'
            );
        }

        $row = self::targetRow($table, $targetId);

        if ((int) ($row['creator_id'] ?? 0) !== $creatorId) {
            throw HttpException::forbidden(
                'That ' . $table . ' row belongs to another creator, so no change to it can be raised here.'
            );
        }

        return [(int) $targetId, $row];
    }

    /**
     * Say precisely why a column cannot be queued, because "unknown field" sends
     * the reader looking for a typo when the real answer is that another module
     * owns the decision.
     */
    private static function refuseUnqueueableField(string $field, string $table): never
    {
        foreach (self::sections() as $section) {
            if ($section['table'] !== $table || !in_array($field, $section['fields'], true)) {
                continue;
            }

            if ($section['handled_in'] !== null) {
                throw HttpException::unprocessable(
                    $section['label'] . ' is changed through its own verified flow, not the review queue.',
                    'handled_elsewhere'
                );
            }

            if ($section['reviewed_in'] !== null) {
                throw HttpException::unprocessable(
                    $section['label'] . ' is reviewed in the ' . $section['reviewed_in'] . ' module.',
                    'handled_elsewhere'
                );
            }

            if ($section['admin_only']) {
                throw HttpException::unprocessable(
                    $section['label'] . ' is only ever set by an administrator.',
                    'admin_only'
                );
            }

            if ($section['moderated']) {
                throw HttpException::unprocessable(
                    $section['label'] . ' items are moderated one at a time, not proposed as a field change.',
                    'moderated_section'
                );
            }
        }

        throw HttpException::unprocessable(
            'Not a reviewable profile field: ' . $table . '.' . $field,
            'unknown_field'
        );
    }

    /** "Display name, headline and 2 more" — enough to render a queue row. */
    private static function summarise(array $fields, string $table = 'creators'): string
    {
        $labels = self::sectionLabels($fields, $table);

        if (count($labels) <= 2) {
            return mb_substr(implode(', ', $labels), 0, 255);
        }

        return mb_substr(
            implode(', ', array_slice($labels, 0, 2)) . ' and ' . (count($labels) - 2) . ' more',
            0,
            255
        );
    }

    /**
     * Step older open proposals for the same field aside.
     *
     * `<=>` rather than `=` because `target_id` is null for every change to
     * `creators` itself, and `NULL = NULL` is null, so a plain comparison would
     * match nothing and every bio edit would leave its predecessor pending.
     */
    private static function supersedeOpenFields(
        int $creatorId,
        string $table,
        ?int $targetId,
        string $field,
        int $exceptRequestId
    ): void {
        Database::execute(
            'UPDATE `creator_change_fields`
                SET `status` = "superseded", `updated_at` = NOW()
              WHERE `creator_id` = :creator
                AND `status` = "pending"
                AND `target_table` = :target_table
                AND `target_id` <=> :target_id
                AND `field` = :field
                AND `request_id` <> :request',
            [
                'creator'      => $creatorId,
                'target_table' => $table,
                'target_id'    => $targetId,
                'field'        => $field,
                'request'      => $exceptRequestId,
            ]
        );
    }

    /** Retire parents that have no pending field left. */
    private static function closeEmptyRequests(int $creatorId): void
    {
        Database::execute(
            'UPDATE `creator_change_requests` r
                SET r.`status` = "superseded", r.`updated_at` = NOW()
              WHERE r.`creator_id` = :creator
                AND r.`status` = "pending"
                AND NOT EXISTS (
                      SELECT 1 FROM `creator_change_fields` f
                       WHERE f.`request_id` = r.`id` AND f.`status` = "pending"
                    )',
            ['creator' => $creatorId]
        );
    }

    // =================================================================
    // Deciding
    // =================================================================

    /**
     * Approve one field: write the proposed value onto the live row.
     *
     * @param  array<string,mixed> $context actor_id, actor_name, note, ip
     * @return array<string,mixed> The decided field row.
     */
    public static function approveField(int $fieldId, array $context = []): array
    {
        return self::decideField($fieldId, 'approved', null, $context);
    }

    /**
     * Reject one field: the proposed value is discarded and the live row is
     * left exactly as it was.
     *
     * @param array<string,mixed> $context actor_id, actor_name, note, ip
     */
    public static function rejectField(int $fieldId, string $reason, array $context = []): array
    {
        return self::decideField($fieldId, 'rejected', $reason, $context);
    }

    /**
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function decideField(int $fieldId, string $decision, ?string $reason, array $context): array
    {
        if ($reason !== null) {
            self::assertReason($reason, self::REASON_CONTEXT_CHANGE, $context['note'] ?? null);
        }

        /** @var array<string,mixed> $decided */
        $decided = Database::transaction(static function () use ($fieldId, $decision, $reason, $context): array {
            // Which request this field belongs to, read WITHOUT a lock, purely
            // so the locks below can be taken parent-then-child — the same
            // order decideRequest() takes them in. Two admins deciding the same
            // request from the two screens would otherwise be able to grab the
            // pair in opposite orders and deadlock.
            $requestId = (int) (Database::scalar(
                'SELECT `request_id` FROM `creator_change_fields` WHERE `id` = :id',
                ['id' => $fieldId]
            ) ?? 0);

            if ($requestId <= 0) {
                throw HttpException::notFound('That proposed change no longer exists.');
            }

            $request = Database::selectOne(
                'SELECT * FROM `creator_change_requests` WHERE `id` = :id FOR UPDATE',
                ['id' => $requestId]
            );

            if ($request === null) {
                throw HttpException::notFound('The request this change belongs to no longer exists.');
            }

            // A field can only be pending under an OPEN parent: rollUp() never
            // closes a request while one of its fields still waits. So a
            // pending field under a closed parent means something outside this
            // service wrote to these tables — and deciding it anyway would flip
            // a WITHDRAWN request to 'approved' and push its value live, which
            // is exactly the outcome withdrawing was meant to prevent.
            // withdraw() has always made this check; the deciding paths did not.
            if ($request['status'] !== 'pending') {
                throw HttpException::conflict(
                    'This request was ' . (string) $request['status']
                    . ' and is no longer open for a decision.',
                    'request_closed'
                );
            }

            // Locked, not merely read: two administrators opening the same
            // request and both pressing Approve would otherwise both pass the
            // "is it still pending" test and both write the value, producing two
            // audit entries for one decision.
            $field = Database::selectOne(
                'SELECT * FROM `creator_change_fields` WHERE `id` = :id FOR UPDATE',
                ['id' => $fieldId]
            );

            if ($field === null) {
                throw HttpException::notFound('That proposed change no longer exists.');
            }

            if ($field['status'] !== 'pending') {
                throw HttpException::conflict(
                    'That change was already ' . $field['status'] . '.',
                    'already_decided'
                );
            }

            $now = date('Y-m-d H:i:s');

            if ($decision === 'approved') {
                self::applyValue($field);
            }

            Database::update('creator_change_fields', [
                'status'           => $decision,
                // Approving is an opinion; applying is a write. 038 keeps them
                // apart so "approved but never written" is a retriable list
                // rather than a lie.
                'applied_at'       => $decision === 'approved' ? $now : null,
                'reviewed_by'      => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
                'reviewed_at'      => $now,
                'decision_note'    => isset($context['note']) ? mb_substr((string) $context['note'], 0, 500) : null,
                'rejection_reason' => $reason,
                'updated_at'       => $now,
            ], ['id' => $fieldId]);

            // The reason travels up too: when the last pending field is refused
            // one at a time, the parent becomes 'rejected' and a parent that
            // says rejected with no reason is unreportable.
            self::rollUp((int) $field['request_id'], $context, $reason);

            /** @var array<string,mixed> $fresh */
            $fresh = Database::selectOne('SELECT * FROM `creator_change_fields` WHERE `id` = :id', ['id' => $fieldId]);

            return $fresh;
        });

        self::audit(
            $decision === 'approved' ? 'creator.change_approved' : 'creator.change_rejected',
            (int) $decided['creator_id'],
            ucfirst($decision) . ' the proposed ' . (string) $decided['field'] . '.',
            [
                'request_id' => (int) $decided['request_id'],
                'field_id'   => $fieldId,
                'field'      => $decided['field'],
                'reason'     => $reason,
            ],
            $context
        );

        return $decided;
    }

    /**
     * Approve everything still pending on a request.
     *
     * One transaction, so a submission that moves five fields either lands
     * whole or not at all — a handle taken while the request sat in the queue
     * must not leave the creator with a new bio and their old name.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed> The decided request row.
     */
    public static function approve(int $requestId, array $context = []): array
    {
        return self::decideRequest($requestId, 'approved', null, $context);
    }

    /**
     * Reject everything still pending on a request.
     *
     * @param array<string,mixed> $context
     */
    public static function reject(int $requestId, string $reason, array $context = []): array
    {
        return self::decideRequest($requestId, 'rejected', $reason, $context);
    }

    /**
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function decideRequest(int $requestId, string $decision, ?string $reason, array $context): array
    {
        if ($reason !== null) {
            self::assertReason($reason, self::REASON_CONTEXT_CHANGE, $context['note'] ?? null);
        }

        /** @var array<string,mixed> $request */
        $request = Database::transaction(static function () use ($requestId, $decision, $reason, $context): array {
            $request = Database::selectOne(
                'SELECT * FROM `creator_change_requests` WHERE `id` = :id FOR UPDATE',
                ['id' => $requestId]
            );

            if ($request === null) {
                throw HttpException::notFound('Change request not found.');
            }

            $pending = Database::select(
                'SELECT * FROM `creator_change_fields`
                  WHERE `request_id` = :id AND `status` = "pending"
                  ORDER BY `id` FOR UPDATE',
                ['id' => $requestId]
            );

            if ($pending === []) {
                throw HttpException::conflict(
                    'Every field on this request has already been decided.',
                    'already_decided'
                );
            }

            // Checked AFTER the field sweep so a fully decided request keeps
            // its more precise message. What it catches is the other shape:
            // pending fields hanging under a parent that is no longer open,
            // which deciding would silently reopen. See decideField().
            if ($request['status'] !== 'pending') {
                throw HttpException::conflict(
                    'This request was ' . (string) $request['status']
                    . ' and is no longer open for a decision.',
                    'request_closed'
                );
            }

            $now = date('Y-m-d H:i:s');

            foreach ($pending as $field) {
                if ($decision === 'approved') {
                    self::applyValue($field);
                }

                Database::update('creator_change_fields', [
                    'status'           => $decision,
                    'applied_at'       => $decision === 'approved' ? $now : null,
                    'reviewed_by'      => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
                    'reviewed_at'      => $now,
                    'decision_note'    => isset($context['note']) ? mb_substr((string) $context['note'], 0, 500) : null,
                    'rejection_reason' => $reason,
                    'updated_at'       => $now,
                ], ['id' => (int) $field['id']]);
            }

            self::rollUp($requestId, $context, $reason);

            /** @var array<string,mixed> $fresh */
            $fresh = Database::selectOne('SELECT * FROM `creator_change_requests` WHERE `id` = :id', ['id' => $requestId]);

            return $fresh;
        });

        self::audit(
            $decision === 'approved' ? 'creator.change_approved' : 'creator.change_rejected',
            (int) $request['creator_id'],
            ucfirst($decision) . ' the profile change request.',
            ['request_id' => $requestId, 'reason' => $reason],
            $context
        );

        return $request;
    }

    /**
     * Take a request out of the queue without recording a verdict against the
     * creator.
     *
     * A proposal can stop being a question — the creator asked for it to be
     * dropped, or the value has since been changed another way — and neither
     * "approved" nor "rejected" would be true of it. The field rows go to
     * 'superseded' because 038 gives them no 'withdrawn' value: from the
     * field's point of view something else settled it.
     *
     * @param array<string,mixed> $context
     */
    public static function withdraw(int $requestId, array $context = []): array
    {
        /** @var array<string,mixed> $request */
        $request = Database::transaction(static function () use ($requestId, $context): array {
            $request = Database::selectOne(
                'SELECT * FROM `creator_change_requests` WHERE `id` = :id FOR UPDATE',
                ['id' => $requestId]
            );

            if ($request === null) {
                throw HttpException::notFound('Change request not found.');
            }

            if ($request['status'] !== 'pending') {
                throw HttpException::conflict(
                    'Only a pending request can be withdrawn.',
                    'already_decided'
                );
            }

            $now = date('Y-m-d H:i:s');

            Database::execute(
                'UPDATE `creator_change_fields`
                    SET `status` = "superseded", `updated_at` = :now
                  WHERE `request_id` = :id AND `status` = "pending"',
                ['id' => $requestId, 'now' => $now]
            );

            Database::update('creator_change_requests', [
                'status'        => 'withdrawn',
                'reviewed_by'   => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
                'reviewed_at'   => $now,
                'decision_note' => isset($context['note']) ? mb_substr((string) $context['note'], 0, 1000) : null,
                'updated_at'    => $now,
            ], ['id' => $requestId]);

            /** @var array<string,mixed> $fresh */
            $fresh = Database::selectOne('SELECT * FROM `creator_change_requests` WHERE `id` = :id', ['id' => $requestId]);

            return $fresh;
        });

        self::audit(
            'creator.change_withdrawn',
            (int) $request['creator_id'],
            'Withdrew the profile change request.',
            ['request_id' => $requestId],
            $context
        );

        return $request;
    }

    /**
     * Recompute the parent status from its fields.
     *
     * 'partial' is not a hedge: an administrator who accepts a new bio and
     * refuses the new handle has made both decisions, and the submission as a
     * whole is neither approved nor rejected. Without that value the row would
     * have to lie in one direction or the other.
     *
     * @param array<string,mixed> $context
     */
    private static function rollUp(int $requestId, array $context, ?string $reason = null): void
    {
        $counts = Database::selectOne(
            'SELECT
                SUM(`status` = "pending")    AS `pending`,
                SUM(`status` = "approved")   AS `approved`,
                SUM(`status` = "rejected")   AS `rejected`,
                SUM(`applied_at` IS NOT NULL) AS `applied`,
                COUNT(*)                     AS `total`
               FROM `creator_change_fields` WHERE `request_id` = :id',
            ['id' => $requestId]
        ) ?? [];

        $pending  = (int) ($counts['pending'] ?? 0);
        $approved = (int) ($counts['approved'] ?? 0);
        $rejected = (int) ($counts['rejected'] ?? 0);
        $applied  = (int) ($counts['applied'] ?? 0);

        $now = date('Y-m-d H:i:s');

        // Still open: record that it moved, but do not close it. A per-field
        // decision on a five-field submission leaves four questions unanswered.
        if ($pending > 0) {
            Database::update('creator_change_requests', ['updated_at' => $now], ['id' => $requestId]);

            return;
        }

        $status = match (true) {
            $approved > 0 && $rejected > 0 => 'partial',
            $approved > 0                  => 'approved',
            $rejected > 0                  => 'rejected',
            default                        => 'superseded',
        };

        Database::update('creator_change_requests', [
            'status'           => $status,
            'applied_at'       => $applied > 0 ? $now : null,
            'reviewed_by'      => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            'reviewed_at'      => $now,
            'decision_note'    => isset($context['note']) ? mb_substr((string) $context['note'], 0, 1000) : null,
            'rejection_reason' => $status === 'rejected' ? $reason : null,
            'updated_at'       => $now,
        ], ['id' => $requestId]);
    }

    /**
     * Write an approved value onto the row it belongs to.
     *
     * Every identifier is checked against the live schema before it reaches
     * SQL. The rows are written by this service and could only name a column
     * the taxonomy names, but a table name that came out of a database row and
     * goes back into an UPDATE is exactly the shape that stops being safe the
     * day somebody else writes to these tables.
     *
     * @param array<string,mixed> $field A `creator_change_fields` row.
     */
    private static function applyValue(array $field): void
    {
        $table  = (string) $field['target_table'];
        $column = $field['field'] === null ? '' : (string) $field['field'];

        if ($column === '') {
            throw HttpException::conflict(
                'This change names no single column, so it cannot be applied automatically.',
                'not_applicable'
            );
        }

        if (self::sectionForField($column, $table) === null) {
            throw HttpException::conflict(
                $table . '.' . $column . ' is no longer a reviewable field, so this change cannot be applied.',
                'field_retired'
            );
        }

        $id = $field['target_id'] !== null
            ? (int) $field['target_id']
            : ($table === 'creators' ? (int) $field['creator_id'] : 0);

        if ($id <= 0) {
            throw HttpException::conflict('This change does not say which row to write.', 'no_target');
        }

        // raise() proves the row is the creator's own at submission time. It is
        // proved AGAIN here because that guarantee is only as good as whatever
        // wrote the row, and this is the statement that does the damage: a
        // `creator_change_fields` row produced by a data fix, an import or a
        // later module must not be able to write one creator's approved value
        // onto another creator's record.
        self::assertTargetOwnedByCreator($table, $id, (int) $field['creator_id']);

        $value = self::castForColumn($table, $column, (string) $field['value_type'], $field['new_value']);

        // Refused BEFORE the UPDATE, so the administrator is told what is wrong
        // with the value instead of being shown an unexplained 500 — see
        // assertColumnAccepts().
        self::assertColumnAccepts($table, $column, $value);

        // A handle is unique across creators and this request may have sat in
        // the queue for days. Checked here rather than trusted from submission
        // time, because the collision is created by whoever took the handle in
        // between, and the reviewer is the person who can be told about it.
        if ($table === 'creators' && $column === 'handle' && $value !== null) {
            $taken = Database::scalar(
                'SELECT 1 FROM `creators` WHERE `handle` = :handle AND `id` <> :id LIMIT 1',
                ['handle' => (string) $value, 'id' => $id]
            );

            if ($taken !== null) {
                throw HttpException::conflict(
                    'The handle "' . (string) $value . '" was taken while this request was waiting.',
                    'handle_taken'
                );
            }
        }

        $data = [$column => $value];

        if (self::hasColumn($table, 'updated_at')) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        try {
            Database::update($table, $data, ['id' => $id]);
        } catch (PDOException $e) {
            // The backstop for whatever the checks above do not enumerate — an
            // enum value retired since submission, a date the server will not
            // parse, a unique index this service does not know about.
            //
            // The transaction rolls back and the field stays pending either
            // way, so the decision is always retriable; what it must not be is
            // an unhandled throw, which App::runAdmin() can only render as an
            // unexpected-error 500 with nothing in it for the administrator who
            // pressed Approve.
            throw self::refuseWrite($e, $column);
        }
    }

    /**
     * Prove the row about to be written is the one this request's creator owns.
     *
     * @see resolveTarget() for why both ends of the request have to agree.
     */
    private static function assertTargetOwnedByCreator(string $table, int $id, int $creatorId): void
    {
        if ($table === 'creators') {
            if ($id !== $creatorId) {
                throw HttpException::conflict(
                    'This change was raised for creator ' . $creatorId . ' but names profile '
                    . $id . ', so it cannot be applied.',
                    'target_mismatch'
                );
            }

            return;
        }

        if (!self::hasColumn($table, 'creator_id')) {
            throw HttpException::conflict(
                $table . ' does not record which creator a row belongs to, so this change'
                . ' cannot be applied safely.',
                'target_unownable'
            );
        }

        $owner = Database::scalar(
            'SELECT `creator_id` FROM ' . Database::quoteIdentifier($table) . ' WHERE `id` = :id',
            ['id' => $id]
        );

        if ($owner === null) {
            throw HttpException::conflict(
                'The row this change refers to no longer exists.',
                'target_missing'
            );
        }

        if ((int) $owner !== $creatorId) {
            throw HttpException::conflict(
                'The row this change refers to now belongs to another creator, so it cannot be applied.',
                'target_mismatch'
            );
        }
    }

    /**
     * Refuse a value the column cannot physically hold, in the reviewer's words.
     *
     * Every case here reached MySQL as a raw PDOException before — 1048 for a
     * NULL in a NOT NULL column, 1406 "Data too long", 1264 "Out of range" —
     * and the admin panel could only show a generic 500 with no explanation of
     * which field or why. The person pressing Approve is precisely the person
     * who can act on "the proposed headline is 400 characters and the column
     * holds 150", so that is what they are told.
     *
     * Checked against the LIVE column definition rather than a length map,
     * because a map of column widths would be a fifth place in this codebase
     * where the shape of a creator profile is written down.
     */
    private static function assertColumnAccepts(string $table, string $column, mixed $value): void
    {
        if ($value === null || is_bool($value)) {
            return;
        }

        $meta = self::columnMeta($table)[$column] ?? null;

        if ($meta === null) {
            return;
        }

        $type  = strtolower($meta['type']);
        $label = RevisionService::fieldLabel($column);

        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match) === 1) {
            // Characters, not bytes: MySQL counts a utf8mb4 column in
            // characters, and an emoji in a display name is four bytes.
            $length = mb_strlen((string) $value);

            if ($length > (int) $match[1]) {
                throw HttpException::unprocessable(
                    'The proposed ' . $label . ' is ' . $length . ' characters long; '
                    . $table . '.' . $column . ' holds at most ' . $match[1] . '.',
                    'value_too_long'
                );
            }

            return;
        }

        // TEXT family limits ARE in bytes, which is why this counts differently
        // from the branch above.
        foreach (['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295] as $name => $limit) {
            if ($type !== $name && !str_starts_with($type, $name . ' ')) {
                continue;
            }

            $bytes = strlen((string) $value);

            if ($bytes > $limit) {
                throw HttpException::unprocessable(
                    'The proposed ' . $label . ' is ' . $bytes . ' bytes; '
                    . $table . '.' . $column . ' holds at most ' . $limit . '.',
                    'value_too_long'
                );
            }

            return;
        }

        $unsigned = str_contains($type, 'unsigned');

        if (preg_match('/^(?:decimal|numeric)\((\d+),(\d+)\)/', $type, $match) === 1) {
            // DECIMAL(p,s) holds p digits of which s are after the point, so
            // anything at or above 10^(p-s) has no representation in it. Unlike
            // an integer, UNSIGNED does not double the range — it only drops
            // the negative half.
            $ceiling = (float) (10 ** ((int) $match[1] - (int) $match[2]));

            self::assertNumberFits((float) $value, $unsigned ? 0.0 : -$ceiling, $ceiling, $unsigned, $table, $column, $label);

            return;
        }

        // `bigint` is deliberately absent: its unsigned maximum is larger than
        // PHP_INT_MAX, so a range check written in PHP floats would reject
        // values the column accepts. The PDOException backstop covers it.
        $halves = [
            'tinyint'   => 128,
            'smallint'  => 32768,
            'mediumint' => 8388608,
            'int'       => 2147483648,
        ];

        foreach ($halves as $name => $half) {
            if (!str_starts_with($type, $name)) {
                continue;
            }

            // Two's complement: signed spans [-half, half), unsigned [0, 2*half).
            self::assertNumberFits(
                (float) $value,
                $unsigned ? 0.0 : -(float) $half,
                $unsigned ? (float) $half * 2 : (float) $half,
                $unsigned,
                $table,
                $column,
                $label
            );

            return;
        }
    }

    /** [$min, $max) — $max is the first value the column can no longer hold. */
    private static function assertNumberFits(
        float $value,
        float $min,
        float $max,
        bool $unsigned,
        string $table,
        string $column,
        string $label
    ): void {
        if ($unsigned && $value < 0) {
            throw HttpException::unprocessable(
                'The proposed ' . $label . ' is negative and ' . $table . '.' . $column
                . ' cannot hold a negative number.',
                'value_out_of_range'
            );
        }

        if ($value >= $max || $value < $min) {
            throw HttpException::unprocessable(
                'The proposed ' . $label . ' is outside the range ' . $table . '.' . $column . ' can hold.',
                'value_out_of_range'
            );
        }
    }

    /**
     * Turn a driver-level refusal into something an operator can act on.
     *
     * A duplicate key is a CONFLICT — somebody else got there first, and the
     * decision can be retried once that is sorted out. Everything else is the
     * proposed value being unacceptable, which is a 422.
     */
    private static function refuseWrite(PDOException $e, string $column): HttpException
    {
        $reason = mb_substr((string) ($e->errorInfo[2] ?? $e->getMessage()), 0, 200);
        $label  = RevisionService::fieldLabel($column);

        if ((string) ($e->errorInfo[1] ?? '') === '1062') {
            return HttpException::conflict(
                'The proposed ' . $label . ' is already taken: ' . $reason,
                'duplicate_value'
            );
        }

        return HttpException::unprocessable(
            'The database refused the proposed ' . $label . ': ' . $reason,
            'write_refused'
        );
    }

    /**
     * Turn a stored string back into something the column will accept.
     *
     * Both value columns are LONGTEXT because an `about` is 5,000 characters and
     * a `languages` value is a JSON array. `value_type` is what tells the write
     * back which of those it is holding — JSON stays a string because the column
     * it is going into stores it as one.
     */
    private static function castForColumn(string $table, string $column, string $valueType, mixed $stored): mixed
    {
        if ($stored === null || $stored === '') {
            if (!in_array($valueType, ['integer', 'decimal', 'boolean'], true)) {
                return $stored;
            }

            $meta = self::columnMeta($table)[$column] ?? null;

            // An empty proposal for a numeric column is an erasure, not a
            // zero — but only a NULLABLE column can be erased. `price_min` is
            // `decimal(12,2) NOT NULL DEFAULT '0.00'` under
            // STRICT_TRANS_TABLES, so handing it NULL made approving an empty
            // price a raw 1048 and an unexplained 500. For a NOT NULL column
            // the column's OWN DEFAULT is what "no value" means, which is the
            // same answer an INSERT that omitted it would get.
            if ($meta === null || $meta['nullable']) {
                return null;
            }

            $default = $meta['default'];

            if ($default === null || $default === '') {
                return $valueType === 'decimal' ? 0.0 : 0;
            }

            return $valueType === 'decimal' ? (float) $default : (int) $default;
        }

        return match ($valueType) {
            'integer' => (int) $stored,
            'decimal' => (float) $stored,
            'boolean' => in_array(strtolower((string) $stored), ['1', 'true', 'yes', 'on'], true) ? 1 : 0,
            default   => (string) $stored,
        };
    }

    // =================================================================
    // Portfolio and gallery item moderation
    // =================================================================

    /**
     * The verdict a newly uploaded item should start with.
     *
     * `creator.moderate_gallery_uploads` has been switched ON since long before
     * 038, with nowhere to record its outcome. The upload path calls this so
     * that "moderate uploads" finally means something, and everything already
     * published stays published because 038 defaults the column to 'approved'.
     */
    public static function initialModerationStatus(): string
    {
        return Config::settingBool('creator', 'moderate_gallery_uploads', false) ? 'pending' : 'approved';
    }

    /**
     * Approve one gallery or portfolio item.
     *
     * @param array<string,mixed> $context actor_id, actor_name, note, ip
     */
    public static function approveItem(int $itemId, array $context = []): array
    {
        return self::moderateItem($itemId, 'approved', null, $context);
    }

    /**
     * Reject one gallery or portfolio item, with a reason from the taxonomy.
     *
     * @param array<string,mixed> $context actor_id, actor_name, note, ip
     */
    public static function rejectItem(int $itemId, string $reason, array $context = []): array
    {
        return self::moderateItem($itemId, 'rejected', $reason, $context);
    }

    /**
     * The operator's verdict on one item.
     *
     * `is_active` is deliberately not touched. It is the CREATOR's control over
     * their own wall, and flipping it to hide something an operator refused
     * would be indistinguishable from the creator having hidden it themselves —
     * which is the state 038 was written to end. Both flags must pass for an
     * item to be publicly visible; each answers a different question.
     *
     * @param array<string,mixed> $context
     */
    private static function moderateItem(int $itemId, string $decision, ?string $reason, array $context): array
    {
        /** @var array<string,mixed> $item */
        $item = Database::transaction(static function () use ($itemId, $decision, $reason, $context): array {
            $item = Database::selectOne(
                'SELECT * FROM `creator_portfolio` WHERE `id` = :id AND `deleted_at` IS NULL FOR UPDATE',
                ['id' => $itemId]
            );

            if ($item === null) {
                throw HttpException::notFound('That item no longer exists.');
            }

            if ($reason !== null) {
                self::assertReason($reason, (string) $item['collection'], $context['note'] ?? null);
            }

            $now = date('Y-m-d H:i:s');

            Database::update('creator_portfolio', [
                'moderation_status' => $decision,
                // An approval clears whatever a previous rejection said, or the
                // item would read as approved and refused at the same time.
                'moderation_reason' => $decision === 'approved' ? null : $reason,
                'moderation_note'   => isset($context['note']) ? mb_substr((string) $context['note'], 0, 500) : null,
                'reviewed_by'       => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
                'reviewed_at'       => $now,
                'updated_at'        => $now,
            ], ['id' => $itemId]);

            /** @var array<string,mixed> $fresh */
            $fresh = Database::selectOne('SELECT * FROM `creator_portfolio` WHERE `id` = :id', ['id' => $itemId]);

            return $fresh;
        });

        self::audit(
            $decision === 'approved' ? 'creator.item_approved' : 'creator.item_rejected',
            (int) $item['creator_id'],
            ucfirst($decision) . ' a ' . (string) $item['collection'] . ' item.',
            ['item_id' => $itemId, 'collection' => $item['collection'], 'reason' => $reason],
            $context
        );

        return $item;
    }

    /**
     * Items waiting for a verdict, oldest first — the queue 038's
     * `idx_portfolio_moderation` was added to serve.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function itemQueue(string $status = 'pending', int $perPage = 25, int $offset = 0): array
    {
        $status = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending';

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_portfolio`
              WHERE `moderation_status` = :status AND `deleted_at` IS NULL',
            ['status' => $status]
        );

        $rows = Database::select(
            'SELECT p.*, c.`display_name`, c.`handle`, a.`name` AS `reviewer_name`
               FROM `creator_portfolio` p
               INNER JOIN `creators` c ON c.`id` = p.`creator_id`
               LEFT JOIN `admins` a ON a.`id` = p.`reviewed_by`
              WHERE p.`moderation_status` = :status AND p.`deleted_at` IS NULL
              ORDER BY p.`created_at` ASC, p.`id` ASC
              LIMIT ' . max(1, min(200, $perPage)) . ' OFFSET ' . max(0, $offset),
            ['status' => $status]
        );

        foreach ($rows as &$row) {
            $row['reason_label'] = $row['moderation_reason'] === null
                ? null
                : TaxonomyService::label(self::REASON_TAXONOMY, (string) $row['moderation_reason']);
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,int> */
    public static function itemCounts(): array
    {
        $row = Database::selectOne(
            'SELECT
                SUM(`moderation_status` = "pending")  AS `pending`,
                SUM(`moderation_status` = "approved") AS `approved`,
                SUM(`moderation_status` = "rejected") AS `rejected`
               FROM `creator_portfolio` WHERE `deleted_at` IS NULL'
        ) ?? [];

        return [
            'pending'  => (int) ($row['pending'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }

    // =================================================================
    // Reading, for the queue and the detail screen
    // =================================================================

    /**
     * The queue.
     *
     * @param  array{status?:string,creator_id?:int,search?:string,per_page?:int,offset?:int} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function queue(array $filters = []): array
    {
        $clauses  = [];
        $bindings = [];

        $status = (string) ($filters['status'] ?? 'pending');

        if ($status !== 'all') {
            $clauses[]          = 'r.`status` = :status';
            $bindings['status'] = in_array(
                $status,
                ['pending', 'approved', 'partial', 'rejected', 'withdrawn', 'superseded'],
                true
            ) ? $status : 'pending';
        }

        if (!empty($filters['creator_id'])) {
            $clauses[]              = 'r.`creator_id` = :creator_id';
            $bindings['creator_id'] = (int) $filters['creator_id'];
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $clauses[]          = '(c.`display_name` LIKE :search OR c.`handle` LIKE :search OR r.`summary` LIKE :search)';
            $bindings['search'] = '%' . $search . '%';
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_change_requests` r
               INNER JOIN `creators` c ON c.`id` = r.`creator_id`' . $where,
            $bindings
        );

        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 25)));
        $offset  = max(0, (int) ($filters['offset'] ?? 0));

        $rows = Database::select(
            'SELECT r.*, c.`display_name`, c.`handle`, c.`status` AS `creator_status`,
                    u.`avatar_url`, a.`name` AS `reviewer_name`,
                    (SELECT COUNT(*) FROM `creator_change_fields` f WHERE f.`request_id` = r.`id`) AS `field_count`,
                    (SELECT COUNT(*) FROM `creator_change_fields` f
                      WHERE f.`request_id` = r.`id` AND f.`status` = "pending") AS `pending_count`
               FROM `creator_change_requests` r
               INNER JOIN `creators` c ON c.`id` = r.`creator_id`
               LEFT JOIN `users` u ON u.`id` = c.`user_id`
               LEFT JOIN `admins` a ON a.`id` = r.`reviewed_by`' . $where . '
              ORDER BY r.`created_at` ASC, r.`id` ASC
              LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );

        $slaSeconds = self::slaHours() * 3600;

        foreach ($rows as &$row) {
            $age = time() - strtotime((string) $row['created_at']);

            $row['age_hours'] = (int) floor($age / 3600);
            // Overdue is only a question about something still waiting.
            $row['overdue']   = $row['status'] === 'pending' && $age > $slaSeconds;
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,int> */
    public static function counts(): array
    {
        $row = Database::selectOne(
            'SELECT
                SUM(`status` = "pending")    AS `pending`,
                SUM(`status` = "approved")   AS `approved`,
                SUM(`status` = "partial")    AS `partial`,
                SUM(`status` = "rejected")   AS `rejected`,
                SUM(`status` = "withdrawn")  AS `withdrawn`,
                SUM(`status` = "superseded") AS `superseded`,
                COUNT(*)                     AS `all`
               FROM `creator_change_requests`'
        ) ?? [];

        $out = [];

        foreach (['pending', 'approved', 'partial', 'rejected', 'withdrawn', 'superseded', 'all'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        // Approved and never written: the retry list 038's `idx_cchr_unapplied`
        // exists for. An operator needs to see it, because the creator is being
        // told their change was accepted.
        $out['unapplied'] = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creator_change_requests`
              WHERE `status` IN ("approved","partial") AND `applied_at` IS NULL'
        );

        return $out;
    }

    /**
     * One request with everything the detail screen renders.
     *
     * @return array<string,mixed>|null
     */
    public static function find(int $requestId): ?array
    {
        $request = Database::selectOne(
            'SELECT r.*, c.`display_name`, c.`handle`, c.`status` AS `creator_status`,
                    u.`avatar_url`, u.`email`, a.`name` AS `reviewer_name`
               FROM `creator_change_requests` r
               INNER JOIN `creators` c ON c.`id` = r.`creator_id`
               LEFT JOIN `users` u ON u.`id` = c.`user_id`
               LEFT JOIN `admins` a ON a.`id` = r.`reviewed_by`
              WHERE r.`id` = :id',
            ['id' => $requestId]
        );

        if ($request === null) {
            return null;
        }

        $request['fields'] = self::fieldsFor($requestId);

        $age = time() - strtotime((string) $request['created_at']);

        $request['age_hours'] = (int) floor($age / 3600);
        $request['overdue']   = $request['status'] === 'pending' && $age > self::slaHours() * 3600;

        return $request;
    }

    /**
     * The proposed fields of one request, presented for a side-by-side render.
     *
     * @return list<array<string,mixed>>
     */
    public static function fieldsFor(int $requestId): array
    {
        $rows = Database::select(
            'SELECT f.*, a.`name` AS `reviewer_name`
               FROM `creator_change_fields` f
               LEFT JOIN `admins` a ON a.`id` = f.`reviewed_by`
              WHERE f.`request_id` = :id
              ORDER BY f.`id`',
            ['id' => $requestId]
        );

        foreach ($rows as &$row) {
            $section = self::section((string) $row['section']);

            $row['section_label'] = $section['label'] ?? ucfirst(str_replace('_', ' ', (string) $row['section']));
            $row['section_icon']  = $section['icon'] ?? 'circle';
            $row['field_label']   = $row['field'] === null
                ? ($section['label'] ?? (string) $row['section'])
                : RevisionService::fieldLabel((string) $row['field']);
            $row['reason_label']  = $row['rejection_reason'] === null
                ? null
                : TaxonomyService::label(self::REASON_TAXONOMY, (string) $row['rejection_reason']);

            // What the live row says RIGHT NOW, which is not necessarily what
            // `old_value` recorded: an administrator may have edited the profile
            // directly while the request waited, and approving would overwrite
            // that. The reviewer has to be able to see it.
            $row['live_value'] = self::liveValue($row);
            $row['drifted']    = $row['status'] === 'pending'
                && $row['live_value'] !== null
                && $row['live_value'] !== (string) $row['old_value'];
        }
        unset($row);

        return $rows;
    }

    /** The value the target row holds today, or null when it cannot be read. */
    private static function liveValue(array $field): ?string
    {
        $table  = (string) $field['target_table'];
        $column = $field['field'] === null ? '' : (string) $field['field'];

        if ($column === '' || !self::hasColumn($table, $column)) {
            return null;
        }

        $id = $field['target_id'] !== null
            ? (int) $field['target_id']
            : ($table === 'creators' ? (int) $field['creator_id'] : 0);

        if ($id <= 0) {
            return null;
        }

        // comparable(), not stringify(): `drifted` compares this against
        // `old_value`, which raise() normalised the same way. Comparing the two
        // through different rules would flag every decimal field as drifted the
        // moment it was queued.
        return self::comparable($table, $column, Database::scalar(
            'SELECT ' . Database::quoteIdentifier($column) . ' FROM ' . Database::quoteIdentifier($table) . ' WHERE `id` = :id',
            ['id' => $id]
        ));
    }

    /**
     * Everything still waiting for one creator — what the app shows so a
     * creator is not left wondering why their new headline is not on the page.
     *
     * @return list<array<string,mixed>>
     */
    public static function pendingForCreator(int $creatorId): array
    {
        $rows = Database::select(
            'SELECT f.`id`, f.`request_id`, f.`section`, f.`field`, f.`old_value`, f.`new_value`,
                    f.`value_type`, f.`created_at`
               FROM `creator_change_fields` f
              WHERE f.`creator_id` = :id AND f.`status` = "pending"
              ORDER BY f.`created_at`, f.`id`',
            ['id' => $creatorId]
        );

        foreach ($rows as &$row) {
            $section = self::section((string) $row['section']);

            $row['section_label'] = $section['label'] ?? (string) $row['section'];
        }
        unset($row);

        return $rows;
    }

    // =================================================================
    // Version history
    // =================================================================

    /**
     * The stored versions of one creator profile, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function versions(int $creatorId, int $limit = 20): array
    {
        return Database::select(
            'SELECT r.`id`, r.`version`, r.`change_note`, r.`actor_type`, r.`actor_id`,
                    r.`admin_name`, r.`created_at`
               FROM `revisions` r
              WHERE r.`entity_type` = :type AND r.`entity_id` = :id
              ORDER BY r.`version` DESC
              LIMIT ' . max(1, min(200, $limit)),
            ['type' => self::REVISION_ENTITY, 'id' => $creatorId]
        );
    }

    /**
     * Before and after, for the comparison screen.
     *
     * [$revisionId] is the left-hand side; null takes the newest stored
     * version. [$againstId] is the right-hand side; null compares against the
     * profile as it stands right now, which is the comparison an administrator
     * reviewing a queued change actually wants.
     *
     * The diff itself is RevisionService::diff() — there is one diff
     * implementation in this codebase and this is not a second one.
     *
     * @return array{from:array<string,mixed>|null,to:array<string,mixed>|null,changes:list<array<string,mixed>>}
     */
    public static function compare(int $creatorId, ?int $revisionId = null, ?int $againstId = null): array
    {
        $from = $revisionId !== null
            ? RevisionService::find($revisionId)
            : Database::selectOne(
                'SELECT * FROM `revisions`
                  WHERE `entity_type` = :type AND `entity_id` = :id
                  ORDER BY `version` DESC LIMIT 1',
                ['type' => self::REVISION_ENTITY, 'id' => $creatorId]
            );

        // A snapshot belonging to another record must never be rendered as this
        // creator's history.
        if (
            $from !== null
            && ((string) $from['entity_type'] !== self::REVISION_ENTITY || (int) $from['entity_id'] !== $creatorId)
        ) {
            $from = null;
        }

        $to = $againstId !== null ? RevisionService::find($againstId) : null;

        if (
            $againstId !== null
            && ($to === null
                || (string) $to['entity_type'] !== self::REVISION_ENTITY
                || (int) $to['entity_id'] !== $creatorId)
        ) {
            throw HttpException::notFound('That version does not belong to this creator.');
        }

        $before = $from === null ? [] : RevisionService::decode($from['snapshot']);

        $after = $to !== null
            ? RevisionService::decode($to['snapshot'])
            : (Database::selectOne('SELECT * FROM `creators` WHERE `id` = :id', ['id' => $creatorId]) ?? []);

        $changes = [];

        foreach (RevisionService::diff($before, $after) as $change) {
            if (in_array($change['field'], self::DERIVED_COLUMNS, true)) {
                continue;
            }

            $section = self::sectionForField((string) $change['field']);

            $change['section']       = $section['value'] ?? null;
            $change['section_label'] = $section['label'] ?? null;
            $change['reviewed']      = $section !== null && $section['requires_review'];

            $changes[] = $change;
        }

        return [
            'from'    => $from,
            'to'      => $to,
            'changes' => $changes,
        ];
    }

    /**
     * Store the creator profile as one version of `revisions`.
     *
     * Written here rather than through RevisionService::record() for two
     * reasons, both structural: its ENTITIES whitelist has no 'creator' entry,
     * so record() refuses the type outright; and its only attribution is
     * `admin_id`, while the point of the columns 038 added is that a creator
     * editing their own profile is not an administrator. `admin_id` and
     * `admin_name` are still written when the actor IS an admin, so the CMS
     * revision screens keep reading what they always read.
     *
     * @param  array<string,mixed> $row     the `creators` row to remember
     * @param  array<string,mixed> $context actor_type, actor_id, actor_name
     * @return int the new revision id, or 0 when it could not be stored
     */
    public static function snapshot(int $creatorId, array $row, string $note, array $context = []): int
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || $row === []) {
            return 0;
        }

        $actor = self::actorType($context);

        $bindings = [
            'entity_type' => self::REVISION_ENTITY,
            'entity_id'   => $creatorId,
            'snapshot'    => $json,
            'note'        => mb_substr($note, 0, 255),
            // `revisions`.`actor_type` is admin|user|system. A creator acts as
            // the `users` row behind the profile, which is what 'user' means
            // here and why `actor_id` is a BIGINT rather than the INT that
            // `admin_id` is.
            'actor_type'  => $actor === 'admin' ? 'admin' : ($actor === 'creator' ? 'user' : 'system'),
            'actor_id'    => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            'admin_id'    => $actor === 'admin' && isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            'admin_name'  => $actor === 'admin' && isset($context['actor_name'])
                ? mb_substr((string) $context['actor_name'], 0, 120)
                : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        // The version is computed inside the INSERT, so two writers cannot both
        // claim version N; UNIQUE (entity_type, entity_id, version) is the
        // backstop and a collision is retried. A snapshot must never be the
        // reason a creator's save fails, so three attempts and then give up
        // quietly — the request still goes through, only its left-hand side is
        // missing, which the comparison screen already degrades to.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                Database::execute(
                    'INSERT INTO `revisions`
                        (`entity_type`, `entity_id`, `version`, `snapshot`, `change_note`,
                         `actor_type`, `actor_id`, `admin_id`, `admin_name`, `created_at`)
                     SELECT :entity_type, :entity_id, COALESCE(MAX(r.`version`), 0) + 1,
                            :snapshot, :note, :actor_type, :actor_id, :admin_id, :admin_name, :created_at
                       FROM `revisions` r
                      WHERE r.`entity_type` = :entity_type AND r.`entity_id` = :entity_id',
                    $bindings
                );

                return (int) Database::scalar(
                    'SELECT `id` FROM `revisions`
                      WHERE `entity_type` = :type AND `entity_id` = :id
                      ORDER BY `version` DESC LIMIT 1',
                    ['type' => self::REVISION_ENTITY, 'id' => $creatorId]
                );
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000' && $attempt < 3) {
                    usleep(random_int(20_000, 80_000));
                    continue;
                }

                return 0;
            }
        }

        return 0;
    }

    // =================================================================
    // Internals
    // =================================================================

    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
    private static function targetRow(string $table, ?int $targetId): array
    {
        if ($targetId === null || $targetId <= 0) {
            throw HttpException::unprocessable(
                'A change to ' . $table . ' has to say which row it changes.',
                'target_required'
            );
        }

        $row = Database::selectOne(
            'SELECT * FROM ' . Database::quoteIdentifier($table) . ' WHERE `id` = :id',
            ['id' => $targetId]
        );

        if ($row === null) {
            throw HttpException::notFound('The row this change refers to no longer exists.');
        }

        return $row;
    }

    /**
     * `requested_by_type` is polymorphic and unconstrained by a foreign key: the
     * id is a `users`.`id` for a creator and an `admins`.`id` for an admin, so
     * an unrecognised value here would make the whole column unreadable.
     *
     * @param array<string,mixed> $context
     */
    private static function actorType(array $context): string
    {
        $type = (string) ($context['actor_type'] ?? 'creator');

        return in_array($type, ['creator', 'admin', 'system'], true) ? $type : 'system';
    }

    /**
     * One comparable string form for any value.
     *
     * MySQL hands back "1" where PHP supplied the integer 1, and an unchanged
     * field compared across that boundary reads as an edit. Same rule as
     * RevisionService::stringify(), which is why an array becomes its JSON.
     */
    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * One comparable string form for a value, in the shape its COLUMN stores it.
     *
     * stringify() alone is not enough once a typed column is involved, and the
     * "unchanged values are not a change to hold" guard was silently dead for
     * every DECIMAL column because of it. MySQL hands `decimal(12,2)` back as
     * '22500.00'; PHP sends the float 22500.0, which stringifies to '22500'.
     * Same number, different string — so a creator re-saving their OWN current
     * price raised a brand-new change request on every single save, each one
     * proposing the value already there, each one telling them pricing was
     * waiting for an administrator, and each one blocking the next price from
     * going live behind a queue of no-op decisions.
     *
     * The normalisation is driven by the LIVE column type and not by the value
     * merely looking numeric, deliberately: a handle of '007' and a handle of
     * '7' are both numeric strings and are two different handles. Only the
     * column knows that price_min is a number and handle is not.
     */
    private static function comparable(string $table, string $field, mixed $value): string
    {
        $string = self::stringify($value);

        // An empty proposal is an erasure, never the number zero — normalising
        // it to '0.00' here would hide a real clear-this-field edit. An array
        // is already canonical JSON out of stringify().
        if ($string === '' || is_array($value) || !is_numeric($string)) {
            return $string;
        }

        $type = strtolower((string) (self::columns($table)[$field] ?? ''));

        if (preg_match('/^(?:decimal|numeric)\((\d+),(\d+)\)/', $type, $match) === 1) {
            return number_format((float) $string, (int) $match[2], '.', '');
        }

        if (str_starts_with($type, 'double') || str_starts_with($type, 'float')) {
            return self::stringify((float) $string);
        }

        foreach (['tinyint', 'smallint', 'mediumint', 'bigint', 'int'] as $integer) {
            if (str_starts_with($type, $integer)) {
                return (string) (int) $string;
            }
        }

        return $string;
    }

    /**
     * What kind of value a column holds, so a diff can render as text, as a
     * number or as a structured comparison instead of the reader guessing from
     * the string.
     *
     * Read from the live schema rather than from a map, because the map would
     * be the fourth place in this codebase where the shape of a creator profile
     * is written down.
     */
    private static function valueType(string $table, string $field, mixed $value): string
    {
        if (is_array($value)) {
            return 'json';
        }

        if (str_ends_with($field, '_url')) {
            return 'url';
        }

        $type = strtolower((string) (self::columns($table)[$field] ?? ''));

        return match (true) {
            $type === 'tinyint(1)'                                      => 'boolean',
            str_starts_with($type, 'int'), str_starts_with($type, 'big'),
            str_starts_with($type, 'small'), str_starts_with($type, 'medium'),
            str_starts_with($type, 'tiny')                              => 'integer',
            str_starts_with($type, 'decimal'), str_starts_with($type, 'double'),
            str_starts_with($type, 'float')                             => 'decimal',
            str_starts_with($type, 'date'), str_starts_with($type, 'timestamp') => 'date',
            str_contains($type, 'text'), str_contains($type, 'json')    => self::looksLikeJson($value) ? 'json' : 'text',
            default                                                     => self::looksLikeJson($value) ? 'json' : 'string',
        };
    }

    private static function looksLikeJson(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || !in_array($trimmed[0], ['[', '{'], true)) {
            return false;
        }

        return is_array(json_decode($trimmed, true));
    }

    /**
     * column => type for a table, cached per process.
     *
     * @return array<string,string>
     */
    private static function columns(string $table): array
    {
        static $cache = [];

        return $cache[$table] ??= array_map(
            static fn (array $meta): string => $meta['type'],
            self::columnMeta($table)
        );
    }

    /**
     * Everything the write-back needs to know about a column, cached per
     * process — one SHOW COLUMNS per table for the whole request.
     *
     * The type alone was not enough. Whether a column is NULLABLE decides what
     * an empty proposal means ({@see castForColumn()}), and how wide it is
     * decides whether a proposal can be written at all
     * ({@see assertColumnAccepts()}); asking the live schema is what keeps
     * those two answers from becoming a hand-maintained list that drifts.
     *
     * @return array<string,array{type:string,nullable:bool,default:string|null}>
     */
    private static function columnMeta(string $table): array
    {
        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $out = [];

        try {
            foreach (Database::select('SHOW COLUMNS FROM ' . Database::quoteIdentifier($table)) as $column) {
                $out[(string) $column['Field']] = [
                    'type'     => (string) $column['Type'],
                    'nullable' => strtoupper((string) ($column['Null'] ?? 'YES')) === 'YES',
                    'default'  => $column['Default'] === null ? null : (string) $column['Default'],
                ];
            }
        } catch (\Throwable) {
            // A table the taxonomy names but the schema does not have is not an
            // error here — fieldIndex() uses this to decide the pair is not
            // queueable, which is exactly the right outcome.
            $out = [];
        }

        return $cache[$table] = $out;
    }

    private static function hasColumn(string $table, string $column): bool
    {
        return isset(self::columns($table)[$column]);
    }

    /**
     * The trail lives here rather than in the controller so that a decision
     * taken by the API, by a cron sweep or by the admin panel all read the same
     * in `activity_logs`. The per-field reviewer, timestamp, note and reason on
     * the rows themselves are the primary record; this is the cross-cutting
     * "who did what to which creator" line the rest of the panel reads.
     *
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $context
     */
    private static function audit(
        string $action,
        int $creatorId,
        string $description,
        array $properties,
        array $context
    ): void {
        ActivityLogger::log(
            $action,
            'creator',
            $creatorId,
            $description,
            $properties,
            self::actorType($context),
            isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            isset($context['actor_name']) ? (string) $context['actor_name'] : null
        );
    }
}
