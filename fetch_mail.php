<?php
require_once __DIR__ . '/security.php';
set_time_limit(180);
ini_set('memory_limit', '256M');

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    require __DIR__ . '/auth.php';
}

$dbFile = ZMAIL_DATA_DIR . '/mails.db';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

define('MAX_MAIL_SIZE', 500 * 1024);
define('MAX_MAILS_PER_RUN', 60);
define('ATTACH_DIR', ZMAIL_ATTACH_DIR);
define('MAX_ATTACH_SIZE', 5 * 1024 * 1024);

if (!is_dir(ATTACH_DIR)) mkdir(ATTACH_DIR, 0700, true);

$accounts = $db->query("SELECT * FROM accounts WHERE enabled = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$rules = $db->query("SELECT keyword FROM block_rules WHERE enabled = 1")->fetchAll(PDO::FETCH_COLUMN);
if (empty($rules)) {
    $rules = ['取消订阅', '退订', 'unsubscribe'];
}

$forwardRules = [];
try {
    $forwardRules = $db->query("SELECT * FROM forward_rules WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 读取配置
$fetchDays = (int)($db->query("SELECT value FROM settings WHERE key='fetch_days'")->fetchColumn() ?: 7);
if ($fetchDays <= 0) $fetchDays = 7;

$autoDeleteDays = (int)($db->query("SELECT value FROM settings WHERE key='auto_delete_days'")->fetchColumn() ?: 0);

define('MAX_MAIL_SIZE', 500 * 1024);
define('MAX_MAILS_PER_RUN', 60);
define('MAX_ATTACH_SIZE', 5 * 1024 * 1024);

// 附件不再落盘：只保存 IMAP UID、part 编号、文件名、大小和 MIME。
// 用户点击下载时由 download.php 从原邮箱按需读取。

function collect_parts($part, $prefix, &$out) {
    if (isset($part->parts) && count($part->parts)) {
        foreach ($part->parts as $i => $sub) {
            $num = $prefix === '' ? ($i + 1) : $prefix . '.' . ($i + 1);
            collect_parts($sub, $num, $out);
        }
        return;
    }
    $out[] = ['num' => $prefix, 'part' => $part];
}

function try_decode($data, $encoding) {
    if ($encoding == 3) {
        $d = base64_decode($data, true);
        if ($d !== false) $data = $d;
    } elseif ($encoding == 4) {
        $data = quoted_printable_decode($data);
    }
    if (strlen($data) > 100 && preg_match('/^[A-Za-z0-9+\/=\r\n\s]+$/', $data)) {
        $d = base64_decode($data, true);
        if ($d !== false && $d !== '') {
            if (mb_detect_encoding($d, 'UTF-8, GBK, ISO-8859-1', true) !== false) {
                $data = $d;
            }
        }
    }
    return $data;
}

function get_part_filename($part) {
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $p) {
            if (strtolower($p->attribute) == 'filename') {
                return mb_decode_mimeheader($p->value);
            }
        }
    }
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) == 'name') {
                return mb_decode_mimeheader($p->value);
            }
        }
    }
    return '';
}

function is_attachment($part) {
    if (empty($part->disposition)) return false;
    $d = strtolower($part->disposition);
    if ($d === 'attachment') return true;
    if ($d === 'inline') {
        $fn = get_part_filename($part);
        if ($fn !== '' && ($part->type ?? 0) != 5) return true;
    }
    return false;
}

