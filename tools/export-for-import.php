<?php

/**
 * Export the local database to an Excel file the app's own Import screen accepts.
 *
 * Why this exists: the app's built-in export (AssetsExport) speaks modern headers
 * ("Company", "OS Version", "Status"...) while the importer's column_map speaks
 * the legacy sheet's vocabulary ("Company Name", "OS / Versi", "Status Aset"...).
 * Roughly half the export headers have no counterpart in the map, so feeding an
 * export back into the import silently drops fields. This script closes that loop
 * without touching either side: it reuses the ORIGINAL sheet's 39 headers verbatim
 * and fills them from the database, so the result normalises onto column_map by
 * construction.
 *
 * It is a merge, not a dump, because neither source is complete on its own:
 *
 *   - The database is authoritative for hardware and status: the resident agent
 *     has re-reported storage, OS, brand, model and hostname since the original
 *     import, and Status Aset in the sheet is empty for all 272 rows while the
 *     database carries real statuses.
 *
 *   - The sheet is authoritative for custody and lifecycle: the original seeder
 *     never read User Pertama / USER SEKARANG / Admin-IT PIC / Riwayat Perbaikan,
 *     so those columns are populated in the sheet (161/272/268/58 rows) and empty
 *     in the database. The importer turns them into assignment history and
 *     placeholder users on the receiving side (PRD section 16).
 *
 * Matching is by serial or hostname, never by asset_id: 247 of the 261 database
 * rows carry generated ids (AST-<hostname>-N) because the sheet only fills ASSET
 * ID for 14 rows. Ambiguous keys - 26 serials and 11 hostnames repeat in the
 * source - break the tie with the second key and otherwise return nothing, because
 * a wrong custody row is worse than a missing one.
 *
 * Identity policy: the importer treats asset_id, serial and hostname as identity
 * and skips any row that repeats one - inside the file or against the register -
 * so the register's junk serials would cost rows. WMI placeholders occur up to
 * seven times each ("System Serial Number" six, "To Be Filled By O.E.M." four,
 * bare "-" seven) and generic hostnames six ("MSI"). This export empties junk and
 * repeated serials, which lands as a warning rather than an error while asset_id
 * is present, and suffixes repeated hostnames, which cannot be blanked because
 * hostname is required. The first occurrence keeps the original value. Without
 * this the 261 rows would arrive on a fresh register as roughly 230: the skipped
 * rows are visible in the preview but the assets are simply not there.
 *
 * Usage:  php tools/export-for-import.php [output.xlsx]
 * Then:   upload the file on the server at /import (Import Excel).
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$sheetPath = dirname(__DIR__) . '/IT Assets.xlsx';
$dbPath = dirname(__DIR__) . '/database/database.sqlite';
$outPath = $argv[1] ?? dirname(__DIR__) . '/storage/app/imports/local-assets-merged.xlsx';

foreach ([$sheetPath, $dbPath] as $p) {
    if (! is_file($p)) {
        fwrite(STDERR, "not found: $p\n");
        exit(1);
    }
}

/** Same normalisation the importer applies to headers (config/asset-import.php). */
function key_of(string $header): string
{
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
}

// --- read the original sheet ------------------------------------------------

$book = IOFactory::load($sheetPath);
$src = $book->getSheetByName('Details') ?? $book->getSheet(1);
$rows = $src->toArray();
$srcHeader = array_shift($rows);

$bySerial = [];
$byHost = [];
$sheetDataRows = 0;
foreach ($rows as $r) {
    $rec = array_combine($srcHeader, $r);
    $filled = false;
    foreach ($rec as $v) {
        if (trim((string) $v) !== '') {
            $filled = true;
            break;
        }
    }
    if (! $filled) {
        continue;
    }
    $sheetDataRows++;
    $sn = mb_strtolower(trim((string) ($rec['Serial Number'] ?? '')));
    $hn = mb_strtolower(trim((string) ($rec['Hostname'] ?? '')));
    if ($sn !== '' && $sn !== 'n/a') {
        $bySerial[$sn][] = $rec;
    }
    if ($hn !== '') {
        $byHost[$hn][] = $rec;
    }
}

/**
 * Pick the sheet row for a database row without ever guessing.
 *
 * 26 serials and 11 hostnames repeat in the sheet, so a key alone can name two
 * different assets. The second key breaks the tie; if the candidates still
 * disagree, nothing is returned - a wrong custody row is worse than a missing
 * one, and the receiver leaves those columns blank instead of misattributing
 * who holds the device.
 */
function pick_sheet_row(array $bySerial, array $byHost, string $sn, string $hn): ?array
{
    $candidates = [];
    if ($sn !== '' && $sn !== 'n/a' && isset($bySerial[$sn])) {
        $candidates = $bySerial[$sn];
    } elseif ($hn !== '' && isset($byHost[$hn])) {
        $candidates = $byHost[$hn];
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }
    if (count($candidates) > 1) {
        $narrowed = array_values(array_filter($candidates, fn ($c) => mb_strtolower(trim((string) ($c['Hostname'] ?? ''))) === $hn
            || ($hn === '' && mb_strtolower(trim((string) ($c['Serial Number'] ?? ''))) === $sn)));
        if (count($narrowed) === 1) {
            return $narrowed[0];
        }
    }

    return null;
}

