<?php
/**
 * CLI / Utility Script: Import Dips from resources/dips send.xlsx
 * - Populates database table tbl_dip_lookup
 * - Exports seed file resources/tbl_dip_lookup_seed.sql
 * - Generates documentation resources/dip_lookup_master.md
 */

require_once __DIR__ . '/../include/config.php';

function colToIdx($col) {
    $col = strtoupper($col);
    $len = strlen($col);
    $idx = 0;
    for ($i = 0; $i < $len; $i++) {
        $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}

function parseWorksheetFromZip($zipPath, $sheetXmlPath, $ssXmlPath) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        die("Error opening ZIP archive: $zipPath\n");
    }

    $strings = [];
    $ssContent = $zip->getFromName($ssXmlPath);
    if ($ssContent !== false) {
        $xml = simplexml_load_string($ssContent);
        foreach ($xml->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
            }
            $strings[] = $text;
        }
    }

    $sheetContent = $zip->getFromName($sheetXmlPath);
    if ($sheetContent === false) {
        $zip->close();
        return [];
    }

    $xml = simplexml_load_string($sheetContent);
    $pairs = [];

    foreach ($xml->sheetData->row as $row) {
        $rowCols = [];
        foreach ($row->c as $c) {
            $cellRef = (string)$c['r'];
            $colLetter = preg_replace('/[0-9]/', '', $cellRef);
            $idx = colToIdx($colLetter);
            $t = (string)$c['t'];
            $v = (string)$c->v;
            if ($t === 's' && isset($strings[intval($v)])) {
                $val = trim($strings[intval($v)]);
            } else {
                $val = trim($v);
            }
            $rowCols[$idx] = $val;
        }

        foreach ($rowCols as $cIdx => $val) {
            if ($cIdx % 2 === 0 && isset($rowCols[$cIdx + 1])) {
                $mmVal = $val;
                $litreVal = $rowCols[$cIdx + 1];
                if (is_numeric($mmVal) && is_numeric($litreVal)) {
                    $mm = floatval($mmVal);
                    $litre = floatval($litreVal);
                    // Filter out invalid inverted summary rows at sheet end (mm > 4000)
                    if ($mm <= 4000) {
                        $pairs[$mm] = $litre;
                    }
                }
            }
        }
    }

    $zip->close();
    ksort($pairs);
    return $pairs;
}

$zipPath = __DIR__ . '/../resources/dips send.xlsx';
if (!file_exists($zipPath)) {
    die("File not found: $zipPath\n");
}

echo "=== Starting Dip Chart Import & Export ===\n";

$chart23500 = parseWorksheetFromZip($zipPath, 'xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml');
$chart50000 = parseWorksheetFromZip($zipPath, 'xl/worksheets/sheet2.xml', 'xl/sharedStrings.xml');

echo "Parsed 23,500 Ltrs chart entries: " . count($chart23500) . "\n";
echo "Parsed 50,000 Ltrs chart entries: " . count($chart50000) . "\n";

// Fetch existing active DB records to avoid duplicate IDs
$existingDb = [];
$res = mysqli_query($connection, "SELECT id, tank_capacity, dip_mm FROM tbl_dip_lookup WHERE deleted_at IS NULL");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $key = sprintf("%.2f-%.2f", floatval($row['tank_capacity']), floatval($row['dip_mm']));
        $existingDb[$key] = intval($row['id']);
    }
}

$insertedCount = 0;
$updatedCount = 0;

$allRecords = []; // Structure for SQL seed generation

// Process 23,500 Ltrs records
foreach ($chart23500 as $mm => $litre) {
    $cap = 23500.00;
    $key = sprintf("%.2f-%.2f", $cap, $mm);
    $allRecords[] = [
        'capacity' => $cap,
        'dip_mm'   => $mm,
        'dip_litre' => $litre
    ];

    if (isset($existingDb[$key])) {
        $id = $existingDb[$key];
        $updateSql = "UPDATE tbl_dip_lookup SET dip_litre = '$litre', updated_at = NOW() WHERE id = '$id'";
        mysqli_query($connection, $updateSql);
        $updatedCount++;
    } else {
        $insertSql = "INSERT INTO tbl_dip_lookup (tank_capacity, dip_mm, dip_litre) VALUES ('$cap', '$mm', '$litre')";
        mysqli_query($connection, $insertSql);
        $insertedCount++;
    }
}

// Process 50,000 Ltrs records
foreach ($chart50000 as $mm => $litre) {
    $cap = 50000.00;
    $key = sprintf("%.2f-%.2f", $cap, $mm);
    $allRecords[] = [
        'capacity' => $cap,
        'dip_mm'   => $mm,
        'dip_litre' => $litre
    ];

    if (isset($existingDb[$key])) {
        $id = $existingDb[$key];
        $updateSql = "UPDATE tbl_dip_lookup SET dip_litre = '$litre', updated_at = NOW() WHERE id = '$id'";
        mysqli_query($connection, $updateSql);
        $updatedCount++;
    } else {
        $insertSql = "INSERT INTO tbl_dip_lookup (tank_capacity, dip_mm, dip_litre) VALUES ('$cap', '$mm', '$litre')";
        mysqli_query($connection, $insertSql);
        $insertedCount++;
    }
}

