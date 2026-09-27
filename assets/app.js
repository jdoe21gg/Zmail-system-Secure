/* 公共 JS */

function initTheme() {
    var saved = localStorage.getItem('mail_theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    updateThemeButton(saved);
}

function toggleTheme() {
    var cur = document.documentElement.getAttribute('data-theme') || 'light';
    var next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('mail_theme', next);
    updateThemeButton(next);
    if (currentMailId) {
        var activeRow = document.querySelector('.mail-row.active');
        if (activeRow) selectMail(currentMailId, activeRow);
    }
}

function updateThemeButton(theme) {
    var btn = document.getElementById('themeToggle');
    if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
}

function isDark() {
    return (document.documentElement.getAttribute('data-theme') || 'light') === 'dark';
}

var currentMailId = null;
var currentMailData = null;

function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
}
function secureFetch(url, options) {
    options = options || {};
    options.headers = options.headers || {};
    options.headers['X-CSRF-Token'] = csrfToken();
    return fetch(url, options);
}

function selectMail(id, el) {
    var rows = document.querySelectorAll('.mail-row');
    for (var i = 0; i < rows.length; i++) rows[i].classList.remove('active');
    if (el) el.classList.add('active');

    currentMailId = id;

    // 手机端：切到阅读视图
    if (window.innerWidth <= 768) {
        document.body.classList.add('mobile-reading');
    }

    var contentEl = document.getElementById('mailContent');
    contentEl.innerHTML = '<div class="mail-content-empty"><div>加载中...</div></div>';

    fetch('detail.php?id=' + id)
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) {
                contentEl.innerHTML = '<div class="mail-content-empty"><div>加载失败</div></div>';
                return;
            }

            currentMailData = d;

            var starIcon = d.is_starred ? '★' : '☆';
            var impIcon  = d.is_important ? '🔴' : '⚪';

            var html = '';
            html += '<div class="mail-view-head">';
            html += '<button class="mobile-back-btn" onclick="backToList()">← 返回列表</button>';
            html += '<h2 class="mail-view-subject">' + escapeHtml(d.subject || '(无主题)') + '</h2>';
            html += '<div class="mail-view-actions">';
            html += '<button class="mail-action-btn" onclick="toggleField(\'is_starred\')" title="星标">' + starIcon + '</button>';
            html += '<button class="mail-action-btn" onclick="toggleField(\'is_important\')" title="重要">' + impIcon + '</button>';
            html += '</div>';
            html += '<div class="mail-view-info">';
            html += '<div><strong>邮箱：</strong>' + escapeHtml(d.account_label) + '</div>';
            html += '<div><strong>发件人：</strong>' + escapeHtml(d.from_addr) + '</div>';
            html += '<div><strong>时间：</strong>' + formatDate(d.mail_date) + '</div>';
            html += '</div>';

            if (d.attachments && d.attachments.length > 0) {
                html += '<div class="mail-attachments">';
                html += '<div class="attach-title">📎 附件（' + d.attachments.length + '）</div>';
                html += '<div class="attach-list">';
                for (var i = 0; i < d.attachments.length; i++) {
                    var a = d.attachments[i];
                    var sizeStr = formatSize(a.size);
                    var url = 'download.php?id=' + encodeURIComponent(d.id) + '&i=' + encodeURIComponent(i);
                    html += '<a class="attach-item" href="' + url + '" download>';
                    html += '<span class="attach-icon">📄</span>';
                    html += '<span class="attach-name">' + escapeHtml(a.name) + '</span>';
                    html += '<span class="attach-size">' + sizeStr + '</span>';
                    html += '</a>';
                }
                html += '</div>';
                html += '</div>';
            }

            html += '</div>';
            html += '<div class="mail-view-body" id="mailViewBody"></div>';
            contentEl.innerHTML = html;

            if (el) el.classList.remove('unread');

            var bodyEl = document.getElementById('mailViewBody');
            var dark = isDark();

            if (d.body_html && d.body_html.trim() !== '') {
                var iframe = document.createElement('iframe');
                iframe.style.cssText = 'width:100%;border:none;display:block;height:100px;';
                iframe.style.background = dark ? '#1e293b' : '#ffffff';
                iframe.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
                iframe.setAttribute('referrerpolicy', 'no-referrer');
                bodyEl.appendChild(iframe);

                var baseStyle =
                    'html,body{margin:0;padding:0;overflow-x:hidden;width:100%;}' +
                    'body{font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;' +
                    'font-size:14px;line-height:1.7;padding:16px;word-break:break-word;}' +
                    'img{max-width:100% !important;height:auto !important;}' +
                    'table{max-width:100% !important;}' +
                    'pre{white-space:pre-wrap;word-break:break-word;}';

                var darkStyle = dark
                    ? 'body{background:#1e293b !important;color:#e5e7eb !important;}a{color:#60a5fa !important;}'
                    : 'body{background:#ffffff !important;color:#333 !important;}';

                var doc = iframe.contentDocument || iframe.contentWindow.document;
                doc.open();
                doc.write(
                    '<!DOCTYPE html><html><head><meta charset="utf-8">' +
                    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
                    '<style>' + baseStyle + darkStyle + '</style></head><body>' +
                    d.body_html +
                    '</body></html>'
                );
                doc.close();

                var adjustHeight = function () {
                    try {
                        var d2 = iframe.contentDocument || iframe.contentWindow.document;
                        var h = Math.max(d2.documentElement.scrollHeight, d2.body.scrollHeight);
                        if (h > 0) iframe.style.height = h + 'px';
                    } catch (e) {}
                };

                try {
                    var imgs = iframe.contentDocument.querySelectorAll('img');
                    for (var k = 0; k < imgs.length; k++) {
                        imgs[k].addEventListener('load', adjustHeight);
                        imgs[k].addEventListener('error', adjustHeight);
                    }
                } catch (e) {}

                try {
                    var d3 = iframe.contentDocument || iframe.contentWindow.document;
                    if (window.ResizeObserver) {
                        var ro = new ResizeObserver(adjustHeight);
                        ro.observe(d3.body);
                    }
                } catch (e) {}

                setTimeout(adjustHeight, 100);
                setTimeout(adjustHeight, 300);
                setTimeout(adjustHeight, 800);
                setTimeout(adjustHeight, 1500);
            } else {
                bodyEl.innerHTML = '<div style="text-align:center;color:#999;padding:40px 20px;">此邮件没有可显示的正文内容<br><span style="font-size:12px;">可能是纯图片邮件或需要在线查看</span></div>';
            }
        })
        .catch(function () {
            contentEl.innerHTML = '<div class="mail-content-empty"><div>加载失败</div></div>';
        });
}

