<?php
// Bird Up! - drawer menu items.
//
// Returns the list of links shown in the side drawer when a user clicks
// the menu button. The live JS expects {items: [{label, href, native}]}.
//
// Protected by the shared cookie session from auth.inc.php. If the
// Caddyfile has AV_AUTH_HASH set, a valid birdup_admin session cookie is
// required; otherwise the endpoint is open (default LAN deploy).

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth.inc.php';
av_require_auth();

// All four items are in-app overlays. `native: true` tells the FE to
// route via `#admin=<section>` rather than opening a new window. We
// deliberately don't link out to BirdNET-Pi's stock pages - those stay
// reachable at /index.php, and the github link lives in the drawer
// footer next to "built by Dylan".
echo json_encode([
    'items' => [
        ['label' => 'settings', 'href' => '/#admin=settings', 'native' => true],
        ['label' => 'system',   'href' => '/#admin=system',   'native' => true],
        ['label' => 'logs',     'href' => '/#admin=logs',     'native' => true],
        ['label' => 'tools',    'href' => '/#admin=tools',    'native' => true],
    ],
]);
