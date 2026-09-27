<?php
require __DIR__ . '/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'method not allowed'], JSON_UNESCAPED_UNICODE); exit; }
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$lock = sys_get_temp_dir() . '/mail_fetch.lock';
if (file_exists($lock) && time() - filemtime($lock) < 10) {
    echo json_encode(['ok' => false, 'msg' => '请稍等，正在执行中...'], JSON_UNESCAPED_UNICODE);
    exit;
}
touch($lock);

ob_start();
include __DIR__ . '/fetch_mail.php';
$output = ob_get_clean();

echo json_encode(['ok' => true, 'log' => $output], JSON_UNESCAPED_UNICODE);