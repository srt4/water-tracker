<?php
// temps.php — Serve the full dataset (archive + live CSV), gzip-compressed.
//
// The browser sends "Accept-Encoding: gzip" automatically.
// We compress on the fly so the response is small over the wire,
// but the browser sees plain CSV text. No frontend changes needed.
date_default_timezone_set('America/Los_Angeles');

$csvFile   = __DIR__ . '/temps.csv';
$archiveGz = __DIR__ . '/temps-archive.csv.gz';

header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');

// Lightweight stats mode for client cache sync checks.
// Example: temps.php?stats=1
if (isset($_GET['stats'])) {
    header('Content-Type: application/json; charset=utf-8');

    $archiveStats = file_exists($archiveGz)
        ? summarizeCsvGz($archiveGz)
        : ['rows' => 0, 'first' => null, 'last' => null];
    $liveStats = file_exists($csvFile)
        ? summarizeCsvFile($csvFile)
        : ['rows' => 0, 'first' => null, 'last' => null];

    $firstTs = $archiveStats['first'] !== null ? $archiveStats['first'] : $liveStats['first'];
    $lastTs  = $liveStats['last'] !== null ? $liveStats['last'] : $archiveStats['last'];

    echo json_encode([
        'totalRows' => $archiveStats['rows'] + $liveStats['rows'],
        'archiveRows' => $archiveStats['rows'],
        'liveRows' => $liveStats['rows'],
        'firstTimestamp' => $firstTs,
        'lastTimestamp' => $lastTs,
        'generatedAt' => date('c')
    ]);
    exit();
}

// Optional: limit to last N days via ?days=7 query param
$daysLimit = isset($_GET['days']) ? intval($_GET['days']) : 0;
$daysCutoff = $daysLimit > 0
    ? date('Y-m-d H:i:s', strtotime("-{$daysLimit} days"))
    : null;

// Optional incremental fetch via ?since=...
// Supports unix seconds, unix milliseconds, or a parseable datetime string.
$sinceCutoff = isset($_GET['since']) ? parseCutoffParam($_GET['since']) : null;
// Optional upper bound via ?before=... (exclusive).
$beforeCutoff = isset($_GET['before']) ? parseCutoffParam($_GET['before']) : null;

// Use the stricter (later) cutoff when both are provided.
$cutoff = null;
if ($daysCutoff !== null && $sinceCutoff !== null) {
    $cutoff = max($daysCutoff, $sinceCutoff);
} else if ($daysCutoff !== null) {
    $cutoff = $daysCutoff;
} else if ($sinceCutoff !== null) {
    $cutoff = $sinceCutoff;
}

header('Content-Type: text/csv; charset=utf-8');
// Use PHP's built-in output compression if the client supports gzip.
// This is the simplest approach — Apache/nginx often do this too,
// but this guarantees it works regardless of server config.
if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
    ob_start('ob_gzhandler');
}

// 1. Write CSV header
echo "Timestamp,Sensor1_Temp,Sensor2_Temp\n";

// Optimization for ?since=: if cutoff is newer than the first row in live CSV,
// we can skip scanning the archive completely.
$readArchive = true;
if ($beforeCutoff === null && $cutoff !== null && file_exists($csvFile)) {
    $firstLiveTs = firstDataTimestampInCsv($csvFile);
    if ($firstLiveTs !== null && $cutoff >= $firstLiveTs) {
        $readArchive = false;
    }
}

// 2. Stream archived rows (if archive exists)
if ($readArchive && file_exists($archiveGz)) {
    $gz = gzopen($archiveGz, 'r');
    if ($gz) {
        while (!gzeof($gz)) {
            $line = gzgets($gz);
            if ($line === false) break;
            $line = trim($line);
            if ($line === '' || stripos($line, 'Timestamp') === 0) continue; // skip headers

            if ($cutoff !== null || $beforeCutoff !== null) {
                $fields = str_getcsv($line);
                $ts = $fields[0] ?? '';
                if ($ts === '') continue;
                if ($cutoff !== null && $ts < $cutoff) continue;
                if ($beforeCutoff !== null && $ts >= $beforeCutoff) continue;
            }

            echo $line . "\n";
        }
        gzclose($gz);
    }
}

// 3. Stream live CSV rows
if (file_exists($csvFile)) {
    $fp = fopen($csvFile, 'r');
    if ($fp) {
        $first = true;
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($first) { $first = false; continue; } // skip header
            if ($line === '') continue;

            if ($cutoff !== null || $beforeCutoff !== null) {
                $fields = str_getcsv($line);
                $ts = $fields[0] ?? '';
                if ($ts === '') continue;
                if ($cutoff !== null && $ts < $cutoff) continue;
                if ($beforeCutoff !== null && $ts >= $beforeCutoff) continue;
            }

            echo $line . "\n";
        }
        fclose($fp);
    }
}

function parseCutoffParam($raw) {
    if ($raw === null) return null;
    $raw = trim((string)$raw);
    if ($raw === '') return null;

    // Numeric epoch input
    if (preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
        $num = (float)$raw;
        if ($num <= 0) return null;
        // Treat very large values as milliseconds
        if ($num > 1000000000000) $num = $num / 1000.0;
        return date('Y-m-d H:i:s', (int)$num);
    }

    // Datetime string input
    $ts = strtotime($raw);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

function firstDataTimestampInCsv($csvPath) {
    $fp = fopen($csvPath, 'r');
    if (!$fp) return null;

    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        if (stripos($line, 'Timestamp') === 0) continue;
        $fields = str_getcsv($line);
        fclose($fp);
        if (!empty($fields[0])) return $fields[0];
        return null;
    }
    fclose($fp);
    return null;
}

function summarizeCsvFile($csvPath) {
    $fp = fopen($csvPath, 'r');
    if (!$fp) return ['rows' => 0, 'first' => null, 'last' => null];

    $rows = 0;
    $first = null;
    $last = null;
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Timestamp') === 0) continue;
        $fields = str_getcsv($line);
        $ts = $fields[0] ?? '';
        if ($ts === '') continue;
        if ($first === null) $first = $ts;
        $last = $ts;
        $rows++;
    }
    fclose($fp);

    return ['rows' => $rows, 'first' => $first, 'last' => $last];
}

function summarizeCsvGz($gzPath) {
    $gz = gzopen($gzPath, 'r');
    if (!$gz) return ['rows' => 0, 'first' => null, 'last' => null];

    $rows = 0;
    $first = null;
    $last = null;
    while (!gzeof($gz)) {
        $line = gzgets($gz);
        if ($line === false) break;
        $line = trim($line);
        if ($line === '' || stripos($line, 'Timestamp') === 0) continue;
        $fields = str_getcsv($line);
        $ts = $fields[0] ?? '';
        if ($ts === '') continue;
        if ($first === null) $first = $ts;
        $last = $ts;
        $rows++;
    }
    gzclose($gz);

    return ['rows' => $rows, 'first' => $first, 'last' => $last];
}
?>
