<?php
/**
 * check_duplicates.php
 * Reads output.csv and lists duplicate 'نامک' entries along with their شناسه.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$outputCsv = __DIR__ . '/output.csv';

// ====== FUNCTIONS ======
function readCsv($filePath)
{
    $rows = [];
    if (!file_exists($filePath)) {
        die("Error: File not found: $filePath\n");
    }
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = fgetcsv($handle);
        if (!$headers) {
            die("Error: CSV file has no headers.\n");
        }
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $data);
        }
        fclose($handle);
    }
    return [$headers, $rows];
}

// ====== MAIN SCRIPT ======
list($headers, $rows) = readCsv($outputCsv);

if (empty($rows)) {
    die("Error: CSV file is empty.\n");
}

$idColumn = $headers[0];
$slugColumn = 'نامک';
if (!in_array($slugColumn, $headers)) {
    die("Error: Column 'نامک' not found in CSV headers.\n");
}

$slugMap = [];
foreach ($rows as $row) {
    $slug = $row[$slugColumn] ?? '';
    $id   = $row[$idColumn] ?? '';
    if ($slug !== '') {
        $slugMap[$slug][] = $id;
    }
}

$duplicatesFound = false;
foreach ($slugMap as $slug => $ids) {
    if (count($ids) > 1) {
        $duplicatesFound = true;
        echo "نامک: $slug\n";
        echo "شناسه: " . implode(', ', $ids) . "\n\n";
    }
}

if (!$duplicatesFound) {
    echo "✅ No duplicate 'نامک' entries found.\n";
}