echo "Database Update Complete: $insertedCount inserted, $updatedCount updated.\n";

// --- 1. Export SQL Dump File (resources/tbl_dip_lookup_seed.sql) ---
$sqlDumpPath = __DIR__ . '/../resources/tbl_dip_lookup_seed.sql';
$sqlContent = "-- ========================================================\n";
$sqlContent .= "-- Seed SQL Dump for tbl_dip_lookup Master Table\n";
$sqlContent .= "-- Generated: " . date("Y-m-d H:i:s") . "\n";
$sqlContent .= "-- Total Records: " . count($allRecords) . "\n";
$sqlContent .= "-- ========================================================\n\n";

$sqlContent .= "-- Optional: Truncate or clean existing lookup table before re-import\n";
$sqlContent .= "-- TRUNCATE TABLE `tbl_dip_lookup`;\n\n";

$chunkSize = 250;
$chunks = array_chunk($allRecords, $chunkSize);

foreach ($chunks as $chunkIndex => $chunk) {
    $sqlContent .= "INSERT INTO `tbl_dip_lookup` (`tank_capacity`, `dip_mm`, `dip_litre`) VALUES\n";
    $valLines = [];
    foreach ($chunk as $rec) {
        $capStr   = number_format($rec['capacity'], 2, '.', '');
        $mmStr    = number_format($rec['dip_mm'], 2, '.', '');
        $litreStr = number_format($rec['dip_litre'], 2, '.', '');
        $valLines[] = "('$capStr', '$mmStr', '$litreStr')";
    }
    $sqlContent .= implode(",\n", $valLines) . ";\n\n";
}

file_put_contents($sqlDumpPath, $sqlContent);
echo "Generated SQL seed dump: " . basename($sqlDumpPath) . " (" . filesize($sqlDumpPath) . " bytes)\n";

// --- 2. Export Markdown Master Reference File (resources/dip_lookup_master.md) ---
$mdPath = __DIR__ . '/../resources/dip_lookup_master.md';
$mdContent = "# Dip Lookup Master Chart Documentation\n\n";
$mdContent .= "This document provides a reference summary and lookup values extracted from `resources/dips send.xlsx` and stored in `tbl_dip_lookup`.\n\n";
$mdContent .= "## Master Statistics\n\n";
$mdContent .= "| Tank Capacity | Record Count | Min Dip (mm) | Max Dip (mm) | Max Volume (Ltrs) |\n";
$mdContent .= "| :--- | :--- | :--- | :--- | :--- |\n";

$minMm23500 = count($chart23500) ? min(array_keys($chart23500)) : 0;
$maxMm23500 = count($chart23500) ? max(array_keys($chart23500)) : 0;
$maxL23500  = count($chart23500) ? $chart23500[$maxMm23500] : 0;

$minMm50000 = count($chart50000) ? min(array_keys($chart50000)) : 0;
$maxMm50000 = count($chart50000) ? max(array_keys($chart50000)) : 0;
$maxL50000  = count($chart50000) ? $chart50000[$maxMm50000] : 0;

$mdContent .= sprintf("| **23,500 Ltrs** | %s | %s mm | %s mm | %s L |\n", number_format(count($chart23500)), number_format($minMm23500, 2), number_format($maxMm23500, 2), number_format($maxL23500, 2));
$mdContent .= sprintf("| **50,000 Ltrs** | %s | %s mm | %s mm | %s L |\n\n", number_format(count($chart50000)), number_format($minMm50000, 2), number_format($maxMm50000, 2), number_format($maxL50000, 2));

$mdContent .= "## Sample Dip Reading Lookups\n\n";
$mdContent .= "### 23,500 Ltrs Capacity Tank Sample (Every 100mm)\n\n";
$mdContent .= "| Dip (mm) | Volume (Litres) |\n| :--- | :--- |\n";
foreach ($chart23500 as $mm => $l) {
    if ($mm % 100 == 0) {
        $mdContent .= sprintf("| %s mm | %s L |\n", number_format($mm, 0), number_format($l, 2));
    }
}

$mdContent .= "\n### 50,000 Ltrs Capacity Tank Sample (Every 100mm)\n\n";
$mdContent .= "| Dip (mm) | Volume (Litres) |\n| :--- | :--- |\n";
foreach ($chart50000 as $mm => $l) {
    if ($mm % 100 == 0) {
        $mdContent .= sprintf("| %s mm | %s L |\n", number_format($mm, 0), number_format($l, 2));
    }
}

file_put_contents($mdPath, $mdContent);
echo "Generated Markdown master reference: " . basename($mdPath) . " (" . filesize($mdPath) . " bytes)\n";

echo "=== Import & Export Completed Successfully ===\n";
