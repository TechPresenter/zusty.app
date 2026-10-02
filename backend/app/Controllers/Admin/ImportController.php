<?php

declare(strict_types=1);

namespace Zuste\Controllers\Admin;

use Zuste\Admin\Resource;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Core\Session;
use Zuste\Services\CreatorService;
use Zuste\Services\ExportService;
use Zuste\Services\ImportService;
use Zuste\Services\NotificationService;

/**
 * Generic CSV import for the declarative resource registry.
 *
 * Three steps, and the middle one writes nothing:
 *   index    — pick a resource, take a template, upload or paste CSV
 *   preview  — map columns, see the dry-run report, choose a duplicate policy
 *   commit   — one transaction, then a plain count of what happened
 *
 * The CSV is carried in the form between preview and commit rather than being
 * parked on the server. Nothing an operator uploads is ever written to disk:
 * the PHP upload temp file is read once and unlinked immediately, and the
 * payload is re-parsed and re-validated from scratch on commit, so the
 * browser's copy of the dry-run report is never trusted.
 */
final class ImportController extends BaseAdminController
{
    /** Extensions we will read. The contents are checked as well. */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt', 'tsv'];

    // -----------------------------------------------------------------
    // Step 1 — choose a resource and supply data
    // -----------------------------------------------------------------

    /** GET /admin/import */
    public function index(Request $request): never
    {
        $writable = ImportService::writableResources($this->adminId($request));

        $selected = null;
        $key      = $request->string('resource');

        if ($key !== '' && isset($writable[$key])) {
            $selected = $writable[$key];
        } elseif ($writable !== []) {
            $selected = reset($writable) ?: null;
        }

        $summary = Session::getFlash('import_summary');

        $this->view($request, 'admin.import.index', [
            'pageTitle'    => 'Import CSV',
            'pageSubtitle' => 'Bring records in from a spreadsheet, with a dry run before anything is written.',
            'resources'    => $writable,
            'selected'     => $selected,
            'summary'      => is_array($summary) ? $summary : null,
            'maxBytes'     => ImportService::MAX_BYTES,
            'maxRows'      => ImportService::MAX_ROWS,
        ]);
    }

    // -----------------------------------------------------------------
    // Template
    // -----------------------------------------------------------------

    /** GET /admin/import/{resource}/template */
    public function template(Request $request): never
    {
        $resource = $this->resource($request, (string) $request->routeParam('resource', ''));

        ExportService::csv(
            'zuste-import-template-' . $resource->key,
            ImportService::templateHeaders($resource),
            [ImportService::templateExample($resource)]
        );
    }

    // -----------------------------------------------------------------
    // Step 2 — dry run
    // -----------------------------------------------------------------

    /** POST /admin/import/preview */
    public function preview(Request $request): never
    {
        $key      = $request->string('resource');
        $resource = $this->resource($request, $key);

        try {
            $csv    = $this->payload($request);
            $parsed = ImportService::parse($csv, ImportService::delimiterFor($request->string('delimiter')));
        } catch (HttpException $e) {
            $this->back('error', $e->getMessage(), '/admin/import?resource=' . rawurlencode($resource->key));
        }

        $clashes = [];

        if ($request->has('map')) {
            [$mapping, $clashes] = ImportService::sanitiseMapping($resource, $parsed['headers'], $request->array('map'));
        } else {
            $mapping = ImportService::autoMap($resource, $parsed['headers']);
        }

        $analysis = ImportService::analyse($resource, $parsed['headers'], $parsed['rows'], $mapping, $clashes);

        $mode = $request->string('mode', ImportService::MODE_SKIP);

        if (!in_array($mode, [ImportService::MODE_SKIP, ImportService::MODE_UPDATE, ImportService::MODE_ABORT], true)) {
            $mode = ImportService::MODE_SKIP;
        }

        $this->view($request, 'admin.import.preview', [
            'pageTitle'    => 'Import ' . strtolower($resource->plural),
            'pageSubtitle' => 'Nothing has been written yet. Check the mapping and the report below.',
            'resource'     => $resource,
            'analysis'     => $analysis,
            'headers'      => $parsed['headers'],
            'samples'      => array_slice($parsed['rows'], 0, 3),
            'delimiter'    => $parsed['delimiter'],
            'delimiterToken' => ImportService::tokenFor($parsed['delimiter']),
            'csv'          => $csv,
            'mode'         => $mode,
            'fields'       => $resource->fields,
            'required'     => ImportService::requiredFields($resource),
        ]);
    }

    // -----------------------------------------------------------------
    // Step 3 — write
    // -----------------------------------------------------------------

