<?php
declare(strict_types=1);

/**
 * Minimal dependency-free Excel (.xlsx) writer and reader.
 *
 * Writer emits inline-string cells (no sharedStrings) so the file opens in
 * Excel, LibreOffice and Google Sheets. Reader handles shared strings,
 * inline strings, numbers and Excel date serials.
 */

/** Column index (0-based) -> A, B, ... AA, AB */
function xlsx_col_letter(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $i--;
        $s = chr(65 + ($i % 26)) . $s;
        $i = intdiv($i, 26);
    }
    return $s;
}

function xml_esc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Stream an .xlsx download. $rows = associative arrays matching $headers.
 *
 * @param string[]        $headers column titles
 * @param array[]         $rows    list of value lists, same order as $headers
 */
function xlsx_export(array $headers, array $rows, string $filename, string $sheetName = 'Sheet1'): void
{
    $bytes = xlsx_build($headers, $rows, $sheetName);
    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
    }
    echo $bytes;
    exit;
}

/** Build the raw .xlsx bytes (no output, no exit) — handy for tests/tools. */
function xlsx_build(array $headers, array $rows, string $sheetName = 'Sheet1'): string
{
    $xmlSheet = xml_esc($sheetName);
    $sheet = '<row r="1">' . xlsx_header_row($headers) . '</row>';
    $rn    = 2;
    foreach ($rows as $row) {
        $sheet .= '<row r="' . $rn . '">' . xlsx_cells_row(array_values($row), $rn) . '</row>';
        $rn++;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        throw new RuntimeException('Cannot create a temporary file.');
    }
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML);

    $zip->addFromString('_rels/.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);

    $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="{$xmlSheet}" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML);

    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML);

    $zip->addFromString('xl/styles.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>
<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
<borders count="1"><border/></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>
</styleSheet>
XML);

    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' .
        $sheet . '</sheetData></worksheet>');

    $zip->close();

    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    return $bytes === false ? '' : $bytes;
}

/** Build the header row (row 1) as A1-style cells. */
function xlsx_header_row(array $headers): string
{
    $cells = '';
    foreach (array_values($headers) as $i => $h) {
        $ref  = xlsx_col_letter($i) . '1';
        $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . xml_esc((string) $h) . '</t></is></c>';
    }
    return $cells;
}

/** Cells for one row; $rowNumber is 1-based (2 = first data row). */
function xlsx_cells_row(array $vals, int $rowNumber): string
{
    $n    = $rowNumber;
    $cells = '';
    foreach (array_values($vals) as $i => $v) {
        $ref = xlsx_col_letter($i) . $n;
        if (is_int($v) || is_float($v)) {
            $cells .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
        } elseif ($v === null || $v === '') {
            $cells .= '<c r="' . $ref . '"/>';
        } else {
            $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xml_esc((string) $v) . '</t></is></c>';
        }
    }
    return $cells;
}

/**
 * Read the first sheet of an .xlsx file.
 *
 * @return array{headers: string[], rows: array<string,mixed>[], errors: string[]}
 *         headers = first row, rows = every following row keyed by header.
 */
function xlsx_read(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['headers' => [], 'rows' => [], 'errors' => ['File is not a valid .xlsx file.']];
    }

    // shared strings
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($xml);
        if ($sx) {
            foreach ($sx->si as $si) {
                $txt = '';
                if (isset($si->t)) {
                    $txt = (string) $si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $txt .= (string) $r->t;
                    }
                }
                $shared[] = $txt;
            }
        }
    }

    // first worksheet
    $sheetXml = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet1\.xml$#', $name)) {
            $sheetXml = $zip->getFromIndex($i);
            break;
        }
    }
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        return ['headers' => [], 'rows' => [], 'errors' => ['No worksheet found in the file.']];
    }

    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) {
        return ['headers' => [], 'rows' => [], 'errors' => ['Worksheet XML could not be parsed.']];
    }

    $grid = [];
    $maxCol = 0;
    foreach ($sx->sheetData->row as $row) {
        $rIdx = (int) ($row['r'] ?? (count($grid) + 1)) - 1;
        foreach ($row->c as $c) {
            $ref  = (string) ($c['r'] ?? '');
            $col  = preg_match('/^([A-Z]+)/', $ref, $m) ? $m[1] : null;
            $ci   = $col !== null ? xlsx_col_to_index($col) : count($grid[$rIdx] ?? []);
            $type = (string) ($c['t'] ?? 'n');
            $val  = null;

            if ($type === 'inlineStr') {
                $val = isset($c->is->t) ? (string) $c->is->t : '';
            } elseif (isset($c->v)) {
                $raw = (string) $c->v;
                if ($type === 's') {
                    $val = $shared[(int) $raw] ?? '';
                } elseif ($type === 'b') {
                    $val = $raw === '1' ? '1' : '0';
                } elseif ($type === 'str' || $type === 'e') {
                    $val = $raw;
                } else {
                    $val = is_numeric($raw) ? $raw : $raw;
                }
            }
            $grid[$rIdx][$ci] = $val;
            $maxCol = max($maxCol, $ci);
        }
    }

    if (!$grid) {
        return ['headers' => [], 'rows' => [], 'errors' => ['The spreadsheet is empty.']];
    }

    ksort($grid);
    $grid = array_values($grid);
    $headers = [];
    foreach ($grid[0] as $i => $h) {
        $headers[$i] = trim((string) $h);
    }

    $rows = [];
    for ($r = 1; $r < count($grid); $r++) {
        $line = [];
        foreach ($headers as $i => $h) {
            $line[$h] = $grid[$r][$i] ?? '';
        }
        if (implode('', array_map('strval', $line)) !== '') {
            $rows[] = $line;
        }
    }

    return ['headers' => $headers, 'rows' => $rows, 'errors' => []];
}

function xlsx_col_to_index(string $letters): int
{
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/** Turn an xlsx cell value (string, or Excel date serial) into 'Y-m-d H:i:s'. */
function xlsx_to_datetime($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v)) {
        $n = (float) $v;
        if ($n > 20000 && $n < 60000) {          // Excel serial date (1900 system)
            $ts = (int) round(($n - 25569) * 86400);
            return date('Y-m-d H:i:s', $ts + (new DateTimeImmutable('now'))->getOffset());
        }
        $v = (string) $v;
    }
    $ts = strtotime((string) $v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
