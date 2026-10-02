<?php

declare(strict_types=1);

namespace Zuste\Services;

use PDOException;
use Zuste\Core\Database;
use Zuste\Core\Logger;

/**
 * Point-in-time snapshots of editable records.
 *
 * Every CMS-style update stores the row as it looked *before* the edit, so the
 * panel can answer two questions that an audit line cannot: "what exactly
 * changed?" and "put it back".
 *
 * Snapshots are keyed by an entity type that must appear in ENTITIES. That map
 * is the only place a type is turned into a table name — nothing derived from
 * a request ever reaches SQL as an identifier.
 */
final class RevisionService
{
    /**
     * Fields that move on every write and say nothing about intent. Excluding
     * them keeps a diff to the columns a human actually edited.
     */
    private const NOISY_KEYS = ['created_at', 'updated_at'];

    /** Never stored in a snapshot, whatever the source row holds. */
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'two_factor_secret', 'token_hash', 'code_hash',
        'account_number', 'document_number', 'key_secret', 'api_key', 'service_account_json',
    ];

    /**
     * The entity types that may be versioned and restored.
     *
     * Keys match Admin\Resource::registry() so a CRUD hook can pass
     * `$resource->key` straight through.
     *
     * @var array<string,array{table:string,label:string,plural:string,title:string,permission:string,url:string,icon:string}>
     */
    private const ENTITIES = [
        'pages' => [
            'table' => 'pages', 'label' => 'Page', 'plural' => 'Pages', 'title' => 'title',
            'permission' => 'cms.pages', 'url' => '/admin/pages/%d/edit', 'icon' => 'file',
        ],
        'blogs' => [
            'table' => 'blogs', 'label' => 'Article', 'plural' => 'Blog articles', 'title' => 'title',
            'permission' => 'cms.blogs', 'url' => '/admin/blogs/%d/edit', 'icon' => 'file',
        ],
        'categories' => [
            'table' => 'categories', 'label' => 'Category', 'plural' => 'Categories', 'title' => 'name',
            'permission' => 'categories.manage', 'url' => '/admin/categories/%d/edit', 'icon' => 'grid',
        ],
        'banners' => [
            'table' => 'banners', 'label' => 'Banner', 'plural' => 'Banners', 'title' => 'title',
            'permission' => 'cms.banners', 'url' => '/admin/banners/%d/edit', 'icon' => 'image',
        ],
        'faqs' => [
            'table' => 'faqs', 'label' => 'FAQ', 'plural' => 'FAQs', 'title' => 'question',
            'permission' => 'cms.faqs', 'url' => '/admin/faqs/%d/edit', 'icon' => 'info',
        ],
        'testimonials' => [
            'table' => 'testimonials', 'label' => 'Testimonial', 'plural' => 'Testimonials', 'title' => 'author_name',
            'permission' => 'cms.testimonials', 'url' => '/admin/testimonials/%d/edit', 'icon' => 'star',
        ],
        'coupons' => [
            'table' => 'coupons', 'label' => 'Coupon', 'plural' => 'Coupons', 'title' => 'code',
            'permission' => 'marketing.manage', 'url' => '/admin/coupons/%d/edit', 'icon' => 'card',
        ],
        'popups' => [
            'table' => 'popups', 'label' => 'Popup', 'plural' => 'Popups', 'title' => 'name',
            'permission' => 'marketing.manage', 'url' => '/admin/popups/%d/edit', 'icon' => 'megaphone',
        ],
        'redirects' => [
            'table' => 'redirects', 'label' => 'Redirect', 'plural' => 'Redirects', 'title' => 'source_path',
            'permission' => 'cms.pages', 'url' => '/admin/redirects/%d/edit', 'icon' => 'external',
        ],
        'flags' => [
            'table' => 'feature_flags', 'label' => 'Feature flag', 'plural' => 'Feature flags', 'title' => 'name',
            'permission' => 'settings.manage', 'url' => '/admin/flags/%d/edit', 'icon' => 'flag',
        ],
        'sections' => [
            'table' => 'homepage_sections', 'label' => 'Homepage section', 'plural' => 'Homepage sections', 'title' => 'title',
            'permission' => 'cms.pages', 'url' => '/admin/sections/%d/edit', 'icon' => 'dashboard',
        ],
        'ai-prompts' => [
            'table' => 'ai_prompts', 'label' => 'AI prompt', 'plural' => 'AI prompts', 'title' => 'name',
            'permission' => 'ai.manage', 'url' => '/admin/ai-prompts/%d/edit', 'icon' => 'star',
        ],
    ];

    // -----------------------------------------------------------------
    // The whitelist
    // -----------------------------------------------------------------

    /** @return array<string,array{table:string,label:string,plural:string,title:string,permission:string,url:string,icon:string}> */
    public static function entities(): array
    {
        return self::ENTITIES;
    }

    /** @return array{table:string,label:string,plural:string,title:string,permission:string,url:string,icon:string}|null */
    public static function entity(string $type): ?array
    {
        return self::ENTITIES[$type] ?? null;
    }

    public static function supports(string $type): bool
    {
        return isset(self::ENTITIES[$type]);
    }

    /** Human name for an entity type, falling back to the raw key. */
    public static function labelFor(string $type): string
    {
        return self::ENTITIES[$type]['label'] ?? ucfirst(str_replace(['_', '-'], ' ', $type));
    }

    /** Deep link to the record a revision belongs to, or '' when unknown. */
    public static function urlFor(string $type, int $entityId): string
    {
        $entity = self::entity($type);

        return $entity === null ? '' : sprintf($entity['url'], $entityId);
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Store a snapshot and return the new revision id (0 when nothing was written).
     *
     * The version number is computed inside the INSERT itself, so two admins
     * saving the same record at the same moment cannot both claim version N.
     * The unique key (entity_type, entity_id, version) is the backstop; a
     * collision is retried rather than surfaced, because a revision must never
     * be the reason an edit fails.
     *
     * @param array<string,mixed>      $snapshot the row as it should be remembered
     * @param array<string,mixed>|null $admin    the signed-in admin row, when there is one
     */
    public static function record(
        string $entityType,
        int $entityId,
        array $snapshot,
        string $changeNote = '',
        ?array $admin = null
    ): int {
        if (!self::supports($entityType) || $entityId <= 0 || $snapshot === []) {
            Logger::warning('Revision not recorded', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'reason'      => self::supports($entityType) ? 'empty snapshot or bad id' : 'unknown entity type',
            ]);

            return 0;
        }

        $json = json_encode(self::scrub($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            Logger::error('Revision snapshot could not be encoded', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'error'       => json_last_error_msg(),
            ]);

            return 0;
        }

        $bindings = [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'snapshot'    => $json,
            'change_note' => $changeNote === '' ? null : mb_substr($changeNote, 0, 255),
            'admin_id'    => isset($admin['id']) ? (int) $admin['id'] : null,
            'admin_name'  => isset($admin['name']) ? mb_substr((string) $admin['name'], 0, 120) : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                Database::statement(
                    'INSERT INTO `revisions`
                        (`entity_type`, `entity_id`, `version`, `snapshot`, `change_note`, `admin_id`, `admin_name`, `created_at`)
                     SELECT :entity_type, :entity_id, COALESCE(MAX(r.`version`), 0) + 1,
                            :snapshot, :change_note, :admin_id, :admin_name, :created_at
                       FROM `revisions` r
                      WHERE r.`entity_type` = :entity_type AND r.`entity_id` = :entity_id',
                    $bindings
                );

                return self::idOfLastRevision($entityType, $entityId);
            } catch (PDOException $e) {
                // 23000 = integrity constraint: another writer took this version.
                if ((string) $e->getCode() === '23000' && $attempt < 3) {
                    usleep(random_int(20_000, 80_000));
                    continue;
                }

                Logger::error('Could not record a revision', [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'error'       => $e->getMessage(),
                ]);

                return 0;
            }
        }

        return 0;
    }

    /**
     * The id of the newest revision for an entity.
     *
     * Read back rather than taken from `PDO::lastInsertId()` on purpose.
     * `Database::statement()` can run further SQL on the same connection before
     * it returns — it resolves the slow-query threshold on the first call of a
     * request, and folds any statement over that threshold into `query_log`.
     * MySQL resets `mysql_insert_id()` on every statement, so by the time the
     * caller could read it the value is either 0 or the id of the `query_log`
     * row. Reading the row back on its own index is cheap and cannot lie about
     * which table the id belongs to.
     */
    private static function idOfLastRevision(string $entityType, int $entityId): int
    {
        return (int) Database::scalar(
            'SELECT `id` FROM `revisions`
              WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id
              ORDER BY `version` DESC
              LIMIT 1',
            ['entity_type' => $entityType, 'entity_id' => $entityId]
        );
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM `revisions` WHERE `id` = :id', ['id' => $id]);
    }

    /**
     * The revision immediately before a given version of the same entity.
     *
     * @return array<string,mixed>|null
     */
    public static function previous(string $entityType, int $entityId, int $version): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `revisions`
              WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id AND `version` < :version
              ORDER BY `version` DESC
              LIMIT 1',
            ['entity_type' => $entityType, 'entity_id' => $entityId, 'version' => $version]
        );
    }

    /** Highest version stored for an entity (0 when it has never been versioned). */
    public static function latestVersion(string $entityType, int $entityId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(MAX(`version`), 0) FROM `revisions`
              WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id',
            ['entity_type' => $entityType, 'entity_id' => $entityId]
        );
    }

    /**
     * Decode a stored snapshot into an array. Corrupt JSON yields [].
     *
     * @return array<string,mixed>
     */
    public static function decode(mixed $snapshot): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (!is_string($snapshot) || $snapshot === '') {
            return [];
        }

        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Best-effort human label for a snapshot: the entity's title column, then
     * any of the usual naming columns, then the id.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function snapshotLabel(string $entityType, array $snapshot, int $entityId): string
    {
        $entity     = self::entity($entityType);
        $candidates = [];

        if ($entity !== null) {
            $candidates[] = $entity['title'];
        }

        foreach (['title', 'name', 'question', 'code', 'author_name', 'source_path', 'flag_key'] as $column) {
            $candidates[] = $column;
        }

        foreach ($candidates as $column) {
            $value = $snapshot[$column] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return \Zuste\Helpers\Str::limit(trim((string) $value), 90);
            }
        }

        return '#' . $entityId;
    }

    // -----------------------------------------------------------------
    // Diffing
    // -----------------------------------------------------------------

    /**
     * Fields that differ between two snapshots.
     *
     * Comparison is on the string form, because MySQL hands back "1" where PHP
     * supplied the integer 1 and neither is a real change.
     *
     * @param  array<string,mixed> $old
     * @param  array<string,mixed> $new
     * @return list<array{field:string,label:string,before:string,after:string,kind:string}>
     */
    public static function diff(array $old, array $new): array
    {
        $fields  = array_keys($old + $new);
        $changes = [];

        foreach ($fields as $field) {
            $key = (string) $field;

            if (in_array($key, self::NOISY_KEYS, true)) {
                continue;
            }

            $hadBefore = array_key_exists($key, $old);
            $hasAfter  = array_key_exists($key, $new);

            $before = $hadBefore ? self::stringify($old[$key]) : '';
            $after  = $hasAfter ? self::stringify($new[$key]) : '';

            if ($hadBefore && $hasAfter && $before === $after) {
                continue;
            }

            if (!$hadBefore && !$hasAfter) {
                continue;
            }

            $changes[] = [
                'field'  => $key,
                'label'  => self::fieldLabel($key),
                'before' => $before,
                'after'  => $after,
                'kind'   => match (true) {
                    !$hadBefore => 'added',
                    !$hasAfter  => 'removed',
                    default     => 'changed',
                },
            ];
        }

        return $changes;
    }

    /** Turn a column name into something readable: `meta_description` → `Meta description`. */
    public static function fieldLabel(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    /** Render any snapshot value as a single comparable string. */
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

    // -----------------------------------------------------------------
    // Restoring
    // -----------------------------------------------------------------

    /**
     * The columns of a snapshot that may be written back to the source table.
     *
     * Anything that is not a real column today is dropped (a schema can move
     * on between the snapshot and the restore), along with the primary key and
     * the audit timestamps.
     *
     * @param  array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function restorableColumns(string $entityType, array $snapshot): array
    {
        $entity = self::entity($entityType);

        if ($entity === null) {
            return [];
        }

        /** @var list<string> $columns */
        $columns = Database::column('SHOW COLUMNS FROM ' . Database::quoteIdentifier($entity['table']));

        $writable = array_intersect_key($snapshot, array_flip($columns));

        unset($writable['id'], $writable['created_at'], $writable['updated_at'], $writable['deleted_at']);

        // A scrubbed placeholder must never be written back over a live secret.
        foreach (array_keys($writable) as $column) {
            if (in_array(strtolower((string) $column), self::SENSITIVE_KEYS, true)) {
                unset($writable[$column]);
            }
        }

        return $writable;
    }

    /**
     * The live row a revision refers to, or null when it is gone or soft-deleted.
     *
     * `$forUpdate` adds a row lock and must only be used inside a transaction.
     * Database::lockForUpdate() is not reused here because it has no
     * `deleted_at` filter, and a restore must never resurrect content onto a
     * record sitting in the recycle bin.
     *
     * @return array<string,mixed>|null
     */
    public static function sourceRow(string $entityType, int $entityId, bool $forUpdate = false): ?array
    {
        $entity = self::entity($entityType);

        if ($entity === null || $entityId <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM ' . Database::quoteIdentifier($entity['table']) . ' WHERE `id` = :id';

        /** @var list<string> $columns */
        $columns = Database::column('SHOW COLUMNS FROM ' . Database::quoteIdentifier($entity['table']));

        if (in_array('deleted_at', $columns, true)) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        $sql .= ' LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return Database::selectOne($sql, ['id' => $entityId]);
    }

    /**
     * Write a snapshot back over the live row and version the result.
     *
     * Runs in one transaction: the row that was replaced is read under a lock
     * and captured as its own revision first, so a restore is itself undoable
     * and two concurrent restores cannot both claim to have replaced the same
     * state.
     *
     * @param  array<string,mixed>      $snapshot
     * @param  array<string,mixed>|null $admin
     * @return int number of columns written
     */
    public static function restore(
        string $entityType,
        int $entityId,
        array $snapshot,
        int $fromVersion,
        ?array $admin = null
    ): int {
        $entity = self::entity($entityType);

        if ($entity === null) {
            throw new \InvalidArgumentException('Unknown entity type: ' . $entityType);
        }

        $writable = self::restorableColumns($entityType, $snapshot);

        if ($writable === []) {
            throw new \RuntimeException('This snapshot has no columns that still exist on the record.');
        }

        /** @var int $written */
        $written = Database::transaction(static function () use ($entity, $entityType, $entityId, $writable, $fromVersion, $admin): int {
            // The state about to be replaced is read inside the transaction and
            // under a row lock. Reading it outside was not enough: two admins
            // restoring the same record would both capture the same pre-state
            // and both file it as "what I replaced", so the intermediate version
            // was never snapshotted and the chain lied about what happened. A
            // plain read moved inside would not fix it either — under
            // REPEATABLE READ only a locking read sees the latest committed row.
            // The lock also holds the record still until the update lands, so it
            // cannot be hard-deleted from under the write.
            $current = self::sourceRow($entityType, $entityId, true);

            if ($current === null) {
                throw new \RuntimeException('The record this revision belongs to no longer exists.');
            }

            // Remember the state being replaced before it disappears. If that
            // cannot be written the restore is abandoned, because an
            // un-undoable restore is worse than a failed one.
            $recorded = self::record(
                $entityType,
                $entityId,
                $current,
                'Replaced by a restore of version ' . $fromVersion . '.',
                $admin
            );

            if ($recorded === 0) {
                throw new \RuntimeException('Could not snapshot the current record, so nothing was changed.');
            }

            /** @var list<string> $columns */
            $columns = Database::column('SHOW COLUMNS FROM ' . Database::quoteIdentifier($entity['table']));

            $data = $writable;

            if (in_array('updated_at', $columns, true)) {
                $data['updated_at'] = date('Y-m-d H:i:s');
            }

            Database::update($entity['table'], $data, ['id' => $entityId]);

            return count($writable);
        });

        return $written;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Values are stored whole — `revisions`.`snapshot` is a LONGTEXT and a
     * truncated snapshot would restore truncated content, which is worse than
     * a large row. Secrets are dropped rather than masked, so a restore can
     * never write a placeholder over a live credential.
     *
     * @param  array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function scrub(array $snapshot): array
    {
        $clean = [];

        foreach ($snapshot as $key => $value) {
            $name = (string) $key;

            if (in_array(strtolower($name), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[$name] = $value;
        }

        return $clean;
    }
}
