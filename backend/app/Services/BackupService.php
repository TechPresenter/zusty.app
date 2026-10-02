<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;

/**
 * Database backup and restore.
 *
 * `mysqldump` is used when the host allows shell execution (fast, correct).
 * When it is disabled — common on shared hosting — a pure-PHP dumper walks
 * the schema and streams INSERT statements, so backups still work.
 */
final class BackupService
{
    /**
     * @return array{id:int,file:string,size:int,path:string}
     */
    public static function createDatabaseBackup(string $trigger = 'manual', ?int $adminId = null): array
    {
        $directory = rtrim((string) Config::get('storage.backup_path'), '/\\');

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw HttpException::server('The backup directory is not writable.');
        }

        self::protectDirectory($directory);

        $fileName = sprintf('zuste-db-%s.sql', date('Y-m-d_His'));
        $path     = $directory . '/' . $fileName;

        $backupId = Database::insert('backups', [
            'file_name'  => $fileName,
            'path'       => $path,
            'type'       => 'database',
            'trigger'    => $trigger === 'cron' ? 'cron' : 'manual',
            'status'     => 'running',
            'created_by' => $adminId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $usedShell = self::dumpWithMysqldump($path);

            if (!$usedShell) {
                self::dumpWithPhp($path);
            }

            // Compress when the extension is available — dumps compress ~10:1.
            if (function_exists('gzopen') && is_file($path)) {
                $gzPath = $path . '.gz';

                $source = fopen($path, 'rb');
                $target = gzopen($gzPath, 'wb9');

                if ($source !== false && $target !== false) {
                    while (!feof($source)) {
                        gzwrite($target, (string) fread($source, 262144));
                    }

                    fclose($source);
                    gzclose($target);
                    @unlink($path);

                    $path     = $gzPath;
                    $fileName .= '.gz';
                }
            }

            $size = (int) (@filesize($path) ?: 0);

            Database::update('backups', [
                'file_name'    => $fileName,
                'path'         => $path,
                'size_bytes'   => $size,
                'checksum'     => (string) (@hash_file('sha256', $path) ?: ''),
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $backupId]);

            self::pruneOldBackups($directory);

            return ['id' => $backupId, 'file' => $fileName, 'size' => $size, 'path' => $path];
        } catch (\Throwable $e) {
            Database::update('backups', [
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ], ['id' => $backupId]);

            Logger::error('Database backup failed', ['error' => $e->getMessage()]);

            throw $e;
        }
    }

