<?php
// Bird Up! - PHP test harness for correction.php and birdnet-api.php
//
// This is a self-contained plain PHP script (no PHPUnit). It creates a
// temporary BirdNET-Pi file tree, seeds a SQLite detections database with
// visible and hidden rows, starts a temporary php -S server, and exercises
// the correction endpoint and the filtered aggregations via HTTP.
//
// Run with:
//   php tests/php/CorrectionEndpointTest.php
// or via Docker:
//   docker run --rm -v $(pwd):/app -w /app php:8.4-cli php tests/php/CorrectionEndpointTest.php
//
// Exit code is 0 only if all assertions pass.

declare(strict_types=1);

$failures = 0;
$passed = 0;
$summary = [];

function section(string $title): void {
    echo "\n=== $title ===\n";
}

function assert_eq($expected, $actual, string $msg): void {
    global $failures, $passed, $summary;
    if ($expected === $actual) {
        $passed++;
        $summary[] = "PASS: $msg";
        echo "  PASS: $msg\n";
    } else {
        $failures++;
        $exp = json_encode($expected);
        $act = json_encode($actual);
        $summary[] = "FAIL: $msg (expected $exp, got $act)";
        echo "  FAIL: $msg (expected $exp, got $act)\n";
    }
}

function assert_true($cond, string $msg): void {
    assert_eq(true, (bool)$cond, $msg);
}

function assert_contains_string(string $haystack, string $needle, string $msg): void {
    assert_true(strpos($haystack, $needle) !== false, $msg);
}

function assert_not_contains_string(string $haystack, string $needle, string $msg): void {
    assert_true(strpos($haystack, $needle) === false, $msg);
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array {
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = "$k: $v";
    }
    $ctx = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
        ],
    ];
    if ($body !== null) {
        $ctx['http']['content'] = $body;
    }
    $stream = stream_context_create($ctx);
    $raw = @file_get_contents($url, false, $stream);
    $code = 0;
    $responseHeaders = $http_response_header ?? [];
    if (!empty($responseHeaders)) {
        if (preg_match('/HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $m)) {
            $code = (int)$m[1];
        }
    }
    return ['code' => $code, 'body' => $raw, 'headers' => $responseHeaders];
}

function http_post_json(string $url, array $data, array $headers = []): array {
    $headers['Content-Type'] = 'application/json';
    return http_request('POST', $url, $headers, json_encode($data));
}

function http_get(string $url, array $headers = []): array {
    return http_request('GET', $url, $headers);
}

function auth_header(): array {
    return ['Authorization' => 'Basic ' . base64_encode('birdnet:password')];
}

function stop_server(): void {
    $pid = $GLOBALS['server_pid'] ?? 0;
    if ($pid > 0) {
        exec('kill ' . $pid . ' 2>/dev/null');
    }
}
register_shutdown_function('stop_server');

// ---------------------------------------------------------------------------
// Locate the repo root and create a temporary BirdNET-Pi tree.
// ---------------------------------------------------------------------------

