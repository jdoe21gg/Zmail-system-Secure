<?php
require_once __DIR__ . '/security.php';
/**
 * Zmail System - 安装向导
 * 部署后访问：https://你的域名/mail/install.php
 * 安装完成后请立即删除本文件
 */

session_start();
if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));

define('LOCK_FILE', ZMAIL_LOCK_FILE);
define('DB_FILE', ZMAIL_DATA_DIR . '/mails.db');
define('USERS_DB', ZMAIL_DATA_DIR . '/users.db');
define('DATA_DIR', ZMAIL_DATA_DIR);

if (file_exists(LOCK_FILE)) {
    exit('
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>已安装</title>
    <link rel="stylesheet" href="assets/style.css"></head><body>
    <div class="login-wrap"><div class="login-box">
        <h1>✅ 系统已安装</h1>
        <p class="text-center text-muted">如需重新安装，请先删除 <code>data/installed.lock</code> 文件。</p>
        <a href="login.php" class="btn btn-primary btn-block mt-20">进入登录页</a>
    </div></div></body></html>');
}

$step = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
$error = '';

if ($step > 1 && empty($_SESSION['install_step_done'])) {
    $step = 1;
}

/**
 * 环境检测
 */
function check_environment() {
    $checks = [];

    $checks[] = [
        'name'  => 'PHP 版本 ≥ 7.4',
        'ok'    => version_compare(PHP_VERSION, '7.4.0', '>='),
        'value' => PHP_VERSION,
        'tip'   => '当前版本过低，请升级 PHP。',
    ];

    $checks[] = [
        'name'  => 'IMAP 扩展',
        'ok'    => function_exists('imap_open'),
        'value' => function_exists('imap_open') ? '已开启' : '未开启',
        'tip'   => '需要在 PHP 配置中启用 php_imap 扩展，或联系主机商开启。',
    ];

    $checks[] = [
        'name'  => 'PDO SQLite 驱动',
        'ok'    => extension_loaded('pdo_sqlite'),
        'value' => extension_loaded('pdo_sqlite') ? '已开启' : '未开启',
        'tip'   => '需要 pdo_sqlite 扩展来存储邮件。',
    ];

    $checks[] = [
        'name'  => 'mbstring 扩展',
        'ok'    => function_exists('mb_convert_encoding'),
        'value' => function_exists('mb_convert_encoding') ? '已开启' : '未开启',
        'tip'   => '用于处理邮件编码转换。',
    ];

    $checks[] = [
        'name'  => 'Session 支持',
        'ok'    => function_exists('session_start'),
        'value' => function_exists('session_start') ? '已开启' : '未开启',
        'tip'   => '用于登录状态保持。',
    ];

    $checks[] = [
        'name'  => 'cURL 扩展',
        'ok'    => function_exists('curl_init'),
        'value' => function_exists('curl_init') ? '已开启' : '未开启',
        'tip'   => '用于邮件转发功能。未开启不影响收信。',
        'warn'  => true,
    ];

    $writable = is_dir(DATA_DIR) ? is_writable(DATA_DIR) : is_writable(__DIR__);
    $checks[] = [
        'name'  => '目录可写',
        'ok'    => $writable,
        'value' => $writable ? '可写' : '不可写',
        'tip'   => '需要能创建 data/ 目录并写入文件。请设置目录权限为 755 或 777。',
    ];

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $checks[] = [
        'name'  => 'HTTPS（强烈建议）',
        'ok'    => $https,
        'value' => $https ? '已开启' : '未开启',
        'tip'   => '未启用 HTTPS 时，登录密码和邮件内容会以明文传输。',
        'warn'  => true,
    ];

    return $checks;
}

/**
 * 创建数据库
 */
function do_install_database() {
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0700, true)) throw new Exception('无法创建 data 目录');
    }

    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS mails (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        account       TEXT NOT NULL,
        account_label TEXT,
        uid           TEXT NOT NULL,
        from_addr     TEXT,
        subject       TEXT,
        mail_date     TEXT,
        mail_ts       INTEGER DEFAULT 0,
        body          TEXT,
        body_html     TEXT,
        attachments   TEXT,
        blocked       INTEGER DEFAULT 0,
        is_starred    INTEGER DEFAULT 0,
        is_important  INTEGER DEFAULT 0,
        is_read       INTEGER DEFAULT 1,
        created_at    TEXT,
        UNIQUE(account, uid)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_account ON mails(account)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_mail_ts ON mails(mail_ts)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_blocked ON mails(blocked)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_starred ON mails(is_starred)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_read ON mails(is_read)");

    $db->exec("CREATE TABLE IF NOT EXISTS accounts (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        key_name    TEXT UNIQUE NOT NULL,
        label       TEXT NOT NULL,
        host        TEXT NOT NULL,
        user        TEXT NOT NULL,
        password    TEXT NOT NULL,
        enabled     INTEGER DEFAULT 1,
        last_fetch  TEXT,
        created_at  TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS block_rules (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        keyword    TEXT NOT NULL,
        enabled    INTEGER DEFAULT 1,
        created_at TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS forward_rules (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        keyword    TEXT NOT NULL,
        channel    TEXT NOT NULL,
        target     TEXT NOT NULL,
        enabled    INTEGER DEFAULT 1,
        created_at TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS blocked_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        account     TEXT NOT NULL,
        uid         TEXT NOT NULL,
        from_addr   TEXT,
        subject     TEXT,
        mail_date   TEXT,
        matched     TEXT,
        created_at  TEXT,
        UNIQUE(account, uid)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT
    )");

    // 默认拦截规则
    $count = $db->query("SELECT COUNT(*) FROM block_rules")->fetchColumn();
    if ($count == 0) {
        $stmt = $db->prepare("INSERT INTO block_rules (keyword, enabled, created_at) VALUES (?, 1, ?)");
        foreach (['取消订阅', '退订', 'unsubscribe'] as $kw) {
            $stmt->execute([$kw, date('Y-m-d H:i:s')]);
        }
    }

    // 默认设置
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('fetch_days', '7')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('auto_delete_days', '30')");

    // 附件目录
    $attachDir = DATA_DIR . '/attachments';
    if (!is_dir($attachDir)) mkdir($attachDir, 0755, true);

    // 用户库
    $udb = new PDO('sqlite:' . USERS_DB);
    $udb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $udb->exec("CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at    TEXT
    )");

    return true;
}

/**
 * 创建管理员
 */
function do_create_admin($username, $password) {
    if (strlen($username) < 3) throw new Exception('用户名至少 3 个字符');
    if (strlen($password) < 8) throw new Exception('密码至少 8 位');

    $udb = new PDO('sqlite:' . USERS_DB);
    $udb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $check = $udb->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetchColumn()) throw new Exception('用户名已存在，请换一个');

    $stmt = $udb->prepare("INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)");
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
    return true;
}

/**
 * 添加邮箱
 */
function do_add_account($key, $label, $host, $user, $pass) {
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->prepare("INSERT INTO accounts (key_name, label, host, user, password, enabled, created_at)
                          VALUES (?, ?, ?, ?, ?, 1, ?)");
    $stmt->execute([$key, $label, $host, $user, $pass, date('Y-m-d H:i:s')]);
    return true;
}

/**
 * POST 处理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'step1') {
            $checks = check_environment();
            $blocked = array_filter($checks, fn($c) => !$c['ok'] && empty($c['warn']));
            if ($blocked) {
                $error = '请先解决所有标红的检测项';
            } else {
                $_SESSION['install_step_done'] = 1;
                header('Location: install.php?step=2');
                exit;
            }
        }

        if ($action === 'step2') {
            do_install_database();
            $_SESSION['install_step_done'] = 2;
            header('Location: install.php?step=3');
            exit;
        }

        if ($action === 'step3') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm'] ?? '';
            if ($password !== $confirm) throw new Exception('两次输入的密码不一致');
            do_create_admin($username, $password);
            $_SESSION['admin_username'] = $username;
            $_SESSION['install_step_done'] = 3;
            header('Location: install.php?step=4');
            exit;
        }

        if ($action === 'step4') {
            if (isset($_POST['skip'])) {
                $_SESSION['install_step_done'] = 4;
                header('Location: install.php?step=5');
                exit;
            }
            $key   = trim($_POST['key_name'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $host  = trim($_POST['host'] ?? '');
            $user  = trim($_POST['user'] ?? '');
            $pass  = $_POST['password'] ?? '';

            if ($key === '' || $label === '' || $host === '' || $user === '' || $pass === '') {
                throw new Exception('请填写完整，或点击跳过');
            }
            if (!preg_match('/^[a-z0-9_]+$/', $key)) {
                throw new Exception('标识只能包含小写字母、数字、下划线');
            }

            $mbox = @imap_open($host, $user, $pass, 0, 1);
            if (!$mbox) throw new Exception('连接失败：' . imap_last_error());
            imap_close($mbox);

            do_add_account($key, $label, $host, $user, $pass);
            $_SESSION['install_step_done'] = 4;
            $_SESSION['added_account'] = $label;
            header('Location: install.php?step=5');
            exit;
        }

        if ($action === 'finish') {
            file_put_contents(LOCK_FILE, json_encode([
                'installed_at' => date('Y-m-d H:i:s'),
                'version'      => '1.0.0',
            ]));
            unset($_SESSION['install_step_done']);
            header('Location: install.php?step=done');
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/**
 * 完成页
 */
if (isset($_GET['step']) && $_GET['step'] === 'done') {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装完成 - Zmail System</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .done-icon { font-size: 64px; text-align: center; margin-bottom: 16px; }
        .done-list { background: #f8fafc; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
        .danger-box { background: #fee2e2; color: #991b1b; padding: 14px 16px; border-radius: 8px; margin: 16px 0; font-size: 14px; line-height: 1.8; }
    </style>
    </head>
    <body>
    <div class="login-wrap">
        <div class="login-box" style="max-width:520px;">
            <div class="done-icon">🎉</div>
            <h1 style="margin-bottom:8px;">安装完成</h1>
            <p class="text-center text-muted mb-0">Zmail System 已就绪。</p>

            <div class="done-list">
                <ul style="margin:0;padding-left:20px;">
                    <li>管理员账号：<strong><?= htmlspecialchars($_SESSION['admin_username'] ?? 'admin') ?></strong></li>
                    <?php if (!empty($_SESSION['added_account'])): ?>
                        <li>已添加邮箱：<strong><?= htmlspecialchars($_SESSION['added_account']) ?></strong></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="danger-box">
                <strong>⚠️ 重要：请立即删除 install.php</strong><br>
                否则他人可以重新运行安装程序，覆盖你的数据库。
            </div>

            <p class="text-small text-muted">接下来建议：</p>
            <ul class="text-small text-muted" style="line-height:2;padding-left:20px;">
                <li>配置 Cron 定时任务，每分钟执行 <code>fetch_mail.php</code></li>
                <li>在「邮箱账户」中添加更多邮箱</li>
                <li>在「拦截与转发」中调整关键词和推送渠道</li>
            </ul>

            <a href="login.php" class="btn btn-primary btn-block mt-20">进入登录页</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$checks = check_environment();
$blocked = array_filter($checks, fn($c) => !$c['ok'] && empty($c['warn']));
$can_proceed = empty($blocked);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>安装向导 - 第 <?= $step ?> 步 - Zmail System</title>
<link rel="stylesheet" href="assets/style.css">
<style>
    .steps { display: flex; justify-content: center; gap: 4px; margin-bottom: 28px; }
    .step-dot { flex: 1; max-width: 80px; height: 4px; background: #e5e7eb; border-radius: 2px; }
    .step-dot.active { background: var(--primary); }
    .step-dot.done { background: #10b981; }
    .check-list { list-style: none; padding: 0; margin: 0; }
    .check-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 0; border-bottom: 1px solid #f0f0f0; }
    .check-item:last-child { border-bottom: none; }
    .check-icon { font-size: 20px; line-height: 1.4; flex-shrink: 0; }
    .check-content { flex: 1; }
    .check-name { font-weight: 600; font-size: 14px; }
    .check-value { font-size: 13px; color: #666; margin-top: 2px; }
    .check-tip { font-size: 12px; margin-top: 6px; padding: 8px 10px; border-radius: 6px; line-height: 1.6; }
    .check-tip.err { background: #fee2e2; color: #991b1b; }
    .check-tip.warn { background: #fef3c7; color: #92400e; }
    .summary { text-align: center; padding: 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
    .summary-ok { background: #dcfce7; color: #166534; }
    .summary-err { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-box" style="max-width:560px;">
        <h1 style="margin-bottom:20px;">📬 Zmail System 安装向导</h1>

        <div class="steps">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="step-dot <?= $i == $step ? 'active' : ($i < $step ? 'done' : '') ?>"></div>
            <?php endfor; ?>
        </div>

        <?php if ($error): ?>
            <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <h3 class="mt-0 mb-16">第 1 步：环境检测</h3>

            <?php if ($can_proceed): ?>
                <div class="summary summary-ok">✅ 所有必需项均已通过</div>
            <?php else: ?>
                <div class="summary summary-err">❌ 有 <?= count($blocked) ?> 项未通过，请先解决</div>
            <?php endif; ?>

            <ul class="check-list">
                <?php foreach ($checks as $c): ?>
                    <li class="check-item">
                        <span class="check-icon"><?= $c['ok'] ? '✅' : (isset($c['warn']) ? '⚠️' : '❌') ?></span>
                        <div class="check-content">
                            <div class="check-name"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="check-value">当前：<?= htmlspecialchars($c['value']) ?></div>
                            <?php if (!$c['ok']): ?>
                                <div class="check-tip <?= isset($c['warn']) ? 'warn' : 'err' ?>">
                                    <?= htmlspecialchars($c['tip']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="step1">
                <input type="hidden" name="step" value="1">
                <button type="submit" class="btn btn-primary btn-block mt-20"
                        <?= $can_proceed ? '' : 'disabled style="opacity:.5;cursor:not-allowed;"' ?>>
                    <?= $can_proceed ? '下一步 →' : '请先解决以上问题' ?>
                </button>
            </form>

        <?php elseif ($step == 2): ?>
            <h3 class="mt-0 mb-16">第 2 步：创建数据库</h3>

            <p class="text-small text-muted">系统将在 <code>data/</code> 目录下创建：</p>
            <ul class="text-small text-muted" style="line-height:2;padding-left:20px;">
                <li><code>mails.db</code> — 邮件、账户、规则</li>
                <li><code>users.db</code> — 管理员账号</li>
                <li><code>attachments/</code> — 附件目录</li>
                <li><code>.htaccess</code> — 目录保护</li>
            </ul>

            <div class="form-tip highlight">
                SQLite 是文件型数据库，不需要额外申请，适合虚拟主机。
            </div>

            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="step2">
                <input type="hidden" name="step" value="2">
                <button type="submit" class="btn btn-primary btn-block mt-20">创建数据库 →</button>
            </form>

        <?php elseif ($step == 3): ?>
            <h3 class="mt-0 mb-16">第 3 步：创建管理员账号</h3>

            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="step3">
                <input type="hidden" name="step" value="3">

                <label>用户名</label>
                <input type="text" name="username" placeholder="admin" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>">
                <div class="form-tip">至少 3 个字符</div>

                <label>密码</label>
                <input type="password" name="password" placeholder="至少 8 位" required>
                <div class="form-tip">建议包含大小写字母和数字</div>

                <label>确认密码</label>
                <input type="password" name="confirm" placeholder="再次输入" required>

                <button type="submit" class="btn btn-primary btn-block mt-20">创建管理员 →</button>
            </form>

        <?php elseif ($step == 4): ?>
            <h3 class="mt-0 mb-16">第 4 步：添加第一个邮箱</h3>

            <p class="text-small text-muted">这一步可以跳过，安装完成后再添加。</p>

            <?php
            $presets = [
                'gmail' => [
                    'label' => 'Gmail',
                    'host'  => '{imap.gmail.com:993/imap/ssl}INBOX',
                    'tip'   => '需在 Google 账户生成「应用专用密码」。',
                ],
                'qq' => [
                    'label' => 'QQ 邮箱',
                    'host'  => '{imap.qq.com:993/imap/ssl}INBOX',
                    'tip'   => '开启 IMAP 服务后获得 16 位授权码，用户名填 QQ 号。',
                ],
                'outlook' => [
                    'label' => 'Outlook',
                    'host'  => '{outlook.office365.com:993/imap/ssl}INBOX',
                    'tip'   => '需使用应用密码；部分账户默认关闭 IMAP。',
                ],
                '163' => [
                    'label' => '163 邮箱',
                    'host'  => '{imap.163.com:993/imap/ssl}INBOX',
                    'tip'   => '开启 IMAP 后生成授权码。',
                ],
                'custom' => [
                    'label' => '自定义',
                    'host'  => '',
                    'tip'   => '格式：{imap.服务器:993/imap/ssl}INBOX',
                ],
            ];
            ?>

            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="step4">
                <input type="hidden" name="step" value="4">

                <label>邮箱品牌</label>
                <select id="preset" onchange="applyPreset()">
                    <?php foreach ($presets as $pk => $p): ?>
                        <option value="<?= $pk ?>"
                                data-host="<?= htmlspecialchars($p['host']) ?>"
                                data-tip="<?= htmlspecialchars($p['tip']) ?>">
                            <?= htmlspecialchars($p['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-tip highlight" id="presetTip"></div>

                <div class="form-row">
                    <div>
                        <label>标识</label>
                        <input type="text" name="key_name" placeholder="如 gmail_main" value="mail1">
                    </div>
                    <div>
                        <label>显示名称</label>
                        <input type="text" name="label" placeholder="如 Gmail 主号">
                    </div>
                </div>

                <label>IMAP 服务器</label>
                <input type="text" name="host" id="host" placeholder="{imap.gmail.com:993/imap/ssl}INBOX">

                <div class="form-row">
                    <div>
                        <label>邮箱账号</label>
                        <input type="text" name="user" placeholder="yourname@gmail.com">
                    </div>
                    <div>
                        <label>授权码 / 应用密码</label>
                        <input type="password" name="password" placeholder="不是登录密码">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">测试连接并添加 →</button>
                    <button type="submit" name="skip" value="1" class="btn btn-ghost">跳过此步</button>
                </div>
            </form>

            <script>
            function applyPreset() {
                var sel = document.getElementById('preset');
                var opt = sel.options[sel.selectedIndex];
                document.getElementById('host').value = opt.getAttribute('data-host');
                document.getElementById('presetTip').textContent = opt.getAttribute('data-tip');
            }
            applyPreset();
            </script>

        <?php elseif ($step == 5): ?>
            <h3 class="mt-0 mb-16">第 5 步：确认安装</h3>

            <div class="summary summary-ok">✅ 所有配置已完成</div>

            <ul class="text-small" style="line-height:2.2;padding-left:20px;">
                <li>管理员：<strong><?= htmlspecialchars($_SESSION['admin_username'] ?? '') ?></strong></li>
                <?php if (!empty($_SESSION['added_account'])): ?>
                    <li>邮箱：<strong><?= htmlspecialchars($_SESSION['added_account']) ?></strong></li>
                <?php else: ?>
                    <li>邮箱：<span class="text-muted">未添加（可稍后补充）</span></li>
                <?php endif; ?>
            </ul>

            <div class="form-tip highlight">
                点击下方按钮后，系统会生成安装锁文件，install.php 将无法再次访问。
            </div>

            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="finish">
                <button type="submit" class="btn btn-primary btn-block mt-20">完成安装</button>
            </form>
        <?php endif; ?>

    </div>
</div>
</body>
</html>