<?php
require __DIR__ . '/auth.php';
$db = get_db();

$msg = '';
$ok = false;

$presets = [
    'gmail' => [
        'label' => 'Gmail',
        'host'  => '{imap.gmail.com:993/imap/ssl}INBOX',
        'tip'   => '需在 Google 账户开启两步验证后，生成「应用专用密码」，不能用登录密码。',
    ],
    'qq' => [
        'label' => 'QQ 邮箱',
        'host'  => '{imap.qq.com:993/imap/ssl}INBOX',
        'tip'   => '设置 - 账户 - 开启 IMAP/SMTP 服务，短信验证后获得 16 位授权码。用户名填 QQ 号，不带 @qq.com。',
    ],
    'outlook' => [
        'label' => 'Outlook / Hotmail',
        'host'  => '{outlook.office365.com:993/imap/ssl}INBOX',
        'tip'   => '若开启双重验证，需在安全设置里生成「应用密码」。部分账户默认关闭 IMAP，需手动开启。',
    ],
    '163' => [
        'label' => '163 邮箱',
        'host'  => '{imap.163.com:993/imap/ssl}INBOX',
        'tip'   => '设置 - POP3/SMTP/IMAP - 开启 IMAP，生成授权码。',
    ],
    '126' => [
        'label' => '126 邮箱',
        'host'  => '{imap.126.com:993/imap/ssl}INBOX',
        'tip'   => '同 163，需开启 IMAP 并生成授权码。',
    ],
    'custom' => [
        'label' => '自定义 / 企业邮箱',
        'host'  => '',
        'tip'   => '格式：{imap.服务器地址:993/imap/ssl}INBOX，端口和加密方式按服务商文档填写。',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $keyName  = trim($_POST['key_name'] ?? '');
    $label    = trim($_POST['label'] ?? '');
    $host     = trim($_POST['host'] ?? '');
    $user     = trim($_POST['user'] ?? '');
    $password = $_POST['password'] ?? '';
    $enabled  = isset($_POST['enabled']) ? 1 : 0;

    if ($keyName === '' || !preg_match('/^[a-z0-9_]+$/', $keyName)) {
        $msg = '标识只能包含小写字母、数字、下划线';
    } elseif ($label === '') {
        $msg = '显示名称不能为空';
    } elseif ($host === '' || strpos($host, '{') !== 0) {
        $msg = 'IMAP 地址格式错误，应以 { 开头';
    } elseif ($user === '') {
        $msg = '邮箱账号不能为空';
    } elseif ($password === '') {
        $msg = '授权码 / 应用密码不能为空';
    } else {
        $check = $db->prepare("SELECT id FROM accounts WHERE key_name = ?");
        $check->execute([$keyName]);
        if ($check->fetchColumn()) {
            $msg = '标识「' . $keyName . '」已存在，请换一个';
        } else {
            $mbox = @imap_open($host, $user, $password, 0, 1);
            if (!$mbox) {
                $msg = '连接失败：' . imap_last_error();
            } else {
                imap_close($mbox);
                $stmt = $db->prepare("INSERT INTO accounts (key_name, label, host, user, password, enabled, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$keyName, $label, $host, $user, zmail_encrypt($password), $enabled, date('Y-m-d H:i:s')]);
                $msg = '添加成功，Cron 下次执行时就会拉取此邮箱';
                $ok = true;
            }
        }
    }
}

$page_title = '添加邮箱';
$nav_active = 'accounts';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>添加邮箱账户</h1>
    <div class="actions">
        <a href="accounts.php">返回账户列表</a>
    </div>
</div>

<div class="card">
    <?php if ($msg): ?>
        <div class="msg <?= $ok ? 'msg-ok' : 'msg-err' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($ok): ?>
        <div class="form-actions">
            <a href="add_account.php" class="btn btn-primary">继续添加</a>
            <a href="accounts.php" class="btn btn-ghost">查看账户列表</a>
        </div>
    <?php else: ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
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
                <label>标识（英文，唯一）</label>
                <input type="text" name="key_name" placeholder="如 gmail_main" required
                       value="<?= htmlspecialchars($_POST['key_name'] ?? '') ?>">
                <div class="form-tip">只能小写字母、数字、下划线</div>
            </div>
            <div>
                <label>显示名称</label>
                <input type="text" name="label" placeholder="如 Gmail 主号" required
                       value="<?= htmlspecialchars($_POST['label'] ?? '') ?>">
            </div>
        </div>

        <label>IMAP 服务器地址</label>
        <input type="text" name="host" id="host" placeholder="{imap.gmail.com:993/imap/ssl}INBOX" required
               value="<?= htmlspecialchars($_POST['host'] ?? '') ?>">
        <div class="form-tip">格式：<code>{imap.服务器:993/imap/ssl}INBOX</code></div>

        <div class="form-row">
            <div>
                <label>邮箱账号 / 用户名</label>
                <input type="text" name="user" placeholder="yourname@gmail.com" required
                       value="<?= htmlspecialchars($_POST['user'] ?? '') ?>">
            </div>
            <div>
                <label>授权码 / 应用密码</label>
                <input type="password" name="password" placeholder="不是登录密码" required>
            </div>
        </div>

        <div class="checkbox-row">
            <input type="checkbox" name="enabled" id="enabled" value="1" checked>
            <label for="enabled">启用（Cron 会拉取此账户）</label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">测试连接并保存</button>
            <a href="accounts.php" class="btn btn-ghost">取消</a>
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
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>