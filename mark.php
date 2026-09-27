<?php
require __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]); exit;
}
require_csrf();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$id    = (int)($data['id'] ?? 0);
$field = $data['field'] ?? '';
$value = (int)($data['value'] ?? 0);

$allowed = ['is_starred', 'is_important', 'is_read'];
if (!in_array($field, $allowed) || $id <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'invalid']); exit;
}

$db = get_db();
$stmt = $db->prepare("UPDATE mails SET {$field} = ? WHERE id = ?");
$stmt->execute([$value, $id]);

echo json_encode(['ok' => true]);