// --- read the database ------------------------------------------------------

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbRows = $pdo->query(
    'SELECT a.*, s.name AS status_name, c.name AS category_name,
            l.name AS location_name, co.name AS company_name
     FROM assets a
     LEFT JOIN asset_statuses s ON s.id = a.asset_status_id
     LEFT JOIN asset_categories c ON c.id = a.category_id
     LEFT JOIN locations l ON l.id = a.location_id
     LEFT JOIN companies co ON co.id = a.company_id
     ORDER BY a.id'
)->fetchAll(PDO::FETCH_ASSOC);

// --- which source wins per column -------------------------------------------
//
// Keys are column_map keys from config/asset-import.php. Headers come from the
// original sheet row verbatim, so the receiving normalisation lands on them.

$dbFields = [
    'companyname' => 'company_name',
    'assetid' => 'asset_id',
    'kategori' => 'category_name',
    'merk' => 'brand',
    'model' => 'model',
    'serialnumber' => 'serial_number',
    'hostname' => 'hostname',
    'processor' => 'processor',
    'storage' => 'storage',
    'ram' => 'ram',
    'osversi' => 'os_version',
    'lokasi' => 'location_name',
    'tahunpembelian' => 'purchase_year',
    'vendor' => 'vendor',
    'hargarp' => 'purchase_price',
    'statusaset' => 'status_name',
    'macaddress' => 'mac_address',
    'ipaddressstatis' => 'static_ip',
];

// Everything else comes from the matched sheet row: the assignment block, the
// current holder, Admin/IT PIC and repair notes were never imported locally.

// --- write the merged workbook ---------------------------------------------

$out = new Spreadsheet();
$ws = $out->getActiveSheet();
// The importer looks for a sheet named after config('asset-import.sheet_names')
// first; it falls back to sheet 0 either way, but matching the name keeps the
// generated file indistinguishable from the original for a human inspecting it.
$ws->setTitle('Details');

$written = [];
foreach ($srcHeader as $h) {
    if ($h !== null && trim((string) $h) !== '') {
        $written[] = trim((string) $h);
    }
}
$ws->fromArray($written, null, 'A1');

$matched = 0;
$unmatched = 0;
$junkSerials = 0;
$repeatSerials = 0;
$renamedHosts = 0;
$usedSerial = [];
$usedHost = [];
$excelRow = 2;

// Serials that identify nothing: what a machine reports when the firmware
// field is unset. Keeping them would make later rows look like duplicates of
// earlier ones, and the importer skips duplicates.
$junkSerialsWanted = [
    '-', 'system serial number', 'to be filled by o.e.m.', 'default string',
    'default', 'none', 'not specified', 'unknown', 'invalid',
    'base board serial number', 'chassis serial number', 'serial number',
    '0', 'n/a', 'na',
];

foreach ($dbRows as $db) {
    $sn = mb_strtolower(trim((string) ($db['serial_number'] ?? '')));
    $hn = mb_strtolower(trim((string) ($db['hostname'] ?? '')));
    $rec = pick_sheet_row($bySerial, $byHost, $sn, $hn);
    if ($rec !== null) {
        $matched++;
    } else {
        $unmatched++;
    }

    // --- identity (see docblock) -------------------------------------------
    $serialRaw = trim((string) ($db['serial_number'] ?? ''));
    $serialKey = mb_strtolower($serialRaw);
    if ($serialKey === '' || in_array($serialKey, $junkSerialsWanted, true)) {
        $outSerial = '';
        if ($serialKey !== '') {
            $junkSerials++;
        }
    } elseif (isset($usedSerial[$serialKey])) {
        $outSerial = '';
        $repeatSerials++;
    } else {
        $outSerial = $serialRaw;
        $usedSerial[$serialKey] = true;
    }

    $hostRaw = trim((string) ($db['hostname'] ?? ''));
    $outHost = $hostRaw;
    if ($hostRaw !== '') {
        $candidate = $hostRaw;
        $n = 1;
        while (isset($usedHost[mb_strtolower($candidate)])) {
            $n++;
            $candidate = $hostRaw . '-' . $n;
        }
        if ($candidate !== $hostRaw) {
            $renamedHosts++;
        }
        $outHost = $candidate;
        $usedHost[mb_strtolower($outHost)] = true;
    }

    $cells = [];
    foreach ($written as $header) {
        $k = key_of($header);
        if ($k === 'serialnumber') {
            $cells[] = $outSerial;

            continue;
        }
        if ($k === 'hostname') {
            $cells[] = $outHost;

            continue;
        }
        if (isset($dbFields[$k])) {
            $v = $db[$dbFields[$k]] ?? '';
        } else {
            $v = $rec[$header] ?? '';
        }
        if ($v === null) {
            $v = '';
        }
        $cells[] = $v;
    }
    $ws->fromArray($cells, null, 'A' . $excelRow);
    $excelRow++;
}

$dir = dirname($outPath);
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}
(new Xlsx($out))->save($outPath);

printf("wrote %s\n", $outPath);
printf("  database rows exported : %d\n", count($dbRows));
printf("  original sheet rows    : %d\n", $sheetDataRows);
printf("  custody columns matched: %d (unmatched: %d)\n", $matched, $unmatched);
printf("  junk serials emptied   : %d\n", $junkSerials);
printf("  repeats serials emptied: %d\n", $repeatSerials);
printf("  hostnames suffixed     : %d\n", $renamedHosts);
printf("  columns                : %d (original header order, verbatim)\n", count($written));