    /** POST /admin/import/commit */
    public function commit(Request $request): never
    {
        $resource = $this->resource($request, $request->string('resource'));
        $back     = '/admin/import?resource=' . rawurlencode($resource->key);

        try {
            // Re-parse and re-validate from the submitted payload. The dry run
            // the browser saw is a report, never an instruction.
            $csv    = $this->payload($request);
            $parsed = ImportService::parse($csv, ImportService::delimiterFor($request->string('delimiter')));

            [$mapping, $clashes] = $request->has('map')
                ? ImportService::sanitiseMapping($resource, $parsed['headers'], $request->array('map'))
                : [ImportService::autoMap($resource, $parsed['headers']), []];

            $analysis = ImportService::analyse($resource, $parsed['headers'], $parsed['rows'], $mapping, $clashes);
            $result   = ImportService::commit($resource, $analysis, $request->string('mode'), $this->admin($request));
        } catch (HttpException $e) {
            $this->back('error', $e->getMessage(), $back);
        } catch (\Throwable $e) {
            // The transaction has already rolled back by the time this is
            // reached, so the honest report is "nothing was written" plus the
            // reason — not a 500 page that leaves the operator guessing.
            Logger::error('CSV import failed', [
                'resource' => $resource->key,
                'admin_id' => $this->adminId($request),
                'error'    => $e->getMessage(),
            ]);

            $this->back(
                'error',
                'The import was rolled back and nothing was written — ' . self::reason($e),
                $back
            );
        }

        $this->afterWrite($resource);

        $summary = [
            'resource' => $resource->plural,
            'key'      => $resource->key,
            'created'  => $result['created'],
            'updated'  => $result['updated'],
            'skipped'  => $result['skipped'],
            // Rows skipped because the recycle bin still holds their unique
            // value. Reported separately so a surprising skip count is
            // explained on the screen the operator lands on, not just in the
            // dry run they have already left.
            'recycled' => $analysis['counts']['recycled'],
            'total'    => $analysis['counts']['total'],
            'mode'     => $request->string('mode'),
        ];

        Session::flash('import_summary', $summary);

        $this->log(
            $request,
            'import.completed',
            $resource->key,
            null,
            'Imported ' . $result['created'] . ' created, ' . $result['updated'] . ' updated, '
            . $result['skipped'] . ' skipped into ' . strtolower($resource->plural) . '.',
            $summary
        );

        $this->respond(
            $request,
            $this->summaryLine($result),
            $back,
            $summary
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Resolve a resource key against the registry and check the operator may
     * write to it. The key never reaches SQL — Resource::find() either returns
     * a registered definition or 404s.
     */
    private function resource(Request $request, string $key): Resource
    {
        if ($key === '' || !Resource::exists($key)) {
            throw HttpException::notFound('Unknown import target.');
        }

        $resource = Resource::find($key);

        $this->authorize($request, $resource->managePermission);

        return $resource;
    }

    /**
     * The CSV text for this request: an uploaded file if there is one,
     * otherwise the pasted / carried text.
     *
     * The upload is read straight out of PHP's temp file and that file is
     * removed immediately. Nothing reaches the uploads directory, and nothing
     * is left in the system temp directory either.
     */
    private function payload(Request $request): string
    {
        $file = $request->file('csv_file');

        if ($file !== null) {
            return $this->readUpload($file);
        }

        $pasted = $request->input('csv_text', '');

        if (!is_string($pasted) || trim($pasted) === '') {
            throw HttpException::unprocessable('Choose a CSV file or paste some rows first.');
        }

        return $pasted;
    }

    /**
     * @param array<string,mixed> $file
     */
    private function readUpload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::unprocessable(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server accepts. The limit is '
                    . ImportService::formatBytes(ImportService::MAX_BYTES) . '.',
                UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
                UPLOAD_ERR_NO_FILE   => 'No file was received.',
                default              => 'The upload failed.',
            });
        }

        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw HttpException::unprocessable('That upload could not be read.');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size > ImportService::MAX_BYTES) {
            @unlink($temporary);

            throw HttpException::unprocessable(
                'That file is ' . ImportService::formatBytes($size) . '. The limit is '
                . ImportService::formatBytes(ImportService::MAX_BYTES)
                . ' — split it into smaller files and import them one after another.'
            );
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            @unlink($temporary);

            throw HttpException::unprocessable('Upload a .csv file, or paste the rows into the box instead.');
        }

        // Read the real type from the bytes: a spreadsheet or an archive
        // renamed to .csv is rejected here rather than parsed as gibberish.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $detected = (string) finfo_file($finfo, $temporary);
            finfo_close($finfo);

            $isText = str_starts_with($detected, 'text/')
                || in_array($detected, ['application/csv', 'application/x-empty', 'inode/x-empty'], true);

            if (!$isText) {
                @unlink($temporary);

                throw HttpException::unprocessable(
                    'That is not a text file (it looks like ' . $detected . '). Export the sheet as CSV first.'
                );
            }
        }

        $contents = file_get_contents($temporary);

        // The upload never becomes a stored file — it is read once and gone.
        @unlink($temporary);

        if ($contents === false) {
            throw HttpException::unprocessable('That upload could not be read.');
        }

        if (str_contains($contents, "\0")) {
            throw HttpException::unprocessable('That file contains binary data, so it is not a CSV.');
        }

        return $contents;
    }

    /**
     * A readable reason from a database failure.
     *
     * The driver message names the column and the offending value, which is
     * exactly what the operator needs to fix their spreadsheet. The SQLSTATE
     * and vendor error number are stripped — they belong in the log, which
     * keeps the full message.
     */
    private static function reason(\Throwable $e): string
    {
        if (!$e instanceof \PDOException) {
            return 'the database refused one of the rows.';
        }

        $message = $e->getMessage();

        if (preg_match('/^SQLSTATE\[[^\]]+\]:\s*(?:[^:]*:\s*)?(?:\d+\s+)?(.+)$/s', $message, $matches) === 1) {
            $message = $matches[1];
        }

        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            return 'the database refused one of the rows.';
        }

        return rtrim(mb_substr($message, 0, 200), '.') . '.';
    }

    /** @param array{created:int,updated:int,skipped:int} $result */
    private function summaryLine(array $result): string
    {
        $parts = [$result['created'] . ' created'];

        if ($result['updated'] > 0) {
            $parts[] = $result['updated'] . ' updated';
        }

        if ($result['skipped'] > 0) {
            $parts[] = $result['skipped'] . ' skipped';
        }

        return 'Import finished: ' . implode(', ', $parts) . '.';
    }

    /** The same denormalised counters ResourceController keeps honest. */
    private function afterWrite(Resource $resource): void
    {
        if ($resource->key === 'categories') {
            CreatorService::refreshStatistics();
        }

        if ($resource->key === 'faqs') {
            NotificationService::flushTemplates();
        }
    }
}
