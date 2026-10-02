<?php

declare(strict_types=1);

namespace Zuste\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * PDO wrapper.
 *
 * Every method takes bound parameters — there is no string-interpolation
 * escape hatch anywhere in the codebase. Identifiers (table/column names)
 * that must be dynamic go through `quoteIdentifier()`, which whitelists the
 * character set rather than escaping.
 */
final class Database
{
    private static ?PDO $connection = null;

    private static int $transactionDepth = 0;

    /** Every statement this request. */
    private static int $queryCount = 0;

    /**
     * Guards the slow-query recorder against itself: recording a slow query
     * runs SQL, which would otherwise re-enter the recorder forever.
     */
    private static bool $recordingSlowQuery = false;

    /**
     * Auto-increment id produced by the last *caller* statement.
     *
     * Read straight after each statement so the slow-query recorder — which
     * runs its own INSERT on the same connection once a statement crosses the
     * threshold — can never replace the caller's id with a `query_log` id.
     */
    private static int $lastInsertId = 0;

    /** Slow-query threshold in ms; null until read, -1 when disabled. */
    private static ?int $slowQueryMs = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host    = (string) Config::get('database.host', '127.0.0.1');
        $port    = (int) Config::get('database.port', 3306);
        $name    = (string) Config::get('database.name', '');
        $charset = (string) Config::get('database.charset', 'utf8mb4');
        $socket  = (string) Config::get('database.socket', '');

        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $name, $charset)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$connection = new PDO(
                $dsn,
                (string) Config::get('database.user', ''),
                (string) Config::get('database.password', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements: the driver never interpolates.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::ATTR_PERSISTENT         => (bool) Config::get('database.persistent', false),
                ]
            );
        } catch (PDOException $e) {
            // Never leak DSN or credentials to the caller.
            Logger::critical('Database connection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Database connection failed.', 500, $e);
        }

        self::$connection->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        self::$connection->exec("SET SESSION time_zone='" . self::timezoneOffset() . "'");

        return self::$connection;
    }

    /** Close the connection (used by long-running CLI jobs between batches). */
    public static function disconnect(): void
    {
        self::$connection       = null;
        self::$transactionDepth = 0;
        self::$lastInsertId     = 0;
    }

    // -----------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<string|int,mixed> $bindings
     */
    public static function statement(string $sql, array $bindings = []): PDOStatement
    {
        $started = microtime(true);

        // Native prepared statements allow each named placeholder to appear
        // only once. Rewriting repeats here means queries can read naturally
        // (`WHERE a = :id OR b = :id`) without giving up EMULATE_PREPARES=false.
        [$sql, $bindings] = self::expandRepeatedPlaceholders($sql, $bindings);

        try {
            $stmt = self::connection()->prepare($sql);
            self::bindValues($stmt, $bindings);
            $stmt->execute();
        } catch (PDOException $e) {
            Logger::error('Query failed', [
                'sql'   => $sql,
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
            ]);
            throw $e;
        }

        $elapsedMs = round((microtime(true) - $started) * 1000, 2);
        self::$queryCount++;

        // Capture before recordSlowQuery() can run SQL of its own. The latch is
        // load-bearing: the recorder re-enters statement() for its `query_log`
        // write, and that nested call must not overwrite the caller's id.
        if (!self::$recordingSlowQuery) {
            self::$lastInsertId = (int) self::connection()->lastInsertId();
        }

        self::recordSlowQuery($sql, $elapsedMs);

        return $stmt;
    }

    /** Statements executed during this request. */
    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * Fold a slow statement into `query_log`.
     *
     * Identical statements are collapsed on a hash of the normalised SQL, so a
     * query running ten thousand times is one row with an occurrence count
     * rather than ten thousand rows. Recording is best-effort: the monitor must
     * never be able to break the query it is measuring.
     */
    private static function recordSlowQuery(string $sql, float $elapsedMs): void
    {
        if (self::$recordingSlowQuery) {
            return;
        }

        // Never measure the recorder's own bookkeeping.
        if (stripos($sql, 'query_log') !== false) {
            return;
        }

        self::$recordingSlowQuery = true;

        try {
            if (self::$slowQueryMs === null) {
                // Read once per request, from the threshold already exposed in
                // Settings → Security. Disabled when set to 0 or less.
                //
                // 100 ms, not 500: nothing in this application except password
                // hashing crosses half a second, so a 500 ms floor leaves the
                // monitor empty while the queries that actually hurt — the
                // multi-hundred-millisecond table scans — go unrecorded.
                $configured = Config::settingInt('security', 'slow_query_threshold_ms', 100);
                self::$slowQueryMs = $configured > 0 ? $configured : -1;
            }

            if (self::$slowQueryMs < 0 || $elapsedMs < self::$slowQueryMs) {
                return;
            }

            $normalised = self::normaliseSql($sql);
            $hash       = md5($normalised);
            $route      = mb_substr((string) ($_SERVER['REQUEST_URI'] ?? 'cli'), 0, 191);

            // One statement: insert the first sighting, or fold into the existing
            // row. Avoids a read-then-write race between concurrent requests.
            self::statement(
                'INSERT INTO `query_log`
                    (`sql_hash`, `sql_text`, `duration_ms`, `route`, `occurrences`,
                     `max_duration_ms`, `first_seen_at`, `last_seen_at`)
                 VALUES (:hash, :sql, :duration, :route, 1, :duration, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    `occurrences`     = `occurrences` + 1,
                    `duration_ms`     = ((`duration_ms` * `occurrences`) + :duration) / (`occurrences` + 1),
                    `max_duration_ms` = GREATEST(`max_duration_ms`, :duration),
                    `last_seen_at`    = NOW()',
                [
                    'hash'     => $hash,
                    'sql'      => mb_substr($normalised, 0, 8000),
                    'duration' => $elapsedMs,
                    'route'    => $route,
                ]
            );
        } catch (\Throwable) {
            // A missing table or a permissions problem must not surface here.
        } finally {
            self::$recordingSlowQuery = false;
        }
    }

    /**
     * Collapse literals so the same query shape hashes identically regardless
     * of the values bound into it.
     */
    private static function normaliseSql(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));
        $sql = (string) preg_replace("/'[^']*'/", '?', $sql);
        $sql = (string) preg_replace('/\b\d+\b/', '?', $sql);

        return $sql;
    }

    /**
     * @param  array<string|int,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::statement($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * @param  array<string|int,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::statement($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch the first column of the first row.
     *
     * @param array<string|int,mixed> $bindings
     */
    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::statement($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param  array<string|int,mixed> $bindings
     * @return list<mixed>
     */
    public static function column(string $sql, array $bindings = []): array
    {
        /** @var list<mixed> $values */
        $values = self::statement($sql, $bindings)->fetchAll(PDO::FETCH_COLUMN);

        return $values;
    }

    /** @param array<string|int,mixed> $bindings */
    public static function execute(string $sql, array $bindings = []): int
    {
        return self::statement($sql, $bindings)->rowCount();
    }

    /**
     * Insert a row and return the new id.
     *
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot insert an empty row.');
        }

        $data = self::withoutGeneratedColumns($table, $data);

        if ($data === []) {
            throw new \InvalidArgumentException('Cannot insert a row of only generated columns.');
        }

        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        self::statement($sql, $data);

        return self::$lastInsertId;
    }

    /**
     * Insert, ignoring duplicate-key collisions. Returns the new id or 0.
     *
     * @param array<string,mixed> $data
     */
    public static function insertIgnore(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT IGNORE INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        self::statement($sql, $data);

        return self::$lastInsertId;
    }

    /**
     * Update rows matching a simple equality WHERE clause.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new \InvalidArgumentException('Update requires both data and a WHERE clause.');
        }

        $data = self::withoutGeneratedColumns($table, $data);

        if ($data === []) {
            return 0;
        }

        $sets       = [];
        $conditions = [];
        $bindings   = [];

        foreach ($data as $column => $value) {
            $sets[]                  = self::quoteIdentifier($column) . ' = :set_' . $column;
            $bindings['set_' . $column] = $value;
        }

        foreach ($where as $column => $value) {
            $conditions[]                = self::quoteIdentifier($column) . ' = :where_' . $column;
            $bindings['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::quoteIdentifier($table),
            implode(', ', $sets),
            implode(' AND ', $conditions)
        );

        return self::execute($sql, $bindings);
    }

    /** @var array<string,list<string>> table => generated column names */
    private static array $generatedColumns = [];

    /**
     * Strip columns the database computes for itself.
     *
     * MySQL rejects any write that names a generated column, even one assigning
     * the value it already holds. That makes the natural "read a row, put it
     * back" shape — used by the integration suite's snapshot/restore, and by
     * any admin code that round-trips a SELECT * — a hard error the moment a
     * generated column is added to a table, and the error names the column
     * rather than the pattern, so it reads like a bug in the caller.
     *
     * Dropping them here means a caller never has to know which columns the
     * database owns. The introspection is cached per table per process, so it
     * costs one SHOW COLUMNS the first time a table is written to.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function withoutGeneratedColumns(string $table, array $data): array
    {
        if (!isset(self::$generatedColumns[$table])) {
            $generated = [];

            try {
                foreach (self::select('SHOW COLUMNS FROM ' . self::quoteIdentifier($table)) as $column) {
                    if (str_contains(strtoupper((string) ($column['Extra'] ?? '')), 'GENERATED')) {
                        $generated[] = (string) $column['Field'];
                    }
                }
            } catch (\Throwable) {
                // A table we cannot introspect is left alone: writing the data
                // as given reproduces the previous behaviour exactly.
                $generated = [];
            }

            self::$generatedColumns[$table] = $generated;
        }

        if (self::$generatedColumns[$table] === []) {
            return $data;
        }

        return array_diff_key($data, array_flip(self::$generatedColumns[$table]));
    }

    /** @param array<string,mixed> $where */
    public static function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('Refusing to delete without a WHERE clause.');
        }

        $conditions = [];
        foreach (array_keys($where) as $column) {
            $conditions[] = self::quoteIdentifier($column) . ' = :' . $column;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            self::quoteIdentifier($table),
            implode(' AND ', $conditions)
        );

        return self::execute($sql, $where);
    }

    /** @param array<string,mixed> $where */
    public static function exists(string $table, array $where): bool
    {
        $conditions = [];
        foreach (array_keys($where) as $column) {
            $conditions[] = self::quoteIdentifier($column) . ' = :' . $column;
        }

        $sql = sprintf(
            'SELECT 1 FROM %s WHERE %s LIMIT 1',
            self::quoteIdentifier($table),
            implode(' AND ', $conditions)
        );

        return self::scalar($sql, $where) !== null;
    }

    // -----------------------------------------------------------------
    // Transactions (savepoint-aware so nesting is safe)
    // -----------------------------------------------------------------

    public static function beginTransaction(): void
    {
        if (self::$transactionDepth === 0) {
            self::connection()->beginTransaction();
        } else {
            self::connection()->exec('SAVEPOINT trans' . self::$transactionDepth);
        }

        self::$transactionDepth++;
    }

    public static function commit(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }

        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            self::connection()->commit();
        } else {
            self::connection()->exec('RELEASE SAVEPOINT trans' . self::$transactionDepth);
        }
    }

    public static function rollBack(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }

        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            if (self::connection()->inTransaction()) {
                self::connection()->rollBack();
            }
        } else {
            self::connection()->exec('ROLLBACK TO SAVEPOINT trans' . self::$transactionDepth);
        }
    }

    public static function inTransaction(): bool
    {
        return self::$transactionDepth > 0;
    }

    /**
     * Run a callback inside a transaction, retrying once on deadlock.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback, int $attempts = 2): mixed
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            self::beginTransaction();

            try {
                $result = $callback();
                self::commit();

                return $result;
            } catch (Throwable $e) {
                self::rollBack();

                $isDeadlock = $e instanceof PDOException
                    && in_array((string) ($e->errorInfo[1] ?? ''), ['1213', '1205'], true);

                if ($isDeadlock && $attempt < $attempts) {
                    usleep(random_int(50_000, 200_000));
                    continue;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Transaction failed after retries.');
    }

    /** Acquire a row lock — callers must already be inside a transaction. */
    public static function lockForUpdate(string $table, string $idColumn, int|string $id): ?array
    {
        return self::selectOne(
            sprintf(
                'SELECT * FROM %s WHERE %s = :id FOR UPDATE',
                self::quoteIdentifier($table),
                self::quoteIdentifier($idColumn)
            ),
            ['id' => $id]
        );
    }

    // -----------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------

    /**
     * Whitelist-validate and backtick-quote an identifier.
     * Anything outside [A-Za-z0-9_.] is rejected outright.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier)) {
            throw new \InvalidArgumentException('Illegal SQL identifier: ' . $identifier);
        }

        return implode('.', array_map(
            static fn (string $part): string => '`' . $part . '`',
            explode('.', $identifier)
        ));
    }

    /**
     * Build a positional `IN (?, ?, ?)` fragment.
     *
     * @param  list<mixed> $values
     * @return array{0:string,1:list<mixed>}
     */
    public static function inClause(array $values): array
    {
        if ($values === []) {
            return ['(NULL)', []];
        }

        return ['(' . implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values)];
    }

    /**
     * Give every repeated named placeholder its own unique name and duplicate
     * the bound value, so `WHERE a = :id OR b = :id` works under native
     * prepares.
     *
     * Only names that actually appear in `$bindings` are touched, so a colon
     * inside a string literal is never rewritten.
     *
     * @param  array<string|int,mixed> $bindings
     * @return array{0:string,1:array<string|int,mixed>}
     */
    private static function expandRepeatedPlaceholders(string $sql, array $bindings): array
    {
        // Positional bindings have no such limitation.
        if ($bindings === [] || array_is_list($bindings)) {
            return [$sql, $bindings];
        }

        $normalized = [];
        foreach ($bindings as $key => $value) {
            $normalized[ltrim((string) $key, ':')] = $value;
        }

        $occurrences = [];
        $duplicates  = [];

        $rewritten = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $matches) use (&$occurrences, &$duplicates, $normalized): string {
                $name = $matches[1];

                if (!array_key_exists($name, $normalized)) {
                    return $matches[0];
                }

                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;

                if ($occurrences[$name] === 1) {
                    return $matches[0];
                }

                $alias              = $name . '_dup' . $occurrences[$name];
                $duplicates[$alias] = $normalized[$name];

                return ':' . $alias;
            },
            $sql
        );

        if ($rewritten === null) {
            return [$sql, $bindings];
        }

        return [$rewritten, $duplicates === [] ? $normalized : array_merge($normalized, $duplicates)];
    }

    /** @param array<string|int,mixed> $bindings */
    private static function bindValues(PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');

            $type = match (true) {
                is_bool($value)             => PDO::PARAM_BOOL,
                $value === null             => PDO::PARAM_NULL,
                is_int($value)              => PDO::PARAM_INT,
                is_resource($value)         => PDO::PARAM_LOB,
                default                     => PDO::PARAM_STR,
            };

            if (is_array($value)) {
                throw new \InvalidArgumentException(
                    'Array binding for "' . (string) $key . '" — use Database::inClause() instead.'
                );
            }

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
                $type  = PDO::PARAM_STR;
            }

            if (is_float($value)) {
                // MySQL is happiest with a decimal string for DECIMAL columns.
                $value = (string) $value;
                $type  = PDO::PARAM_STR;
            }

            $stmt->bindValue($parameter, $value, $type);
        }
    }

    /** Convert the PHP timezone into the +HH:MM form MySQL expects. */
    private static function timezoneOffset(): string
    {
        $offset  = (new \DateTimeZone(date_default_timezone_get()))
            ->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $sign    = $offset < 0 ? '-' : '+';
        $offset  = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }
}
