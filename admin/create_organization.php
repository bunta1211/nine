<?php
/**
 * 所属組織を新規作成するページ
 * ログインユーザーなら誰でも組織を作成できる
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$name = '';
$type = 'corporation';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>所属組織を作成 - Social9</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="admin-container">
        <aside class="admin-sidebar">
            <div class="admin-logo">
                <h1>🏢 組織</h1>
            </div>
            <nav class="admin-nav">
                <a href="/chat.php">💬 チャットへ戻る</a>
                <a href="members.php">👥 組織アドレス帳</a>
                <a href="groups.php">📁 グループ一覧</a>
            </nav>
        </aside>
        <main class="admin-main">
            <header class="admin-header">
                <h2>➕ 所属組織を作成する</h2>
            </header>
            <div class="card create-org-card">
                <p id="createOrgError" class="create-org-error" style="display: none;"></p>
                <form id="createOrgForm" method="post" action="#" novalidate>
                    <div class="form-group">
                        <label for="name">組織名 <span class="required">*</span></label>
                        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($name) ?>" placeholder="例: 〇〇株式会社、マイファミリー">
                    </div>
                    <div class="form-group">
                        <label for="type">種別</label>
                        <select id="type" name="type">
                            <option value="corporation" <?= $type === 'corporation' ? 'selected' : '' ?>>企業・団体</option>
                            <option value="family" <?= $type === 'family' ? 'selected' : '' ?>>家族</option>
                            <option value="school" <?= $type === 'school' ? 'selected' : '' ?>>学校</option>
                            <option value="group" <?= $type === 'group' ? 'selected' : '' ?>>その他グループ</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <a href="members.php" class="btn btn-secondary">キャンセル</a>
                        <button type="submit" id="createOrgSubmit" class="btn btn-primary">作成する</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
    <script>
(function() {
    var form = document.getElementById('createOrgForm');
    var errorEl = document.getElementById('createOrgError');
    var submitBtn = document.getElementById('createOrgSubmit');
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var name = (document.getElementById('name').value || '').trim();
        if (!name) {
            errorEl.textContent = '組織名を入力してください。';
            errorEl.style.display = 'block';
            return;
        }
        errorEl.style.display = 'none';
        submitBtn.disabled = true;
        fetch('/admin/api/create-organization.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                name: name,
                type: (document.getElementById('type').value || 'corporation')
            })
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.ok && res.data && res.data.success) {
                window.location.href = 'members.php';
                return;
            }
            var msg = (res.data && res.data.message) ? res.data.message : '作成に失敗しました。';
            if (res.data && res.data.debug_message) {
                msg += ' （詳細: ' + res.data.debug_message + '）';
            }
            errorEl.textContent = msg;
            errorEl.style.display = 'block';
            submitBtn.disabled = false;
        })
        .catch(function() {
            errorEl.textContent = '通信エラーです。';
            errorEl.style.display = 'block';
            submitBtn.disabled = false;
        });
    });
})();
    </script>
</body>
</html>
