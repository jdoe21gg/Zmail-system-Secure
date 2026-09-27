<?php
require __DIR__ . '/auth.php';
$db = get_db();

$filter  = $_GET['account'] ?? '';
$search  = trim($_GET['q'] ?? '');
$starred = !empty($_GET['starred']);
$unread  = !empty($_GET['unread']);
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = ['blocked = 0'];
$params = [];
if ($filter !== '') { $where[] = 'account = ?'; $params[] = $filter; }
if ($search !== '') { $where[] = '(subject LIKE ? OR from_addr LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($starred) { $where[] = 'is_starred = 1'; }
if ($unread)  { $where[] = 'is_read = 0'; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare("SELECT COUNT(*) FROM mails $whereSql");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$stmt = $db->prepare("SELECT id, account, account_label, from_addr, subject, mail_date,
                      is_starred, is_important, is_read, attachments
                      FROM mails $whereSql
                      ORDER BY mail_ts DESC, id DESC
                      LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$mails = $stmt->fetchAll(PDO::FETCH_ASSOC);

$accounts = $db->query("SELECT DISTINCT account, account_label FROM mails ORDER BY account_label")
               ->fetchAll(PDO::FETCH_ASSOC);

$page_title = '收件箱';
$nav_active = 'index';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>收件箱</h1>
    <div class="actions">
        <button class="btn btn-ghost" onclick="runFetch()">立即收信</button>
    </div>
</div>

<div class="mail-app">

    <aside class="mail-sidebar">
        <div class="sidebar-toolbar">
            <form method="get" class="sidebar-search">
                <select name="account" onchange="this.form.submit()">
                    <option value="">全部邮箱</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= htmlspecialchars($a['account']) ?>"
                            <?= $filter === $a['account'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['account_label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="搜索主题或发件人">
                <?php if ($starred): ?><input type="hidden" name="starred" value="1"><?php endif; ?>
                <?php if ($unread):  ?><input type="hidden" name="unread"  value="1"><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">搜索</button>
            </form>
            <div class="filter-tabs">
                <a href="?<?= http_build_query(array_filter(['account'=>$filter,'q'=>$search])) ?>" class="<?= (!$starred && !$unread) ? 'active' : '' ?>">全部</a>
                <a href="?<?= http_build_query(array_filter(['account'=>$filter,'q'=>$search,'unread'=>1])) ?>" class="<?= $unread ? 'active' : '' ?>">未读</a>
                <a href="?<?= http_build_query(array_filter(['account'=>$filter,'q'=>$search,'starred'=>1])) ?>" class="<?= $starred ? 'active' : '' ?>">星标</a>
            </div>
        </div>

        <div class="sidebar-actions">
            <label class="check-all-label">
                <input type="checkbox" id="checkAll" onchange="toggleAll(this)">
                <span>全选</span>
            </label>
            <span class="sidebar-count">共 <?= $total ?> 封</span>
            <button class="btn btn-sm btn-del" onclick="batchDelete()">删除选中</button>
            <button class="btn btn-sm btn-del" onclick="clearAll()">清空</button>
        </div>

        <div class="mail-list-scroll" id="mailListScroll">
            <?php if (empty($mails)): ?>
                <div class="empty">暂无邮件</div>
            <?php else: ?>
                <?php foreach ($mails as $m): ?>
                    <?php
                    $hasAttach = false;
                    if (!empty($m['attachments'])) {
                        $att = json_decode($m['attachments'], true);
                        $hasAttach = !empty($att);
                    }
                    $isUnread = empty($m['is_read']);
                    ?>
                    <div class="mail-row <?= $isUnread ? 'unread' : '' ?>" id="row-<?= $m['id'] ?>" data-id="<?= $m['id'] ?>" onclick="selectMail(<?= $m['id'] ?>, this)">
                        <input type="checkbox" class="mail-check" value="<?= $m['id'] ?>" onclick="event.stopPropagation();updateCheckAll()">
                        <div class="mail-row-body">
                            <div class="mail-row-head">
                                <span class="mail-row-subject">
                                    <?php if ($m['is_starred']): ?><span class="icon-star">★</span><?php endif; ?>
                                    <?= htmlspecialchars($m['subject'] ?: '(无主题)') ?>
                                </span>
                                <span class="mail-row-date"><?= htmlspecialchars(substr($m['mail_date'], 0, 16)) ?></span>
                            </div>
                            <div class="mail-row-meta">
                                <span class="badge badge-mail"><?= htmlspecialchars($m['account_label']) ?></span>
                                <span class="mail-row-from"><?= htmlspecialchars($m['from_addr']) ?></span>
                                <?php if ($hasAttach): ?><span class="icon-attach">📎</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="sidebar-pagination">
                <?php
                $qbase = ['account'=>$filter,'q'=>$search];
                if ($starred) $qbase['starred'] = 1;
                if ($unread)  $qbase['unread']  = 1;
                for ($i = 1; $i <= $totalPages; $i++):
                    $qbase['page'] = $i;
                ?>
                    <a href="?<?= http_build_query($qbase) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </aside>

    <section class="mail-content" id="mailContent">
        <div class="mail-content-empty">
            <div class="empty-icon">📬</div>
            <div>选择左侧邮件查看内容</div>
        </div>
    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>