function backToList() {
    document.body.classList.remove('mobile-reading');
    var rows = document.querySelectorAll('.mail-row');
    for (var i = 0; i < rows.length; i++) rows[i].classList.remove('active');
    currentMailId = null;
}

function toggleField(field) {
    if (!currentMailId || !currentMailData) return;
    var cur = currentMailData[field] ? 1 : 0;
    var next = cur ? 0 : 1;

    secureFetch('mark.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: currentMailId, field: field, value: next })
    })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                currentMailData[field] = next;
                var activeRow = document.querySelector('.mail-row.active');
                if (activeRow) selectMail(currentMailId, activeRow);
            }
        });
}

function formatSize(bytes) {
    if (!bytes) return '0 B';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(2) + ' MB';
}

function formatDate(str) {
    if (!str) return '';
    var d = new Date(str);
    if (isNaN(d.getTime())) return str;

    var weekdays = ['日', '一', '二', '三', '四', '五', '六'];
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };

    return d.getFullYear() + '年' + (d.getMonth() + 1) + '月' + d.getDate() + '日 ' +
           '星期' + weekdays[d.getDay()] + ' ' +
           pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function toggleAll(el) {
    var boxes = document.querySelectorAll('.mail-check');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = el.checked;
}

function updateCheckAll() {
    var boxes = document.querySelectorAll('.mail-check');
    var checked = 0;
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].checked) checked++;
    }
    var allBox = document.getElementById('checkAll');
    if (allBox) {
        allBox.checked = (checked > 0 && checked === boxes.length);
        allBox.indeterminate = (checked > 0 && checked < boxes.length);
    }
}

function getCheckedIds() {
    var boxes = document.querySelectorAll('.mail-check:checked');
    var ids = [];
    for (var i = 0; i < boxes.length; i++) ids.push(parseInt(boxes[i].value, 10));
    return ids;
}

function batchDelete() {
    var ids = getCheckedIds();
    if (ids.length === 0) {
        alert('请先勾选要删除的邮件');
        return;
    }
    if (!confirm('确定删除选中的 ' + ids.length + ' 封邮件？')) return;

    secureFetch('delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids })
    })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) location.reload();
            else alert('删除失败');
        })
        .catch(function () { alert('请求失败'); });
}

function clearAll() {
    if (!confirm('确定清空所有本地邮件？云端邮件不受影响。')) return;

    secureFetch('delete.php?all=1', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) location.reload();
            else alert('操作失败');
        })
        .catch(function () { alert('请求失败'); });
}

function runFetch() {
    if (!confirm('立即拉取所有启用的邮箱？')) return;
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '收信中...';
    secureFetch('run_fetch.php', {method:'POST'})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            alert(d.ok ? '收信完成，即将刷新' : (d.msg || '执行失败'));
            if (d.ok) location.reload();
            else { btn.disabled = false; btn.textContent = '立即收信'; }
        })
        .catch(function () {
            alert('请求失败');
            btn.disabled = false;
            btn.textContent = '立即收信';
        });
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '<')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function deleteMail(id) {
    if (!confirm('确定删除这封邮件？')) return;
    secureFetch('delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: [id] })
    })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) location.reload();
            else alert('删除失败');
        })
        .catch(function () { alert('请求失败'); });
}

document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    var token = csrfToken();
    var forms = document.querySelectorAll('form[method="post"], form:not([method])');
    for (var i = 0; i < forms.length; i++) {
        forms[i].addEventListener('submit', function () {
            if (!this.querySelector('input[name="csrf_token"]')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = token;
                this.appendChild(input);
            }
        });
    }
});
