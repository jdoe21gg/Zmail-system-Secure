<?php
require __DIR__ . '/auth.php';
$db = get_db();

$msg = '';
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

// 添加拦截规则
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $kw = trim($_POST['keyword'] ?? '');
    if ($kw === '') {
        $msg = '关键词不能为空';
    } else {
        $db->prepare("INSERT INTO block_rules (keyword, enabled, created_at) VALUES (?, 1, ?)")
           ->execute([$kw, date('Y-m-d H:i:s')]);
        $msg = "已添加拦截规则「{$kw}」";
        $ok = true;
    }
}

// 添加转发规则
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_forward') {
    $kw      = trim($_POST['keyword'] ?? '');
    $channel = $_POST['channel'] ?? '';
    $target  = trim($_POST['target'] ?? '');

    if ($kw === '') {
        $msg = '转发关键词不能为空';
    } elseif (!in_array($channel, ['telegram', 'webhook', 'serverchan'])) {
        $msg = '推送渠道不正确';
    } elseif ($target === '') {
        $msg = '推送目标不能为空';
    } else {
        $db->prepare("INSERT INTO forward_rules (keyword, channel, target, enabled, created_at) VALUES (?, ?, ?, 1, ?)")
           ->execute([$kw, $channel, $target, date('Y-m-d H:i:s')]);
        $msg = "已添加转发规则「{$kw}」";
        $ok = true;
    }
}

// 保存拉取范围
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fetch_range') {
    $days = max(1, (int)($_POST['days'] ?? 7));
    $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('fetch_days', ?)")
       ->execute([(string)$days]);
    $msg = "已设置：每次拉取最近 {$days} 天的邮件";
    $ok = true;
}

// 保存定时清理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_auto_delete') {
    $days = max(0, (int)($_POST['days'] ?? 0));
    $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('auto_delete_days', ?)")
       ->execute([(string)$days]);
    $msg = $days > 0 ? "已设置：超过 {$days} 天的邮件将自动删除" : '已关闭自动清理';
    $ok = true;
}

