<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Admin\Resource;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Validator;
use Zuste\Helpers\Str;

/**
 * Generic CSV import for every resource described in Admin\Resource.
 *
 * The declarative registry already states, per resource, which columns exist,
 * what they are called, how they validate and which one is unique. That is
 * everything an importer needs — so this service reads the registry rather
 * than carrying a hand-written mapping per entity, exactly like the export
 * side does.
 *
 * Three deliberate rules:
 *
 *  1. Column names written to SQL come from the registry's `fields`, never
 *     from the CSV header. A header only ever selects which declared field a
 *     column feeds; an unrecognised header is ignored.
 *  2. Validation is delegated to Core\Validator using the resource's own
 *     rules, so an import can never create a row the form would have refused.
 *     The `unique:` clause is stripped before validating because uniqueness is
 *     handled separately — a duplicate is a decision for the operator, not a
 *     validation failure.
 *  3. Nothing is written until the operator has seen the dry run, and the
 *     write itself is one transaction: it either lands whole or not at all.
 */
final class ImportService
{
    /** Hard ceiling on the uploaded/pasted payload. */
    public const MAX_BYTES = 5_242_880;

    /** Hard ceiling on data rows (excluding the header). */
    public const MAX_ROWS = 5000;

    /** Rows per INSERT statement — a 5,000-row file becomes 25 round trips, not 5,000. */
    public const BATCH_SIZE = 200;

    /**
     * Placeholder budget for one statement. MySQL allows 65,535; staying well
     * under it means a very wide resource cannot overflow the limit however
     * many rows a batch happens to hold.
     */
    private const MAX_PLACEHOLDERS = 5000;

    public const MODE_SKIP   = 'skip';
    public const MODE_UPDATE = 'update';
    public const MODE_ABORT  = 'abort';

    /** Delimiters we will sniff for, in preference order. */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Named delimiters for the UI. A tab cannot travel through a form value
     * intact — trimming would eat it — so the picker sends a token instead.
     */
    public const DELIMITER_TOKENS = [
        'comma'     => ',',
        'semicolon' => ';',
        'tab'       => "\t",
        'pipe'      => '|',
    ];

    /** How much of the payload is examined when sniffing the delimiter. */
    private const SNIFF_BYTES = 65_536;

    /** @var array<string,list<string>> */
    private static array $columnCache = [];

    // -----------------------------------------------------------------
    // Registry access
    // -----------------------------------------------------------------

    /**
     * Resources this admin may actually write to. Anything they cannot write
     * is never offered, so the picker can only produce a legal choice.
     *
     * @return array<string,Resource>
     */
    public static function writableResources(int $adminId): array
    {
        $writable = [];

        foreach (Resource::registry() as $key => $resource) {
            if (PermissionService::adminCan($adminId, $resource->managePermission)) {
                $writable[$key] = $resource;
            }
        }

        return $writable;
    }

    /**
     * The column the resource declares as unique, read out of its own
     * `unique:table,column` rule. Null when the resource declares none — in
     * which case duplicate detection is genuinely impossible and is reported
     * as such rather than guessed at.
     */
    public static function uniqueColumn(Resource $resource): ?string
    {
        foreach ($resource->fields as $field) {
            $rules = (string) ($field['rules'] ?? '');

            if (preg_match('/unique:([A-Za-z_][A-Za-z0-9_]*),([A-Za-z_][A-Za-z0-9_]*)/', $rules, $matches) === 1) {
                return $matches[2];
            }
        }

        return null;
    }

    /** Token from the picker to a real delimiter; anything else means "detect". */
    public static function delimiterFor(string $token): string
    {
        return self::DELIMITER_TOKENS[$token] ?? 'auto';
    }

    /** The token that names a delimiter, so the choice survives to the next step. */
    public static function tokenFor(string $delimiter): string
    {
        $tokens = array_flip(self::DELIMITER_TOKENS);

        return (string) ($tokens[$delimiter] ?? 'auto');
    }

    /** Fields keyed by their column name. @return array<string,array<string,mixed>> */
    public static function fieldsByName(Resource $resource): array
    {
        $fields = [];

        foreach ($resource->fields as $field) {
            $fields[(string) $field['name']] = $field;
        }

        return $fields;
    }

    /** @return list<string> Names of fields the resource declares as required. */
    public static function requiredFields(Resource $resource): array
    {
        $required = [];

        foreach ($resource->fields as $field) {
            if (self::hasRule((string) ($field['rules'] ?? ''), 'required')) {
                $required[] = (string) $field['name'];
            }
        }

        return $required;
    }

    // -----------------------------------------------------------------
    // Template
    // -----------------------------------------------------------------

