<?php
// Bird Up! - read-only health endpoint for the k8s birdup-health probe.
//
// Deliberately NO auth (unlike sibling endpoints): returns audio-freshness
// + key service states only, for the cluster-side monitoring CronJob
// (homelab repo: infrastructure/birdup-health/). LAN-only deployment; the
// collage is already public on the LAN, and this leaks no secrets - just
// "is the pi recording, analyzing, ingesting from the mic node, and serving".
//
//   GET /avian/api/health.php
//   -> {"ok":true,"as_of":"...","hostname":"...",
//       "last_dump":{"exists":true,"last_dump_age_s":N,"last_dump":"..."},
//       "freshness":{"audio_fresh":true,"source":"last_dump","age_s":N,"max_age_s":1800},
//       "stream_data":{"exists":true,"file_count":N,"newest_age_s":N,"newest_name":"..."},
//       "services":{"birdnet_analysis":"active",
//                    "birdnet-dumpd":"active","caddy":"active"}}
//
// Services: the ACTIVE ingest path only. birdnet_recording (RTSP pull) and
// birdnet-udp2 (UDP push) are retired fallbacks - deliberately NOT listed,
// they are disabled/vestigial on the live deployment.
//
// Keeping the admin password out of the cluster is the point: the probe
// must never need AV_AUTH_HASH / the birdup_admin session. If this endpoint
// ever needs protection, move it behind Caddy basic_auth with a dedicated
// monitoring credential - do NOT reuse the admin session.
//
// Freshness: `last_dump` comes from birdnet-dumpd's heartbeat marker
// (StreamData/.last-dump, written after every completed dump). The WAV files
// themselves are consumed by birdnet_analysis within ~1-2 min, so the dir is
// routinely empty between dumps - newest_wav_age is NOT a liveness signal.
//
// `freshness.audio_fresh` resolves the false negative: audio is fresh when
// the .last-dump marker is fresh OR (WAVs exist and the newest is fresh,
// covering first-boot/marker-missing windows). An empty dir with a fresh
// marker means the node dumped and analysis already consumed the files -
// audio IS flowing. Probes should gate on `freshness.audio_fresh`, never on
// `stream_data.newest_age_s` alone (null/empty between dumps).

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Path layout: /home/{USER}/BirdNET-Pi/avian/api/health.php
//   __DIR__  -> .../BirdNET-Pi/avian/api
//   dirname(__DIR__, 2) -> .../BirdNET-Pi
//   dirname(__DIR__, 3) -> /home/{USER}
$BIRDSONGS_DIR = dirname(__DIR__, 3) . '/BirdSongs';
$STREAM_DIR = "$BIRDSONGS_DIR/StreamData";

// Mic node freshness is judged by the dumpd heartbeat marker (WAV mtime
// alone would false-negative: the dir is routinely empty between dumps).
// The node TCP-dumps ~5 min buffers every 5 min (battery + ECO duty cycle),
// so a growing marker age is the earliest signal of node/dumpd trouble.
const FRESHNESS_MAX_S = 1800; // probe-side threshold; mirrored in probe.py

function shellout(string $cmd): string {
    $rc = 0; $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

function read_last_dump(string $dir): array {
    $f = "$dir/.last-dump";
    if (!is_file($f)) return ['exists' => false];
    return [
        'exists'          => true,
        'last_dump_age_s' => time() - (int)@filemtime($f),
        'last_dump'       => trim((string)@file_get_contents($f)),
    ];
}

function read_streamdata(string $dir): array {
    if (!is_dir($dir)) return ['exists' => false];
    $files = @scandir($dir, SCANDIR_SORT_DESCENDING) ?: [];
    $wav = array_values(array_filter($files, function ($f) {
        return $f !== '.' && $f !== '..' && preg_match('/\.(wav|mp3|raw)$/i', $f);
    }));
    $newest_age = null;
    if (count($wav) > 0) {
        $newest_age = time() - (int)@filemtime("$dir/" . $wav[0]);
    }
    return [
        'exists'       => true,
        'file_count'   => count($wav),
        'newest_age_s' => $newest_age,
        'newest_name'  => $wav[0] ?? null,
    ];
}

// Single authoritative freshness verdict for probes. Resolves the
// empty-dir false negative: the marker is the primary signal; WAV mtime is
// only corroboration (normally the dir is empty - analysis consumed the
// files - so a bare WAV check would wrongly report no audio).
function compute_freshness(array $lastDump, array $stream): array {
    $fresh = false;
    $source = 'none';
    $age = null;

    if ($lastDump['exists'] && ($lastDump['last_dump_age_s'] ?? PHP_INT_MAX) <= FRESHNESS_MAX_S) {
        $fresh = true;
        $source = 'last_dump';
        $age = $lastDump['last_dump_age_s'];
    }
    if (($stream['newest_age_s'] ?? null) !== null && $stream['newest_age_s'] <= FRESHNESS_MAX_S) {
        $fresh = true;
        $source = $source === 'last_dump' ? 'both' : 'stream_wav';
        $age = $age === null ? $stream['newest_age_s'] : min($age, $stream['newest_age_s']);
    }
    return [
        'audio_fresh' => $fresh,
        'source'      => $source,
        'age_s'       => $age,
        'max_age_s'   => FRESHNESS_MAX_S,
    ];
}

// Read-only systemctl queries work unprivileged (same as services_status()
// in birdnet-status.php, which runs as the caddy/php-fpm user).
$units = ['birdnet_analysis', 'birdnet-dumpd', 'caddy'];
$services = [];
foreach ($units as $u) {
    $services[$u] = trim(shellout('systemctl is-active ' . escapeshellarg($u)));
}

$stream = read_streamdata($STREAM_DIR);
$lastDump = read_last_dump($STREAM_DIR);
echo json_encode([
    'ok'          => true,
    'as_of'       => date('c'),
    'hostname'    => trim(shellout('hostname')),
    'last_dump'   => $lastDump,
    'freshness'   => compute_freshness($lastDump, $stream),
    'stream_data' => $stream,
    'services'    => $services,
]);