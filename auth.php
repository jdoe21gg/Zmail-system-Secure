<?php
require_once __DIR__ . '/security.php';

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

if (!file_exists(ZMAIL_LOCK_FILE)) {
    header('Location: install.php');
    exit;
}

if (empty($_SESSION['uid'])) {
    header('Location: login.php');
    exit;
}

$timeout = 1800;
if (isset($_SESSION['last']) && time() - $_SESSION['last'] > $timeout) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['last'] = time();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrf_token(): string {
    return $_SESSION['csrf'] ?? '';
}

function require_csrf(): void {
    $given = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($given) || !hash_equals($_SESSION['csrf'] ?? '', $given)) {
        http_response_code(403);
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false ||
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'CSRF 校验失败'], JSON_UNESCAPED_UNICODE);
        } else {
            echo 'CSRF 校验失败';
        }
        exit;
    }
}

function get_db(): PDO {
    zmail_ensure_data_dir();
    $db = new PDO('sqlite:' . ZMAIL_DATA_DIR . '/mails.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    zmail_encrypt_legacy_accounts($db);
    return $db;
}

function get_users_db(): PDO {
    zmail_ensure_data_dir();
    $db = new PDO('sqlite:' . ZMAIL_DATA_DIR . '/users.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}