    /**
     * Header row for the downloadable template: the resource's declared field
     * names, which is also what auto-mapping matches on first.
     *
     * @return list<string>
     */
    public static function templateHeaders(Resource $resource): array
    {
        return array_map(static fn (array $field): string => (string) $field['name'], $resource->fields);
    }

    /**
     * One example row, derived from each field's declared type, options and
     * default — so the template shows the shape every column expects.
     *
     * @return list<string>
     */
    public static function templateExample(Resource $resource): array
    {
        $row = [];

        foreach ($resource->fields as $field) {
            $row[] = self::exampleValue($field);
        }

        return $row;
    }

    /** @param array<string,mixed> $field */
    private static function exampleValue(array $field): string
    {
        $rules = (string) ($field['rules'] ?? '');

        return self::fitToRules(self::rawExample($field, $rules), $rules);
    }

    /** @param array<string,mixed> $field */
    private static function rawExample(array $field, string $rules): string
    {
        $type  = (string) $field['type'];
        $label = strtolower((string) $field['label']);

        // A declared default is the most truthful example there is.
        if (array_key_exists('default', $field) && $field['default'] !== '' && $field['default'] !== null) {
            return is_bool($field['default'])
                ? ($field['default'] ? '1' : '0')
                : (string) $field['default'];
        }

        // Several fields already carry a worked example in their hint
        // ("e.g. MONSOON20", "e.g. /old-page"). Nothing beats the one the
        // person who declared the field wrote.
        if (preg_match('/\be\.g\.\s*([^,.;]+)/i', (string) ($field['hint'] ?? ''), $matches) === 1) {
            $example = trim($matches[1]);

            if ($example !== '') {
                return $example;
            }
        }

        if (self::hasRule($rules, 'slug')) {
            return 'example-slug';
        }

        if (self::hasRule($rules, 'hex_color') || $type === 'color') {
            return '#B80049';
        }

        if (self::hasRule($rules, 'email')) {
            return 'name@example.com';
        }

        if ($type === 'select') {
            $options = $field['options'] ?? [];

            foreach (is_array($options) ? array_keys($options) : [] as $option) {
                if ((string) $option !== '') {
                    return (string) $option;
                }
            }

            return '';
        }

        return match ($type) {
            // A switch with no declared default is off on the create form too.
            'switch'   => '0',
            'datetime' => date('Y-m-d H:i:s'),
            'image'    => 'https://cdn.example.com/' . preg_replace('/[^a-z0-9]+/', '-', $label) . '.jpg',
            'number'   => (string) self::exampleNumber($rules),
            'html', 'textarea' => 'Replace this with your ' . $label . '.',
            default    => 'Example ' . $label,
        };
    }

    /**
     * Keep the generated example inside the field's own length rules — a
     * template whose example row fails validation would be worse than useless.
     * `min:` / `max:` only, never `min_value:` / `max_value:`.
     */
    private static function fitToRules(string $value, string $rules): string
    {
        if ($value === '') {
            return $value;
        }

        if (preg_match('/(?:^|\|)min:(\d+)/', $rules, $matches) === 1) {
            $seed = $value;

            while (mb_strlen($value) < (int) $matches[1]) {
                $value .= ' ' . $seed;
            }
        }

        if (preg_match('/(?:^|\|)max:(\d+)/', $rules, $matches) === 1) {
            $value = rtrim(mb_substr($value, 0, (int) $matches[1]));
        }

        return $value;
    }

    private static function exampleNumber(string $rules): int
    {
        if (preg_match('/min_value:(\d+)/', $rules, $matches) === 1) {
            return (int) $matches[1];
        }

        return 1;
    }

    // -----------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------

