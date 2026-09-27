<?php
require __DIR__ . '/auth.php';

$id = (int)($_GET['id'] ?? 0);
$idx = (int)($_GET['i'] ?? -1);

if ($id <= 0 || $idx < 0) {
    http_response_code(400);
    exit('invalid attachment');
}

$db = get_db();
$stmt = $db->prepare("SELECT account, uid, attachments FROM mails WHERE id = ?");
$stmt->execute([$id]);
$mail = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mail) {
    http_response_code(404);
    exit('mail not found');
}

$attachments = !empty($mail['attachments']) ? json_decode($mail['attachments'], true) : [];
if (!is_array($attachments) || !isset($attachments[$idx]) || !is_array($attachments[$idx])) {
    http_response_code(404);
    exit('attachment not found');
}

$att = $attachments[$idx];
$part = (string)($att['part'] ?? '');
$uid  = (int)($att['uid'] ?? $mail['uid']);

if ($part === '' || $uid <= 0) {
    http_response_code(404);
    exit('attachment metadata unavailable');
}

$stmt = $db->prepare("SELECT host, user, password FROM accounts WHERE key_name = ?");
$stmt->execute([$mail['account']]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    http_response_code(404);
    exit('mail account not found');
}

$size = (int)($att['size'] ?? 0);
if ($size > 5 * 1024 * 1024) {
    http_response_code(413);
    exit('attachment exceeds 5 MB download limit');
}

$mbox = @imap_open($account['host'], $account['user'], zmail_decrypt($account['password']), 0, 1);
if (!$mbox) {
    http_response_code(502);
    exit('unable to connect to mail server');
}

$structure = @imap_fetchstructure($mbox, $uid, FT_UID);
if (!$structure) {
    imap_close($mbox);
    http_response_code(404);
    exit('mail structure unavailable');
}

$parts = [];
collect_download_parts($structure, '', $parts);
$target = null;
foreach ($parts as $item) {
    if ($item['num'] === $part) {
        $target = $item['part'];
        break;
    }
}

if (!$target) {
    imap_close($mbox);
    http_response_code(404);
    exit('attachment part not found');
}

$data = @imap_fetchbody($mbox, $uid, $part, FT_UID);
if ($data === false) {
    imap_close($mbox);
    http_response_code(502);
    exit('unable to read attachment');
}

$encoding = (int)($target->encoding ?? 0);
if ($encoding === 3) {
    $decoded = base64_decode($data, true);
    if ($decoded === false) {
        imap_close($mbox);
        http_response_code(502);
        exit('invalid base64 attachment');
    }
    $data = $decoded;
} elseif ($encoding === 4) {
    $data = quoted_printable_decode($data);
}

if (strlen($data) > 5 * 1024 * 1024) {
    imap_close($mbox);
    http_response_code(413);
    exit('attachment exceeds 5 MB download limit');
}

$name = (string)($att['name'] ?? 'attachment');
$name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '_', $name) ?: 'attachment';
$mime = (string)($att['mime'] ?? 'application/octet-stream');
if (!preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/i', $mime)) {
    $mime = 'application/octet-stream';
}

imap_close($mbox);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . strlen($data));
header('X-Content-Type-Options: nosniff');
echo $data;
exit;

function collect_download_parts($part, $prefix, &$out) {
    if (isset($part->parts) && count($part->parts)) {
        foreach ($part->parts as $i => $sub) {
            $num = $prefix === '' ? (string)($i + 1) : $prefix . '.' . ($i + 1);
            collect_download_parts($sub, $num, $out);
        }
        return;
    }
    $out[] = ['num' => $prefix, 'part' => $part];
}
