<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', '');

    $secure = request_is_https();
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    if ($secure) {
        ini_set('session.cookie_samesite', 'Lax');
    } else {
        ini_set('session.cookie_samesite', '');
    }

    session_name('BMSSESSID');
    session_start();
}

function clear_session_cookie(): void
{
    $params = session_get_cookie_params();
    $options = [
        'expires' => time() - 42000,
        'path' => ($params['path'] ?? '') !== '' ? $params['path'] : '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => true,
    ];
    if (!empty($params['samesite'])) {
        $options['samesite'] = (string) $params['samesite'];
    }
    setcookie(session_name(), '', $options);
}

function destroy_app_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        clear_session_cookie();
        session_destroy();
    }
}

function logout_and_restart(): void
{
    destroy_app_session();
    start_app_session();
}
