<?php
// Bird Up! - admin correction endpoint for individual detections.
//
// Supports actions from the species detail modal:
//   hide            - mark a detection as hidden (reversible)
//   hide_all        - mark every visible detection of a species as hidden
//   reidentify      - change a detection's species label
//   reidentify_all  - change every visible detection of a species to a new label
//   exclude         - append a species to the upstream exclude list
//   exclude_all     - append a species to the upstream exclude list
//
// Protected by the shared cookie session from auth.inc.php. If the
// Caddyfile has AV_AUTH_HASH set, a valid birdup_admin session cookie is
// required; otherwise the endpoint is open (default LAN deploy).

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth.inc.php';
av_require_auth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// Path layout: {HOME}/BirdNET-Pi/avian/api/correction.php
$BIRDNETPI_DIR   = dirname(__DIR__, 2);
$DB_PATH         = "$BIRDNETPI_DIR/scripts/birds.db";
$EXCLUDE_PATH    = "$BIRDNETPI_DIR/exclude_species_list.txt";

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad json']);
    exit;
}

$action = $body['action'] ?? '';
$file   = $body['file']   ?? '';
$date   = $body['date']   ?? '';
$label  = $body['label']  ?? '';
$sci    = $body['sci']    ?? '';

if (!is_string($action) || !in_array($action, ['hide', 'reidentify', 'exclude', 'hide_all', 'reidentify_all', 'exclude_all'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid action']);
    exit;
}

$isBulk = in_array($action, ['hide_all', 'reidentify_all', 'exclude_all'], true);
if (!$isBulk && (!is_string($file) || $file === '' || !is_string($date) || $date === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'missing file or date']);
    exit;
}
if ($action === 'reidentify' && (!is_string($label) || $label === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'missing label']);
    exit;
}
if ($action === 'exclude' && (!is_string($label) || $label === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'missing label']);
    exit;
}
if ($action === 'exclude_all' && (!is_string($sci) || $sci === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'missing sci']);
    exit;
}
if ($action === 'hide_all' && (!is_string($sci) || $sci === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'missing sci']);
    exit;
}
if ($action === 'reidentify_all' && (!is_string($sci) || $sci === '' || !is_string($label) || $label === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'missing sci or label']);
    exit;
}

if (!is_file($DB_PATH)) {
    http_response_code(500);
    echo json_encode(['error' => 'database not found']);
    exit;
}

$db = new SQLite3($DB_PATH, SQLITE3_OPEN_READWRITE);
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'database open failed']);
    exit;
}
$db->busyTimeout(2000);