function parse_mail($mbox, $uid, $mailSize) {
    $plain = '';
    $html  = '';
    $images = [];
    $fallbacks = [];
    $attachments = [];

    if ($mailSize > MAX_MAIL_SIZE) {
        return ['', '', []];
    }

    $structure = imap_fetchstructure($mbox, $uid, FT_UID);
    $parts = [];
    collect_parts($structure, '', $parts);

    foreach ($parts as $p) {
        $partNum  = $p['num'];
        $part     = $p['part'];
        $type     = $part->type ?? 0;
        $subtype  = strtoupper($part->subtype ?? '');
        $encoding = $part->encoding ?? 0;

        if (is_attachment($part)) {
            $fn = get_part_filename($part);
            if ($fn === '') $fn = 'attachment_' . $partNum;

            // 仅保存元数据，不保存附件二进制。
            $mime = 'application/octet-stream';
            if ($type === 5) {
                $mime = 'image/' . strtolower($subtype ?: 'jpeg');
                if ($subtype === 'JPG' || $subtype === 'JPEG') $mime = 'image/jpeg';
            } elseif ($type === 0) {
                $mime = 'text/' . strtolower($subtype ?: 'plain');
            } elseif ($type === 3) {
                $mime = 'application/' . strtolower($subtype ?: 'octet-stream');
            } elseif ($subtype !== '') {
                $mime = strtolower(($type === 4 ? 'audio/' : ($type === 6 ? 'video/' : 'application/')) . $subtype);
            }

            $partBytes = isset($part->bytes) ? (int)$part->bytes : 0;
            $attachments[] = [
                'name' => $fn,
                'size' => $partBytes,
                'mime' => $mime,
                'part' => $partNum,
                'uid' => (string)$uid,
            ];
            continue;
        }

        $data = imap_fetchbody($mbox, $uid, $partNum, FT_UID);
        if ($data === false || $data === '') continue;

        if ($type == 0) {
            $data = try_decode($data, $encoding);
            $charset = 'UTF-8';
            if (!empty($part->parameters)) {
                foreach ($part->parameters as $pp) {
                    if (strtolower($pp->attribute) == 'charset') $charset = $pp->value;
                }
            }
            $data = @mb_convert_encoding($data, 'UTF-8', $charset . ', UTF-8, GBK, GB2312, BIG5, ISO-8859-1, Windows-1252');

            if ($subtype == 'PLAIN' && $plain === '') {
                $plain = $data;
            } elseif ($subtype == 'HTML' && $html === '') {
                $html = $data;
            } else {
                if (preg_match('/<html|<!DOCTYPE|<body|<table|<div/i', $data)) {
                    $fallbacks[] = $data;
                }
            }
            continue;
        }

        if ($type == 5) {
            $data = try_decode($data, $encoding);
            $mime = 'image/' . strtolower($subtype ?: 'jpeg');
            if ($subtype == 'JPG') $mime = 'image/jpeg';
            if ($subtype == 'SVG') $mime = 'image/svg+xml';
            $cid = !empty($part->id) ? trim($part->id, '<>') : '';
            if ($cid !== '') {
                $images[$cid] = 'data:' . $mime . ';base64,' . base64_encode($data);
            }
            continue;
        }
    }

    if ($html === '' && !empty($fallbacks)) {
        usort($fallbacks, fn($a, $b) => strlen($b) - strlen($a));
        $html = $fallbacks[0];
    }

    if ($html === '' && $plain !== '' && preg_match('/<html|<body|<table|<div/i', $plain)) {
        $html = $plain;
        $plain = strip_tags($plain);
    }

    $smtpHeader = '/^(Received|Return-Path|Delivered-To|X-Original-To|Authentication-Results):/i';
    if (preg_match($smtpHeader, trim($plain))) $plain = '';
    if (preg_match($smtpHeader, trim($html)))  $html  = '';

    if ($html !== '' && !empty($images)) {
        $html = preg_replace_callback('/["\']cid:([^"\']+)["\']/i', function($m) use ($images) {
            $cid = trim($m[1], '<>');
            return isset($images[$cid]) ? '"' . $images[$cid] . '"' : $m[0];
        }, $html);
    }

    return [$plain, $html, $attachments];
}

$totalProcessed = 0;

