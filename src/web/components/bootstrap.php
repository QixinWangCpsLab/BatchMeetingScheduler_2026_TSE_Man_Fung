<?php
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

define('BMS_APP', true);

date_default_timezone_set('Asia/Hong_Kong');
mb_internal_encoding('UTF-8');

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

ob_start();

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/passwords.php';
require_once __DIR__ . '/ids.php';
require_once __DIR__ . '/slots.php';
require_once __DIR__ . '/matching.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/exam_actions.php';
require_once __DIR__ . '/layout.php';

start_app_session();