$repoRoot = realpath(__DIR__ . '/../..');
if ($repoRoot === false || !is_dir($repoRoot . '/avian/api')) {
    fwrite(STDERR, "Could not locate repo root from " . __DIR__ . "\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/avian-test-' . uniqid();
@mkdir($tmp, 0755, true);
$birdnetPiDir = $tmp . '/BirdNET-Pi';
$birdSongsDir = $tmp . '/BirdSongs/Extracted/By_Date';
$apiDir = $birdnetPiDir . '/avian/api';
$scriptsDir = $birdnetPiDir . '/scripts';
$modelDir = $birdnetPiDir . '/model';
$l18nDir = $modelDir . '/l18n';

foreach ([$apiDir, $scriptsDir, $l18nDir, $birdSongsDir] as $d) {
    @mkdir($d, 0755, true);
}

$required = [
    $repoRoot . '/avian/api/correction.php' => $apiDir . '/correction.php',
    $repoRoot . '/avian/api/birdnet-api.php' => $apiDir . '/birdnet-api.php',
    $repoRoot . '/model/l18n/labels_en.json' => $l18nDir . '/labels_en.json',
    $repoRoot . '/model/BirdNET_GLOBAL_6K_V2.4_Model_FP16_Labels.txt' => $modelDir . '/BirdNET_GLOBAL_6K_V2.4_Model_FP16_Labels.txt',
];

foreach ($required as $src => $dst) {
    if (!is_readable($src)) {
        fwrite(STDERR, "Required source file missing: $src\n");
        exit(1);
    }
    copy($src, $dst);
}

// Generate a small model/labels.txt in the same format BirdNET-Pi creates at
// runtime: <Scientific Name>_<Common Name>. This lets loadCommonName() and
// loadValidSciNames() use the primary file without falling back.
$testSpecies = [
    'Turdus migratorius' => 'American Robin',
    'Cyanocitta cristata' => 'Blue Jay',
    'Sialia sialis' => 'Eastern Bluebird',
    'Cardinalis cardinalis' => 'Northern Cardinal',
];
$labelsTxt = '';
foreach ($testSpecies as $sci => $com) {
    $labelsTxt .= $sci . '_' . $com . "\n";
}
file_put_contents($modelDir . '/labels.txt', $labelsTxt);

// Create the exclude list file so the endpoint can append to it.
touch($birdnetPiDir . '/exclude_species_list.txt');

// ---------------------------------------------------------------------------
// Create the SQLite database with the Hidden column and seed detections.
// ---------------------------------------------------------------------------

$dbPath = $scriptsDir . '/birds.db';
$db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec(<<<'SQL'
CREATE TABLE detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT,
  Lat FLOAT,
  Lon FLOAT,
  Cutoff FLOAT,
  Week INT,
  Sens FLOAT,
  Overlap FLOAT,
  File_Name VARCHAR(100) NOT NULL,
  Hidden INTEGER DEFAULT 0 NOT NULL
);
SQL
);

$date = date('Y-m-d');
$time = date('H:i:s');

$rows = [
    ['Turdus migratorius', 'American Robin', 0.9, 'American_Robin-' . $date . '-birdnet-12:00:00.mp3', 0],
    ['Cyanocitta cristata', 'Blue Jay', 0.85, 'Blue_Jay-' . $date . '-birdnet-12:01:00.mp3', 0],
    ['Cyanocitta cristata', 'Blue Jay', 0.82, 'Blue_Jay-' . $date . '-birdnet-12:01:30.mp3', 0],
    ['Sialia sialis', 'Eastern Bluebird', 0.8, 'Eastern_Bluebird-' . $date . '-birdnet-12:02:00.mp3', 1],
    ['Cardinalis cardinalis', 'Northern Cardinal', 0.75, 'Northern_Cardinal-' . $date . '-birdnet-12:03:00.mp3', 0],
];

$insert = $db->prepare('INSERT INTO detections (Date, Time, Sci_Name, Com_Name, Confidence, File_Name, Hidden) VALUES (:d, :t, :sci, :com, :conf, :file, :hidden)');
foreach ($rows as $r) {
    $insert->bindValue(':d', $date, SQLITE3_TEXT);
    $insert->bindValue(':t', $time, SQLITE3_TEXT);
    $insert->bindValue(':sci', $r[0], SQLITE3_TEXT);
    $insert->bindValue(':com', $r[1], SQLITE3_TEXT);
    $insert->bindValue(':conf', $r[2], SQLITE3_FLOAT);
    $insert->bindValue(':file', $r[3], SQLITE3_TEXT);
    $insert->bindValue(':hidden', $r[4], SQLITE3_INTEGER);
    $insert->execute();
}
$db->close();

// Create the audio files and spectrogram images on disk for the Blue Jay rows
// so the reidentify actions can move them.
$blueJaySrcDir = $birdSongsDir . '/' . $date . '/Blue_Jay';
@mkdir($blueJaySrcDir, 0755, true);
$blueJayFiles = [
    $blueJaySrcDir . '/Blue_Jay-' . $date . '-birdnet-12:01:00.mp3',
    $blueJaySrcDir . '/Blue_Jay-' . $date . '-birdnet-12:01:30.mp3',
];
foreach ($blueJayFiles as $blueJayAudio) {
    file_put_contents($blueJayAudio, 'fake audio');
    file_put_contents($blueJayAudio . '.png', 'fake spectrogram');
}

// ---------------------------------------------------------------------------
// Start the temporary PHP server in the background.
// ---------------------------------------------------------------------------

$port = 9876;
$docroot = $apiDir;
$serverLog = $tmp . '/server.log';
$serverErr = $tmp . '/server.err';
$cmd = sprintf(
    'AV_REQUIRE_AUTH=1 php -S 127.0.0.1:%d -t %s > %s 2> %s & echo $!',
    $port,
    escapeshellarg($docroot),
    escapeshellarg($serverLog),
    escapeshellarg($serverErr)
);
$pid = (int)trim((string)shell_exec($cmd));
$GLOBALS['server_pid'] = $pid;
if ($pid <= 0) {
    fwrite(STDERR, "Failed to start temporary PHP server\n");
    exit(1);
}

$baseUrl = "http://127.0.0.1:$port";
$ready = false;
$deadline = microtime(true) + 5.0;
while (microtime(true) < $deadline) {
    $r = @http_get($baseUrl . '/birdnet-api.php?action=stats');
    if ($r['code'] === 200) {
        $ready = true;
        break;
    }
    usleep(50000);
}

if (!$ready) {
    fwrite(STDERR, "Temporary PHP server did not become ready on port $port\n");
    fwrite(STDERR, "STDERR log:\n" . @file_get_contents($serverErr) . "\n");
    stop_server();
    exit(1);
}

// ---------------------------------------------------------------------------
// Test helper: decode a JSON response safely.
// ---------------------------------------------------------------------------

function decode(array $r): ?array {
    $data = @json_decode($r['body'] ?? 'null', true);
    return is_array($data) ? $data : null;
}

// ---------------------------------------------------------------------------
// 1. birdnet-api.php filtered aggregations (before any corrections).
// ---------------------------------------------------------------------------

section('birdnet-api aggregations exclude hidden rows before corrections');

$stats = decode(http_get($baseUrl . '/birdnet-api.php?action=stats'));
assert_eq(4, $stats['totals']['detections'] ?? null, 'stats totals detections excludes hidden row');
assert_eq(3, $stats['totals']['species'] ?? null, 'stats totals species excludes hidden species');
assert_eq(4, $stats['today']['detections'] ?? null, 'stats today detections excludes hidden row');
assert_eq(3, $stats['today']['species'] ?? null, 'stats today species excludes hidden species');

$lifelist = decode(http_get($baseUrl . '/birdnet-api.php?action=lifelist'));
$lifelistScis = array_map(fn($s) => $s['sci'], $lifelist['species'] ?? []);
assert_true(in_array('Turdus migratorius', $lifelistScis, true), 'lifelist includes visible species');
assert_true(!in_array('Sialia sialis', $lifelistScis, true), 'lifelist excludes hidden species');
assert_eq(3, count($lifelist['species'] ?? []), 'lifelist count excludes hidden rows');

$recent = decode(http_get($baseUrl . '/birdnet-api.php?action=recent&hours=1000000'));
$recentScis = array_map(fn($s) => $s['sci'], $recent['species'] ?? []);
assert_true(!in_array('Sialia sialis', $recentScis, true), 'recent excludes hidden species');
assert_eq(3, count($recent['species'] ?? []), 'recent count excludes hidden rows');

$speciesHidden = decode(http_get($baseUrl . '/birdnet-api.php?action=species&sci=Sialia%20sialis'));
assert_eq(0, count($speciesHidden['detections'] ?? []), 'species action returns no detections for hidden-only species');
assert_eq(0, $speciesHidden['summary']['total'] ?? null, 'species action summary total is 0 for hidden-only species');

$speciesVisible = decode(http_get($baseUrl . '/birdnet-api.php?action=species&sci=Turdus%20migratorius'));
assert_eq(1, count($speciesVisible['detections'] ?? []), 'species action returns visible detection for a species');

$timeseries = decode(http_get($baseUrl . '/birdnet-api.php?action=timeseries'));
$dailyDetections = 0;
foreach ($timeseries['daily'] ?? [] as $day) {
    if (($day['date'] ?? null) === $date) {
        $dailyDetections = $day['detections'] ?? 0;
        break;
    }
}
assert_eq(4, $dailyDetections, 'timeseries daily detections excludes hidden rows');

$firstseen = decode(http_get($baseUrl . '/birdnet-api.php?action=firstseen'));
$firstseenScis = array_map(fn($s) => $s['sci'], $firstseen['species'] ?? []);
assert_true(!in_array('Sialia sialis', $firstseenScis, true), 'firstseen excludes hidden species');
assert_eq(3, count($firstseen['species'] ?? []), 'firstseen count excludes hidden rows');

// ---------------------------------------------------------------------------
// 2. correction.php auth failure.
// ---------------------------------------------------------------------------

section('correction.php auth gate');

$noAuth = http_post_json($baseUrl . '/correction.php', ['action' => 'hide', 'file' => 'x', 'date' => $date]);
assert_eq(401, $noAuth['code'], 'unauthenticated correction request returns 401');

// ---------------------------------------------------------------------------
// 3. correction.php hide action.
// ---------------------------------------------------------------------------

section('correction.php hide action');

$hide = http_post_json($baseUrl . '/correction.php', [
    'action' => 'hide',
    'file' => 'Northern_Cardinal-' . $date . '-birdnet-12:03:00.mp3',
    'date' => $date,
], auth_header());
assert_eq(200, $hide['code'], 'hide returns 200');
$hideData = decode($hide);
assert_eq(true, $hideData['ok'] ?? null, 'hide returns ok');
assert_eq(1, $hideData['hidden'] ?? null, 'hide marks exactly one row');

$statsAfterHide = decode(http_get($baseUrl . '/birdnet-api.php?action=stats'));
assert_eq(3, $statsAfterHide['totals']['detections'] ?? null, 'stats totals detections after hide');
assert_eq(2, $statsAfterHide['totals']['species'] ?? null, 'stats totals species after hide');

// ---------------------------------------------------------------------------
// 4. correction.php exclude action.
// ---------------------------------------------------------------------------

section('correction.php exclude action');

$exclude = http_post_json($baseUrl . '/correction.php', [
    'action' => 'exclude',
    'file' => 'American_Robin-' . $date . '-birdnet-12:00:00.mp3',
    'date' => $date,
    'label' => 'Turdus migratorius',
], auth_header());
assert_eq(200, $exclude['code'], 'exclude returns 200');
$excludeData = decode($exclude);
assert_eq(true, $excludeData['ok'] ?? null, 'exclude returns ok');
assert_eq(true, $excludeData['added'] ?? null, 'exclude adds a new entry');

$excludeList = file_get_contents($birdnetPiDir . '/exclude_species_list.txt');
assert_contains_string($excludeList, 'Turdus migratorius_American Robin', 'exclude list contains Turdus migratorius_American Robin');
assert_eq(1, count(array_filter(explode("\n", $excludeList), fn($l) => trim($l) !== '')), 'exclude list contains exactly one entry');

$excludeDup = http_post_json($baseUrl . '/correction.php', [
    'action' => 'exclude',
    'file' => 'American_Robin-' . $date . '-birdnet-12:00:00.mp3',
    'date' => $date,
    'label' => 'Turdus migratorius',
], auth_header());
$excludeDupData = decode($excludeDup);
assert_eq(true, $excludeDupData['ok'] ?? null, 'duplicate exclude still returns ok');
assert_eq(false, $excludeDupData['added'] ?? null, 'duplicate exclude reports not added');
$excludeListAfterDup = file_get_contents($birdnetPiDir . '/exclude_species_list.txt');
assert_eq(1, count(array_filter(explode("\n", $excludeListAfterDup), fn($l) => trim($l) !== '')), 'exclude list still contains exactly one entry after duplicate');

// ---------------------------------------------------------------------------
// 5. correction.php reidentify action.
// ---------------------------------------------------------------------------

section('correction.php reidentify action');

$reid = http_post_json($baseUrl . '/correction.php', [
    'action' => 'reidentify',
    'file' => 'Blue_Jay-' . $date . '-birdnet-12:01:00.mp3',
    'date' => $date,
    'label' => 'Cardinalis cardinalis',
], auth_header());
assert_eq(200, $reid['code'], 'reidentify returns 200');
$reidData = decode($reid);
assert_eq(true, $reidData['ok'] ?? null, 'reidentify returns ok');
assert_eq(1, $reidData['updated'] ?? null, 'reidentify updated exactly one DB row');

assert_true(file_exists($blueJayFiles[0]), 'reidentify leaves original Blue Jay audio file in place');
assert_true(file_exists($blueJayFiles[1]), 'reidentify leaves other Blue Jay audio file in place');

// Verify the DB row was updated with the new species and Confidence = 0.
$checkDb = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$checkDb->busyTimeout(2000);
$stmt = $checkDb->prepare('SELECT Sci_Name, Com_Name, File_Name, Confidence FROM detections WHERE File_Name = :file AND Date = :date');
$stmt->bindValue(':file', 'Blue_Jay-' . $date . '-birdnet-12:01:00.mp3', SQLITE3_TEXT);
$stmt->bindValue(':date', $date, SQLITE3_TEXT);
$res = $stmt->execute();
$row = $res->fetchArray(SQLITE3_ASSOC);
assert_eq('Cardinalis cardinalis', $row['Sci_Name'] ?? null, 'reidentify updated Sci_Name');
assert_eq('Northern Cardinal', $row['Com_Name'] ?? null, 'reidentify updated Com_Name');
assert_eq('Blue_Jay-' . $date . '-birdnet-12:01:00.mp3', $row['File_Name'] ?? null, 'reidentify leaves File_Name unchanged');
assert_eq(0.0, $row['Confidence'] ?? null, 'reidentify set Confidence to 0');
$checkDb->close();

// ---------------------------------------------------------------------------
// 6. birdnet-api.php aggregations after corrections.
// ---------------------------------------------------------------------------

section('birdnet-api aggregations after corrections');

$statsAfter = decode(http_get($baseUrl . '/birdnet-api.php?action=stats'));
assert_eq(3, $statsAfter['totals']['detections'] ?? null, 'final stats totals detections excludes hidden rows');
assert_eq(3, $statsAfter['totals']['species'] ?? null, 'final stats totals species excludes hidden species');

$finalLifelist = decode(http_get($baseUrl . '/birdnet-api.php?action=lifelist'));
$finalScis = array_map(fn($s) => $s['sci'], $finalLifelist['species'] ?? []);
assert_true(in_array('Turdus migratorius', $finalScis, true), 'final lifelist includes American Robin');
assert_true(in_array('Cyanocitta cristata', $finalScis, true), 'final lifelist includes remaining Blue Jay');
assert_true(in_array('Cardinalis cardinalis', $finalScis, true), 'final lifelist includes reidentified Northern Cardinal');
assert_true(!in_array('Sialia sialis', $finalScis, true), 'final lifelist excludes hidden Eastern Bluebird');
assert_eq(3, count($finalLifelist['species'] ?? []), 'final lifelist count is 3');

$finalSpecies = decode(http_get($baseUrl . '/birdnet-api.php?action=species&sci=Cardinalis%20cardinalis'));
assert_eq(1, count($finalSpecies['detections'] ?? []), 'final species action returns the reidentified visible Cardinal detection');

$finalSpeciesHidden = decode(http_get($baseUrl . '/birdnet-api.php?action=species&sci=Sialia%20sialis'));
assert_eq(0, count($finalSpeciesHidden['detections'] ?? []), 'final species action still returns no hidden detections');

// ---------------------------------------------------------------------------
// 7. correction.php bulk hide_all action.
// ---------------------------------------------------------------------------

section('correction.php bulk hide_all action');

$hideAll = http_post_json($baseUrl . '/correction.php', [
    'action' => 'hide_all',
    'sci' => 'Turdus migratorius',
], auth_header());
assert_eq(200, $hideAll['code'], 'hide_all returns 200');
$hideAllData = decode($hideAll);
assert_eq(true, $hideAllData['ok'] ?? null, 'hide_all returns ok');
assert_eq(1, $hideAllData['hidden'] ?? null, 'hide_all marks all visible rows for the species');

$statsAfterHideAll = decode(http_get($baseUrl . '/birdnet-api.php?action=stats'));
assert_eq(2, $statsAfterHideAll['totals']['detections'] ?? null, 'stats totals detections after hide_all');
assert_eq(2, $statsAfterHideAll['totals']['species'] ?? null, 'stats totals species after hide_all');

// ---------------------------------------------------------------------------
// 8. correction.php bulk reidentify_all action.
// ---------------------------------------------------------------------------

section('correction.php bulk reidentify_all action');

$reidAll = http_post_json($baseUrl . '/correction.php', [
    'action' => 'reidentify_all',
    'sci' => 'Cyanocitta cristata',
    'label' => 'Cardinalis cardinalis',
], auth_header());
assert_eq(200, $reidAll['code'], 'reidentify_all returns 200');
$reidAllData = decode($reidAll);
assert_eq(true, $reidAllData['ok'] ?? null, 'reidentify_all returns ok');
assert_eq(1, $reidAllData['updated'] ?? null, 'reidentify_all updated exactly one DB row');

assert_true(file_exists($blueJayFiles[1]), 'reidentify_all leaves Blue Jay audio file in place');

$statsAfterReidAll = decode(http_get($baseUrl . '/birdnet-api.php?action=stats'));
assert_eq(2, $statsAfterReidAll['totals']['detections'] ?? null, 'stats totals detections after reidentify_all');
assert_eq(1, $statsAfterReidAll['totals']['species'] ?? null, 'stats totals species after reidentify_all');

// ---------------------------------------------------------------------------
// 9. correction.php bulk hide_all on reidentified species.
// ---------------------------------------------------------------------------

section('correction.php bulk hide_all on reidentified species');

$hideAllCardinal = http_post_json($baseUrl . '/correction.php', [
    'action' => 'hide_all',
    'sci' => 'Cardinalis cardinalis',
], auth_header());
assert_eq(200, $hideAllCardinal['code'], 'hide_all on Cardinal returns 200');
$hideAllCardinalData = decode($hideAllCardinal);
assert_eq(true, $hideAllCardinalData['ok'] ?? null, 'hide_all on Cardinal returns ok');
assert_eq(2, $hideAllCardinalData['hidden'] ?? null, 'hide_all hides both reidentified Cardinal rows');

// ---------------------------------------------------------------------------
// 10. correction.php bulk exclude_all action.
// ---------------------------------------------------------------------------

section('correction.php bulk exclude_all action');

$excludeAll = http_post_json($baseUrl . '/correction.php', [
    'action' => 'exclude_all',
    'sci' => 'Cyanocitta cristata',
], auth_header());
assert_eq(200, $excludeAll['code'], 'exclude_all returns 200');
$excludeAllData = decode($excludeAll);
assert_eq(true, $excludeAllData['ok'] ?? null, 'exclude_all returns ok');
assert_eq(true, $excludeAllData['added'] ?? null, 'exclude_all adds a new entry');

$excludeListAfterAll = file_get_contents($birdnetPiDir . '/exclude_species_list.txt');
assert_contains_string($excludeListAfterAll, 'Cyanocitta cristata_Blue Jay', 'exclude list contains Cyanocitta cristata');

$excludeAllDup = http_post_json($baseUrl . '/correction.php', [
    'action' => 'exclude_all',
    'sci' => 'Cyanocitta cristata',
], auth_header());
$excludeAllDupData = decode($excludeAllDup);
assert_eq(true, $excludeAllDupData['ok'] ?? null, 'duplicate exclude_all still returns ok');
assert_eq(false, $excludeAllDupData['added'] ?? null, 'duplicate exclude_all reports not added');

// ---------------------------------------------------------------------------
// 11. Final aggregations after bulk corrections.
// ---------------------------------------------------------------------------

section('birdnet-api aggregations after bulk corrections');

$statsAfterBulk = decode(http_get($baseUrl . '/birdnet-api.php?action=stats'));
assert_eq(0, $statsAfterBulk['totals']['detections'] ?? null, 'final stats totals detections after bulk corrections');
assert_eq(0, $statsAfterBulk['totals']['species'] ?? null, 'final stats totals species after bulk corrections');

$finalLifelistAfterBulk = decode(http_get($baseUrl . '/birdnet-api.php?action=lifelist'));
assert_eq(0, count($finalLifelistAfterBulk['species'] ?? []), 'final lifelist is empty after all rows hidden');

// ---------------------------------------------------------------------------
// Cleanup and summary.
// ---------------------------------------------------------------------------

stop_server();
// Give the server a moment to release the port before we delete the temp tree.
usleep(100000);

// Best-effort cleanup of the temp tree.
$rm = function (string $path) use (&$rm): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_dir($path)) {
        foreach (glob($path . '/*') as $child) {
            $rm($child);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
};
$rm($tmp);

section('Summary');
foreach ($summary as $line) {
    echo $line . "\n";
}
echo "\n";
echo "Total: " . ($passed + $failures) . " | PASS: $passed | FAIL: $failures\n";

exit($failures > 0 ? 1 : 0);
