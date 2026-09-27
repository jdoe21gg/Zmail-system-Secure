<?php
require_once __DIR__ . '/security.php';
if (!file_exists(ZMAIL_LOCK_FILE)) {
    header('Location: install.php');
    exit;
}

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

if (!empty($_SESSION['uid'])) {
    header('Location: index.php');
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/mail_login_' . hash('sha256', $ip);
$now = time();
$attemptsData = [];
if (is_file($rateFile)) {
    $decoded = json_decode((string)file_get_contents($rateFile), true);
    if (is_array($decoded)) $attemptsData = array_values(array_filter($decoded, fn($t) => is_int($t) && $t > $now - 900));
}
$attempts = count($attemptsData);

$error = '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $givenCsrf = $_POST['csrf_token'] ?? '';
    if (!is_string($givenCsrf) || !hash_equals($_SESSION['csrf'], $givenCsrf)) {
        $error = '请求无效，请刷新页面后重试';
    } elseif ($attempts >= 5) {
        $error = '尝试次数过多，请 15 分钟后再试';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $db = new PDO('sqlite:' . ZMAIL_DATA_DIR . '/users.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            @unlink($rateFile);
            session_regenerate_id(true);
            $_SESSION['uid']      = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['last']     = time();
            header('Location: index.php');
            exit;
        } else {
            file_put_contents($rateFile, json_encode(array_merge($attemptsData, [$now]), JSON_UNESCAPED_UNICODE), LOCK_EX);
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
<title>登录 - 邮件管理</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <h1>邮件管理</h1>
        <?php if ($error): ?><div class="msg msg-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!empty($_GET['timeout'])): ?><div class="msg msg-warn">登录已超时，请重新登录</div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="text" name="username" placeholder="用户名" required autofocus>
            <input type="password" name="password" placeholder="密码" required style="margin-top:12px;">
            <button type="submit" class="btn btn-primary btn-block">登录</button>
        </form>
        <div class="login-tip">仅限管理员访问</div>
    </div>
</div>
</body>
</html>