<?php
// Bird Up! - cookie-based session auth endpoint.
//
// Front-end calls:
//   POST action=login&username=...&password=...
//     -> 200 {ok: true} + Set-Cookie on success
//     -> 401 {ok: false} on bad credentials
//   POST action=logout
//     -> 200 {ok: true} + expired cookie
//   GET  action=status
//     -> 200 {authenticated: true|false}

declare(strict_types=1);

require_once __DIR__ . '/auth.inc.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? 'status';

if ($method === 'POST' && $action === 'login') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    if (is_string($user) && is_string($pass) && av_verify_password($user, $pass)) {
        av_login();
        av_json_response(200, ['ok' => true]);
    }

    av_json_response(401, ['ok' => false]);
}

if ($method === 'POST' && $action === 'logout') {
    av_logout();
    av_json_response(200, ['ok' => true]);
}

// Default / status: report whether the caller has a valid session and whether
// server-side auth is configured at all. If auth is disabled (no AV_AUTH_HASH),
// the front-end can treat the drawer as unlocked without requiring a password.
av_init_session();
$authenticated = av_is_authenticated();
$authEnabled   = av_auth_enabled();
if ($authenticated) {
    av_refresh_session();
}

av_json_response(200, ['authenticated' => $authenticated, 'auth_enabled' => $authEnabled]);
