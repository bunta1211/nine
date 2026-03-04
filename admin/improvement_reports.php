<?php
/**
 * 改善・デバッグログ（改善提案一覧）
 * 管理者用：提案の一覧・報告者別件数・Cursor用コピー・改善完了通知
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
$currentPage = 'improvement_reports';
require_once __DIR__ . '/_sidebar.php';

requireLogin();
if (!function_exists('isOrgAdminUser') || !isOrgAdminUser()) {
    header('Location: ../chat.php');
    exit;
}

$pdo = getDB();

// テーブル存在チェック
$hasTable = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'improvement_reports'");
    $hasTable = (bool) $stmt->fetch();
} catch (PDOException $e) {}

$reports = [];
$byUser = [];
if ($hasTable) {
    $status = $_GET['status'] ?? '';
    $source = $_GET['source'] ?? '';
    $sql = "SELECT r.*, u.display_name AS reporter_name FROM improvement_reports r LEFT JOIN users u ON r.user_id = u.id WHERE 1=1";
    $params = [];
    if ($status !== '' && in_array($status, ['pending', 'done', 'cancelled'], true)) {
        $sql .= " AND r.status = ?";
        $params[] = $status;
    }
    if ($source !== '' && in_array($source, ['ai_chat', 'manual'], true)) {
        $sql .= " AND r.source = ?";
        $params[] = $source;
    }
    $sql .= " ORDER BY r.created_at DESC LIMIT 200";
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT user_id, COUNT(*) AS cnt FROM improvement_reports GROUP BY user_id ORDER BY cnt DESC");
    $byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($byUser as &$row) {
        if (!empty($row['user_id'])) {
            $s = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $s->execute([$row['user_id']]);
            $row['display_name'] = $s->fetchColumn() ?: '（不明）';
        } else {
            $row['display_name'] = '（手動）';
        }
    }
    unset($row);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>改善・デバッグログ | Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        <?php adminSidebarCSS(); ?>
        .ir-admin-page .main-content * { box-sizing: border-box; margin: 0; padding: 0; }
        .ir-admin-page { font-family: 'Hiragino Sans', 'Meiryo', sans-serif; background: #f0f2f5; min-height: 100vh; }
        .ir-admin-page .header { background: linear-gradient(135deg, #059669, #10b981); padding: 14px 32px; color: white; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .ir-admin-page .header a { color: rgba(255,255,255,0.85); text-decoration: none; font-size: 14px; transition: color 0.15s; }
        .ir-admin-page .header a:hover { color: white; }
        .ir-admin-page .header h1 { font-size: 20px; font-weight: 700; letter-spacing: 0.5px; }
        .ir-admin-page .container { margin: 0 auto; padding: 20px 0; }
        .ir-admin-page .top-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .ir-admin-page .top-row .card-stats { flex: 0 0 260px; }
        .ir-admin-page .top-row .card-filters { flex: 1; }
        .ir-admin-page .card { background: white; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); padding: 20px 24px; margin-bottom: 20px; }
        .ir-admin-page .card h2 { font-size: 15px; font-weight: 700; color: #374151; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #e5e7eb; }
        .ir-admin-page .filters { display: flex; gap: 8px; flex-wrap: wrap; }
        .ir-admin-page .filters a { padding: 7px 16px; background: #f3f4f6; border-radius: 20px; text-decoration: none; color: #4b5563; font-size: 13px; font-weight: 500; transition: all 0.15s; border: 1px solid transparent; }
        .ir-admin-page .filters a:hover { background: #e5e7eb; }
        .ir-admin-page .filters a.active { background: #059669; color: white; border-color: #047857; }
        .ir-admin-page .ir-table-wrap { overflow-x: auto; }
        .ir-admin-page table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .ir-admin-page th { padding: 10px 14px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; position: sticky; top: 0; }
        .ir-admin-page td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #f3f4f6; font-size: 14px; vertical-align: middle; }
        .ir-admin-page tbody tr { transition: background 0.1s; }
        .ir-admin-page tbody tr:hover { background: #f9fafb; }
        .ir-admin-page .col-id { width: 50px; text-align: center; color: #9ca3af; font-weight: 600; }
        .ir-admin-page .col-date { width: 130px; font-size: 13px; color: #6b7280; white-space: nowrap; }
        .ir-admin-page .col-title { min-width: 250px; font-weight: 500; }
        .ir-admin-page .col-reporter { width: 90px; }
        .ir-admin-page .col-location { width: 160px; font-size: 13px; color: #6b7280; }
        .ir-admin-page .col-status { width: 80px; text-align: center; }
        .ir-admin-page .col-actions { width: 220px; white-space: nowrap; }
        .ir-admin-page .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .ir-admin-page .status-pending { background: #fef3c7; color: #92400e; }
        .ir-admin-page .status-done { background: #d1fae5; color: #065f46; }
        .ir-admin-page .status-cancelled { background: #fee2e2; color: #991b1b; }
        .ir-admin-page .btn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 6px; border: 1px solid transparent; cursor: pointer; font-size: 12px; font-weight: 500; text-decoration: none; transition: all 0.15s; }
        .ir-admin-page .btn-copy { background: #f3f4f6; color: #374151; border-color: #d1d5db; }
        .ir-admin-page .btn-copy:hover { background: #e5e7eb; }
        .ir-admin-page .btn-done { background: #059669; color: white; }
        .ir-admin-page .btn-done:hover { background: #047857; }
        .ir-admin-page .btn-detail { background: transparent; color: #6b7280; border-color: #d1d5db; }
        .ir-admin-page .btn-detail:hover { background: #f3f4f6; color: #374151; }
        .ir-admin-page .btn-submit { background: #059669; color: white; padding: 10px 24px; font-size: 14px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
        .ir-admin-page .btn-submit:hover { background: #047857; }
        .ir-admin-page .by-user-list { list-style: none; }
        .ir-admin-page .by-user-list li { padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; display: flex; justify-content: space-between; }
        .ir-admin-page .by-user-list li:last-child { border-bottom: none; }
        .ir-admin-page .by-user-count { font-weight: 700; color: #059669; }
        .ir-admin-page .no-table { color: #b91c1c; padding: 20px; }
        .ir-admin-page .detail-row { display: none; }
        .ir-admin-page .detail-row.open { display: table-row; }
        .ir-admin-page .detail-cell { background: #f8fafc; padding: 20px 24px; }
        .ir-admin-page .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .ir-admin-page .detail-item { }
        .ir-admin-page .detail-item dt { font-weight: 600; color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px; }
        .ir-admin-page .detail-item dd { margin: 0; font-size: 14px; color: #1f2937; line-height: 1.6; white-space: pre-wrap; word-break: break-word; }
        .ir-admin-page .detail-full { grid-column: 1 / -1; }
        .ir-admin-page .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .ir-admin-page .form-group { display: flex; flex-direction: column; gap: 4px; }
        .ir-admin-page .form-group.full { grid-column: 1 / -1; }
        .ir-admin-page .form-label { font-size: 13px; font-weight: 600; color: #374151; }
        .ir-admin-page .form-input, .ir-admin-page .form-textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; transition: border-color 0.15s; }
        .ir-admin-page .form-input:focus, .ir-admin-page .form-textarea:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 2px rgba(5,150,105,0.15); }
        .ir-admin-page .form-textarea { resize: vertical; }
        @media (max-width: 768px) {
            .ir-admin-page .container { padding: 12px; }
            .ir-admin-page .top-row { flex-direction: column; }
            .ir-admin-page .detail-grid, .ir-admin-page .form-grid { grid-template-columns: 1fr; }
            .ir-admin-page .col-actions { white-space: normal; }
        }
    </style>
</head>
<body class="ir-admin-page">
<div class="admin-container">
    <?php adminSidebarHTML($currentPage); ?>
    <main class="main-content">
<div class="header">
    <h1>改善・デバッグログ</h1>
</div>
<div class="container">
    <?php if (!$hasTable): ?>
        <div class="card no-table">
            improvement_reports テーブルがありません。database/improvement_reports.sql を実行してください。
        </div>
    <?php else: ?>
        <div class="top-row">
            <div class="card card-stats">
                <h2>報告者別</h2>
                <?php if (empty($byUser)): ?>
                    <p style="color:#9ca3af;font-size:14px;">まだ提案はありません。</p>
                <?php else: ?>
                    <ul class="by-user-list">
                        <?php foreach ($byUser as $row): ?>
                            <li>
                                <span><?= htmlspecialchars($row['display_name']) ?></span>
                                <span class="by-user-count"><?= (int)$row['cnt'] ?>件</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card card-filters">
                <h2>フィルター</h2>
                <div class="filters">
                    <a href="?" class="<?= empty($status) && empty($source) ? 'active' : '' ?>">すべて</a>
                    <a href="?status=pending" class="<?= ($status ?? '') === 'pending' ? 'active' : '' ?>">未対応</a>
                    <a href="?status=done" class="<?= ($status ?? '') === 'done' ? 'active' : '' ?>">対応済み</a>
                    <a href="?source=ai_chat" class="<?= ($source ?? '') === 'ai_chat' ? 'active' : '' ?>">AI秘書</a>
                    <a href="?source=manual" class="<?= ($source ?? '') === 'manual' ? 'active' : '' ?>">手動</a>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>提案一覧 <span style="font-weight:400;color:#9ca3af;font-size:13px;">（<?= count($reports) ?>件）</span></h2>
            <?php if (empty($reports)): ?>
                <p style="color:#9ca3af;font-size:14px;">該当する提案はありません。</p>
            <?php else: ?>
                <div class="ir-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="col-id">ID</th>
                            <th class="col-date">日時</th>
                            <th class="col-title">タイトル</th>
                            <th class="col-reporter">報告者</th>
                            <th class="col-location">場所</th>
                            <th class="col-status">状態</th>
                            <th class="col-actions">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r):
                            $statusClass = $r['status'] === 'done' ? 'status-done' : ($r['status'] === 'cancelled' ? 'status-cancelled' : 'status-pending');
                            $statusLabel = $r['status'] === 'done' ? '対応済み' : ($r['status'] === 'cancelled' ? '却下' : '未対応');
                            $dateFormatted = date('Y/m/d H:i', strtotime($r['created_at']));
                        ?>
                            <tr data-id="<?= (int)$r['id'] ?>">
                                <td class="col-id"><?= (int)$r['id'] ?></td>
                                <td class="col-date"><?= $dateFormatted ?></td>
                                <td class="col-title"><?= htmlspecialchars(mb_substr($r['title'], 0, 60)) ?></td>
                                <td class="col-reporter"><?= htmlspecialchars($r['reporter_name'] ?? '手動') ?></td>
                                <td class="col-location"><?= htmlspecialchars($r['ui_location'] ?? '-') ?></td>
                                <td class="col-status"><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                <td class="col-actions">
                                    <button type="button" class="btn btn-copy ir-copy-btn" data-id="<?= (int)$r['id'] ?>">📋 コピー</button>
                                    <?php if ($r['status'] === 'pending' && !empty($r['user_id'])): ?>
                                        <button type="button" class="btn btn-done ir-mark-done-btn" data-id="<?= (int)$r['id'] ?>">✓ 完了・通知</button>
                                    <?php elseif ($r['status'] === 'pending'): ?>
                                        <button type="button" class="btn btn-done ir-mark-done-btn" data-id="<?= (int)$r['id'] ?>">✓ 完了</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-detail ir-toggle-detail">▼</button>
                                </td>
                            </tr>
                            <tr class="detail-row" data-detail-id="<?= (int)$r['id'] ?>">
                                <td colspan="7" class="detail-cell">
                                    <div class="detail-grid">
                                        <div class="detail-item detail-full">
                                            <dt>問題の内容</dt>
                                            <dd><?= nl2br(htmlspecialchars($r['problem_summary'])) ?></dd>
                                        </div>
                                        <div class="detail-item">
                                            <dt>想定原因・場所</dt>
                                            <dd><?= nl2br(htmlspecialchars($r['suspected_location'] ?? '-')) ?></dd>
                                        </div>
                                        <div class="detail-item">
                                            <dt>関連ファイル</dt>
                                            <dd><?= nl2br(htmlspecialchars($r['related_files'] ?? '-')) ?></dd>
                                        </div>
                                        <div class="detail-item detail-full">
                                            <dt>望ましい対応・改善計画</dt>
                                            <dd><?= nl2br(htmlspecialchars($r['suggested_fix'] ?? '-')) ?></dd>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>新規提案（手動）</h2>
            <form id="ir-create-form" action="../api/improvement_reports.php" method="post">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">タイトル</label>
                        <input type="text" name="title" required maxlength="255" class="form-input">
                    </div>
                    <div class="form-group full">
                        <label class="form-label">問題の内容</label>
                        <textarea name="problem_summary" required rows="3" class="form-textarea"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">問題の場所（上/左/中央/右パネル等）</label>
                        <input type="text" name="ui_location" maxlength="255" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">関連ファイル（カンマ区切り）</label>
                        <input type="text" name="related_files" maxlength="500" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">想定原因・場所</label>
                        <textarea name="suspected_location" rows="2" class="form-textarea"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">望ましい対応</label>
                        <textarea name="suggested_fix" rows="2" class="form-textarea"></textarea>
                    </div>
                    <div class="form-group full" style="margin-top:8px;">
                        <button type="submit" class="btn-submit">保存</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
    </main>
</div>
<script>
(function() {
    var apiBase = '../api/improvement_reports.php';

    document.querySelectorAll('.ir-toggle-detail').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tr = btn.closest('tr');
            var id = tr.getAttribute('data-id');
            var detail = document.querySelector('.detail-row[data-detail-id="' + id + '"]');
            if (detail) detail.classList.toggle('open');
        });
    });

    document.querySelectorAll('.ir-copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = parseInt(btn.getAttribute('data-id'), 10);
            fetch(apiBase + '?action=get&id=' + id, { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.report) return;
                    var r = data.report;
                    var text = '## 改善・デバッグ提案書（Cursor用）\n- ID: ' + r.id + '\n- 日時: ' + (r.created_at || '') + '\n- 概要: ' + (r.title || '') + '\n\n### 問題の内容\n' + (r.problem_summary || '') + '\n\n### 問題の場所（画面・パネル）\n' + (r.ui_location || '') + '\n\n### 想定される原因・場所\n' + (r.suspected_location || '') + '\n\n### 望ましい対応\n' + (r.suggested_fix || '') + '\n\n### 関連ファイル\n' + (r.related_files || '').replace(/,/g, '\n');
                    navigator.clipboard.writeText(text).then(function() { alert('クリップボードにコピーしました。Cursor に貼り付けてください。'); }).catch(function() { prompt('以下をコピーしてください:', text); });
                })
                .catch(function() { alert('取得に失敗しました'); });
        });
    });

    document.querySelectorAll('.ir-mark-done-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('この提案を「対応済み」にし、報告者に通知しますか？')) return;
            var id = parseInt(btn.getAttribute('data-id'), 10);
            var formData = new FormData();
            formData.append('action', 'mark_done');
            formData.append('report_id', id);
            fetch(apiBase, { method: 'POST', credentials: 'include', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) location.reload();
                    else alert(data.message || '失敗しました');
                })
                .catch(function() { alert('通信エラー'); });
        });
    });

    var form = document.getElementById('ir-create-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var fd = new FormData(form);
            fetch(apiBase, { method: 'POST', credentials: 'include', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { alert('保存しました'); location.reload(); }
                    else alert(data.message || '保存に失敗しました');
                })
                .catch(function() { alert('通信エラー'); });
        });
    }
})();
</script>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>