foreach ($accounts as $acct) {
    if ($totalProcessed >= MAX_MAILS_PER_RUN) break;

    $key = $acct['key_name'];
    echo "=== 处理: {$acct['label']} ===\n";

    $mbox = @imap_open($acct['host'], $acct['user'], zmail_decrypt($acct['password']));
    if (!$mbox) {
        echo "  连接失败: " . imap_last_error() . "\n";
        continue;
    }

    $since = date('d-M-Y', strtotime("-{$fetchDays} days"));
    $uids = imap_search($mbox, 'SINCE "' . $since . '"', SE_UID);
    if ($uids === false || empty($uids)) {
        echo "  无新邮件\n";
        imap_close($mbox);
        $db->prepare("UPDATE accounts SET last_fetch = ? WHERE id = ?")
           ->execute([date('Y-m-d H:i:s'), $acct['id']]);
        continue;
    }

    rsort($uids);

    foreach ($uids as $uid) {
        if ($totalProcessed >= MAX_MAILS_PER_RUN) break;

        $check = $db->prepare("SELECT id FROM mails WHERE account = ? AND uid = ?");
        $check->execute([$key, $uid]);
        if ($check->fetchColumn()) continue;

        $check2 = $db->prepare("SELECT id FROM blocked_log WHERE account = ? AND uid = ?");
        $check2->execute([$key, $uid]);
        if ($check2->fetchColumn()) continue;

        $overview = imap_fetch_overview($mbox, $uid, FT_UID);
        if (empty($overview)) continue;

        $from    = isset($overview[0]->from)    ? mb_decode_mimeheader($overview[0]->from)    : '';
        $subject = isset($overview[0]->subject) ? mb_decode_mimeheader($overview[0]->subject) : '(无主题)';
        $date    = $overview[0]->date ?? date('Y-m-d H:i:s');
        $mailTs  = strtotime($date);
        if ($mailTs === false || $mailTs <= 0) $mailTs = time();
        $size    = $overview[0]->size ?? 0;
        $seen    = !empty($overview[0]->seen) ? 1 : 0;

        list($plain, $html, $attachments) = parse_mail($mbox, $uid, $size);

        $haystack = $subject . "\n" . $plain;
        $hit = false;
        $matchedKw = '';
        foreach ($rules as $kw) {
            if ($kw !== '' && mb_stripos($haystack, $kw) !== false) {
                $hit = true;
                $matchedKw = $kw;
                break;
            }
        }

        if ($hit) {
            echo "  [本地拦截] 命中「{$matchedKw}」| $from | $subject\n";
            $db->prepare("INSERT OR IGNORE INTO blocked_log
                (account, uid, from_addr, subject, mail_date, matched, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$key, $uid, $from, $subject, $date, $matchedKw, date('Y-m-d H:i:s')]);
            $totalProcessed++;
            continue;
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        $stmt = $db->prepare("INSERT OR IGNORE INTO mails
            (account, account_label, uid, from_addr, subject, mail_date, mail_ts, body, body_html, attachments, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $key, $acct['label'], $uid, $from, $subject, $date, $mailTs,
            mb_substr($plain, 0, 5000),
            $html,
            !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null,
            $seen,
            date('Y-m-d H:i:s'),
        ]);
        echo "  [入库] $from | $subject";
        if (!empty($attachments)) echo " (附件 " . count($attachments) . " 个)";
        echo "\n";

        foreach ($forwardRules as $fr) {
            if ($fr['keyword'] !== '' && mb_stripos($haystack, $fr['keyword']) !== false) {
                forward_mail($fr, $subject, $from, $plain, $html);
            }
        }

        $totalProcessed++;
    }

    imap_close($mbox);
    $db->prepare("UPDATE accounts SET last_fetch = ? WHERE id = ?")
       ->execute([date('Y-m-d H:i:s'), $acct['id']]);
}

// 定时清理：只删除本地邮件索引/正文，不会删除或修改原邮箱邮件。
// 附件不落盘，因此无需占用或清理本地附件目录。
if ($autoDeleteDays > 0) {
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$autoDeleteDays} days"));
    $deleted = $db->prepare("DELETE FROM mails WHERE created_at < ?");
    $deleted->execute([$cutoff]);
    $n = $deleted->rowCount();

    if ($n > 0) {
        echo "定时清理：删除 {$n} 封本地邮件（云端邮件不受影响）\n";
    }
}

echo "完成，共处理 {$totalProcessed} 封。\n";

function forward_mail($rule, $subject, $from, $plain, $html) {
    $channel = $rule['channel'];
    $target  = $rule['target'];
    $text = "【邮件转发】\n主题：$subject\n发件人：$from\n\n" . mb_substr($plain, 0, 500);

    if ($channel === 'telegram') {
        list($token, $chatId) = explode('|', $target, 2);
        if (!$token || !$chatId) return;
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $text]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } elseif ($channel === 'webhook') {
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['subject' => $subject, 'from' => $from, 'text' => $plain], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } elseif ($channel === 'serverchan') {
        $url = "https://sctapi.ftqq.com/{$target}.send";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['title' => $subject, 'desp' => $text]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}