    /**
     * Turn raw CSV text into a header row and a rectangular row set.
     *
     * Handles a UTF-8 BOM, CP1252 payloads (what Excel on Windows writes when
     * "CSV" rather than "CSV UTF-8" is chosen), CR / CRLF / LF line endings,
     * quoted values and values containing the delimiter or a newline.
     *
     * @return array{delimiter:string,headers:list<string>,rows:list<list<string>>}
     */
    public static function parse(string $csv, string $delimiter = 'auto'): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw HttpException::unprocessable(
                'That file is ' . self::formatBytes(strlen($csv)) . '. The limit is '
                . self::formatBytes(self::MAX_BYTES) . ' — split it into smaller files and import them one after another.'
            );
        }

        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = (string) mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }

        // Normalise line endings first: a lone CR (classic Mac) would otherwise
        // be read as one enormous single-line record.
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);

        if (trim($csv) === '') {
            throw HttpException::unprocessable('There is nothing to import. Upload a file or paste some CSV.');
        }

        $delimiter = in_array($delimiter, self::DELIMITERS, true)
            ? $delimiter
            : self::detectDelimiter($csv);

        // Read one record more than the ceiling so "too many rows" is a clear
        // message rather than a silent truncation or a timeout.
        $records = self::readRecords($csv, $delimiter, self::MAX_ROWS + 2);

        if ($records === []) {
            throw HttpException::unprocessable('No rows could be read from that file. Check that it is a CSV.');
        }

        $headers = array_map(static fn (mixed $value): string => trim((string) $value), array_shift($records));

        // Spreadsheets often leave a run of empty trailing columns behind.
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        if ($headers === []) {
            throw HttpException::unprocessable('The first row has to name the columns. Download the template to see the expected headers.');
        }

        if (count($records) > self::MAX_ROWS) {
            throw HttpException::unprocessable(
                'That file has more than ' . number_format(self::MAX_ROWS) . ' data rows. '
                . 'Split it into files of ' . number_format(self::MAX_ROWS) . ' rows or fewer.'
            );
        }

        $width = count($headers);
        $rows  = [];

        foreach ($records as $record) {
            $row = [];

            for ($index = 0; $index < $width; $index++) {
                $row[$index] = trim((string) ($record[$index] ?? ''));
            }

            // Skip lines that are entirely empty — a trailing newline is normal.
            if (implode('', $row) === '') {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw HttpException::unprocessable('That file has a header row but no data rows.');
        }

        return ['delimiter' => $delimiter, 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * Sniff the delimiter by actually parsing with each candidate and scoring
     * the result on column count and how consistent that count is. Guessing
     * from character frequency alone breaks on quoted values that contain the
     * other candidates.
     */
    public static function detectDelimiter(string $csv): string
    {
        $sample = substr($csv, 0, self::SNIFF_BYTES);

        // Never sniff on a half-read record.
        if (strlen($csv) > self::SNIFF_BYTES) {
            $cut = strrpos($sample, "\n");

            if ($cut !== false && $cut > 0) {
                $sample = substr($sample, 0, $cut);
            }
        }

        $best      = ',';
        $bestScore = 0.0;

        foreach (self::DELIMITERS as $candidate) {
            $records = self::readRecords($sample, $candidate, 20);

            if ($records === []) {
                continue;
            }

            $counts = array_map('count', $records);
            $width  = $counts[0];

            if ($width < 2) {
                continue;
            }

            $consistent = count(array_filter($counts, static fn (int $count): bool => $count === $width));
            $score      = $width * ($consistent / count($counts));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return list<list<string|null>>
     */
    private static function readRecords(string $csv, string $delimiter, int $limit): array
    {
        $handle = fopen('php://temp/maxmemory:' . (self::MAX_BYTES + 1_048_576), 'r+');

        if ($handle === false) {
            throw HttpException::server('Could not open a buffer to read the file.');
        }

        fwrite($handle, $csv);
        rewind($handle);

        $records = [];

        // An empty escape string gives RFC 4180 behaviour: a backslash is
        // ordinary data, and "" is the only way to escape a quote.
        while (count($records) < $limit) {
            $record = fgetcsv($handle, null, $delimiter, '"', '');

            if ($record === false) {
                break;
            }

            // fgetcsv reports a blank line as [null].
            if ($record === [null]) {
                continue;
            }

            $records[] = $record;
        }

        fclose($handle);

        return $records;
    }

    // -----------------------------------------------------------------
    // Column mapping
    // -----------------------------------------------------------------

    /**
     * Map CSV headers onto declared fields: exact name, then case-insensitive
     * name, then the human label. Anything unrecognised maps to '' (ignored).
     *
     * @param  list<string> $headers
     * @return array<int,string>
     */
    public static function autoMap(Resource $resource, array $headers): array
    {
        $byName  = [];
        $byLabel = [];

        foreach ($resource->fields as $field) {
            $name           = (string) $field['name'];
            $byName[self::normaliseHeader($name)] = $name;
            $byLabel[self::normaliseHeader((string) $field['label'])] ??= $name;
        }

        $fields  = self::fieldsByName($resource);
        $mapping = [];
        $taken   = [];

        foreach ($headers as $index => $header) {
            $header = (string) $header;

            $candidate = null;

            if (isset($fields[$header])) {
                $candidate = $header;
            } else {
                $key       = self::normaliseHeader($header);
                $candidate = $byName[$key] ?? $byLabel[$key] ?? null;
            }

            if ($candidate === null || isset($taken[$candidate])) {
                $mapping[$index] = '';
                continue;
            }

            $taken[$candidate] = true;
            $mapping[$index]   = $candidate;
        }

        return $mapping;
    }

    private static function normaliseHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = (string) preg_replace('/[^a-z0-9]+/', '_', $header);

        return trim($header, '_');
    }

    /**
     * Clean an operator-supplied mapping: drop unknown field names, drop
     * indexes that do not exist, and keep only the first column claiming a
     * given field so a later pass can report the clash.
     *
     * @param  list<string>       $headers
     * @param  array<mixed,mixed> $submitted
     * @return array{0:array<int,string>,1:list<string>} mapping, clashing field names
     */
    public static function sanitiseMapping(Resource $resource, array $headers, array $submitted): array
    {
        $fields  = self::fieldsByName($resource);
        $mapping = [];
        $seen    = [];
        $clashes = [];

        foreach (array_keys($headers) as $index) {
            $value = (string) ($submitted[$index] ?? $submitted[(string) $index] ?? '');

            if ($value === '' || !isset($fields[$value])) {
                $mapping[$index] = '';
                continue;
            }

            if (isset($seen[$value])) {
                $clashes[]       = $value;
                $mapping[$index] = '';
                continue;
            }

            $seen[$value]    = true;
            $mapping[$index] = $value;
        }

        return [$mapping, array_values(array_unique($clashes))];
    }

    // -----------------------------------------------------------------
    // Dry run
    // -----------------------------------------------------------------

    /**
     * Validate every row against the resource's own rules and classify it as
     * valid, invalid or duplicate. Writes nothing.
     *
     * `counts.recycled` is a subset of `counts.duplicates`: rows whose match is
     * in the recycle bin. Those can never be written, whatever the duplicate
     * policy, so a caller reporting what will happen has to subtract them.
     *
     * @param  list<string>        $headers
     * @param  list<list<string>>  $rows
     * @param  array<int,string>   $mapping
     * @param  list<string>        $clashes  Fields claimed by more than one column
     * @return array{
     *     headers:list<string>,
     *     mapping:array<int,string>,
     *     unique_column:?string,
     *     rows:list<array<string,mixed>>,
     *     counts:array{total:int,valid:int,invalid:int,duplicates:int,recycled:int},
     *     problems:list<array{line:int,column:string,message:string}>,
     *     blocking:list<string>
     * }
     */
    public static function analyse(Resource $resource, array $headers, array $rows, array $mapping, array $clashes = []): array
    {
        $fields   = self::fieldsByName($resource);
        $blocking = [];

        foreach ($clashes as $clash) {
            $label      = (string) ($fields[$clash]['label'] ?? $clash);
            $blocking[] = 'More than one column is mapped to "' . $label . '". Each field may take one column only.';
        }

        $mapped = array_values(array_filter($mapping, static fn (string $field): bool => $field !== ''));

        if ($mapped === []) {
            $blocking[] = 'No columns are mapped yet. Point at least one column at a field.';
        }

        foreach (self::requiredFields($resource) as $required) {
            if (!in_array($required, $mapped, true)) {
                $blocking[] = 'Map a column to "' . (string) ($fields[$required]['label'] ?? $required)
                    . '" — it is required and cannot be left out.';
            }
        }

        // Which field each column feeds, so an error can name the CSV column
        // the operator actually sees rather than the internal field name.
        $columnFor = [];
        foreach ($mapping as $index => $field) {
            if ($field !== '') {
                $columnFor[$field] = (string) ($headers[$index] ?? $field);
            }
        }

        // Fields carried into every row: the mapped ones, plus any field with a
        // declared default so an omitted column still lands on the same value
        // the create form would have used.
        $carried = $mapped;

        foreach ($resource->fields as $field) {
            if (array_key_exists('default', $field) && !in_array((string) $field['name'], $carried, true)) {
                $carried[] = (string) $field['name'];
            }
        }

        $rules = [];
        foreach ($carried as $name) {
            $rules[$name] = self::rulesWithoutUnique((string) ($fields[$name]['rules'] ?? 'nullable|string|max:255'));
        }

        $uniqueColumn = self::uniqueColumn($resource);
        $analysed     = [];
        $keys         = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // Line 1 is the header row.
            $data = [];

            foreach ($mapping as $column => $field) {
                if ($field !== '') {
                    $data[$field] = $row[$column] ?? '';
                }
            }

            foreach ($carried as $name) {
                $field = $fields[$name] ?? [];

                if (self::isBooleanField($field) && array_key_exists($name, $data)) {
                    $data[$name] = self::normaliseBoolean((string) $data[$name]);
                }

                $missing = !array_key_exists($name, $data) || $data[$name] === '' || $data[$name] === null;

                if ($missing && array_key_exists('default', $field)) {
                    $data[$name] = $field['default'];
                }
            }

            [$passes, $errors, $validated] = Validator::check($data, $rules);

            if (!$passes) {
                $problems = [];

                foreach ($errors as $field => $messages) {
                    $problems[] = [
                        'column'  => $columnFor[$field] ?? (string) ($fields[$field]['label'] ?? $field),
                        'message' => (string) ($messages[0] ?? 'Invalid value.'),
                    ];
                }

                $analysed[] = [
                    'line'             => $line,
                    'status'           => 'invalid',
                    'data'             => [],
                    'key'              => null,
                    'existing_id'      => null,
                    'existing_trashed' => false,
                    'in_file'          => false,
                    'problems'         => $problems,
                ];

                continue;
            }

            $key = self::duplicateKey($resource, $uniqueColumn, $validated);

            if ($key !== null) {
                $keys[] = $key;
            }

            $analysed[] = [
                'line'             => $line,
                'status'           => 'valid',
                'data'             => $validated,
                'key'              => $key,
                'existing_id'      => null,
                'existing_trashed' => false,
                'in_file'          => false,
                'problems'         => [],
            ];
        }

        $existing = ($uniqueColumn !== null && $keys !== [])
            ? self::findExisting($resource, $uniqueColumn, $keys)
            : [];

        $seen     = [];
        $counts   = ['total' => count($analysed), 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'recycled' => 0];
        $problems = [];

        foreach ($analysed as $position => $row) {
            if ($row['status'] === 'invalid') {
                $counts['invalid']++;

                foreach ($row['problems'] as $problem) {
                    $problems[] = [
                        'line'    => $row['line'],
                        'column'  => $problem['column'],
                        'message' => $problem['message'],
                    ];
                }

                continue;
            }

            $key = $row['key'];

            if ($key !== null) {
                $lookup = mb_strtolower($key);
                $inFile = isset($seen[$lookup]);
                $match  = $existing[$lookup] ?? null;
                $seen[$lookup] = true;

                // A match sitting in the recycle bin is still a match as far as
                // the unique index is concerned, but it must never become an
                // update target: writing to it would edit a record nobody can
                // see and report it as updated.
                $trashed    = $match !== null && $match['trashed'];
                $existingId = ($match !== null && !$trashed) ? $match['id'] : null;

                if ($inFile || $match !== null) {
                    $analysed[$position]['status']           = 'duplicate';
                    $analysed[$position]['existing_id']      = $existingId;
                    $analysed[$position]['existing_trashed'] = $trashed;
                    $analysed[$position]['in_file']          = $inFile;

                    $counts['duplicates']++;

                    if ($trashed) {
                        $counts['recycled']++;
                    }

                    if ($trashed) {
                        $message = 'A deleted ' . strtolower($resource->singular) . ' already uses '
                            . (string) $uniqueColumn . ' "' . $key . '". Restore it, or empty it from the '
                            . 'recycle bin, before importing this row.';
                    } elseif ($inFile && $existingId === null) {
                        $message = 'Repeats "' . $key . '", which appears earlier in this file.';
                    } else {
                        $message = 'A record with ' . (string) $uniqueColumn . ' "' . $key . '" already exists.';
                    }

                    $problems[] = [
                        'line'    => $row['line'],
                        'column'  => $columnFor[$uniqueColumn] ?? (string) $uniqueColumn,
                        'message' => $message,
                    ];

                    continue;
                }
            }

            $counts['valid']++;
        }

        return [
            'headers'       => $headers,
            'mapping'       => $mapping,
            'unique_column' => $uniqueColumn,
            'rows'          => $analysed,
            'counts'        => $counts,
            'problems'      => $problems,
            'blocking'      => $blocking,
        ];
    }

    /**
     * The value this row would collide on.
     *
     * When the resource generates its slug from another column and the CSV has
     * no slug column, the slug the row *would* get is derived here — otherwise
     * re-importing the same file would quietly create name-2, name-3, … copies
     * instead of being recognised as a repeat.
     *
     * @param array<string,mixed> $validated
     */
    private static function duplicateKey(Resource $resource, ?string $uniqueColumn, array $validated): ?string
    {
        if ($uniqueColumn === null) {
            return null;
        }

        $value = $validated[$uniqueColumn] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if ($uniqueColumn === 'slug' && $resource->slugSource !== null) {
            $source = (string) ($validated[$resource->slugSource] ?? '');

            if (trim($source) !== '') {
                return Str::slug($source);
            }
        }

        return null;
    }

    /**
     * Rows already holding one of these keys in the resource's unique column.
     *
     * Soft-deleted rows are deliberately included. The table's UNIQUE index is
     * a plain one — it covers trashed rows too — so hiding them here would let
     * a row through as "new" and the INSERT would then be refused by the
     * database, rolling the whole file back. Uniqueness has to mean exactly
     * what the index means, so the trashed state travels with the match and the
     * caller decides what to say about it.
     *
     * @param  list<string> $keys
     * @return array<string,array{id:int,trashed:bool}> lower-cased key => match
     */
    private static function findExisting(Resource $resource, string $column, array $keys): array
    {
        $found  = [];
        $unique = array_values(array_unique($keys));

        foreach (array_chunk($unique, 500) as $chunk) {
            [$placeholders, $bindings] = Database::inClause($chunk);

            $sql = 'SELECT `id`, ' . Database::quoteIdentifier($column) . ' AS `match_key`'
                 . ($resource->softDeletes ? ', `deleted_at`' : ', NULL AS `deleted_at`')
                 . ' FROM ' . Database::quoteIdentifier($resource->table)
                 . ' WHERE ' . Database::quoteIdentifier($column) . ' IN ' . $placeholders;

            foreach (Database::select($sql, $bindings) as $row) {
                $found[mb_strtolower((string) $row['match_key'])] = [
                    'id'      => (int) $row['id'],
                    'trashed' => $row['deleted_at'] !== null,
                ];
            }
        }

        return $found;
    }

    // -----------------------------------------------------------------
    // Commit
    // -----------------------------------------------------------------

    /**
     * Write the analysed rows. One transaction for the whole import: if any
     * row fails, nothing at all is left behind.
     *
     * @param  array<string,mixed> $analysis Output of analyse()
     * @param  array<string,mixed> $admin    The signed-in admin row
     * @return array{created:int,updated:int,skipped:int}
     */
    public static function commit(Resource $resource, array $analysis, string $mode, array $admin): array
    {
        if (!in_array($mode, [self::MODE_SKIP, self::MODE_UPDATE, self::MODE_ABORT], true)) {
            throw HttpException::unprocessable('Choose how duplicates should be handled.');
        }

        if ($analysis['blocking'] !== []) {
            throw HttpException::unprocessable((string) $analysis['blocking'][0]);
        }

        if ($mode === self::MODE_ABORT && $analysis['counts']['duplicates'] > 0) {
            throw HttpException::unprocessable(
                'Import stopped: ' . $analysis['counts']['duplicates'] . ' duplicate row(s) found and you chose to abort. Nothing was written.'
            );
        }

        // "Every row is a duplicate" is a legitimate outcome under skip — it
        // reports 0 created, N skipped. Only a file where nothing at all passed
        // validation is worth refusing outright.
        if ($analysis['counts']['valid'] === 0 && $analysis['counts']['duplicates'] === 0) {
            throw HttpException::unprocessable('There is nothing to import: no row passed validation.');
        }

        $columns   = self::tableColumns($resource->table);
        $timestamp = date('Y-m-d H:i:s');

        $nextSort = null;

        if ($resource->sortableRows && in_array('sort_order', $columns, true)) {
            $nextSort = (int) Database::scalar(
                'SELECT MAX(`sort_order`) FROM ' . Database::quoteIdentifier($resource->table)
            ) + 1;
        }

        $uniqueColumn = $analysis['unique_column'];

        /** @var array{created:int,updated:int,skipped:int} $result */
        $result = Database::transaction(
            static function () use ($resource, $analysis, $mode, $admin, $columns, $timestamp, $nextSort, $uniqueColumn): array {
                $created = 0;
                $updated = 0;
                $skipped = 0;
                $sort    = $nextSort;

                // New rows are buffered and written a batch at a time, grouped by
                // the exact set of columns they fill, so each group becomes one
                // multi-row INSERT rather than one statement per row.
                /** @var array<string,array{columns:list<string>,rows:list<list<mixed>>,lines:list<int>}> $buffer */
                $buffer   = [];
                $buffered = 0;

                $flush = static function () use (&$buffer, &$buffered, $resource): void {
                    foreach ($buffer as $group) {
                        self::insertMany($resource->table, $group['columns'], $group['rows'], $group['lines']);
                    }

                    $buffer   = [];
                    $buffered = 0;
                };

                foreach ($analysis['rows'] as $row) {
                    if ($row['status'] === 'invalid') {
                        $skipped++;
                        continue;
                    }

                    if ($row['status'] === 'duplicate') {
                        // A row whose match is in the recycle bin is skipped in
                        // every mode. Inserting beside it would be refused by
                        // the unique index and roll the whole file back;
                        // updating it would rewrite a record that stays hidden
                        // and report it as updated. The dry run says so, and
                        // says what to do about it.
                        if ($mode !== self::MODE_UPDATE || ($row['existing_trashed'] ?? false) === true) {
                            $skipped++;
                            continue;
                        }

                        $targetId = $row['existing_id'];

                        // A row repeating an earlier row of the same file updates
                        // whatever that first occurrence created — which means the
                        // buffered inserts have to land before it can be found.
                        if ($targetId === null && $row['key'] !== null && $uniqueColumn !== null) {
                            $flush();
                            $targetId = self::findId($resource, $uniqueColumn, (string) $row['key']);
                        }

                        if ($targetId === null) {
                            $skipped++;
                            continue;
                        }

                        $payload = self::payload($resource, $row['data'], $columns, $admin, (int) $targetId, false);

                        if (in_array('updated_at', $columns, true)) {
                            $payload['updated_at'] = $timestamp;
                        }

                        if ($payload !== []) {
                            Database::update($resource->table, $payload, ['id' => (int) $targetId]);
                        }

                        $updated++;
                        continue;
                    }

                    $payload = self::payload($resource, $row['data'], $columns, $admin, null, true);

                    if (in_array('created_at', $columns, true)) {
                        $payload['created_at'] = $timestamp;
                    }

                    if (in_array('updated_at', $columns, true)) {
                        $payload['updated_at'] = $timestamp;
                    }

                    if ($sort !== null && ($payload['sort_order'] ?? null) === null) {
                        $payload['sort_order'] = $sort++;
                    }

                    $signature = implode('|', array_keys($payload));

                    $buffer[$signature]['columns'] = array_keys($payload);
                    $buffer[$signature]['rows'][]  = array_values($payload);
                    $buffer[$signature]['lines'][] = (int) $row['line'];

                    $created++;
                    $buffered++;

                    if ($buffered >= self::BATCH_SIZE) {
                        $flush();
                    }
                }

                $flush();

                return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
            },
            1
        );

        return $result;
    }

    /**
     * One multi-row INSERT for a set of rows that fill the same columns.
     *
     * Column names arrive from the caller already reduced to the resource's
     * declared fields intersected with the table's real columns, and each is
     * put through quoteIdentifier() as well. Every value is bound.
     *
     * @param list<string>      $columns
     * @param list<list<mixed>> $rows
     * @param list<int>         $lines  CSV line each row came from, for error reporting
     */
    private static function insertMany(string $table, array $columns, array $rows, array $lines): void
    {
        if ($columns === [] || $rows === []) {
            return;
        }

        $names = implode(', ', array_map([Database::class, 'quoteIdentifier'], $columns));
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        // Keep any single statement inside the placeholder budget, however many
        // columns the resource declares.
        $perStatement = max(1, intdiv(self::MAX_PLACEHOLDERS, count($columns)));
        $offset       = 0;

        foreach (array_chunk($rows, $perStatement) as $chunk) {
            $bindings = [];

            foreach ($chunk as $row) {
                foreach ($row as $value) {
                    $bindings[] = $value;
                }
            }

            try {
                Database::execute(
                    'INSERT INTO ' . Database::quoteIdentifier($table) . ' (' . $names . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), $tuple)),
                    $bindings
                );
            } catch (\PDOException $e) {
                // MySQL names the row inside the statement it just refused, which
                // is not the line the operator is looking at. Translate it before
                // the message ever reaches them.
                throw self::refusedRow($e, $lines, $offset);
            }

            $offset += count($chunk);
        }
    }

    /**
     * Turn a driver error into something an operator can act on: the CSV line
     * that caused it, and the reason with the SQLSTATE and vendor code removed.
     *
     * @param list<int> $lines
     */
    private static function refusedRow(\PDOException $e, array $lines, int $offset): HttpException
    {
        $message  = $e->getMessage();
        $position = null;

        if (preg_match('/\bat row (\d+)\b/i', $message, $matches) === 1) {
            $position = (int) $matches[1];
        }

        // Strip "SQLSTATE[…]: Some class: 1264 " and the trailing row pointer.
        if (preg_match('/^SQLSTATE\[[^\]]+\]:\s*(?:[^:]*:\s*)?(?:\d+\s+)?(.+)$/s', $message, $matches) === 1) {
            $message = $matches[1];
        }

        $message = trim((string) preg_replace(['/\s*\bat row \d+\s*$/i', '/\s+/'], ['', ' '], $message));
        $message = $message === '' ? 'the database refused the value' : rtrim($message, '.');

        $line = ($position !== null && isset($lines[$offset + $position - 1]))
            ? $lines[$offset + $position - 1]
            : null;

        return HttpException::unprocessable(
            $line === null
                ? 'Nothing was written. The database refused one of the rows: ' . mb_substr($message, 0, 200) . '.'
                : 'Nothing was written. Line ' . $line . ' of your file was refused: '
                    . mb_substr($message, 0, 200) . '.'
        );
    }

    /**
     * The id of the live row holding this value in the resource's unique column.
     *
     * Deliberately live-only, unlike findExisting(). This is reached from one
     * place only: a row repeating a key that appeared earlier in the same file,
     * to find whatever that first occurrence just inserted — which is live by
     * definition. Returning a trashed id here would let an in-file repeat
     * silently overwrite a record sitting in the recycle bin.
     */
    private static function findId(Resource $resource, string $column, string $value): ?int
    {
        $sql = 'SELECT `id` FROM ' . Database::quoteIdentifier($resource->table)
             . ' WHERE ' . Database::quoteIdentifier($column) . ' = :value';

        if ($resource->softDeletes) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        $id = Database::scalar($sql . ' ORDER BY `id` LIMIT 1', ['value' => $value]);

        return $id === null ? null : (int) $id;
    }

    /**
     * Build the column => value set for one row.
     *
     * Only declared fields survive, values are cast to what the column wants,
     * and the result is finally intersected with the table's real columns —
     * three independent reasons a CSV header can never name a column.
     *
     * @param  array<string,mixed> $data
     * @param  list<string>        $columns
     * @param  array<string,mixed> $admin
     * @return array<string,mixed>
     */
    private static function payload(
        Resource $resource,
        array $data,
        array $columns,
        array $admin,
        ?int $existingId,
        bool $isInsert
    ): array {
        $fields  = self::fieldsByName($resource);
        $payload = [];

        foreach ($data as $name => $value) {
            $field = $fields[(string) $name] ?? null;

            if ($field === null) {
                continue;
            }

            $payload[(string) $name] = self::castValue($field, $value);
        }

        if ($resource->slugSource !== null && in_array('slug', $columns, true)) {
            $slug = trim((string) ($payload['slug'] ?? ''));

            if ($slug === '' && $isInsert) {
                $slug = (string) ($payload[$resource->slugSource] ?? '');
            }

            if ($slug === '') {
                // Never blank an existing slug on an update.
                unset($payload['slug']);
            } else {
                $payload['slug'] = Str::uniqueSlug($slug, $resource->table, 'slug', $existingId);
            }
        }

        // The same two side effects ResourceController applies on a form save,
        // so an imported row is indistinguishable from a hand-created one.
        if ($resource->key === 'blogs') {
            if (($payload['status'] ?? '') === 'published' && empty($payload['published_at'])) {
                $payload['published_at'] = date('Y-m-d H:i:s');
            }

            if ($isInsert && empty($payload['author_name'])) {
                $payload['author_name'] = (string) ($admin['name'] ?? 'Admin');
                $payload['author_id']   = (int) ($admin['id'] ?? 0);
            }
        }

        if ($resource->key === 'pages') {
            $payload['updated_by'] = (int) ($admin['id'] ?? 0);

            if ($isInsert && empty($payload['published_at'])) {
                $payload['published_at'] = date('Y-m-d H:i:s');
            }
        }

        return array_intersect_key($payload, array_flip($columns));
    }

    /** @param array<string,mixed> $field */
    private static function castValue(array $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ((string) $field['type']) {
            'switch'   => self::truthy($value) ? 1 : 0,
            'html'     => Str::sanitizeHtml((string) $value),
            'number'   => ($value === '' || !is_numeric($value)) ? null : $value + 0,
            'datetime' => self::toDateTime($value),
            'select'   => $value === '' ? null : $value,
            default    => is_bool($value) ? ($value ? 1 : 0) : $value,
        };
    }

    private static function toDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * Spreadsheets write booleans a dozen ways. Normalise before validating so
     * "Yes" is accepted and "maybe" still fails the declared boolean rule.
     */
    private static function normaliseBoolean(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        if (in_array($value, ['1', 'true', 'yes', 'y', 'on', 'active', 'enabled'], true)) {
            return '1';
        }

        if (in_array($value, ['0', 'false', 'no', 'n', 'off', 'inactive', 'disabled'], true)) {
            return '0';
        }

        return $value;
    }

    /** @param array<string,mixed> $field */
    private static function isBooleanField(array $field): bool
    {
        return ($field['type'] ?? '') === 'switch'
            || self::hasRule((string) ($field['rules'] ?? ''), 'boolean');
    }

    /**
     * Uniqueness is an operator decision here (skip / update / abort), not a
     * validation failure, so the rule is removed before validating.
     */
    private static function rulesWithoutUnique(string $rules): string
    {
        $kept = array_filter(
            explode('|', $rules),
            static fn (string $rule): bool => $rule !== '' && !str_starts_with($rule, 'unique:')
        );

        return implode('|', $kept);
    }

    private static function hasRule(string $rules, string $rule): bool
    {
        foreach (explode('|', $rules) as $part) {
            if ($part === $rule || str_starts_with($part, $rule . ':')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function tableColumns(string $table): array
    {
        if (!isset(self::$columnCache[$table])) {
            /** @var list<string> $columns */
            $columns = array_map(
                'strval',
                Database::column('SHOW COLUMNS FROM ' . Database::quoteIdentifier($table))
            );

            self::$columnCache[$table] = $columns;
        }

        return self::$columnCache[$table];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return rtrim(rtrim(number_format($bytes / 1_048_576, 1, '.', ''), '0'), '.') . ' MB';
        }

        if ($bytes >= 1024) {
            return (string) (int) round($bytes / 1024) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
