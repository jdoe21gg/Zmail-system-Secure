<?php
/**
 * Zmail System security/bootstrap helpers.
 * Set ZMAIL_DATA_DIR to a directory outside the public web root when possible.
 */
define('ZMAIL_DATA_DIR', getenv('ZMAIL_DATA_DIR') ?: (__DIR__ . '/data'));
define('ZMAIL_ATTACH_DIR', ZMAIL_DATA_DIR . '/attachments');
define('ZMAIL_LOCK_FILE', ZMAIL_DATA_DIR . '/installed.lock');
define('ZMAIL_APP_KEY_FILE', ZMAIL_DATA_DIR . '/.app_key');

function zmail_ensure_data_dir(): void {
    if (!is_dir(ZMAIL_DATA_DIR)) {
        @mkdir(ZMAIL_DATA_DIR, 0700, true);
    }
    if (!is_dir(ZMAIL_ATTACH_DIR)) {
        @mkdir(ZMAIL_ATTACH_DIR, 0700, true);
    }
}

function zmail_app_key(): string {
    zmail_ensure_data_dir();
    $env = getenv('ZMAIL_APP_KEY');
    if ($env !== false && $env !== '') {
        $decoded = base64_decode($env, true);
        if ($decoded !== false && strlen($decoded) === 32) return $decoded;
        if (strlen($env) === 32) return $env;
        throw new RuntimeException('ZMAIL_APP_KEY must be 32 raw bytes or base64-encoded 32 bytes.');
    }
    if (is_file(ZMAIL_APP_KEY_FILE)) {
        $key = trim((string)file_get_contents(ZMAIL_APP_KEY_FILE));
        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) === 32) return $decoded;
    }
    $key = random_bytes(32);
    file_put_contents(ZMAIL_APP_KEY_FILE, base64_encode($key), LOCK_EX);
    @chmod(ZMAIL_APP_KEY_FILE, 0600);
    return $key;
}

function zmail_encrypt(string $plaintext): string {
    $key = zmail_app_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) throw new RuntimeException('Unable to encrypt secret.');
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}

function zmail_decrypt(string $value): string {
    if (strpos($value, 'enc:v1:') !== 0) return $value; // legacy plaintext
    $raw = base64_decode(substr($value, 7), true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Invalid encrypted secret.');
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', zmail_app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('Unable to decrypt secret.');
    return $plain;
}

function zmail_encrypt_legacy_accounts(PDO $db): void {
    try {
        $rows = $db->query("SELECT id, password FROM accounts")->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $db->prepare("UPDATE accounts SET password = ? WHERE id = ?");
        foreach ($rows as $row) {
            if (!empty($row['password']) && strpos($row['password'], 'enc:v1:') !== 0) {
                $stmt->execute([zmail_encrypt($row['password']), $row['id']]);
            }
        }
    } catch (Throwable $e) {
        // Database may not have been initialized yet.
    }
}