function json_err(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function loadCommonName(string $birdnetPiDir, string $sci): ?string {
    $jsonPath = "$birdnetPiDir/model/l18n/labels_en.json";
    if (is_readable($jsonPath)) {
        $data = json_decode(file_get_contents($jsonPath), true);
        if (is_array($data) && isset($data[$sci])) {
            return $data[$sci];
        }
    }

    $labelsPath = "$birdnetPiDir/model/labels.txt";
    if (is_readable($labelsPath)) {
        foreach (file($labelsPath, FILE_IGNORE_NEW_LINES) as $line) {
            $pos = strpos($line, '_');
            if ($pos === false) {
                continue;
            }
            $lineSci = substr($line, 0, $pos);
            if ($lineSci === $sci) {
                return substr($line, $pos + 1);
            }
        }
    }

    return null;
}

function loadValidSciNames(string $birdnetPiDir): array {
    $labelsPath = "$birdnetPiDir/model/labels.txt";
    $scis = [];
    if (is_readable($labelsPath)) {
        foreach (file($labelsPath, FILE_IGNORE_NEW_LINES) as $line) {
            $pos = strpos($line, '_');
            if ($pos === false) {
                continue;
            }
            $scis[] = substr($line, 0, $pos);
        }
        return $scis;
    }

    $modelLabels = "$birdnetPiDir/model/BirdNET_GLOBAL_6K_V2.4_Model_FP16_Labels.txt";
    if (is_readable($modelLabels)) {
        foreach (file($modelLabels, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $scis[] = $line;
            }
        }
    }

    return $scis;
}

function addExcludeEntry(string $birdnetPiDir, string $excludePath, string $sci): bool {
    $common = loadCommonName($birdnetPiDir, $sci);
    if ($common === null) {
        json_err(400, 'invalid species');
    }

    $entry = "{$sci}_{$common}";
    $exists = false;
    if (is_readable($excludePath)) {
        foreach (file($excludePath, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, '_');
            $lineSci = ($pos === false) ? $line : substr($line, 0, $pos);
            if ($lineSci === $sci) {
                $exists = true;
                break;
            }
        }
    }

    if ($exists) {
        return false;
    }

    $dir = dirname($excludePath);
    if (!is_dir($dir)) {
        json_err(500, 'exclude list directory not found: ' . $dir);
    }

    if (file_exists($excludePath)) {
        if (!is_writable($excludePath)) {
            json_err(500, 'exclude list is not writable: ' . $excludePath . '. Ensure the PHP-FPM user can write to it (e.g., sudo chown :caddy ' . $excludePath . ' && sudo chmod g+w ' . $excludePath . ')');
        }
    } elseif (!is_writable($dir)) {
        json_err(500, 'cannot create exclude list: ' . $excludePath . ' because ' . $dir . ' is not writable. Create the file and give the PHP-FPM user write access (e.g., sudo touch ' . $excludePath . ' && sudo chown :caddy ' . $excludePath . ' && sudo chmod g+w ' . $excludePath . ')');
    }

    $written = @file_put_contents($excludePath, $entry . "\n", FILE_APPEND | LOCK_EX);
    if ($written === false) {
        $err = error_get_last();
        json_err(500, 'failed to write exclude list: ' . $excludePath . ' (' . ($err['message'] ?? 'unknown error') . ')');
    }

    return true;
}

if ($action === 'hide') {
    $stmt = $db->prepare('UPDATE detections SET Hidden = 1 WHERE File_Name = :file AND Date = :date');
    $stmt->bindValue(':file', $file, SQLITE3_TEXT);
    $stmt->bindValue(':date', $date, SQLITE3_TEXT);
    $res = $stmt->execute();
    if (!$res) {
        json_err(500, 'database update failed');
    }
    $changes = $db->changes();
    if ($changes === 0) {
        json_err(404, 'detection not found');
    }
    echo json_encode(['ok' => true, 'hidden' => $changes]);
    exit;
}

if ($action === 'exclude') {
    $added = addExcludeEntry($BIRDNETPI_DIR, $EXCLUDE_PATH, $label);
    echo json_encode(['ok' => true, 'added' => $added]);
    exit;
}

if ($action === 'exclude_all') {
    $added = addExcludeEntry($BIRDNETPI_DIR, $EXCLUDE_PATH, $sci);
    echo json_encode(['ok' => true, 'added' => $added]);
    exit;
}

if ($action === 'hide_all') {
    $stmt = $db->prepare('UPDATE detections SET Hidden = 1 WHERE Sci_Name = :sci AND Hidden != 1');
    $stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
    $res = $stmt->execute();
    if (!$res) {
        json_err(500, 'database update failed');
    }
    $changes = $db->changes();
    if ($changes === 0) {
        json_err(404, 'species not found');
    }
    echo json_encode(['ok' => true, 'hidden' => $changes]);
    exit;
}

if ($action === 'reidentify') {
    $validSci = loadValidSciNames($BIRDNETPI_DIR);
    if (!in_array($label, $validSci, true)) {
        json_err(400, 'invalid species');
    }

    $newCommon = loadCommonName($BIRDNETPI_DIR, $label);
    if ($newCommon === null) {
        json_err(400, 'common name not found');
    }

    $stmt = $db->prepare('UPDATE detections SET Sci_Name = :newSci, Com_Name = :newCom, Confidence = 0 WHERE File_Name = :file AND Date = :date');
    $stmt->bindValue(':newSci',   $label,    SQLITE3_TEXT);
    $stmt->bindValue(':newCom',   $newCommon, SQLITE3_TEXT);
    $stmt->bindValue(':file',     $file,     SQLITE3_TEXT);
    $stmt->bindValue(':date',     $date,     SQLITE3_TEXT);
    $res = $stmt->execute();
    if (!$res) {
        json_err(500, 'database update failed');
    }
    $updated = $db->changes();
    if ($updated === 0) {
        json_err(404, 'detection not found');
    }

    echo json_encode(['ok' => true, 'updated' => $updated]);
    exit;
}

if ($action === 'reidentify_all') {
    $validSci = loadValidSciNames($BIRDNETPI_DIR);
    if (!in_array($label, $validSci, true)) {
        json_err(400, 'invalid species');
    }

    $newCommon = loadCommonName($BIRDNETPI_DIR, $label);
    if ($newCommon === null) {
        json_err(400, 'common name not found');
    }

    $stmt = $db->prepare('UPDATE detections SET Sci_Name = :newSci, Com_Name = :newCom, Confidence = 0 WHERE Sci_Name = :sci AND Hidden != 1');
    $stmt->bindValue(':newSci',   $label,     SQLITE3_TEXT);
    $stmt->bindValue(':newCom',   $newCommon, SQLITE3_TEXT);
    $stmt->bindValue(':sci',      $sci,       SQLITE3_TEXT);
    $res = $stmt->execute();
    if (!$res) {
        json_err(500, 'database update failed');
    }
    $updated = $db->changes();
    if ($updated === 0) {
        json_err(404, 'no detections found');
    }

    echo json_encode(['ok' => true, 'updated' => $updated]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
