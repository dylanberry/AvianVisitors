<?php
// Bird Up! - OTA server for the wireless mic node (LilyGo T-SIM7080G).
//
// The node's own firmware-update page (http://<node>/ota) polls
//   http://192.168.86.50/avian/ota/ota-version.txt
// and offers a "Download and install vX" button that pulls
//   http://192.168.86.50/avian/ota/<artifact>
// plus a best-effort MD5 at <artifact>.md5 for corruption checking.
// This endpoint is the admin side of that server: publish a new build,
// set the published version, and see how the node is doing.
//
// Endpoints (mutations require the birdup_admin session cookie):
//   GET                                        -> server + node status JSON
//   POST multipart {action: upload, firmware: <file>, version} -> publish build
//   POST json {action: save, version?, node_url?, enabled?}      -> update config
//
// Static files under ~/BirdNET-Pi/avian/ota/ are served publicly by Caddy
// at /avian/ota/* (no auth - the node fetches them unauthenticated).
// The dir must be writable by the php-fpm user (caddy); deploy-to-pi.sh
// creates it with caddy ownership.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth.inc.php';
av_require_auth();

$AVIAN_DIR     = dirname(__DIR__);                  // ~/BirdNET-Pi/avian
$OTA_DIR       = "$AVIAN_DIR/ota";
// Fixed artifact name - MUST match FW_OTA_ARTIFACT_STR compiled into the
// node (src/main.cpp, XIAO_OTA_ARTIFACT for BOARD_T_SIM7080G).
$ARTIFACT      = 'birdnode-e79dd8.bin';
$VERSION_FILE  = "$OTA_DIR/ota-version.txt";
$MD5_FILE      = "$OTA_DIR/$ARTIFACT.md5";
$CONFIG_FILE   = "$OTA_DIR/ota-config.json";
// app0 OTA partition is 0x640000 = 6.25 MiB; cap uploads below that.
$MAX_BIN_SIZE  = 6553600;
// Canonical LAN URL the node fetches from (compile-time in node firmware).
$SERVED_BASE   = 'http://192.168.86.50/avian/ota/';
// Node status probe (default; overridable via config node_url).
$DEFAULT_NODE_URL = 'http://192.168.86.51/api/status';

function ota_read_config(): array
{
    global $CONFIG_FILE, $DEFAULT_NODE_URL;
    $cfg = ['enabled' => true, 'version' => '', 'node_url' => $DEFAULT_NODE_URL];
    if (is_readable($CONFIG_FILE)) {
        $j = json_decode((string)file_get_contents($CONFIG_FILE), true);
        if (is_array($j)) {
            if (array_key_exists('enabled', $j))  $cfg['enabled']  = (bool)$j['enabled'];
            if (is_string($j['version'] ?? null)) $cfg['version'] = $j['version'];
            if (is_string($j['node_url'] ?? null))$cfg['node_url']= $j['node_url'];
        }
    }
    return $cfg;
}

function ota_write_config(array $cfg): bool
{
    global $CONFIG_FILE;
    return file_put_contents($CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function ota_valid_version(string $v): bool
{
    return (bool)preg_match('/^\d{1,4}\.\d{1,4}$/', $v);
}

/** Probe the node's /api/status. Never throws - returns a structured array. */
function ota_probe_node(string $url): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['reachable' => false, 'error' => 'no response'];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['reachable' => false, 'error' => 'bad json from node'];
    }
    return [
        'reachable' => true,
        'fw_version' => (string)($j['fw_version'] ?? ''),
        'ip' => (string)($j['ip'] ?? ''),
        'ota_auto_supported' => (bool)($j['ota_auto_supported'] ?? false),
        'ota_artifact' => (string)($j['ota_artifact'] ?? ''),
    ];
}

function ota_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $cfg = ota_read_config();
    $version = is_readable($VERSION_FILE) ? trim((string)file_get_contents($VERSION_FILE)) : '';
    $artifact = null;
    $artifactPath = "$OTA_DIR/$ARTIFACT";
    if (is_file($artifactPath)) {
        $md5 = is_readable($MD5_FILE)
            ? trim((string)file_get_contents($MD5_FILE))
            : md5_file($artifactPath);
        $artifact = [
            'name'  => $ARTIFACT,
            'size'  => (int)filesize($artifactPath),
            'mtime' => (int)filemtime($artifactPath),
            'md5'   => $md5,
        ];
    }
    $node = ota_probe_node($cfg['node_url']);
    $updateAvailable = false;
    if ($artifact && $version !== '' && $node['reachable'] && $node['fw_version'] !== '') {
        // version_compare with an operator arg returns a bool, not -1/0/1.
        $updateAvailable = version_compare($version, $node['fw_version'], '>');
    }
    echo json_encode([
        'ok'              => true,
        'served_url'      => $SERVED_BASE,
        'node_poll_url'   => $SERVED_BASE . $ARTIFACT,
        'artifact'        => $artifact,
        'version'         => $version,
        'config'          => $cfg,
        'node'            => $node,
        'update_available'=> $updateAvailable,
    ]);
    exit;
}

if ($method !== 'POST') {
    ota_fail(405, 'method not allowed');
}

// Multipart (upload) uses $_POST; JSON (save) is parsed from the body.
$raw  = file_get_contents('php://input');
$json = json_decode((string)$raw, true);
$action = is_array($json)
    ? (string)($json['action'] ?? '')
    : (string)($_POST['action'] ?? '');

