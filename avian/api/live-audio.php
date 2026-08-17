<?php
// Bird Up! - mic-node live-audio toggle.
//
// The drawer LIVE AUDIO player streams from icecast (/stream -> livestream
// service). The mic node (T-SIM7080G / ESP32-C6) duty-cycles its push stream
// (burst mode) to save power, so a listening session would hit silence gaps.
// This endpoint coordinates a gap-free session:
//
//   start  - record pre-session state, (re)start livestream so the icecast
//            mount is fresh, wait for the mount to register, then tell the
//            node to stream continuously (/api/live?on=1). The node saves
//            its previous mode internally. The restart is unconditional:
//            a long-idle ffmpeg can be dropped by icecast's silent-source
//            timeout while systemd still reports the service active, leaving
//            a zombie whose /stream mount 404s.
//   end    - tell the node to stop (/api/live?on=0). The node returns to the
//            mode it was in before the session (burst, unless changed) - i.e.
//            "ends if the mic node was initially in burst mode". Stop the
//            livestream service only if we started it.
//   status - current session state + node mode pair + mount state.
//
// Node URL / service come from avian/config/mic-node.json.
// Requires the same admin session as the other avian endpoints.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth.inc.php';
av_require_auth();

$action = $_GET['action'] ?? 'status';
$configDir = dirname(__DIR__) . '/config';
$nodeFile = "$configDir/mic-node.json";
$stateFile = "$configDir/live-audio-state.json";

function cfg(string $key, string $fallback): string
{
    global $nodeFile;
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        if (is_readable($nodeFile)) {
            $decoded = json_decode((string)file_get_contents($nodeFile), true);
            if (is_array($decoded)) $cfg = $decoded;
        }
    }
    return is_string($cfg[$key] ?? null) && $cfg[$key] !== '' ? $cfg[$key] : $fallback;
}

function readState(): array
{
    global $stateFile;
    if (is_readable($stateFile)) {
        $decoded = json_decode((string)file_get_contents($stateFile), true);
        if (is_array($decoded)) return $decoded;
    }
    return ['active' => false, 'livestream_was_active' => false];
}

function writeState(array $state): void
{
    global $stateFile, $configDir;
    if (!is_dir($configDir)) @mkdir($configDir, 0755, true);
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function systemActive(string $unit): bool
{
    $out = [];
    exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null', $out);
    return trim(implode('', $out)) === 'active';
}

// Icecast serves 200 as soon as a source is registered on the mount; its
// fserve fallback answers 404 while it isn't. (icecast rejects HEAD, so use
// a 1s GET to /dev/null.)
function mountLive(string $url): bool
{
    $out = [];
    exec('curl -s -o /dev/null -w %{http_code} -m 1 ' . escapeshellarg($url) . ' 2>/dev/null', $out);
    return trim(implode('', $out)) === '200';
}

function waitForMount(string $url, int $tries = 40): bool
{
    for ($i = 0; $i < $tries; $i++) {
        if (mountLive($url)) return true;
        usleep(250000);
    }
    return false;
}

function nodeRequest(string $query): ?array
{
    $url = cfg('url', 'http://192.168.86.51') . '/api/live?' . $query;
    $out = [];
    $code = 0;
    exec('curl -s -m 3 -X POST ' . escapeshellarg($url), $out, $code);
    $body = trim(implode('', $out));
    if ($body === '') return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

$service = cfg('service', 'livestream');

switch ($action) {
    case 'start':
        $state = readState();
        if (!empty($state['active'])) {
            respond(['ok' => true, 'already_active' => true]);
            break;
        }
        $wasActive = systemActive($service);
        // Order matters: this ffmpeg only opens its icecast connection once
        // the first UDP packet arrives, so start the node streaming BEFORE
        // (re)starting the service. The restart also guarantees a fresh mount
        // even when the old process is a silent zombie that systemd still
        // calls active (icecast drops idle sources after its timeout).
        $node = nodeRequest('on=1');
        exec('sudo systemctl restart ' . escapeshellarg($service) . ' 2>/dev/null');
        $mount = waitForMount('http://localhost:8000/stream');
        writeState(['active' => true, 'livestream_was_active' => $wasActive]);
        respond([
            'ok' => true,
            'node' => $node,
            'mount_ready' => $mount,
            'livestream_started' => !$wasActive,
        ]);
        break;

    case 'end':
        $state = readState();
        $node = nodeRequest('on=0');
        $shouldStop = !empty($state['active']) && empty($state['livestream_was_active']);
        if ($shouldStop) {
            exec('sudo systemctl stop ' . escapeshellarg($service) . ' 2>/dev/null');
        }
        writeState(['active' => false, 'livestream_was_active' => false]);
        respond([
            'ok' => true,
            'node' => $node,
            'livestream_stopped' => $shouldStop,
        ]);
        break;

    case 'status':
    default:
        $state = readState();
        respond([
            'ok' => true,
            'active' => !empty($state['active']),
            'node' => nodeRequest(''),
            'livestream_active' => systemActive($service),
            'mount_ready' => mountLive('http://localhost:8000/stream'),
        ]);
        break;
}
