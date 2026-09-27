<?php
$page_title  = $page_title  ?? '邮件管理';
$nav_active  = $nav_active  ?? '';
$nav_items = [
    'index'    => ['href' => 'index.php',    'text' => '收件箱'],
    'accounts' => ['href' => 'accounts.php', 'text' => '邮箱账户'],
    'rules'    => ['href' => 'rules.php',    'text' => '拦截与转发'],
    'admin'    => ['href' => 'admin.php',    'text' => '修改密码'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($page_title) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<script>
(function () {
    var t = localStorage.getItem('mail_theme') || 'light';
    document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body>
<div class="topnav">
    <strong>邮件管理</strong>
    <?php foreach ($nav_items as $k => $item): ?>
        <a href="<?= $item['href'] ?>" class="<?= $nav_active === $k ? 'active' : '' ?>">
            <?= $item['text'] ?>
        </a>
    <?php endforeach; ?>
    <span class="spacer"></span>
    <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="切换主题">🌙</button>
    <?php if (!empty($_SESSION['username'])): ?>
        <span style="color:#94a3b8;font-size:13px;"><?= htmlspecialchars($_SESSION['username']) ?></span>
    <?php endif; ?>
    <a href="logout.php">退出</a>
</div>
<div class="container">
