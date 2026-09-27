<?php
require __DIR__ . '/auth.php';
$db = get_db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT id, account, account_label, from_addr, subject, mail_date, body, body_html, attachments, is_starred, is_important, is_read FROM mails WHERE id = ?");
$stmt->execute([$id]);
$mail = $stmt->fetch(PDO::FETCH_ASSOC);

if ($mail) {
    $mail['attachments'] = !empty($mail['attachments']) ? json_decode($mail['attachments'], true) : [];
} else {
    $mail = ['error' => 'not found'];
}

// 标记已读
if (!empty($mail['id']) && empty($mail['is_read'])) {
    $db->prepare("UPDATE mails SET is_read = 1 WHERE id = ?")->execute([$mail['id']]);
    $mail['is_read'] = 1;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($mail, JSON_UNESCAPED_UNICODE);