// 删除规则
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && !empty($_POST['id'])) {
    $db->prepare("DELETE FROM block_rules WHERE id = ?")->execute([(int)$_POST['id']]);
    header('Location: rules.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_forward' && !empty($_POST['id'])) {
    $db->prepare("DELETE FROM forward_rules WHERE id = ?")->execute([(int)$_POST['id']]);
    header('Location: rules.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle' && !empty($_POST['id'])) {
    $db->prepare("UPDATE block_rules SET enabled = 1 - enabled WHERE id = ?")->execute([(int)$_POST['id']]);
    header('Location: rules.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_forward' && !empty($_POST['id'])) {
    $db->prepare("UPDATE forward_rules SET enabled = 1 - enabled WHERE id = ?")->execute([(int)$_POST['id']]);
    header('Location: rules.php'); exit;
}

$rules         = $db->query("SELECT * FROM block_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$forwardRules  = $db->query("SELECT * FROM forward_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$recentBlocked = $db->query("SELECT * FROM blocked_log ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$totalBlocked  = $db->query("SELECT COUNT(*) FROM blocked_log")->fetchColumn();

$autoDeleteDays = (int)($db->query("SELECT value FROM settings WHERE key='auto_delete_days'")->fetchColumn() ?: 0);
$fetchDays      = (int)($db->query("SELECT value FROM settings WHERE key='fetch_days'")->fetchColumn() ?: 7);
if ($fetchDays <= 0) $fetchDays = 7;

// 统计本地邮件数量。附件采用按需 IMAP 下载，不长期占用 PHP 空间。
$totalMails = (int)$db->query("SELECT COUNT(*) FROM mails")->fetchColumn();
$attCount = 0;
$attSizeStr = '按需读取';

$page_title = '拦截与转发';
$nav_active = 'rules';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>拦截与转发</h1>
</div>

<?php if ($msg): ?>
    <div class="msg <?= $ok ? 'msg-ok' : 'msg-err' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- 收信配置 -->
<div class="card">
    <h3>收信配置</h3>
    <div class="settings-grid">
        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"> class="settings-item">
            <input type="hidden" name="action" value="save_fetch_range">
            <label>每次拉取时间范围</label>
            <select name="days">
                <option value="1"  <?= $fetchDays == 1  ? 'selected' : '' ?>>最近 1 天</option>
                <option value="3"  <?= $fetchDays == 3  ? 'selected' : '' ?>>最近 3 天</option>
                <option value="7"  <?= $fetchDays == 7  ? 'selected' : '' ?>>最近 7 天</option>
                <option value="14" <?= $fetchDays == 14 ? 'selected' : '' ?>>最近 14 天</option>
                <option value="30" <?= $fetchDays == 30 ? 'selected' : '' ?>>最近 30 天</option>
                <option value="90" <?= $fetchDays == 90 ? 'selected' : '' ?>>最近 90 天</option>
            </select>
            <div class="form-tip">Cron 每次执行只拉取这个范围内的新邮件。</div>
            <button type="submit" class="btn btn-primary mt-8">保存</button>
        </form>

        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"> class="settings-item">
            <input type="hidden" name="action" value="save_auto_delete">
            <label>定时清理</label>
            <input type="text" name="days" value="<?= $autoDeleteDays ?>" placeholder="0 表示不清理">
            <div class="form-tip">超过设定天数的邮件将在每次收信时自动从本地删除，云端不受影响。</div>
            <button type="submit" class="btn btn-primary mt-8">保存</button>
        </form>
    </div>

    <div class="stats-row">
        <div class="stat-item">
            <div class="stat-num"><?= $totalMails ?></div>
            <div class="stat-lbl">本地邮件</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?= $attCount ?></div>
            <div class="stat-lbl">附件（云端）</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?= htmlspecialchars($attSizeStr) ?></div>
            <div class="stat-lbl">本地附件占用</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?= $totalBlocked ?></div>
            <div class="stat-lbl">累计拦截</div>
        </div>
    </div>
</div>
    <div class="form-tip">附件不保存到 PHP 空间。点击附件时才从原邮箱通过 IMAP 临时读取；删除本地邮件不会删除原邮箱邮件。</div>
</div>

<!-- 拦截规则 -->
<div class="card">
    <h3>拦截规则</h3>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"> style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="add">
        <div style="flex:1;min-width:200px;">
            <label>关键词</label>
            <input type="text" name="keyword" placeholder="如：取消订阅" required>
        </div>
        <button type="submit" class="btn btn-primary">添加</button>
    </form>
    <div class="form-tip">邮件主题或正文包含这些关键词时，只在本地拦截，不会从云端删除。</div>

    <?php if (!empty($rules)): ?>
        <table class="table" style="margin-top:16px;">
            <thead><tr><th>关键词</th><th>状态</th><th>添加时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $r): ?>
                <tr>
                    <td><code><?= htmlspecialchars($r['keyword']) ?></code></td>
                    <td>
                        <span class="badge <?= $r['enabled'] ? 'badge-on' : 'badge-off' ?>">
                            <?= $r['enabled'] ? '启用' : '停用' ?>
                        </span>
                    </td>
                    <td class="text-small text-muted"><?= htmlspecialchars($r['created_at']) ?></td>
                    <td class="actions-cell">
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"> style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn-sm btn-toggle" type="submit"><?= $r['enabled'] ? '停用' : '启用' ?></button></form>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"> style="display:inline" onsubmit="return confirm('确定删除这条拦截规则？')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn-sm btn-del" type="submit">删除</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- 转发规则 -->
<div class="card">
    <h3>自动转发规则</h3>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">>
        <input type="hidden" name="action" value="add_forward">
        <div class="form-row">
            <div>
                <label>匹配关键词</label>
                <input type="text" name="keyword" placeholder="如：验证码" required>
                <div class="form-tip">邮件主题或正文包含此词时触发转发</div>
            </div>
            <div>
                <label>推送渠道</label>
                <select name="channel" id="fwdChannel" onchange="updateFwdTip()">
                    <option value="telegram">Telegram Bot</option>
                    <option value="serverchan">Server 酱（微信）</option>
                    <option value="webhook">自定义 Webhook</option>
                </select>
            </div>
        </div>
        <label>推送目标</label>
        <input type="text" name="target" placeholder="填写推送参数" required>
        <div class="form-tip highlight" id="fwdTip"></div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">添加转发规则</button>
        </div>
    </form>

    <?php if (!empty($forwardRules)): ?>
        <table class="table" style="margin-top:16px;">
            <thead><tr><th>关键词</th><th>渠道</th><th>目标</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($forwardRules as $f): ?>
                <tr>
                    <td><code><?= htmlspecialchars($f['keyword']) ?></code></td>
                    <td>
                        <?php
                        $chLabel = ['telegram' => 'Telegram', 'serverchan' => 'Server酱', 'webhook' => 'Webhook'][$f['channel']] ?? $f['channel'];
                        echo htmlspecialchars($chLabel);
                        ?>
                    </td>
                    <td class="text-small word-break"><?= htmlspecialchars($f['target']) ?></td>
                    <td>
                        <span class="badge <?= $f['enabled'] ? 'badge-on' : 'badge-off' ?>">
                            <?= $f['enabled'] ? '启用' : '停用' ?>
                        </span>
                    </td>
                    <td class="actions-cell">
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"> style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_forward"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn-sm btn-toggle" type="submit"><?= $f['enabled'] ? '停用' : '启用' ?></button></form>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"> style="display:inline" onsubmit="return confirm('确定删除这条转发规则？')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_forward"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn-sm btn-del" type="submit">删除</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- 拦截记录 -->
<div class="card">
    <h3>最近拦截记录（共 <?= $totalBlocked ?> 条，显示最近 30 条）</h3>
    <?php if (empty($recentBlocked)): ?>
        <div class="empty">暂无拦截记录</div>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>时间</th><th>邮箱</th><th>发件人</th><th>主题</th><th>命中</th></tr></thead>
            <tbody>
            <?php foreach ($recentBlocked as $b): ?>
                <tr>
                    <td class="text-small text-muted"><?= htmlspecialchars($b['created_at']) ?></td>
                    <td class="text-small"><?= htmlspecialchars($b['account']) ?></td>
                    <td class="text-small word-break"><?= htmlspecialchars($b['from_addr']) ?></td>
                    <td class="text-small"><?= htmlspecialchars($b['subject']) ?></td>
                    <td><code><?= htmlspecialchars($b['matched']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
var fwdTips = {
    telegram: '格式：BotToken|ChatID，例如 123456:ABC-DEF|987654321',
    serverchan: '填写 SendKey，如 SCT1234567890abcdef',
    webhook: '完整 URL，如 https://example.com/hook'
};
function updateFwdTip() {
    var ch = document.getElementById('fwdChannel').value;
    document.getElementById('fwdTip').textContent = fwdTips[ch] || '';
}
updateFwdTip();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>