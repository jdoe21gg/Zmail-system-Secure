# 📬 Zmail System · 多邮箱聚合系统（Secure Edition 安全修复版）

![电脑端暗色模式](docs/screenshots/desktop-dark.png)

一个可以部署在虚拟主机上的轻量级多邮箱聚合系统。把 Gmail、QQ、Outlook、163 等邮箱集中在一个网页里查看、管理、转发。

A lightweight multi-mailbox aggregator that runs on shared web hosting. Read, manage and forward Gmail, QQ, Outlook, 163 and other mailboxes from a single web page.

> 不需要 Docker，不需要 VPS，只要一个支持 PHP + IMAP 的虚拟主机就能跑。
> No Docker, no VPS — just a PHP + IMAP shared host.

---

## 📢 原项目出处 / Original Project

本项目基于 **[zybnb/Zmail-system](https://github.com/zybnb/Zmail-system)**（MIT 协议）二次开发，原作者保留全部原版权。

This project is based on **[zybnb/Zmail-system](https://github.com/zybnb/Zmail-system)** (MIT License). All original copyrights belong to the original author.

原项目功能说明以其 README 为准；本版仅做安全加固与缺陷修复，不改变原有功能行为。

For the original feature set, see the upstream README. This edition only adds security hardening and bug fixes — no functional changes.

---

## 🛡️ 本版修复内容 / Security Fixes in This Edition

> 完整说明见 [CHANGELOG.md](CHANGELOG.md)（含每个 bug 的现象、原因、修复与验证）。
> Full details (symptom, cause, fix, verification per bug) in [CHANGELOG.md](CHANGELOG.md).

### 安全加固（Security Hardening）

| # | 修复 | Fix |
|---|------|-----|
| 1 | 邮箱账号密码 / 应用密码改用 **AES-256-GCM** 加密存储，不再明文存放 | Mailbox passwords are encrypted with **AES-256-GCM** instead of plaintext |
| 2 | 首次运行时在 `data/.app_key` 生成随机应用密钥（也可通过 `ZMAIL_APP_KEY` 环境变量提供 32 字节 base64 密钥） | A random app key is generated at `data/.app_key` on first use (or via `ZMAIL_APP_KEY` env) |
| 3 | 所有管理类 POST 请求启用 **CSRF Token** 校验 | **CSRF tokens** on all management POST requests |
| 4 | 账户 / 规则的修改操作由 GET 改为 POST | Mutating account/rule actions converted from GET to POST |
| 5 | 邮件 HTML 在 **sandboxed iframe**（无同源权限）中渲染 | Mail HTML rendered in a **sandboxed iframe** without same-origin access |
| 6 | 附件强制作为下载提供，忽略客户端提交的 MIME 类型 | Attachments served as downloads; client-supplied MIME types ignored |
| 7 | 老版本明文密码在首次登录访问数据库时自动迁移为加密存储 | Legacy plaintext passwords auto-migrated to encrypted storage on first authenticated DB access |

> ⚠️ 备份时请同时备份 SQLite 数据库 **和** 应用密钥（`data/.app_key`）。没有密钥，加密的 IMAP 凭证无法恢复。
> ⚠️ Back up both the SQLite databases **and** the app key (`data/.app_key`). Without the key, encrypted IMAP credentials cannot be recovered.

### 缺陷修复（Bug Fixes，详见 CHANGELOG.md / see CHANGELOG.md for details）

| # | 修复 | Fix |
|---|------|-----|
| 1 | `login.php`：登录表单补上隐藏的 `csrf_token` 字段。此前加了 CSRF 校验但表单没带 token，导致所有登录都报"请求无效"，无人能登录 | `login.php`: added the missing hidden `csrf_token` field. CSRF validation existed but the form never sent the token, so every login failed with "invalid request" |
| 2 | `accounts.php`：补上文件开头的 `<?php`、`require auth.php`、`$db = get_db();`。此前缺失导致 PHP 源码被当纯文本输出（页面显示乱码），且若只补标签会变成**无需登录即可访问**的越权漏洞 | `accounts.php`: restored the missing `<?php` header, `require auth.php` and `$db = get_db();`. The page previously leaked raw PHP source, and without the auth require it would have been an unauthenticated access vulnerability |

---

## 📸 截图 / Screenshots

### 电脑端 · 暗色模式 / Desktop · Dark mode

![电脑端暗色](docs/screenshots/desktop-dark.png)

### 电脑端 · 亮色模式 / Desktop · Light mode

![电脑端亮色](docs/screenshots/desktop-light.png)

### 手机端 · 邮件列表 / Mobile · Mail list

![手机列表](docs/screenshots/mobile-list.png)

### 手机端 · 邮件详情 / Mobile · Mail detail

![手机详情](docs/screenshots/mobile-detail.png)

---

## 为什么用它 / Why

如果你也受够了：

If you're tired of:

- 在 Gmail、QQ、Outlook 之间来回切换 / switching between Gmail, QQ and Outlook
- 手机装一堆邮箱 App，各占几十 MB / a pile of mail apps eating tens of MB each
- 虚拟主机没有资源跑 Docker / VPS / a shared host that can't run Docker or a VPS

那这个项目适合你。**只要一个支持 PHP + IMAP 的虚拟主机**，就能把多个邮箱聚合到一个网页管理。

Then this is for you — **any shared host with PHP + IMAP** is enough to aggregate all your mailboxes into one web page.

---

## ✨ 功能 / Features

| 功能 Feature | 说明 Description |
|---|---|
| 📬 多邮箱聚合 / Multi-mailbox | 支持 Gmail / QQ / Outlook / 163 / 126 / 自定义 IMAP / Gmail, QQ, Outlook, 163, 126, custom IMAP |
| 🔍 本地拦截 / Local filtering | 命中关键词的邮件只在本地过滤，云端邮件完全不动 / keyword filtering is local-only, server mail untouched |
| 📎 附件下载 / Attachments | 附件按需从原邮箱读取下载 / attachments fetched on demand from the source mailbox |
| 🔀 邮件转发 / Forwarding | 转发到 Telegram / Server酱 / 自定义 Webhook / forward to Telegram / ServerChan / custom Webhook |
| ⭐ 标记 / Marking | 星标 / 重要 / 未读，可随时切换 / star / important / unread toggles |
| 🌙 暗色模式 / Dark mode | 一键切换，眼睛友好 / one-click toggle |
| 📱 手机适配 / Mobile | 抽屉式布局，手机端列表与详情分屏 / drawer layout, split list/detail on mobile |
| ⏰ 自动收信 / Auto-fetch | Cron 或外部定时任务拉取 / via Cron or an external scheduler |
| 🧹 定时清理 / Auto-cleanup | 自动删除超过 N 天的本地邮件 / auto-delete local mail older than N days |
| 🔒 登录保护 / Login protection | 密码登录 + 限速防爆破 + 会话超时 + CSRF / password login, rate limiting, session timeout, CSRF |

---

## 🚀 快速开始 / Quick Start

### 环境要求 / Requirements

- PHP 7.4+（推荐 8.0+ / 8.0+ recommended）
- PHP 扩展 / extensions：`imap`、`pdo_sqlite`、`mbstring`、`curl`、`session`
- 一个虚拟主机（Serv00、宝塔、cPanel、DirectAdmin 等）/ a shared host (cPanel, DirectAdmin, etc.)

### 部署三步走 / Deploy in 3 steps

**1. 上传代码 / Upload**

把整个文件夹上传到网站目录，比如 `public_html/mail/`。

Upload the whole folder to your site, e.g. `public_html/mail/`.

**2. 访问安装向导 / Run the installer**

打开浏览器访问 / Visit in your browser:

```
https://你的域名/mail/install.php
https://your-domain/mail/install.php
```

按 5 步走 / 5 steps:

1. 环境检测 / Environment check
2. 创建数据库 / Create database
3. 设置管理员账号 / Create admin account
4. 添加第一个邮箱（可跳过）/ Add first mailbox (skippable)
5. 完成 / Done

**3. 配置定时收信 / Schedule fetching**

有 Cron 的主机，在面板添加定时任务 / If your host has Cron:

```
* * * * * /usr/local/bin/php /home/用户名/public_html/mail/fetch_mail.php
```

没有 Cron 的主机（多数免费虚拟主机禁用了 Cron），用外部免费定时服务（如 UptimeRobot）每 5 分钟请求一次：

If your host has no Cron (common on free shared hosting), use a free external scheduler (e.g. UptimeRobot) to request this URL every 5 minutes:

```
https://你的域名/mail/fetch_mail.php
```

> 安装完成后**立刻删除 `install.php`**。
> **Delete `install.php`** right after installation.

---

## 📮 支持的邮箱 / Supported Providers

| 邮箱 Provider | IMAP 服务器 Server |
|---|---|
| Gmail | `{imap.gmail.com:993/imap/ssl}INBOX` |
| QQ 邮箱 / QQ Mail | `{imap.qq.com:993/imap/ssl}INBOX` |
| Outlook / Hotmail | `{outlook.office365.com:993/imap/ssl}INBOX` |
| 163 邮箱 / 163 Mail | `{imap.163.com:993/imap/ssl}INBOX` |
| 126 邮箱 / 126 Mail | `{imap.126.com:993/imap/ssl}INBOX` |
| 自定义 / 企业邮箱 / Custom | `{imap.你的服务器:993/imap/ssl}INBOX` |

> Gmail / Outlook / QQ / 163 等通常**不能直接用登录密码**，需要在邮箱设置里生成**应用专用密码 / 授权码**后填入。
> Gmail, Outlook, QQ, 163 etc. usually require an **app-specific password / auth code** generated in the mailbox settings — the login password won't work.

---

## 📁 目录结构 / Directory Structure

```
zmail-system/
├── README.md
├── LICENSE
├── SECURITY.md          # 安全加固说明 / hardening notes
├── STORAGE.md
├── install.php          # 安装向导（安装后删除 / delete after install）
├── index.php            # 收件箱 / inbox
├── login.php            # 登录 / login
├── logout.php           # 退出 / logout
├── admin.php            # 修改密码 / change password
├── auth.php             # 鉴权公共文件 / auth bootstrap
├── security.php         # 加密 / CSRF 等安全函数 / crypto & CSRF helpers
├── fetch_mail.php       # 收信脚本（Cron/外部定时调用 / cron or scheduler entry）
├── run_fetch.php        # 手动触发收信 / manual fetch trigger
├── detail.php           # 邮件详情接口 / mail detail API
├── delete.php           # 删除邮件 / delete mail
├── mark.php             # 星标 / 重要 / 已读 / star & flags
├── download.php         # 附件下载 / attachment download
├── accounts.php         # 邮箱账户管理 / mailbox management
├── add_account.php      # 添加邮箱 / add mailbox
├── rules.php            # 拦截 / 转发 / 清理规则 / filter & forward rules
├── robots.txt
├── assets/              # style.css, app.js
├── includes/            # header.php, footer.php
├── data/                # 数据库目录（不提交到 Git / not committed）
│   ├── .htaccess
│   └── .app_key         # 应用密钥，首次运行生成 / app key, generated on first run
└── docs/
    └── screenshots/
```

---

## ❓ 常见问题 / FAQ

**Q: 收信延迟多久？/ How fresh is the mail?**

A: 取决于定时任务频率。每分钟一次则延迟 ≤1 分钟，每 5 分钟一次则 ≤5 分钟。
A: Depends on the scheduler frequency — every minute means ≤1 min delay, every 5 minutes means ≤5 min.

**Q: 会删云端邮件吗？/ Will it delete server-side mail?**

A: 不会。所有删除、标记操作只针对本地 SQLite，绝不调用 IMAP 的删除或已读操作。
A: No. Deletions and flags only touch the local SQLite DB; IMAP delete/seen commands are never issued.

**Q: 附件存在哪里？/ Where are attachments stored?**

A: 点击下载时从原邮箱按需读取，不长期落盘。
A: Fetched on demand from the source mailbox when you click download — not stored long-term.

**Q: 数据库安全吗？/ Is the database safe?**

A: SQLite 文件放在 `data/` 目录，内含 `.htaccess` 阻止直接访问；建议把 `data/` 移出网站根目录并开启 HTTPS。
A: SQLite files live in `data/` with `.htaccess` blocking direct access. Moving `data/` outside the web root and enabling HTTPS is recommended.

---

## 🛠️ 技术栈 / Tech Stack

- 后端 Backend：纯 PHP，无框架 / pure PHP, no framework
- 数据库 Database：SQLite
- 前端 Frontend：原生 JavaScript + CSS，零依赖 / vanilla JS + CSS, zero dependencies
- 邮件协议 Mail protocol：IMAP 收信 / IMAP fetching
- 部署 Deploy：任何支持 PHP 的虚拟主机 / any PHP shared host

---

## 🔒 安全建议 / Security Notes

1. 部署完成后立刻删除 `install.php` / Delete `install.php` right after deployment
2. 开启 HTTPS（用 Let's Encrypt 免费证书）/ Enable HTTPS (free via Let's Encrypt)
3. 如果主机支持，把 `data/` 目录移出网站根目录，或设置 `ZMAIL_DATA_DIR` / Move `data/` outside the web root when possible, or set `ZMAIL_DATA_DIR`
4. 使用强密码（≥8 位，含大小写和数字）/ Use a strong password (8+ chars, mixed case + digits)
5. 备份数据库时一并备份 `data/.app_key`，否则加密凭证无法恢复 / Back up `data/.app_key` together with the databases

---

## 📄 协议 / License

[MIT License](LICENSE) · 随便改、随便用、随便发（需保留原版权声明）。
MIT — free to modify, use and distribute (keep the original copyright notice).

---

## 🤝 贡献 / Contributing

欢迎提 Issue 和 PR。/ Issues and PRs are welcome.
