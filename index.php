<?php
// --- Deployment config ---
define('SERVER_TIMEZONE', 'America/Los_Angeles'); // Timezone the server writes timestamps in
date_default_timezone_set(SERVER_TIMEZONE);

// If this isn't a POST from the ESP32, show the usage dashboard.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: usage.html');
    exit();
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);
$timestamp = date('Y-m-d H:i:s');
$csvFile   = __DIR__ . '/temps.csv';
$archiveGz = __DIR__ . '/temps-archive.csv.gz';
$errorFile = __DIR__ . '/errors.log';

// --- Configuration ---
// Roll data older than this many days into the gzip archive.
// The live CSV stays small and fast to serve.
define('ROLL_AFTER_DAYS', 7);

// Minimum rows to keep in the live CSV even if they're old
// (prevents rolling an almost-empty file).
define('MIN_KEEP_ROWS', 100);

// 1. Log Errors if data is missing
if (!$data) {
    $errorMsg = "[$timestamp] ERROR: Invalid JSON. Input: " . ($json ?: "EMPTY") . "\n";
    file_put_contents($errorFile, $errorMsg, FILE_APPEND);
    http_response_code(400);
    exit();
}

// 2. Add CSV Header if file is new
if (!file_exists($csvFile)) {
    file_put_contents($csvFile, "Timestamp,Sensor1_Temp,Sensor2_Temp\n");
}

// 3. Log Data to CSV
$fp = fopen($csvFile, 'a');
fputcsv($fp, [$timestamp, $data['sensor1'], $data['sensor2']]);
fclose($fp);

// 4. Warning log for hardware issues (-127 or 85)
if ($data['sensor1'] < -50 || $data['sensor1'] == 85) {
    $errorMsg = "[$timestamp] SENSOR_FAIL: Check wiring on Sensor 1.\n";
    file_put_contents($errorFile, $errorMsg, FILE_APPEND);
}

// 5. Periodically roll old rows into gzip archive.
//    We only attempt this every ~100 writes to avoid doing file I/O every request.
//    A simple check: roll if the live CSV is over 500 KB.
clearstatcache(true, $csvFile);
if (filesize($csvFile) > 500 * 1024) {
    rollArchive($csvFile, $archiveGz);
}

echo "OK";

// -----------------------------------------------------------------------
// Roll rows older than ROLL_AFTER_DAYS into the gzip archive.
// -----------------------------------------------------------------------
function rollArchive($csvFile, $archiveGz) {
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . ROLL_AFTER_DAYS . ' days'));

    $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) < 2) return; // header only

    $header = $lines[0];
    $keep = [$header];
    $archive = [];

    for ($i = 1; $i < count($lines); $i++) {
        // Extract timestamp (first CSV field)
        $fields = str_getcsv($lines[$i]);
        if (empty($fields[0])) { $keep[] = $lines[$i]; continue; }

        if ($fields[0] < $cutoff && (count($lines) - $i) > MIN_KEEP_ROWS) {
            $archive[] = $lines[$i];
        } else {
            $keep[] = $lines[$i];
        }
    }

    if (empty($archive)) return;

    // Append old rows to the gzip archive.
    // Note: gzopen('a') appends to existing gzip data.
    $isNew = !file_exists($archiveGz) || filesize($archiveGz) === 0;
    $gz = gzopen($archiveGz, 'a9'); // append mode, max compression
    if (!$gz) return;

    // Write CSV header if this is a brand-new archive
    if ($isNew) {
        gzwrite($gz, $header . "\n");
    }

    foreach ($archive as $row) {
        gzwrite($gz, $row . "\n");
    }
    gzclose($gz);

    // Rewrite the live CSV with only the recent rows
    file_put_contents($csvFile, implode("\n", $keep) . "\n");
}
?>
