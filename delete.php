<?php
require __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'method not allowed']);
    exit;
}
require_csrf();

$db = get_db();

// 情况 1：清空全部（?all=1）
if (!empty($_GET['all'])) {
    $db->exec("DELETE FROM mails");
    echo json_encode(['ok' => true, 'msg' => '已清空']);
    exit;
}

// 情况 2：批量删除（POST JSON {ids: [1,2,3]}）
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (is_array($data) && !empty($data['ids'])) {
    $ids = array_values(array_filter(array_map('intval', $data['ids'])));
    if (empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => 'no valid ids']);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("DELETE FROM mails WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

// 情况 3：单个删除（向后兼容 ?id=1）
if (!empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("DELETE FROM mails WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['ok' => $stmt->rowCount() > 0]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'no id provided']);