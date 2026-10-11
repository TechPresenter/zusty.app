<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;

/**
 * Profile completion, feature gating and handle-change limiting.
 *
 * Migration 037 built five tables and seeded thirty-three rules, four gates and
 * a nightly job, and nothing read any of them. Before this service the whole
 * completion system was seven hardcoded literals inside
 * CreatorService::computeProfileScore() — one number, no breakdown, no operator
 * control, and nothing anywhere consulted it before letting a creator act.
 *
 * Three things live here, and they are one service because they are one chain:
 * the RULES decide a section's percentage, the percentages roll up into the
 * overall score, and the GATES read the score and the sections. Splitting them
 * would mean three services passing the same breakdown between them.
 *
 * WHAT IS CONFIGURATION AND WHAT IS CODE.
 * The checks are data — `creator_profile_rules` rows an operator can add to
 * without a release. The CHECK TYPES are code, because every one of them needs
 * an evaluator, and a rule naming a type nothing implements would silently
 * never pass and hold a creator below a gate forever. So an unknown check type
 * throws rather than scoring zero: a loud failure an operator can see beats a
 * quiet one that looks like a creator's own fault.
 *
 * WHAT THIS DOES NOT OWN. Section weights stay in `creator.completion_weights`,
 * thresholds that already exist stay in their own settings rows and are pointed
 * at by name, and badges stay with CreatorBadgeService. This service reads all
 * three and duplicates none of them.
 */
final class ProfileCompletionService
{
    /** The taxonomy holding the ten scoring sections. */
    public const SECTION_TAXONOMY = 'completion_section';

    /**
     * The check types this engine can evaluate.
     *
     * Exactly the seven published in migration 037's section 3. Listing them
     * here rather than leaning on the match arm's `default` means an operator
     * can be told WHICH types are valid when they mistype one.
     *
     * @var list<string>
     */
    public const CHECK_TYPES = [
        'filled',
        'not_null',
        'flag',
        'min_length',
        'min_value',
        'min_count',
        'json_min_count',
    ];

    /** Which id `source_key` is matched against. */
    private const SCOPES = ['creator', 'user'];

    /**
     * The `users` columns only a text message can fill.
     *
     * A mobile number is added and confirmed with a code sent by SMS
     * (ContactVerificationService), so while SMS is off a rule that tests one
     * of these can never pass. {@see needsSms()}.
     */
    private const SMS_COLUMNS = ['phone', 'phone_country_code', 'phone_verified_at'];

    /**
     * The error codes this engine raises when the RULES TABLE is wrong.
     *
     * Every one of them means an operator mistyped a rule, not that a creator
     * failed a check, and the two need opposite handling: an operator must see
     * the exact rule and column so they can fix it, while a creator hitting a
     * gate must never be shown the rules table's internals — that message is
     * configuration detail, and it appears in place of the reason they can act
     * on. {@see gateStatus()} is the boundary between the two.
     *
     * @var list<string>
     */
    private const CONFIG_ERROR_CODES = [
        'unknown_check_type',
        'unknown_source_scope',
        'rule_missing_column',
        'rule_bad_filter',
        'rule_unknown_table',
        'rule_unknown_column',
    ];

    /** @var array<string,array<string,mixed>>|null Section catalogue, memoised per process. */
    private static ?array $sections = null;

    /** @var array<string,list<array<string,mixed>>>|null Active rules by section. */
    private static ?array $rules = null;

    /** @var array<string,array<string,mixed>>|null Feature gates by key. */
    private static ?array $gates = null;

    /** @var array<string,list<string>> table => column names, from information_schema. */
    private static array $columns = [];

    /**
     * Drop the per-process caches.
     *
     * The sweep runs for minutes and the proof script edits rules mid-run;
     * both need a way to be sure they are reading the table and not a snapshot
     * taken before their own write.
     */
    public static function flush(): void
    {
        self::$sections = null;
        self::$rules    = null;
        self::$gates    = null;
        self::$columns  = [];
    }

    // =================================================================
    // The catalogue
    // =================================================================