if ($action === 'upload') {
    $file = $_FILES['firmware'] ?? null;
    if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        ota_fail(400, 'no firmware file received');
    }
    $size = (int)$file['size'];
    if ($size <= 0) {
        ota_fail(400, 'firmware file is empty');
    }
    if ($size > $MAX_BIN_SIZE) {
        ota_fail(400, 'file is too large for the OTA app partition (max ' . number_format($MAX_BIN_SIZE) . ' bytes)');
    }
    if (in_array($size, [4194304, 8388608], true)) {
        ota_fail(400, 'this looks like a merged USB firmware image - OTA needs the app-only .bin from .pio/build/<env>/firmware.bin');
    }
    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 4);
    if ($head === '' || ord($head[0]) !== 0xE9) {
        ota_fail(400, 'not an ESP32 app image (missing 0xE9 magic byte)');
    }
    // Merged USB images embed the partition table at flash offset 0x8000
    // (magic 0xAA 0x50); an app-only image has arbitrary code there. This
    // catches factory.bin even though both start with the 0xE9 header.
    if (filesize($file['tmp_name']) >= 0x8002) {
        $at0x8000 = (string)file_get_contents($file['tmp_name'], false, null, 0x8000, 2);
        if ($at0x8000 !== '' && ord($at0x8000[0]) === 0xAA && ord($at0x8000[1]) === 0x50) {
            ota_fail(400, 'this looks like a merged USB firmware image (partition table at 0x8000) - OTA needs the app-only .bin from .pio/build/<env>/firmware.bin');
        }
    }
    $version = trim((string)($_POST['version'] ?? ''));
    if (!ota_valid_version($version)) {
        ota_fail(400, 'version must be in X.Y form (e.g. 1.23)');
    }
    if (!is_dir($OTA_DIR) && !@mkdir($OTA_DIR, 0775, true)) {
        ota_fail(500, 'cannot create ' . $OTA_DIR . ' (check php-fpm user permissions)');
    }
    if (!is_writable($OTA_DIR)) {
        ota_fail(500, $OTA_DIR . ' is not writable by the php-fpm user (caddy). Re-run deploy-to-pi.sh or: sudo chown caddy:caddy ' . $OTA_DIR);
    }
    $dest = "$OTA_DIR/$ARTIFACT";
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        ota_fail(500, 'could not store the uploaded firmware');
    }
    $md5 = md5_file($dest);
    file_put_contents($MD5_FILE, $md5);
    file_put_contents($VERSION_FILE, $version);
    $cfg = ota_read_config();
    $cfg['version'] = $version;
    $cfg['enabled'] = true;   // publishing a build re-arms automatic updates
    ota_write_config($cfg);
    echo json_encode([
        'ok'      => true,
        'version' => $version,
        'size'    => (int)filesize($dest),
        'md5'     => $md5,
        'served_url' => $SERVED_BASE . $ARTIFACT,
    ]);
    exit;
}

if ($action === 'save') {
    if (!is_array($json)) {
        ota_fail(400, 'bad json');
    }
    $cfg = ota_read_config();
    $artifactPath = "$OTA_DIR/$ARTIFACT";
    if (array_key_exists('version', $json)) {
        $v = trim((string)$json['version']);
        if (!ota_valid_version($v)) {
            ota_fail(400, 'version must be in X.Y form (e.g. 1.23)');
        }
        $cfg['version'] = $v;
        // Stage the version in the config. Publish it (write ota-version.txt)
        // only when a firmware artifact exists to back it - otherwise the
        // node would offer a download that 404s. A stale published version
        // is cleared when there is nothing to serve.
        if ($cfg['enabled'] && is_file($artifactPath)) {
            file_put_contents($VERSION_FILE, $v);
        } elseif ($cfg['enabled'] && !is_file($artifactPath)) {
            @unlink($VERSION_FILE);
        }
    }
    if (array_key_exists('node_url', $json)) {
        $u = trim((string)$json['node_url']);
        if (!preg_match('#^https?://[A-Za-z0-9._:/?=&%+-]+$#', $u)) {
            ota_fail(400, 'node address must be an http(s) URL');
        }
        $cfg['node_url'] = $u;
    }
    if (array_key_exists('enabled', $json)) {
        $wasEnabled = $cfg['enabled'];
        $cfg['enabled'] = (bool)$json['enabled'];
        if ($cfg['enabled'] && !$wasEnabled) {
            // Just turned on: publish the configured version. Nothing to
            // serve without an artifact - refuse rather than publish a
            // version the node would fail to download.
            if (!is_file($artifactPath)) {
                ota_fail(400, 'upload a firmware build first - there is nothing to serve yet');
            }
            if (!ota_valid_version($cfg['version'])) {
                ota_fail(400, 'set a version (X.Y) before enabling automatic updates');
            }
            file_put_contents($VERSION_FILE, $cfg['version']);
        } elseif (!$cfg['enabled']) {
            // Disable: point the version file at the node's CURRENT firmware
            // so the node reports "up to date" instead of offering updates.
            $node = ota_probe_node($cfg['node_url']);
            $cur = $node['fw_version'] ?? '';
            file_put_contents($VERSION_FILE, ota_valid_version($cur) ? $cur : '0.0');
        }
    }
    if (!ota_write_config($cfg)) {
        ota_fail(500, 'could not write ota-config.json (check permissions on ' . $OTA_DIR . ')');
    }
    echo json_encode(['ok' => true, 'config' => $cfg]);
    exit;
}

ota_fail(400, 'unknown action');