    /** @return bool True when mysqldump ran successfully. */
    private static function dumpWithMysqldump(string $path): bool
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return false;
        }

        $binary = (string) Config::get('backup.mysqldump', 'mysqldump');

        // The password is passed via an env var, never on the command line,
        // so it cannot be read from the process list.
        $command = sprintf(
            'MYSQL_PWD=%s %s --host=%s --port=%d --user=%s --single-transaction --quick --routines '
            . '--default-character-set=utf8mb4 --no-tablespaces %s > %s 2>&1',
            escapeshellarg((string) Config::get('database.password', '')),
            escapeshellcmd($binary),
            escapeshellarg((string) Config::get('database.host', '127.0.0.1')),
            (int) Config::get('database.port', 3306),
            escapeshellarg((string) Config::get('database.user', '')),
            escapeshellarg((string) Config::get('database.name', '')),
            escapeshellarg($path)
        );

        $output   = [];
        $exitCode = 0;

        @exec($command, $output, $exitCode);

        return $exitCode === 0 && is_file($path) && filesize($path) > 512;
    }

    /** Pure-PHP fallback dumper. */
    private static function dumpWithPhp(string $path): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw HttpException::server('Could not open the backup file for writing.');
        }

        fwrite($handle, "-- Zusty database backup\n");
        fwrite($handle, '-- Generated: ' . date('c') . "\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        /** @var list<string> $tables */
        $tables = Database::column('SHOW TABLES');

        foreach ($tables as $table) {
            $quoted = Database::quoteIdentifier($table);

            $createRow = Database::selectOne('SHOW CREATE TABLE ' . $quoted);
            $create    = $createRow['Create Table'] ?? ($createRow['Create View'] ?? null);

            if ($create === null) {
                continue;
            }

            fwrite($handle, "\n-- Table: {$table}\n");
            fwrite($handle, 'DROP TABLE IF EXISTS ' . $quoted . ";\n");
            fwrite($handle, $create . ";\n\n");

            $rowCount = (int) Database::scalar('SELECT COUNT(*) FROM ' . $quoted);

            if ($rowCount === 0) {
                continue;
            }

            // Page through the table so a large table cannot exhaust memory.
            $pdo       = Database::connection();
            $batchSize = 500;

            for ($offset = 0; $offset < $rowCount; $offset += $batchSize) {
                $rows = Database::select('SELECT * FROM ' . $quoted . ' LIMIT ' . $batchSize . ' OFFSET ' . $offset);

                if ($rows === []) {
                    break;
                }

                $columns = array_map([Database::class, 'quoteIdentifier'], array_keys($rows[0]));
                $values  = [];

                foreach ($rows as $row) {
                    $escaped = [];

                    foreach ($row as $value) {
                        $escaped[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                    }

                    $values[] = '(' . implode(',', $escaped) . ')';
                }

                fwrite(
                    $handle,
                    'INSERT INTO ' . $quoted . ' (' . implode(',', $columns) . ") VALUES\n"
                    . implode(",\n", $values) . ";\n"
                );
            }
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    /**
     * Restore from a backup file.
     *
     * Deliberately requires an explicit confirmation token from the caller —
     * this destroys the current database.
     */
    public static function restore(int $backupId, string $confirmation, int $adminId): array
    {
        if ($confirmation !== 'RESTORE') {
            throw HttpException::unprocessable('Type RESTORE to confirm this irreversible action.');
        }

        $backup = Database::selectOne('SELECT * FROM `backups` WHERE `id` = :id', ['id' => $backupId]);

        if ($backup === null) {
            throw HttpException::notFound('Backup not found.');
        }

        $path = (string) $backup['path'];

        if (!is_readable($path)) {
            throw HttpException::notFound('The backup file is missing from disk.');
        }

        // Take a safety snapshot before overwriting anything.
        $safety = self::createDatabaseBackup('manual', $adminId);

        $sql = str_ends_with($path, '.gz')
            ? (string) gzdecode((string) file_get_contents($path))
            : (string) file_get_contents($path);

        if (trim($sql) === '') {
            throw HttpException::server('The backup file is empty or unreadable.');
        }

        $statements = self::splitStatements($sql);
        $executed   = 0;

        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach ($statements as $statement) {
            $trimmed = self::stripLeadingComments($statement);

            if ($trimmed === '') {
                continue;
            }

            $pdo->exec($trimmed);
            $executed++;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        Logger::warning('Database restored from backup', [
            'backup_id'       => $backupId,
            'admin_id'        => $adminId,
            'statements'      => $executed,
            'safety_snapshot' => $safety['file'],
        ]);

        return ['statements_executed' => $executed, 'safety_backup' => $safety['file']];
    }

    /**
     * Split a dump into statements, respecting quoted strings so a semicolon
     * inside a value does not split a statement in half.
     *
     * @return list<string>
     */
    /**
     * Remove the comment lines a statement begins with, keeping the SQL.
     *
     * The dump writes a `-- Table: users` header immediately before each
     * `DROP TABLE IF EXISTS`, and the splitter cuts on `;`, so every chunk
     * after the first arrives as a comment line followed by real SQL. The
     * restore used to test `str_starts_with($trimmed, '--')` and skip the whole
     * chunk — which threw away every DROP, so the first CREATE TABLE hit a
     * table that still existed and the restore aborted. Backups were being
     * taken and were not restorable.
     *
     * Returns '' for a chunk that is nothing but comments.
     */
    private static function stripLeadingComments(string $statement): string
    {
        $statement = trim($statement);

        while ($statement !== '') {
            if (str_starts_with($statement, '--')) {
                $newline = strpos($statement, "\n");

                // A trailing comment with no newline after it is the end of
                // the file, not the start of a statement.
                if ($newline === false) {
                    return '';
                }

                $statement = ltrim(substr($statement, $newline + 1));
                continue;
            }

            if (str_starts_with($statement, '/*')) {
                $close = strpos($statement, '*/');

                if ($close === false) {
                    return '';
                }

                $statement = ltrim(substr($statement, $close + 2));
                continue;
            }

            break;
        }

        return $statement;
    }

    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $inString   = false;
        $quoteChar  = '';
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                if ($char === '\\') {
                    $buffer .= $char . ($sql[$i + 1] ?? '');
                    $i++;
                    continue;
                }

                if ($char === $quoteChar) {
                    $inString = false;
                }

                $buffer .= $char;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString  = true;
                $quoteChar = $char;
                $buffer   .= $char;
                continue;
            }

            if ($char === ';') {
                $statements[] = $buffer;
                $buffer       = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    /** Delete backups older than the retention window. */
    private static function pruneOldBackups(string $directory): void
    {
        $retentionDays = max(1, (int) Config::get('backup.retention_days', 14));
        $cutoff        = time() - ($retentionDays * 86400);

        foreach (glob($directory . '/zuste-db-*.sql*') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }

        Database::execute(
            'DELETE FROM `backups` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => $retentionDays]
        );
    }

    /** Backups contain the whole database — never expose them over HTTP. */
    private static function protectDirectory(string $directory): void
    {
        if (!is_file($directory . '/.htaccess')) {
            @file_put_contents($directory . '/.htaccess', "Require all denied\nOptions -Indexes\n");
        }

        if (!is_file($directory . '/index.html')) {
            @file_put_contents($directory . '/index.html', '');
        }
    }

    /**
     * Run OPTIMIZE/ANALYZE across the schema.
     *
     * @return array<string,string>
     */
    public static function optimizeDatabase(): array
    {
        /** @var list<string> $tables */
        $tables  = Database::column('SHOW TABLES');
        $results = [];

        foreach ($tables as $table) {
            try {
                Database::execute('OPTIMIZE TABLE ' . Database::quoteIdentifier($table));
                Database::execute('ANALYZE TABLE ' . Database::quoteIdentifier($table));
                $results[$table] = 'ok';
            } catch (\Throwable $e) {
                $results[$table] = 'failed: ' . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Per-table size report for the admin System screen.
     *
     * @return list<array<string,mixed>>
     */
    public static function tableStats(): array
    {
        return Database::select(
            'SELECT `TABLE_NAME` AS `table_name`,
                    `TABLE_ROWS` AS `row_count`,
                    ROUND((`DATA_LENGTH` + `INDEX_LENGTH`) / 1024 / 1024, 2) AS `size_mb`,
                    ROUND(`DATA_FREE` / 1024 / 1024, 2) AS `free_mb`,
                    `ENGINE` AS `engine`
             FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = :schema
             ORDER BY (`DATA_LENGTH` + `INDEX_LENGTH`) DESC',
            ['schema' => (string) Config::get('database.name', '')]
        );
    }
}