    /**
     * The ten scoring sections, with the weight each carries in the overall.
     *
     * Driven by the taxonomy, not by the rules table: migration 037 states that
     * a rule whose section is no longer a term is INERT rather than broken, and
     * iterating terms rather than rules is what makes that true. It also keeps
     * the sections in the operator's chosen order for free, so the progress bar
     * and this service cannot disagree about which segment comes first.
     *
     * @return array<string,array<string,mixed>> Keyed by section value.
     */
    public static function sections(): array
    {
        if (self::$sections !== null) {
            return self::$sections;
        }

        // The share each section takes of the overall 100. This is the single
        // place that number lives — copying it into the rules table is the one
        // mistake that would give the module two sources of truth.
        $weights = Config::settingArray('creator', 'completion_weights', []);

        $out = [];

        foreach (TaxonomyService::terms(self::SECTION_TAXONOMY) as $term) {
            $value = (string) $term['value'];

            $metadata = $term['metadata'] ?? null;

            if (!is_array($metadata)) {
                $decoded  = json_decode((string) $metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }

            $out[$value] = [
                'section'     => $value,
                'label'       => (string) $term['label'],
                'description' => (string) ($term['description'] ?? ''),
                // TaxonomyService normalises `color_hex` to `color` on the way
                // out; reading the column name would give every section the
                // fallback colour.
                'icon'        => (string) ($term['icon'] ?? 'circle'),
                'color'       => (string) ($term['color'] ?? '#64748B'),
                'weight'      => isset($weights[$value]) && is_numeric($weights[$value]) ? (int) $weights[$value] : 0,
                'lockable'    => (bool) ($metadata['lockable'] ?? false),
                // The finer 038 lock/review sections this scoring section
                // covers, so "which locks affect this bar" is a lookup.
                'review_sections' => array_values(array_map(
                    'strval',
                    is_array($metadata['review_sections'] ?? null) ? $metadata['review_sections'] : []
                )),
            ];
        }

        return self::$sections = $out;
    }

    /**
     * Every active rule, grouped by section and in the operator's sort order.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $grouped = [];

        foreach (Database::select(
            'SELECT * FROM `creator_profile_rules` WHERE `is_active` = 1 ORDER BY `section`, `sort_order`, `id`'
        ) as $rule) {
            $grouped[(string) $rule['section']][] = $rule;
        }

        return self::$rules = $grouped;
    }

    /**
     * The active rules a creator can meet right now, by section.
     *
     * {@see rules()} less the rules that need a text message while SMS is off.
     * A creator cannot add or confirm a mobile number without one, so scoring
     * those rules would hold every profile below 100% (and, for a mandatory
     * one, below "ready") for a reason nobody can act on. They are left out of
     * the score, the checklist and the counts rather than switched off: the
     * rows keep their settings, and turning SMS back on brings them back.
     *
     * Not memoised: SMS can be switched while a long sweep runs, and the
     * filter is a pass over rows already in memory.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function applicableRules(): array
    {
        $rules = self::rules();

        if (SmsService::enabled()) {
            return $rules;
        }

        foreach ($rules as $section => $sectionRules) {
            $rules[$section] = array_values(array_filter(
                $sectionRules,
                static fn (array $rule): bool => !self::needsSms($rule)
            ));
        }

        return $rules;
    }

    /**
     * Can this rule only be met with a text message?
     *
     * Decided from what the rule reads, so a rule an operator adds later
     * against the same column or badge is covered too: a mobile-number column
     * on `users`, or a count of `creator_badges` asking only for badges that
     * CreatorBadgeService::SMS_BADGES says only SMS can earn. A badge rule that
     * any one of several badges satisfies needs SMS only when all of them do.
     *
     * @param array<string,mixed> $rule
     */
    public static function needsSms(array $rule): bool
    {
        $table = (string) $rule['source_table'];

        if ($table === 'users') {
            return in_array((string) ($rule['source_column'] ?? ''), self::SMS_COLUMNS, true);
        }

        if ($table !== 'creator_badges') {
            return false;
        }

        $filter = json_decode((string) ($rule['source_filter'] ?? ''), true);
        $badges = is_array($filter) && isset($filter['badge']) ? (array) $filter['badge'] : [];

        return $badges !== []
            && array_diff(array_map('strval', $badges), CreatorBadgeService::SMS_BADGES) === [];
    }

    // =================================================================
    // Evaluation
    // =================================================================

    /**
     * Score one creator against every active rule.
     *
     * Nothing is written. {@see recompute()} is the writing counterpart — kept
     * separate so the admin screen can show an operator what a profile WOULD
     * score without touching the stored breakdown.
     *
     * @return array<string,mixed>
     */
    public static function evaluate(int $creatorId): array
    {
        $creator = Database::selectOne(
            'SELECT `id`, `user_id`, `handle`, `display_name`, `status`, `profile_score`
               FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        );

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        $subjects = [
            'creator' => (int) $creator['id'],
            'user'    => (int) $creator['user_id'],
        ];

        // One cached read per (table, key) pair rather than one per rule. The
        // thirty-three seeded rules touch `creators` fourteen times and `users`
        // five; without this the engine would run nineteen identical single-row
        // queries per creator, and 47 creators nightly turns that into a
        // thousand pointless round trips.
        $rows = [];

        // Only what the creator can meet now: with SMS off, the mobile-number
        // rules are not scored at all ({@see applicableRules()}).
        $rules    = self::applicableRules();
        $sections = self::sections();

        $sectionResults   = [];
        $checklist        = [];
        $mandatoryMissing = 0;

        $weightedScore = 0.0;
        $weightTotal   = 0;

        foreach ($sections as $section => $definition) {
            $sectionRules = $rules[$section] ?? [];

            $earned  = 0;
            $total   = 0;
            $met     = 0;
            $missing = [];
            $details = [];
            $sectionMandatoryMissing = 0;

            foreach ($sectionRules as $rule) {
                $weight = max(1, (int) $rule['weight']);
                $passed = self::evaluateRule($rule, $subjects, $rows);

                $total  += $weight;
                $earned += $passed ? $weight : 0;
                $met    += $passed ? 1 : 0;

                $entry = [
                    'rule_key'     => (string) $rule['rule_key'],
                    'label'        => (string) $rule['label'],
                    'description'  => $rule['description'] !== null ? (string) $rule['description'] : null,
                    'section'      => $section,
                    'check_type'   => (string) $rule['check_type'],
                    'threshold'    => self::threshold($rule),
                    'weight'       => $weight,
                    'is_mandatory' => (int) $rule['is_mandatory'] === 1,
                    'met'          => $passed,
                ];

                $details[] = $entry;

                if (!$passed) {
                    $missing[]   = $entry['rule_key'];
                    $checklist[] = $entry;

                    if ($entry['is_mandatory']) {
                        $mandatoryMissing++;
                        $sectionMandatoryMissing++;
                    }
                }
            }

            // A section with no active rules cannot be completed, so scoring it
            // at 0 and still counting its weight would cap every creator below
            // 100 for a reason none of them can act on. It is excluded from the
            // denominator instead and reported as not applicable.
            $percent = $total > 0 ? (int) round(100 * $earned / $total) : 0;

            if ($total > 0) {
                $weightedScore += $percent * $definition['weight'];
                $weightTotal   += $definition['weight'];
            }

            $sectionResults[$section] = $definition + [
                'percent'           => $percent,
                'rules_total'       => count($sectionRules),
                'rules_met'         => $met,
                'mandatory_missing' => $sectionMandatoryMissing,
                'missing'           => $missing,
                'rules'             => $details,
                'applicable'        => $total > 0,
                'complete'          => $total > 0 && $percent >= 100,
            ];
        }

        $overall = $weightTotal > 0 ? (int) round($weightedScore / $weightTotal) : 0;
        $overall = max(0, min(100, $overall));

        $required = Config::settingInt('creator', 'required_completion_percent', 60);

        return [
            'creator_id'        => $creatorId,
            'overall'           => $overall,
            'sections'          => $sectionResults,
            'missing'           => $checklist,
            'mandatory_missing' => $mandatoryMissing,
            'required_percent'  => $required,
            // Two separate conditions on purpose: a creator can pad the score
            // with optional extras and still be missing a confirmed email, so
            // "high enough" and "nothing mandatory outstanding" are reported
            // apart and only `ready` requires both.
            'meets_required'    => $overall >= $required,
            'ready'             => $overall >= $required && $mandatoryMissing === 0,
            'stored_score'      => (int) $creator['profile_score'],
        ];
    }

    /**
     * Evaluate one rule against one creator.
     *
     * @param array<string,mixed>                   $rule
     * @param array<string,int>                     $subjects  scope => id
     * @param array<string,array<string,mixed>|null> $rows     row cache, by table|key|id
     */
    private static function evaluateRule(array $rule, array $subjects, array &$rows): bool
    {
        $checkType = (string) $rule['check_type'];

        if (!in_array($checkType, self::CHECK_TYPES, true)) {
            // Deliberately fatal. A rule the engine cannot evaluate is not
            // "unmet" — it is a configuration error, and scoring it zero would
            // hold every creator on the platform below a gate with nothing in
            // any log to explain it.
            throw HttpException::unprocessable(sprintf(
                'Profile rule "%s.%s" uses an unknown check type "%s". Valid types: %s.',
                (string) $rule['section'],
                (string) $rule['rule_key'],
                $checkType,
                implode(', ', self::CHECK_TYPES)
            ), 'unknown_check_type');
        }

        $scope = (string) $rule['source_scope'];

        if (!in_array($scope, self::SCOPES, true)) {
            throw HttpException::unprocessable(sprintf(
                'Profile rule "%s.%s" uses an unknown source scope "%s". Valid scopes: %s.',
                (string) $rule['section'],
                (string) $rule['rule_key'],
                $scope,
                implode(', ', self::SCOPES)
            ), 'unknown_source_scope');
        }

        $table     = (string) $rule['source_table'];
        $key       = (string) $rule['source_key'];
        $subjectId = $subjects[$scope];
        $filter    = self::filter($rule);
        $threshold = self::threshold($rule);

        self::assertColumn($rule, $table, $key);

        foreach (array_keys($filter) as $column) {
            self::assertColumn($rule, $table, (string) $column);
        }

        if ($checkType === 'min_count') {
            return self::countRows($table, $key, $subjectId, $filter) >= $threshold;
        }

        // Everything else reads a single column off the subject's own row.
        $column = $rule['source_column'] !== null ? (string) $rule['source_column'] : '';

        if ($column === '') {
            throw HttpException::unprocessable(sprintf(
                'Profile rule "%s.%s" is a %s check with no source_column to test.',
                (string) $rule['section'],
                (string) $rule['rule_key'],
                $checkType
            ), 'rule_missing_column');
        }

        self::assertColumn($rule, $table, $column);

        $row = self::subjectRow($table, $key, $subjectId, $rows);

        if ($row === null || !self::rowMatchesFilter($row, $filter)) {
            return false;
        }

        $value = $row[$column] ?? null;

        return match ($checkType) {
            'filled'         => $value !== null && trim((string) $value) !== '',
            'not_null'       => $value !== null,
            // A flag column is 0/1 in every table this touches, but an operator
            // may point one at an ENUM, so anything PHP reads as truthy and is
            // not the literal string "0" counts.
            'flag'           => $value !== null && (string) $value !== '0' && (string) $value !== '' && (bool) $value,
            'min_length'     => $value !== null && mb_strlen(trim((string) $value)) >= $threshold,
            'min_value'      => $value !== null && is_numeric((string) $value) && (float) $value >= (float) $threshold,
            'json_min_count' => self::jsonCount($value) >= $threshold,
            default          => false,
        };
    }

    /**
     * The number the check compares against.
     *
     * `threshold_setting` wins when it names a settings row, so raising
     * creator.min_portfolio_items moves the rule with it and an operator never
     * has to remember to edit both. The column is the fallback, not the source.
     *
     * @param array<string,mixed> $rule
     */
    private static function threshold(array $rule): int
    {
        $fallback = (int) $rule['threshold'];
        $setting  = $rule['threshold_setting'] !== null ? trim((string) $rule['threshold_setting']) : '';

        if ($setting === '' || !str_contains($setting, '.')) {
            return $fallback;
        }

        // Split once: a setting key may itself contain dots, the group never
        // does.
        [$group, $key] = explode('.', $setting, 2);

        return Config::settingInt($group, $key, $fallback);
    }

    /**
     * The rule's extra conditions, decoded.
     *
     * @param  array<string,mixed> $rule
     * @return array<string,mixed>
     */
    private static function filter(array $rule): array
    {
        $raw = $rule['source_filter'] ?? null;

        if ($raw === null || trim((string) $raw) === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            throw HttpException::unprocessable(sprintf(
                'Profile rule "%s.%s" has a source_filter that is not valid JSON.',
                (string) $rule['section'],
                (string) $rule['rule_key']
            ), 'rule_bad_filter');
        }

        return $decoded;
    }

    /**
     * Count rows matching a rule's filter.
     *
     * Every identifier has already been checked against information_schema and
     * every value is bound, so an operator-supplied string is never
     * concatenated into SQL — the guarantee migration 037 makes about why
     * `source_filter` is a JSON object of column/value pairs rather than a SQL
     * fragment.
     *
     * AN EXPIRED ROW DOES NOT COUNT. `source_filter` can only say
     * `column = value`, so the seeded badge rules
     * (`{"badge":"identity_verified","status":"active"}`) had no way to say
     * "and it has not run out" — and CreatorBadgeService::holds(), which the
     * gates use for the SAME badge, does apply it. That gave one gateStatus()
     * call two answers: the verification section read 100% with nothing
     * outstanding while the gate above it said the badge was required. Since
     * an `expires_at` column exists precisely to say a row stops being true,
     * counting one that has run out is wrong in any table, so the window is
     * applied wherever the column exists. A rule that genuinely wants lapsed
     * rows still can: naming `expires_at` in its own filter takes this over.
     *
     * @param array<string,mixed> $filter
     */
    private static function countRows(string $table, string $key, int $subjectId, array $filter): int
    {
        $sql      = 'SELECT COUNT(*) FROM ' . Database::quoteIdentifier($table)
                  . ' WHERE ' . Database::quoteIdentifier($key) . ' = ?';
        $bindings = [$subjectId];

        if (
            in_array('expires_at', self::$columns[$table] ?? [], true)
            && !array_key_exists('expires_at', $filter)
        ) {
            $sql .= ' AND (`expires_at` IS NULL OR `expires_at` > NOW())';
        }

        foreach ($filter as $column => $value) {
            $quoted = Database::quoteIdentifier((string) $column);

            if ($value === null) {
                // `{"deleted_at":null}` is how a count is narrowed to undeleted
                // rows, and `= NULL` matches nothing in SQL.
                $sql .= ' AND ' . $quoted . ' IS NULL';
                continue;
            }

            if (is_array($value)) {
                [$fragment, $values] = Database::inClause(array_values($value));
                $sql     .= ' AND ' . $quoted . ' IN ' . $fragment;
                $bindings = array_merge($bindings, $values);
                continue;
            }

            $sql       .= ' AND ' . $quoted . ' = ?';
            $bindings[] = is_bool($value) ? (int) $value : $value;
        }

        return (int) Database::scalar($sql, $bindings);
    }

    /**
     * The subject's own row, read once per (table, key, id) per evaluation.
     *
     * @param  array<string,array<string,mixed>|null> $rows
     * @return array<string,mixed>|null
     */
    private static function subjectRow(string $table, string $key, int $subjectId, array &$rows): ?array
    {
        $cacheKey = $table . '|' . $key . '|' . $subjectId;

        if (!array_key_exists($cacheKey, $rows)) {
            $rows[$cacheKey] = Database::selectOne(
                'SELECT * FROM ' . Database::quoteIdentifier($table)
                . ' WHERE ' . Database::quoteIdentifier($key) . ' = :id LIMIT 1',
                ['id' => $subjectId]
            );
        }

        return $rows[$cacheKey];
    }

    /**
     * Apply a scalar rule's filter to the row already in hand.
     *
     * A filter on a single-row check narrows whether THAT row qualifies, and
     * the row is already loaded, so this is decided in PHP rather than by a
     * second query. Values come back from PDO as strings, so both sides are
     * compared as strings — `1` from JSON and `"1"` from the driver are the
     * same value and an identity comparison would call them different.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $filter
     */
    private static function rowMatchesFilter(array $row, array $filter): bool
    {
        foreach ($filter as $column => $expected) {
            $actual = $row[(string) $column] ?? null;

            if ($expected === null) {
                if ($actual !== null) {
                    return false;
                }

                continue;
            }

            if ($actual === null) {
                return false;
            }

            if (is_array($expected)) {
                $candidates = array_map(static fn ($v): string => (string) $v, array_values($expected));

                if (!in_array((string) $actual, $candidates, true)) {
                    return false;
                }

                continue;
            }

            $expectedString = is_bool($expected) ? (string) (int) $expected : (string) $expected;

            if ((string) $actual !== $expectedString) {
                return false;
            }
        }

        return true;
    }

    /** How many entries a JSON array column holds. */
    private static function jsonCount(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_array($value)) {
            return count($value);
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? count($decoded) : 0;
    }

    /**
     * Refuse a rule that names a table or column that does not exist.
     *
     * information_schema rather than a hardcoded allowlist, because the point
     * of rules-as-data is that an operator can write one against a column a
     * later migration adds. Cached per table per process — one query each.
     *
     * @param array<string,mixed> $rule
     */
    private static function assertColumn(array $rule, string $table, string $column): void
    {
        if (!isset(self::$columns[$table])) {
            self::$columns[$table] = array_map('strval', Database::column(
                'SELECT `COLUMN_NAME` FROM `information_schema`.`COLUMNS`
                  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t',
                ['t' => $table]
            ));

            if (self::$columns[$table] === []) {
                throw HttpException::unprocessable(sprintf(
                    'Profile rule "%s.%s" reads a table that does not exist: %s.',
                    (string) $rule['section'],
                    (string) $rule['rule_key'],
                    $table
                ), 'rule_unknown_table');
            }
        }

        if (!in_array($column, self::$columns[$table], true)) {
            throw HttpException::unprocessable(sprintf(
                'Profile rule "%s.%s" reads a column that does not exist: %s.%s.',
                (string) $rule['section'],
                (string) $rule['rule_key'],
                $table,
                $column
            ), 'rule_unknown_column');
        }
    }

    // =================================================================
    // Persistence
    // =================================================================

    /**
     * Score a creator and write the result.
     *
     * Writes the per-section rows AND `creators`.`profile_score`. The score
     * column stays the one overall number on the platform — the widgets, the
     * admin list and the search boost all read it — so this module explains
     * that number rather than adding a second total beside it to fall out of
     * step.
     *
     * @return array<string,mixed> The same shape {@see evaluate()} returns.
     */
    public static function recompute(int $creatorId): array
    {
        // The badge rules read `creator_badges`, and the automatic badges are
        // only as current as their last sync. The nightly badge sweep covers
        // approved creators only, so a creator still in review whose email
        // was confirmed at sign-up was scored, night after night, as missing
        // "Email verified". The badges a confirmed email or mobile earns are
        // brought in line first, so the score says what the account holds.
        //
        // Only those: the full syncAutomatic() also reconciles the KYC-derived
        // badges, and a profile score is no place to withdraw "Identity
        // verified" from anyone (KycService and the creators:badges job own
        // that). A failure here is logged, never fatal.
        try {
            CreatorBadgeService::syncAccountBadges($creatorId);
        } catch (\Throwable $e) {
            Logger::warning('Could not sync the automatic badges before scoring a profile', [
                'creator_id' => $creatorId,
                'error'      => $e->getMessage(),
            ]);
        }

        $result = self::evaluate($creatorId);
        $now    = date('Y-m-d H:i:s');

        foreach ($result['sections'] as $section => $data) {
            Database::execute(
                'INSERT INTO `creator_profile_completion`
                    (`creator_id`, `section`, `percent`, `weight`, `rules_total`, `rules_met`,
                     `mandatory_missing`, `missing`, `computed_at`)
                 VALUES (:c, :s, :percent, :weight, :total, :met, :mandatory, :missing, :now)
                 ON DUPLICATE KEY UPDATE
                    `percent`           = VALUES(`percent`),
                    `weight`            = VALUES(`weight`),
                    `rules_total`       = VALUES(`rules_total`),
                    `rules_met`         = VALUES(`rules_met`),
                    `mandatory_missing` = VALUES(`mandatory_missing`),
                    `missing`           = VALUES(`missing`),
                    `computed_at`       = VALUES(`computed_at`)',
                [
                    'c'         => $creatorId,
                    's'         => (string) $section,
                    'percent'   => (int) $data['percent'],
                    // Copied in at compute time so a widget can draw the whole
                    // bar from this table alone and the stored total stays
                    // reproducible from the stored parts.
                    'weight'    => (int) $data['weight'],
                    'total'     => (int) $data['rules_total'],
                    'met'       => (int) $data['rules_met'],
                    'mandatory' => (int) $data['mandatory_missing'],
                    'missing'   => json_encode($data['missing'], JSON_UNESCAPED_UNICODE),
                    'now'       => $now,
                ]
            );
        }

        // Drop the rows for sections that are no longer scored.
        //
        // sections() is driven by the ACTIVE taxonomy terms, so retiring a
        // section stops it being written — but an upsert never deletes, and the
        // old row stayed behind with its last `mandatory_missing` on it. The
        // two halves of the module then disagreed out loud: breakdown() walks
        // sections() and reported nothing outstanding, while the list screen's
        // "Mandatory" column, the mandatory quick filter and the headline
        // count all read the raw table and kept counting a section no chip on
        // the page even showed. The stored breakdown must contain exactly what
        // the catalogue says is scored, so anything else is removed here.
        $scored = array_keys($result['sections']);

        if ($scored === []) {
            // No active sections at all: every stored row is a leftover.
            Database::execute(
                'DELETE FROM `creator_profile_completion` WHERE `creator_id` = :c',
                ['c' => $creatorId]
            );
        } else {
            [$fragment, $values] = Database::inClause($scored);

            Database::execute(
                'DELETE FROM `creator_profile_completion`
                  WHERE `creator_id` = ? AND `section` NOT IN ' . $fragment,
                array_merge([$creatorId], $values)
            );
        }

        // Only write when it moved. `creators`.`updated_at` is ON UPDATE
        // CURRENT_TIMESTAMP, and a nightly sweep that rewrote an unchanged
        // score would stamp every profile as edited last night and make
        // "recently updated" meaningless across the admin panel.
        if ($result['stored_score'] !== $result['overall']) {
            Database::update('creators', ['profile_score' => $result['overall']], ['id' => $creatorId]);
        }

        return $result;
    }

    /**
     * Re-evaluate every live profile.
     *
     * The `creators:completion` job's body. Takes the stalest breakdowns first
     * so a run cut short by a time budget still makes progress on the rows that
     * need it most — the reason `idx_cpc_stale` exists.
     *
     * ONE BAD PROFILE DOES NOT END THE SWEEP. A creator deleted mid-run, or a
     * rule an operator has just mistyped, used to throw out of the first
     * iteration and leave the other forty-six unscored with nothing written
     * anywhere to say why. Failures are counted and reported instead, and the
     * first one is logged in full so the cause is recoverable — the job's
     * caller can then show "40 rescored, 7 failed" rather than a stack trace.
     *
     * @param  int $limit 0 for every creator.
     * @return array<string,int|string|null>
     */
    public static function recomputeAll(int $limit = 0): array
    {
        $sql = 'SELECT c.`id`
                  FROM `creators` c
                  LEFT JOIN (
                        SELECT `creator_id`, MIN(`computed_at`) AS `oldest`
                          FROM `creator_profile_completion`
                         GROUP BY `creator_id`
                  ) x ON x.`creator_id` = c.`id`
                 WHERE c.`deleted_at` IS NULL
                 ORDER BY x.`oldest` IS NOT NULL, x.`oldest` ASC, c.`id` ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $counts = ['creators' => 0, 'changed' => 0, 'improved' => 0, 'declined' => 0, 'failed' => 0];
        $firstFailure = null;

        foreach (Database::column($sql) as $id) {
            try {
                $result = self::recompute((int) $id);
            } catch (HttpException $e) {
                $counts['failed']++;

                // Logged once, not once per creator: a mistyped rule breaks
                // every profile identically, and forty-seven copies of the
                // same line buries the one fact the operator needs.
                if ($firstFailure === null) {
                    $firstFailure = $e->getMessage();

                    Logger::error('Profile completion sweep hit a failure.', [
                        'creator_id' => (int) $id,
                        'code'       => $e->errorCode(),
                        'error'      => $firstFailure,
                    ]);
                }

                continue;
            }

            $counts['creators']++;

            if ($result['stored_score'] !== $result['overall']) {
                $counts['changed']++;
                $result['overall'] > $result['stored_score']
                    ? $counts['improved']++
                    : $counts['declined']++;
            }
        }

        return $counts + ['first_failure' => $firstFailure];
    }

    /**
     * The stored breakdown, presented for a screen.
     *
     * Filled on first read rather than left empty until the nightly job runs:
     * a creator who signed up an hour ago must not be told their profile is 0%
     * complete because the sweep has not come round yet.
     *
     * Refilled, too, when the stored rows no longer describe the rules that
     * apply ({@see storedIsCurrent()}). Switching SMS off takes the mobile
     * rules out of the score; without this a creator would keep reading
     * "Confirm your mobile number" as outstanding until the nightly sweep,
     * with no way to act on it.
     *
     * @return array<string,mixed>
     */
    public static function breakdown(int $creatorId): array
    {
        // An email confirmed since the last sync earns its badge before the
        // creator is told it is missing ({@see storedIsCurrent()} then sees
        // the stored row is behind). Never fatal to the read.
        try {
            CreatorBadgeService::syncAccountBadges($creatorId);
        } catch (\Throwable $e) {
            Logger::warning('Could not re-check the account badges before a completion read', [
                'creator_id' => $creatorId,
                'error'      => $e->getMessage(),
            ]);
        }

        $stored = self::storedSections($creatorId);

        if (!self::storedIsCurrent($stored, $creatorId)) {
            try {
                self::recompute($creatorId);
            } catch (HttpException $e) {
                // A rule an operator has just mistyped changes the count too.
                // Rows already on file are still worth showing then; the
                // engine's own message describing the rules table is not.
                if ($stored === [] || !in_array($e->errorCode(), self::CONFIG_ERROR_CODES, true)) {
                    throw $e;
                }

                Logger::error('A profile rule is misconfigured; the stored completion was served as it was.', [
                    'creator_id' => $creatorId,
                    'code'       => $e->errorCode(),
                    'error'      => $e->getMessage(),
                ]);
            }

            $stored = self::storedSections($creatorId);
        }

        $sections = [];
        $oldest   = null;

        foreach (self::sections() as $section => $definition) {
            $row = $stored[$section] ?? null;

            $missing = [];

            if ($row !== null && $row['missing'] !== null) {
                $decoded = json_decode((string) $row['missing'], true);
                $missing = is_array($decoded) ? array_map('strval', $decoded) : [];
            }

            if ($row !== null && ($oldest === null || (string) $row['computed_at'] < $oldest)) {
                $oldest = (string) $row['computed_at'];
            }

            $sections[$section] = $definition + [
                'percent'           => $row !== null ? (int) $row['percent'] : 0,
                // The stored weight, not the live setting: it is what the
                // stored percentages were rolled up with, and showing the live
                // one beside a stale total would not add up.
                'stored_weight'     => $row !== null ? (int) $row['weight'] : 0,
                'rules_total'       => $row !== null ? (int) $row['rules_total'] : 0,
                'rules_met'         => $row !== null ? (int) $row['rules_met'] : 0,
                'mandatory_missing' => $row !== null ? (int) $row['mandatory_missing'] : 0,
                'missing'           => $missing,
                'computed_at'       => $row['computed_at'] ?? null,
                // False for a section with no rule that applies (none set up,
                // or all of them needing SMS while it is off): there is
                // nothing in it to finish, and it is not counted in `overall`.
                'applicable'        => $row !== null && (int) $row['rules_total'] > 0,
                'complete'          => $row !== null && (int) $row['rules_total'] > 0 && (int) $row['percent'] >= 100,
            ];
        }

        $creator = Database::selectOne(
            'SELECT `profile_score`, `status` FROM `creators` WHERE `id` = :id',
            ['id' => $creatorId]
        );

        return [
            'creator_id'        => $creatorId,
            'overall'           => (int) ($creator['profile_score'] ?? 0),
            'status'            => (string) ($creator['status'] ?? ''),
            'sections'          => $sections,
            'mandatory_missing' => array_sum(array_column($sections, 'mandatory_missing')),
            'computed_at'       => $oldest,
            'required_percent'  => Config::settingInt('creator', 'required_completion_percent', 60),
        ];
    }

    /**
     * Was the stored breakdown computed against the rules that apply now?
     *
     * Every scored section must have a row, and each row must count as many
     * rules as apply to its section today. The count moves when SMS is
     * switched either way and when an operator adds or retires a rule, which
     * are the changes that make a stored checklist name things that are no
     * longer asked for (or miss ones that now are). A pass over rows already
     * read; no query.
     *
     * Nor may a row list a badge as missing that the creator now holds: a
     * badge awarded since (an email confirmed, a KYC approved) would
     * otherwise stay "outstanding" until the nightly sweep, with nothing left
     * for the creator to do. One query, and only when a stored row names a
     * plain badge rule ({@see heldBadgeRule()}) as missing.
     *
     * @param array<string,array<string,mixed>> $stored
     */
    private static function storedIsCurrent(array $stored, int $creatorId): bool
    {
        if ($stored === []) {
            return false;
        }

        $rules        = self::applicableRules();
        $badgeMissing = [];

        foreach (array_keys(self::sections()) as $section) {
            $row = $stored[$section] ?? null;

            if ($row === null || (int) $row['rules_total'] !== count($rules[$section] ?? [])) {
                return false;
            }

            $missing = json_decode((string) ($row['missing'] ?? ''), true);

            if (!is_array($missing) || $missing === []) {
                continue;
            }

            foreach ($rules[$section] ?? [] as $rule) {
                $badges = self::heldBadgeRule($rule);

                if ($badges !== [] && in_array((string) $rule['rule_key'], array_map('strval', $missing), true)) {
                    $badgeMissing[] = $badges;
                }
            }
        }

        if ($badgeMissing === []) {
            return true;
        }

        $held = array_map('strval', Database::column(
            'SELECT `badge` FROM `creator_badges`
              WHERE `creator_id` = :c AND `status` = "active"
                AND (`expires_at` IS NULL OR `expires_at` > NOW())',
            ['c' => $creatorId]
        ));

        foreach ($badgeMissing as $badges) {
            if (array_intersect($badges, $held) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The badges a plain "holds this badge" rule accepts, or [] for any other
     * rule.
     *
     * Plain means: a `min_count` of at most one over `creator_badges` by
     * `creator_id`, filtered on the badge and on `status` = active and nothing
     * else — the shape of every seeded badge rule, and one whose answer is
     * exactly "does the creator hold one of these now". Anything an operator
     * shaped differently is left to the full evaluation.
     *
     * @param  array<string,mixed> $rule
     * @return list<string>
     */
    private static function heldBadgeRule(array $rule): array
    {
        if ((string) $rule['source_table'] !== 'creator_badges'
            || (string) $rule['check_type'] !== 'min_count'
            || (string) ($rule['source_key'] ?? '') !== 'creator_id'
            || (string) ($rule['source_scope'] ?? '') !== 'creator') {
            return [];
        }

        $filter = json_decode((string) ($rule['source_filter'] ?? ''), true);

        if (!is_array($filter) || !isset($filter['badge']) || ($filter['status'] ?? null) !== 'active'
            || array_diff(array_keys($filter), ['badge', 'status']) !== []) {
            return [];
        }

        try {
            if (self::threshold($rule) > 1) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map('strval', (array) $filter['badge']));
    }

    /**
     * @return array<string,array<string,mixed>> Stored rows keyed by section.
     */
    private static function storedSections(int $creatorId): array
    {
        $out = [];

        foreach (Database::select(
            'SELECT * FROM `creator_profile_completion` WHERE `creator_id` = :id',
            ['id' => $creatorId]
        ) as $row) {
            $out[(string) $row['section']] = $row;
        }

        return $out;
    }

    // =================================================================
    // Feature gates
    // =================================================================

    /**
     * The active gates, in the operator's order.
     *
     * @return array<string,array<string,mixed>> Keyed by feature_key.
     */
    public static function gates(): array
    {
        if (self::$gates !== null) {
            return self::$gates;
        }

        $out = [];

        foreach (Database::select(
            'SELECT * FROM `creator_feature_gates` WHERE `is_active` = 1 ORDER BY `sort_order`, `id`'
        ) as $gate) {
            $out[(string) $gate['feature_key']] = $gate;
        }

        return self::$gates = $out;
    }

    /**
     * May this creator use the feature?
     *
     * An ABSENT gate means ungated, which is the deliberate failure direction:
     * code asking about a feature key no operator has gated must not silently
     * lock everyone out of it. An absent CREATOR is the opposite case and
     * throws — "ungated" is a statement about a feature, never a licence to
     * answer questions about somebody who does not exist.
     *
     * A gate set to `warn` returns true — warn means "allow and nag", and the
     * nag is the caller's job with the message on {@see gateStatus()}. Callers
     * that need the raw requirement check rather than the permission read
     * `meets` off gateStatus() instead.
     */
    public static function canUse(int $creatorId, string $featureKey): bool
    {
        return (bool) self::gateStatus($creatorId, $featureKey)['allowed'];
    }

    /**
     * Refuse a blocked feature, with the operator's own message.
     *
     * "You cannot do this" with no reason is unactionable for the creator
     * reading it, so the gate's `locked_message` is what surfaces — and ONLY
     * that. A rule the operator has mistyped is caught inside
     * {@see verdict()} and logged, so the one thing this can never throw is
     * the engine's own 422 describing the rules table.
     */
    public static function assertCanUse(int $creatorId, string $featureKey): void
    {
        $status = self::gateStatus($creatorId, $featureKey);

        if ($status['allowed'] === true) {
            return;
        }

        throw HttpException::forbidden((string) $status['message']);
    }

    /**
     * The full verdict on one gate, with every reason it is closed.
     *
     * @return array<string,mixed>
     */
    public static function gateStatus(int $creatorId, string $featureKey): array
    {
        $context = [];

        return self::verdict($creatorId, $featureKey, $context);
    }

    /**
     * The verdict on one gate, reusing whatever the caller has already read.
     *
     * $context is a per-call memo, passed by reference: the creator row, the
     * stored breakdown, this creator's overrides and each badge answer land in
     * it the first time they are needed and are reused after. It exists
     * because every seeded gate names required_sections, so four gates on one
     * admin page each re-read the creators row, the overrides row and the
     * WHOLE breakdown — and breakdown() self-heals by recomputing, which made
     * a single GET cost 103 queries and ten writes. {@see gatesFor()} shares
     * one memo across every gate; {@see gateStatus()} passes a fresh one and
     * behaves exactly as it did.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function verdict(int $creatorId, string $featureKey, array &$context): array
    {
        // The creator is resolved BEFORE the ungated shortcut. It used to come
        // after, so canUse(<id that is not a creator>, <ungated feature>)
        // answered true — a fail-open on the one input that should never be
        // trusted, and the opposite of the notFound every other path gives.
        if (!array_key_exists('creator', $context)) {
            $context['creator'] = Database::selectOne(
                'SELECT `profile_score`, `status` FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
                ['id' => $creatorId]
            );
        }

        $creator = $context['creator'];

        if ($creator === null) {
            throw HttpException::notFound('Creator not found.');
        }

        $gate = self::gates()[$featureKey] ?? null;

        if ($gate === null) {
            return [
                'feature_key'  => $featureKey,
                'name'         => $featureKey,
                'gated'        => false,
                'meets'        => true,
                'allowed'      => true,
                'enforcement'  => 'none',
                'override'     => null,
                'reasons'      => [],
                'message'      => '',
                'percent'      => (int) $creator['profile_score'],
                'required'     => 0,
            ];
        }

        $enforcement = (string) $gate['enforcement'];
        $override    = self::activeOverrideFrom(self::overrideRows($creatorId, $context), $featureKey);

        $reasons  = [];
        $required = (int) $gate['min_completion_percent'];
        $percent  = (int) $creator['profile_score'];

        if ($gate['required_status'] !== null && (string) $creator['status'] !== (string) $gate['required_status']) {
            $reasons[] = sprintf(
                'Profile status must be %s (currently %s).',
                str_replace('_', ' ', (string) $gate['required_status']),
                str_replace('_', ' ', (string) $creator['status'])
            );
        }

        if ($percent < $required) {
            $reasons[] = sprintf('Profile is %d%% complete; %d%% is needed.', $percent, $required);
        }

        $requiredSections = self::requiredSections($gate);
        $sections         = [];

        if ($requiredSections !== []) {
            if (!array_key_exists('sections', $context)) {
                try {
                    // Read the stored breakdown ONCE, and only when the gate
                    // names a section. Re-evaluating would cost eight COUNT
                    // queries on every request that touches a gated feature,
                    // which is the exact cost this table was stored rather
                    // than computed to avoid. breakdown() fills itself on
                    // first read, so a creator the nightly sweep has never
                    // seen is still judged on real data rather than an empty
                    // row.
                    $context['sections']       = self::breakdown($creatorId)['sections'];
                    $context['sections_error'] = null;
                } catch (HttpException $e) {
                    if (!in_array($e->errorCode(), self::CONFIG_ERROR_CODES, true)) {
                        throw $e;
                    }

                    // A mistyped rule is the operator's problem, not the
                    // creator's. Letting it out of here put "Profile rule
                    // basic.zz_typo uses an unknown check type" — the rules
                    // table's internals — in front of whoever called a gated
                    // endpoint, in place of the locked_message written for
                    // them. Logged in full, once per call, so the cause is not
                    // lost.
                    Logger::error('A profile rule is misconfigured; feature gates were held shut.', [
                        'creator_id'  => $creatorId,
                        'feature_key' => $featureKey,
                        'code'        => $e->errorCode(),
                        'error'       => $e->getMessage(),
                    ]);

                    $context['sections']       = [];
                    $context['sections_error'] = 'Profile completion could not be checked. Support has been notified.';
                }
            }

            $sections = $context['sections'];

            // Carried on the memo, not just raised where it was caught: every
            // gate that needs the breakdown has to inherit the failure, or the
            // second gate on the page would read an empty section list, find
            // nothing to complain about and OPEN — a breakdown that could not
            // be computed is not evidence that the sections are finished.
            if (($context['sections_error'] ?? null) !== null) {
                $reasons[] = (string) $context['sections_error'];
            }
        }

        foreach ($requiredSections as $section) {
            // A section with no rule that applies has nothing to finish (with
            // SMS off, one whose every rule needs a text message), and
            // demanding it would hold the gate shut for a reason nobody can
            // act on.
            if (!isset($sections[$section]) || (int) $sections[$section]['rules_total'] === 0) {
                continue;
            }

            if ($sections[$section]['complete'] !== true) {
                $reasons[] = sprintf(
                    '%s is %d%% complete and must be finished.',
                    (string) $sections[$section]['label'],
                    (int) $sections[$section]['percent']
                );
            }
        }

        foreach (self::requiredBadges($gate) as $badge) {
            // A badge nobody can earn right now (Mobile verified while SMS is
            // off) is not asked for. Migration 058 took it out of the seeded
            // gates; this keeps a gate an operator writes later from locking
            // every creator out the same way.
            if (!CreatorBadgeService::earnable($badge)) {
                continue;
            }

            // Memoised: two seeded gates ask for email_verified, and holds()
            // is a query every time.
            if (!array_key_exists($badge, $context['badges'] ?? [])) {
                $context['badges'][$badge] = CreatorBadgeService::holds($creatorId, $badge);
            }

            if ($context['badges'][$badge] !== true) {
                $reasons[] = sprintf(
                    'The %s badge is required.',
                    TaxonomyService::label(CreatorBadgeService::TAXONOMY, $badge)
                );
            }
        }

        $meets = $reasons === [];

        // The override is the last word in both directions. Support needs to be
        // able to let a hand-held creator take a booking at 55%, and to pull a
        // feature from someone gaming the score, without editing the gate for
        // everybody.
        $allowed = match (true) {
            $override !== null && (string) $override['mode'] === 'deny'  => false,
            $override !== null && (string) $override['mode'] === 'grant' => true,
            // `warn` allows and nags; `block` and `hide` withhold.
            $enforcement === 'warn'                                      => true,
            default                                                      => $meets,
        };

        $message = '';

        if (!$allowed) {
            $message = $override !== null && (string) $override['mode'] === 'deny'
                ? trim('This feature has been withheld by an administrator. ' . (string) ($override['reason'] ?? ''))
                : ((string) ($gate['locked_message'] ?? '') ?: 'This feature is not available on your profile yet.');
        }

        return [
            'feature_key' => $featureKey,
            'name'        => (string) $gate['name'],
            'description' => $gate['description'] !== null ? (string) $gate['description'] : null,
            'gated'       => true,
            'meets'       => $meets,
            'allowed'     => $allowed,
            'enforcement' => $enforcement,
            'override'    => $override,
            'reasons'     => $reasons,
            'message'     => $message,
            'percent'     => $percent,
            'required'    => $required,
        ];
    }

    /**
     * Every gate's verdict for one creator — what the apps render as the
     * "what unlocks next" list, and what the admin screen shows.
     *
     * @return list<array<string,mixed>>
     */
    public static function gatesFor(int $creatorId): array
    {
        $out = [];

        // One memo for the whole set. Every gate wants the same creator row,
        // the same breakdown and mostly the same badges, so reading them once
        // is the difference between four round trips and one.
        $context = [];

        foreach (array_keys(self::gates()) as $featureKey) {
            $out[] = self::verdict($creatorId, (string) $featureKey, $context);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed> $gate
     * @return list<string>
     */
    private static function requiredSections(array $gate): array
    {
        return self::jsonList($gate['required_sections'] ?? null);
    }

    /**
     * @param  array<string,mixed> $gate
     * @return list<string>
     */
    private static function requiredBadges(array $gate): array
    {
        return self::jsonList($gate['required_badges'] ?? null);
    }

    /** @return list<string> */
    private static function jsonList(mixed $raw): array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    // =================================================================
    // Per-creator overrides
    // =================================================================

    /**
     * The live override for a feature, or null.
     *
     * Expiry is filtered by date as well as read from the row, so a temporary
     * grant that lapsed an hour ago stops applying immediately rather than at
     * the next sweep.
     *
     * @return array<string,mixed>|null
     */
    public static function activeOverride(int $creatorId, string $featureKey): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `creator_feature_overrides`
              WHERE `creator_id` = :c AND `feature_key` = :f
                AND (`expires_at` IS NULL OR `expires_at` > NOW())',
            ['c' => $creatorId, 'f' => $featureKey]
        );
    }

    /**
     * Every override row for a creator, read once per {@see verdict()} memo.
     *
     * @param  array<string,mixed> $context
     * @return list<array<string,mixed>>
     */
    private static function overrideRows(int $creatorId, array &$context): array
    {
        if (!array_key_exists('overrides', $context)) {
            $context['overrides'] = self::overrides($creatorId);
        }

        return $context['overrides'];
    }

    /**
     * Pick the live override for one feature out of rows already in hand.
     *
     * The expiry test is the same one activeOverride() asks the database, kept
     * in step deliberately: the admin screen lists lapsed overrides and the
     * gates must ignore exactly those, so one definition of "live" serves both.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private static function activeOverrideFrom(array $rows, string $featureKey): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['feature_key'] !== $featureKey) {
                continue;
            }

            $expires = $row['expires_at'];

            if ($expires === null || strtotime((string) $expires) > time()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Every override on a creator, including lapsed ones, for the admin screen.
     *
     * @return list<array<string,mixed>>
     */
    public static function overrides(int $creatorId): array
    {
        return Database::select(
            'SELECT * FROM `creator_feature_overrides` WHERE `creator_id` = :c ORDER BY `feature_key`',
            ['c' => $creatorId]
        );
    }

    /**
     * Grant or withhold a feature for one creator regardless of their score.
     *
     * Re-setting an existing override updates it rather than failing on the
     * unique key, so an operator can correct a reason or extend an expiry
     * without clearing it first — the same shape CreatorProfileLockService::
     * lock() uses, and for the same reason.
     *
     * @param array<string,mixed> $context reason, actor_id, expires_at.
     */
    public static function setOverride(int $creatorId, string $featureKey, string $mode, array $context = []): void
    {
        if (!in_array($mode, ['grant', 'deny'], true)) {
            throw HttpException::unprocessable('An override is either a grant or a deny.');
        }

        self::assertCreatorExists($creatorId);

        // A gate that does not exist yet is a legitimate target — migration 037
        // keeps `feature_key` a free string precisely so an operator can gate a
        // feature before the code that reads it ships — but a typo is far more
        // likely than that, so the key is checked and refused when no gate
        // carries it.
        if (!isset(self::gates()[$featureKey])) {
            throw HttpException::unprocessable("No feature gate is configured for: {$featureKey}");
        }

        $expiresAt = self::expiryInstant($context['expires_at'] ?? null);

        Database::execute(
            'INSERT INTO `creator_feature_overrides`
                (`creator_id`, `feature_key`, `mode`, `reason`, `granted_by`, `expires_at`, `created_at`)
             VALUES (:c, :f, :mode, :reason, :by, :expires, NOW())
             ON DUPLICATE KEY UPDATE
                `mode`       = VALUES(`mode`),
                `reason`     = VALUES(`reason`),
                `granted_by` = VALUES(`granted_by`),
                `expires_at` = VALUES(`expires_at`)',
            [
                'c'       => $creatorId,
                'f'       => $featureKey,
                'mode'    => $mode,
                'reason'  => isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 500) : null,
                'by'      => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
                'expires' => $expiresAt,
            ]
        );
    }

    /**
     * The instant an override stops applying, from what the operator typed.
     *
     * END OF DAY, NOT MIDNIGHT. The only way to set this from the panel is an
     * `<input type="date">`, and the `date` validation rule accepts nothing
     * but `Y-m-d` — so what arrives here is a DAY, and "grant until 5 August"
     * plainly means the whole of the 5th. Parsing it with strtotime() alone
     * produced 00:00:00, which the `expires_at > NOW()` test reads as already
     * gone: an override granted for today was dead before the INSERT returned
     * and every other date lapsed a full day early, while the operator was
     * told "Feature granted." either way. End of day is STORED rather than the
     * comparison being moved to DATE(), because the column is a DATETIME that
     * a caller may legitimately fill with a real instant ("two hours from
     * now"), and one comparison that is right for both beats two that can
     * disagree.
     */
    private static function expiryInstant(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = trim((string) $value);

        $timestamp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1
            ? strtotime($raw . ' 23:59:59')
            : strtotime($raw);

        if ($timestamp === false) {
            // (int) false is 0, which used to store 1970 and produce an
            // override that could never apply. This method is public API for
            // other modules, so it refuses rather than quietly inventing a
            // date the caller did not ask for.
            throw HttpException::unprocessable(
                "That expiry could not be read as a date: {$raw}",
                'bad_override_expiry'
            );
        }

        if ($timestamp <= time()) {
            throw HttpException::unprocessable(
                'That expiry has already passed, so the override would never apply. Pick a later date or leave it empty.',
                'override_expiry_past'
            );
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /** Remove an override, putting the creator back under the gate's own rules. */
    public static function clearOverride(int $creatorId, string $featureKey): void
    {
        Database::execute(
            'DELETE FROM `creator_feature_overrides` WHERE `creator_id` = :c AND `feature_key` = :f',
            ['c' => $creatorId, 'f' => $featureKey]
        );
    }

    // =================================================================
    // Handle changes
    // =================================================================

    /**
     * Where a creator stands against the handle-change settings.
     *
     * Both numbers have been sitting in the `creator` settings group since seed
     * 031 with nothing to count. `creator_handle_history` is that counter, and
     * these are the two questions it answers.
     *
     * @return array<string,mixed>
     */
    public static function handleChangeStatus(int $creatorId): array
    {
        // Resolved first. Without it an id that is not a creator came back as
        // "allowed, 3 remaining", which is a fail-open answer to a question
        // about somebody who does not exist.
        self::assertCreatorExists($creatorId);

        $limit    = Config::settingInt('creator', 'username_change_limit', 3);
        $cooldown = Config::settingInt('creator', 'username_change_cooldown_days', 30);

        $row = Database::selectOne(
            'SELECT COUNT(*) AS `used`, MAX(`created_at`) AS `last_at`
               FROM `creator_handle_history` WHERE `creator_id` = :c',
            ['c' => $creatorId]
        );

        $used   = (int) ($row['used'] ?? 0);
        $lastAt = $row['last_at'] ?? null;

        $availableAt = null;

        if ($lastAt !== null && $cooldown > 0) {
            $availableAt = date('Y-m-d H:i:s', (int) strtotime((string) $lastAt) + ($cooldown * 86400));
        }

        // A limit of 0 means unlimited rather than "never" — an operator who
        // wants to forbid handle changes outright locks the `username` section
        // instead, which is what that mechanism is for.
        $limitReached = $limit > 0 && $used >= $limit;
        $inCooldown   = $availableAt !== null && strtotime($availableAt) > time();

        return [
            'limit'         => $limit,
            'used'          => $used,
            'remaining'     => $limit > 0 ? max(0, $limit - $used) : null,
            'cooldown_days' => $cooldown,
            'last_changed'  => $lastAt,
            'available_at'  => $inCooldown ? $availableAt : null,
            'allowed'       => !$limitReached && !$inCooldown,
        ];
    }

    /**
     * Refuse a handle change the settings do not allow.
     *
     * An administrator is never blocked — the point of the limit is to stop a
     * creator churning their public address, not to stop the operator fixing
     * one. Same rule CreatorProfileLockService applies to locks.
     */
    public static function assertHandleChangeAllowed(
        int $creatorId,
        string $newHandle,
        string $actorType = 'creator'
    ): void {
        $newHandle = ltrim(trim($newHandle), '@');

        $minLength = Config::settingInt('creator', 'username_min_length', 3);

        if (mb_strlen($newHandle) < $minLength) {
            throw HttpException::unprocessable(
                "A handle must be at least {$minLength} characters.",
                'handle_too_short'
            );
        }

        // IS IT EVEN FREE? Checked before the administrator exemption, because
        // a handle two creators both hold is a broken profile rather than a
        // policy the operator may waive — `uq_creators_handle` would reject
        // the UPDATE anyway, and this is the difference between a message the
        // operator can act on and a driver-level duplicate-key error. Soft
        // deletes are deliberately NOT excluded: that unique index covers them
        // too, so a deleted creator still occupies the name.
        $taken = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` WHERE `handle` = :h AND `id` <> :c',
            ['h' => $newHandle, 'c' => $creatorId]
        );

        if ($taken > 0) {
            throw HttpException::conflict(
                'That handle is already taken. Choose a different one.',
                'handle_taken'
            );
        }

        if ($actorType === 'admin') {
            return;
        }

        $status = self::handleChangeStatus($creatorId);

        if ($status['limit'] > 0 && $status['used'] >= $status['limit']) {
            throw HttpException::unprocessable(
                sprintf(
                    'You have already changed your handle %d time(s), which is the limit. Contact support if you need another change.',
                    (int) $status['used']
                ),
                'handle_limit_reached'
            );
        }

        if ($status['available_at'] !== null) {
            throw HttpException::unprocessable(
                sprintf(
                    'Handles can be changed once every %d days. You can change yours again on %s.',
                    (int) $status['cooldown_days'],
                    date('j M Y', (int) strtotime((string) $status['available_at']))
                ),
                'handle_cooldown'
            );
        }

        // Handing @famouscreator to the next claimant the moment it is freed
        // manufactures an impersonation, and this ledger is the only record
        // that the name was ever held. Refusing is a correctness guard rather
        // than a policy dial, so it takes no setting: an administrator can
        // still reassign the handle deliberately through the admin path.
        $previous = self::previousHolder($newHandle);

        if ($previous !== null && (int) $previous['creator_id'] !== $creatorId) {
            throw HttpException::conflict(
                'That handle was previously used by another creator and cannot be reused. Choose a different one.',
                'handle_previously_held'
            );
        }
    }

    /**
     * Enforce the limit and record the change.
     *
     * The one call CreatorController::updateProfile() needs. It must run
     * BEFORE the UPDATE that applies the handle and inside the same
     * transaction, so a rejected change writes nothing and an applied one is
     * never missing from the ledger. The handle it should end up with is
     * returned, normalised.
     *
     * THE OLD HANDLE IS READ HERE, NOT PASSED IN. It used to be an argument
     * taken entirely on trust, and `old_handle` is what previousHolder()
     * answers with: passing a name the creator never held wrote that name into
     * the ledger and locked every OTHER creator out of it forever, through the
     * "previously used by another creator" guard below. A ledger of who held
     * what is worth nothing if its caller can choose the entries.
     *
     * @param  array<string,mixed> $context actor_type, actor_id, actor_name, reason, ip.
     * @return string The normalised handle the caller should now store.
     */
    public static function changeHandle(int $creatorId, string $newHandle, array $context = []): string
    {
        $newHandle = ltrim(trim($newHandle), '@');

        $oldHandle = Database::scalar(
            'SELECT `handle` FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        );

        if ($oldHandle === null) {
            throw HttpException::notFound('Creator not found.');
        }

        $oldHandle = ltrim(trim((string) $oldHandle), '@');

        if ($oldHandle === $newHandle) {
            // Not an error and not a change. A profile save that posts the same
            // handle back must not burn one of the creator's three changes.
            return $oldHandle;
        }

        $actorType = (string) ($context['actor_type'] ?? 'creator');

        if (!in_array($actorType, ['creator', 'admin', 'system'], true)) {
            throw HttpException::unprocessable("Unknown handle-change actor type: {$actorType}");
        }

        self::assertHandleChangeAllowed($creatorId, $newHandle, $actorType);

        $ip = $context['ip'] ?? null;

        Database::insert('creator_handle_history', [
            'creator_id' => $creatorId,
            'old_handle' => mb_substr($oldHandle, 0, 60),
            'new_handle' => mb_substr($newHandle, 0, 60),
            'actor_type' => $actorType,
            'actor_id'   => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            'actor_name' => isset($context['actor_name']) ? mb_substr((string) $context['actor_name'], 0, 150) : null,
            'reason'     => isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 255) : null,
            'ip_address' => is_string($ip) ? (@inet_pton($ip) ?: null) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $newHandle;
    }

    /**
     * Who used to hold this handle.
     *
     * The impersonation check, and the lookup a redirect from the old public
     * URL would use. Most recent release first, because a handle that has
     * changed hands twice belongs — for warning purposes — to whoever gave it
     * up last.
     *
     * @return array<string,mixed>|null
     */
    public static function previousHolder(string $handle): ?array
    {
        $handle = ltrim(trim($handle), '@');

        if ($handle === '') {
            return null;
        }

        return Database::selectOne(
            'SELECT h.*, c.`display_name`, c.`handle` AS `current_handle`
               FROM `creator_handle_history` h
               JOIN `creators` c ON c.`id` = h.`creator_id`
              WHERE h.`old_handle` = :h
              ORDER BY h.`created_at` DESC, h.`id` DESC
              LIMIT 1',
            ['h' => $handle]
        );
    }

    /**
     * One creator's handle ledger.
     *
     * @return list<array<string,mixed>>
     */
    public static function handleHistory(int $creatorId, int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM `creator_handle_history`
              WHERE `creator_id` = :c
              ORDER BY `created_at` DESC, `id` DESC
              LIMIT ' . max(1, min(500, $limit)),
            ['c' => $creatorId]
        );
    }

    // =================================================================
    // Internals
    // =================================================================

    private static function assertCreatorExists(int $creatorId): void
    {
        $exists = (int) Database::scalar(
            'SELECT COUNT(*) FROM `creators` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $creatorId]
        );

        if ($exists === 0) {
            throw HttpException::notFound('Creator not found.');
        }
    }
}
