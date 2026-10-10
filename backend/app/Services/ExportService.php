<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Response;

/**
 * CSV, Excel (XLSX) and PDF-ready exports — all without Composer packages.
 *
 * XLSX is a ZIP of a handful of XML parts, which PHP's bundled ZipArchive can
 * assemble directly. That gives real Excel files (correct types, a styled
 * header row, frozen panes) instead of a CSV renamed to .xlsx.
 *
 * "PDF" is served as a print-optimised HTML document that opens the browser's
 * print dialogue. Writing a real PDF engine from scratch would be a large,
 * fragile dependency to maintain, and every browser already contains a
 * high-quality HTML-to-PDF renderer that produces selectable, accessible text.
 */
final class ExportService
{
    /**
     * Flatten associative result rows to the ordered column matrix the three
     * writers below expect.
     *
     * Doing this once, in one place, is what stops a value appearing in the CSV
     * but not in the workbook when a screen gains a new export format.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $columns
     *
     * @return list<list<string>>
     */
    public static function matrix(array $rows, array $columns): array
    {
        $matrix = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $value  = $row[$column] ?? '';
                $line[] = is_scalar($value) || $value === null
                    ? (string) $value
                    : (string) json_encode($value);
            }

            $matrix[] = $line;
        }

        return $matrix;
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function csv(string $filename, array $headers, array $rows): never
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw \Zuste\Core\HttpException::server('Could not build the export.');
        }

        // BOM so Excel reads UTF-8 (₹, accented names) correctly.
        fwrite($handle, "\xEF\xBB\xBF");

        if ($headers !== []) {
            fputcsv($handle, $headers);
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'neutralise'], $row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        Response::download($csv, $filename . '-' . date('Y-m-d-His') . '.csv', 'text/csv; charset=utf-8');
    }

    /**
     * Build a real .xlsx workbook.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function xlsx(string $filename, string $sheetTitle, array $headers, array $rows): never
    {
        if (!class_exists(\ZipArchive::class)) {
            // Degrade honestly rather than serving a mislabelled file.
            self::csv($filename, $headers, $rows);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'zuste-xlsx-');

        if ($tempFile === false) {
            throw \Zuste\Core\HttpException::server('Could not create a temporary file for the export.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($tempFile, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tempFile);
            throw \Zuste\Core\HttpException::server('Could not build the workbook.');
        }

        $sheetTitle = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $sheetTitle) ?: 'Sheet1', 0, 31);

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('docProps/app.xml', self::appProps());
        $zip->addFromString('docProps/core.xml', self::coreProps($sheetTitle));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetTitle));
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($headers, $rows));

        $zip->close();

        $contents = (string) file_get_contents($tempFile);
        @unlink($tempFile);

        Response::download(
            $contents,
            $filename . '-' . date('Y-m-d-His') . '.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /**
     * Print-optimised HTML that the browser turns into a PDF.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function pdf(string $title, array $headers, array $rows, string $subtitle = ''): never
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $appName = Config::settingString('general', 'app_name', 'Zusty');
        // The legal entity comes from Settings → General only; never hard-coded.
        $company = trim(Config::settingString('general', 'company_name', '')) ?: $appName;

        $headHtml = '';
        foreach ($headers as $header) {
            $headHtml .= '<th>' . $e($header) . '</th>';
        }

        $bodyHtml = '';
        foreach ($rows as $row) {
            $bodyHtml .= '<tr>';
            foreach ($row as $cell) {
                $bodyHtml .= '<td>' . $e((string) $cell) . '</td>';
            }
            $bodyHtml .= '</tr>';
        }

        $generated  = date('d M Y, g:i A');
        $rowCount   = count($rows);
        $safeTitle  = $e($title);
        $safeSub    = $e($subtitle);
        $safeApp    = $e($appName);
        $safeCo     = $e($company);

        Response::html(<<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>{$safeTitle}</title>
<style>
  @page { size: A4 landscape; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;
         color:#28171A; margin:0; padding:24px; font-size:12px; }
  header { display:flex; justify-content:space-between; align-items:flex-start;
           border-bottom:2px solid #B80049; padding-bottom:14px; margin-bottom:18px; }
  h1 { font-size:18px; margin:0 0 3px; }
  .muted { color:#5B3F43; font-size:11px; }
  table { width:100%; border-collapse:collapse; }
  th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.06em;
       color:#5B3F43; background:#FFF0F1; padding:8px 10px; border-bottom:2px solid #E4BDC2; }
  td { padding:7px 10px; border-bottom:1px solid #F1D3D6; }
  tbody tr:nth-child(even) { background:#FFFBFB; }
  footer { margin-top:18px; padding-top:10px; border-top:1px solid #E4BDC2; color:#8F6F73; font-size:10px;
           display:flex; justify-content:space-between; }
  .noprint { margin-bottom:16px; }
  @media print { .noprint { display:none; } body { padding:0; } }
</style></head>
<body>
  <div class="noprint" style="text-align:right">
    <button onclick="window.print()" style="background:#B80049;color:#fff;border:0;border-radius:10px;
      padding:10px 20px;font-weight:600;font-size:13px;cursor:pointer">Save as PDF</button>
  </div>

  <header>
    <div>
      <img src="/admin/assets/img/zusty-logo.png" width="138" height="30" alt="Zusty.app" style="display:block;margin-bottom:6px">
      <div class="muted">{$safeCo}</div>
    </div>
    <div style="text-align:right">
      <h1>{$safeTitle}</h1>
      <div class="muted">{$safeSub}</div>
      <div class="muted">Generated {$generated}</div>
    </div>
  </header>

  <table>
    <thead><tr>{$headHtml}</tr></thead>
    <tbody>{$bodyHtml}</tbody>
  </table>

  <footer>
    <span>{$rowCount} rows</span>
    <span>{$safeApp} — confidential</span>
  </footer>

  <script>window.addEventListener('load', function () { setTimeout(window.print, 350); });</script>
</body></html>
HTML);
    }

    // -----------------------------------------------------------------
    // XLSX parts
    // -----------------------------------------------------------------

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private static function sheet(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Freeze the header row so long exports stay readable.
        $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
              . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
              . '</sheetView></sheetViews>';

        $columnCount = max(count($headers), $rows === [] ? 0 : count($rows[0]));

        if ($columnCount > 0) {
            $xml .= '<cols>';
            for ($i = 1; $i <= $columnCount; $i++) {
                $xml .= '<col min="' . $i . '" max="' . $i . '" width="22" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        $rowIndex = 1;

        if ($headers !== []) {
            $xml .= '<row r="1" ht="22" customHeight="1">';

            foreach (array_values($headers) as $columnIndex => $header) {
                $xml .= self::cell(self::columnName($columnIndex) . '1', (string) $header, true);
            }

            $xml .= '</row>';
            $rowIndex++;
        }

        foreach ($rows as $row) {
            $xml .= '<row r="' . $rowIndex . '">';

            foreach (array_values($row) as $columnIndex => $value) {
                $xml .= self::cell(self::columnName($columnIndex) . $rowIndex, (string) $value, false);
            }

            $xml .= '</row>';
            $rowIndex++;
        }

        $xml .= '</sheetData>';

        if ($headers !== [] && $columnCount > 0) {
            $xml .= '<autoFilter ref="A1:' . self::columnName($columnCount - 1) . max(1, $rowIndex - 1) . '"/>';
        }

        return $xml . '</worksheet>';
    }

    /** A single cell — numeric when the value is numeric, otherwise inline text. */
    private static function cell(string $reference, string $value, bool $isHeader): string
    {
        $style = $isHeader ? ' s="1"' : '';

        // Keep leading-zero identifiers ("007", "0812345") and very long ids as
        // text; Excel would mangle them.
        //
        // The pattern is deliberately stricter than is_numeric(). A blanket
        // "starts with 0 ⇒ text" rule also caught "0" and "0.00", so a money or
        // count column came out half number and half text — Excel then flags
        // every zero cell, drops it from AVERAGE/COUNT and sorts the column in
        // two separate blocks. Only a zero followed by another digit is a
        // leading zero worth preserving. is_numeric() is also too permissive
        // for the <v> element, which must hold a plain decimal: " 12", "+5"
        // and "1e5" all pass is_numeric() but are not written here as numbers.
        $isNumeric = !$isHeader
            && strlen($value) < 15
            && preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) === 1;

        if ($isNumeric) {
            return '<c r="' . $reference . '"' . $style . '><v>' . $value . '</v></c>';
        }

        return '<c r="' . $reference . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
             . self::escapeXml($value) . '</t></is></c>';
    }

    private static function columnName(int $index): string
    {
        $name = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr(65 + ($i % 26)) . $name;
        }

        return $name;
    }

    private static function escapeXml(string $value): string
    {
        // Strip control characters that would make the XML invalid.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetTitle): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escapeXml($sheetTitle) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    /** Header row styled in the brand pink, matching the panel. */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFB80049"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">'
            . '<alignment vertical="center"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private static function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>Zusty Admin</Application></Properties>';
    }

    private static function coreProps(string $title): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::escapeXml($title) . '</dc:title>'
            . '<dc:creator>Zusty Admin</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
            . '</cp:coreProperties>';
    }

    /**
     * Stop a spreadsheet treating a value as a formula.
     * `=cmd|'/c calc'!A1` in a CSV is a real remote-code-execution vector.
     */
    private static function neutralise(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
