<?php
require __DIR__ . '/auth.php';

$msg = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $old = $_POST['old'] ?? '';
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $db = get_users_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($old, $user['password_hash'])) {
        $msg = '原密码错误';
    } elseif (strlen($new) < 8) {
        $msg = '新密码至少 8 位';
    } elseif ($new !== $confirm) {
        $msg = '两次输入的新密码不一致';
    } else {
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['uid']]);
        $msg = '密码修改成功';
        $ok = true;
    }
}

$page_title = '修改密码';
$nav_active = 'admin';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>修改密码</h1>
    <div class="actions">
        <a href="index.php">返回收件箱</a>
    </div>
</div>

<div class="card" style="max-width:480px;">
    <?php if ($msg): ?>
        <div class="msg <?= $ok ? 'msg-ok' : 'msg-err' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <label>原密码</label>
        <input type="password" name="old" required>
        <label>新密码</label>
        <input type="password" name="new" placeholder="至少 8 位" required>
        <label>确认新密码</label>
        <input type="password" name="confirm" required>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">确认修改</button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>