<?php
// Bird Up! - shared session authentication helpers.
//
// Used by the protected PHP admin endpoints. Verifies a long-lived
// HttpOnly session cookie set by auth.php at login time.
//
// The real password is never stored in the cookie; only the PHP session
// ID is stored client-side. The session files live on the server (default
// PHP session path, or AV_SESSION_PATH if configured).
//
// Environment variables expected from Caddy's php_fastcgi block:
//   AV_AUTH_HASH    - bcrypt hash of the admin password (same as Caddy basic_auth)
//   AV_AUTH_USER    - admin username (default: birdnet)
//   AV_SESSION_PATH - optional writable directory for PHP session files

declare(strict_types=1);

const AV_SESSION_NAME = 'birdup_admin';
const AV_COOKIE_LIFETIME = 30 * 86400;       // 30 days, "remember me" by default
const AV_SESSION_MAX_LIFETIME = 30 * 86400;  // 30 days server-side
const AV_REGENERATE_INTERVAL = 7 * 86400;      // regenerate session ID every 7 days

/**
 * Configure and start the Bird Up! admin session.
 */
function av_init_session(): void
{
    $customPath = getenv('AV_SESSION_PATH');
    if ($customPath !== false && $customPath !== '') {
        // Only use the configured path if it exists and is writable.
        // If not, fall back to the PHP default so a misconfigured path
        // doesn't silently break authentication.
        if (is_dir($customPath) && is_writable($customPath)) {
            ini_set('session.save_path', $customPath);
        }
    }

    session_name(AV_SESSION_NAME);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '0');      // LAN/Tailscale runs plain HTTP
    ini_set('session.cookie_lifetime', (string)AV_COOKIE_LIFETIME);
    ini_set('session.gc_maxlifetime', AV_SESSION_MAX_LIFETIME);
    ini_set('session.cookie_path', '/');

    session_start();
}

/**
 * Return true if the current session is authenticated.
 */
function av_is_authenticated(): bool
{
    return isset($_SESSION['av_authenticated']) && $_SESSION['av_authenticated'] === true;
}

/**
 * Refresh the session (sliding window) and periodically regenerate the ID
 * to mitigate session fixation / replay risks.
 */
function av_refresh_session(): void
{
    if (!av_is_authenticated()) {
        return;
    }

    $_SESSION['av_last_seen'] = time();

    $authTime = $_SESSION['av_auth_time'] ?? 0;
    if (time() - $authTime > AV_REGENERATE_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['av_auth_time'] = time();
    }
}

/**
 * Return true if server-side auth is configured (i.e. a password hash is set).
 */
function av_auth_enabled(): bool
{
    $hash = getenv('AV_AUTH_HASH') ?: '';
    return $hash !== '';
}

/**
 * Require a valid session. If missing, return 401 JSON and exit.
 */
function av_require_auth(): void
{
    av_init_session();

    if (!av_auth_enabled()) {
        return;
    }

    if (!av_is_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    av_refresh_session();
}

/**
 * Verify the supplied username/password against the configured bcrypt hash.
 */
function av_verify_password(string $username, string $password): bool
{
    $expectedUser = getenv('AV_AUTH_USER') ?: 'birdnet';
    $hash = getenv('AV_AUTH_HASH') ?: '';

    if ($hash === '') {
        // No hash configured: auth is effectively disabled. In a forwarded
        // deploy this should never happen, but default LAN installs may not
        // set one. Treat as authenticated to avoid locking out LAN setups.
        return true;
    }

    if ($username !== $expectedUser) {
        return false;
    }

    return password_verify($password, $hash);
}

/**
 * Mark the session as authenticated and rotate the ID.
 */
function av_login(): void
{
    av_init_session();
    session_regenerate_id(true);
    $_SESSION['av_authenticated'] = true;
    $_SESSION['av_auth_time'] = time();
    $_SESSION['av_last_seen'] = time();
}

/**
 * Destroy the session and expire the cookie.
 */
function av_logout(): void
{
    av_init_session();

    $_SESSION = [];

    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 3600,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'samesite' => $params['samesite'] ?? 'Lax',
            'httponly' => true,
            'secure' => false,
        ]
    );

    session_destroy();
}

/**
 * Send a JSON response. Helper to keep endpoints consistent.
 *
 * @param mixed $data
 */
function av_json_response(int $code, $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
