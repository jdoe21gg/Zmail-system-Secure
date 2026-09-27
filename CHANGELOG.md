# 更新日志 / Changelog

## Secure Edition（升级版）— 2026-09-27

基于原版 `zybnb/Zmail-system`（commit `59ce1b66`）的安全升级版。
Based on upstream `zybnb/Zmail-system` (commit `59ce1b66`).

---

### 🐛 缺陷修复 / Bug Fixes

#### 1. 登录页 CSRF Token 缺失 — 所有用户无法登录（严重）
#### 1. Missing CSRF token on the login form — nobody could log in (critical)

- **现象 Symptom**：任何账号登录都报「请求无效，请刷新页面后重试」，刷新、换浏览器、清缓存都没用。
  Every login attempt failed with "Invalid request, please refresh and try again", regardless of browser or cache.
- **原因 Cause**：`login.php` 里加了 CSRF 校验（`hash_equals($_SESSION['csrf'], $_POST['csrf_token'])`），
  但登录表单 `<form>` 里漏了隐藏的 `csrf_token` 字段；且登录页没有加载 `assets/app.js`
 （其它页面的表单靠它自动补 token）。于是每次提交的 token 都是空，校验永远失败。
  CSRF validation was added to `login.php`, but the login `<form>` never included the hidden
  `csrf_token` field, and the login page doesn't load `assets/app.js` (which auto-injects the
  token into forms on other pages). Every submission failed validation.
- **修复 Fix**：在登录表单内加了一行隐藏字段（`login.php`）：
  Added one hidden field inside the login form (`login.php`):
  ```html
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
  ```
- **验证 Verified**：带有效 token + 错误密码提交，返回「用户名或密码错误」而非「请求无效」，登录流程恢复正常。
  Submitted with a valid token + wrong password → got "wrong username or password" instead of
  "invalid request". Login flow confirmed working.

#### 2. 账户管理页源码泄露 + 越权风险（严重）
#### 2. Account page leaked PHP source + auth bypass risk (critical)

- **现象 Symptom**：后台「邮箱账户管理」页显示一堆 PHP 源码乱码，页面不可用。
  The "mailbox management" admin page displayed raw PHP source code as garbled text.
- **原因 Cause**：`accounts.php` 文件开头丢了三行：`<?php`、`require __DIR__ . '/auth.php';`、`$db = get_db();`。
  没有 `<?php`，Web 服务器把整个文件当纯文本输出。
  The top of `accounts.php` lost three lines: the `<?php` tag, the auth require and the DB init.
  Without the tag, the server served the file as plain text.
- **连带风险 Hidden risk**：如果只补 `<?php` 标签，该页将**无需登录即可访问**——任何人都能查看、
  修改、删除邮箱账户（内含 IMAP 凭证）。属于越权漏洞。
  Restoring only the tag would have left the page **accessible without login** — anyone could
  view/modify/delete mailbox accounts (including IMAP credentials). A privilege-escalation hole.
- **修复 Fix**：按 `add_account.php` 的标准模式补全三行（`accounts.php` 文件头）：
  Restored the three lines following the standard pattern (`accounts.php` header):
  ```php
  <?php
  require __DIR__ . '/auth.php';
  $db = get_db();
  ```
- **验证 Verified**：未登录访问 `accounts.php` 返回 302 跳转到登录页，响应中不再含 PHP 源码。
  Unauthenticated requests to `accounts.php` now 302-redirect to the login page; no source leaks.

---

### ⬆️ 升级内容 / Upgrades（安全加固 Security Hardening）

| # | 升级内容 | Upgrade |
|---|----------|---------|
| 1 | 邮箱账号密码 / 应用专用密码改用 **AES-256-GCM** 加密存储，替代明文存放 | Mailbox / app passwords encrypted with **AES-256-GCM** instead of plaintext |
| 2 | 首次运行在 `data/.app_key` 生成随机应用密钥；也支持通过 `ZMAIL_APP_KEY` 环境变量提供 32 字节 base64 密钥（主机支持环境变量时更推荐） | Random app key generated at `data/.app_key` on first run; `ZMAIL_APP_KEY` env (base64, 32 bytes) supported |
| 3 | 所有管理类 POST 请求启用 **CSRF Token** 校验 | **CSRF tokens** on all management POST requests |
| 4 | 账户 / 规则的增删改操作由 GET 改为 POST，杜绝 CSRF 链接误触 | Mutating account/rule actions changed from GET to POST |
| 5 | 邮件正文 HTML 在 **sandboxed iframe**（无同源权限）中渲染，防 XSS | Mail HTML rendered in a **sandboxed iframe** without same-origin access (XSS mitigation) |
| 6 | 附件强制作为下载提供，忽略客户端提交的 MIME 类型，防内容嗅探 | Attachments forced as downloads; client-supplied MIME types ignored |
| 7 | 老版本明文密码在首次登录态访问数据库时自动迁移为加密存储 | Legacy plaintext passwords auto-migrated to encrypted storage on first authenticated DB access |
| 8 | Webhook 转发仅允许管理员配置的可信 HTTPS 地址 | Webhook forwarding restricted to administrator-configured trusted HTTPS endpoints |

> ⚠️ **备份注意**：请同时备份 SQLite 数据库 **和** 应用密钥（`data/.app_key`，或记下 `ZMAIL_APP_KEY`）。
> 没有密钥，加密的 IMAP 凭证**无法恢复**。
>
> ⚠️ **Backup note**: back up the SQLite databases **and** the app key (`data/.app_key`, or your
> `ZMAIL_APP_KEY` value). Without the key, encrypted IMAP credentials **cannot be recovered**.

---

### 📝 其他说明 / Notes

- 本版不改变原有功能行为，仅做安全加固与缺陷修复。/ No functional changes — hardening and bug fixes only.
- `SECURITY.md` 有加固细节，`STORAGE.md` 有存储说明。/ See `SECURITY.md` for hardening details and `STORAGE.md` for storage notes.
- 部署后请删除 `install.php`，并开启 HTTPS。/ Delete `install.php` after deployment and enable HTTPS.
