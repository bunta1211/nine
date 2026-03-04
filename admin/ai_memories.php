<?php
/**
 * 組織向け AI記憶管理ページ
 * 
 * 専門AIの記憶ストアの確認・修正・追記・検索。
 * 計画書 2.3, 2.4（5）（6）に基づく。
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_specialist_router.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];

$orgStmt = $pdo->prepare("
    SELECT o.id, o.name FROM organizations o
    JOIN organization_members om ON om.organization_id = o.id
    WHERE om.user_id = ? AND om.left_at IS NULL AND om.role IN ('owner','admin')
");
$orgStmt->execute([$userId]);
$orgs = $orgStmt->fetchAll(PDO::FETCH_ASSOC);

$specialistTypes = SpecialistType::LABELS_JA;
unset($specialistTypes['secretary']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI記憶管理 - 管理パネル</title>
    <style>
        <?php adminSidebarCSS(); ?>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; }

        .aimem-page-header { margin-bottom: 24px; }
        .aimem-page-header h2 { font-size: 24px; color: #1e293b; margin-bottom: 4px; }
        .aimem-page-header p { color: #64748b; font-size: 14px; }

        .aimem-filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-end; }
        .aimem-filter-group { display: flex; flex-direction: column; gap: 4px; }
        .aimem-filter-group label { font-size: 12px; color: #64748b; font-weight: 600; }
        .aimem-filter-group select,
        .aimem-filter-group input { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .aimem-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: background 0.2s; }
        .aimem-btn-primary { background: #3b82f6; color: white; }
        .aimem-btn-primary:hover { background: #2563eb; }
        .aimem-btn-success { background: #10b981; color: white; }
        .aimem-btn-success:hover { background: #059669; }
        .aimem-btn-danger { background: #ef4444; color: white; }
        .aimem-btn-danger:hover { background: #dc2626; }
        .aimem-btn-sm { padding: 4px 10px; font-size: 12px; }

        .aimem-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .aimem-stat-card { background: white; border-radius: 8px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .aimem-stat-card .aimem-stat-num { font-size: 28px; font-weight: 700; color: #3b82f6; }
        .aimem-stat-card .aimem-stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }

        .aimem-table-wrap { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }
        .aimem-table { width: 100%; border-collapse: collapse; }
        .aimem-table th, .aimem-table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
        .aimem-table th { background: #f8fafc; font-weight: 600; color: #475569; font-size: 12px; text-transform: uppercase; }
        .aimem-table tr:hover { background: #f8fafc; }
        .aimem-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .aimem-badge-work { background: #dbeafe; color: #1e40af; }
        .aimem-badge-people { background: #dcfce7; color: #166534; }
        .aimem-badge-finance { background: #fef3c7; color: #92400e; }
        .aimem-badge-compliance { background: #fce7f3; color: #9d174d; }
        .aimem-badge-mentalcare { background: #ede9fe; color: #5b21b6; }
        .aimem-badge-education { background: #ccfbf1; color: #115e59; }
        .aimem-badge-customer { background: #fee2e2; color: #991b1b; }

        .aimem-pagination { display: flex; justify-content: center; gap: 8px; padding: 16px; }
        .aimem-pagination button { padding: 6px 12px; border: 1px solid #d1d5db; background: white; border-radius: 4px; cursor: pointer; }
        .aimem-pagination button.active { background: #3b82f6; color: white; border-color: #3b82f6; }
        .aimem-pagination button:disabled { opacity: 0.5; cursor: not-allowed; }

        .aimem-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; justify-content: center; align-items: center; }
        .aimem-modal-overlay.active { display: flex; }
        .aimem-modal { background: white; border-radius: 12px; width: 90%; max-width: 700px; max-height: 80vh; overflow-y: auto; padding: 24px; }
        .aimem-modal h3 { margin-bottom: 16px; font-size: 18px; }
        .aimem-form-group { margin-bottom: 16px; }
        .aimem-form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px; }
        .aimem-form-group input, .aimem-form-group select, .aimem-form-group textarea {
            width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit;
        }
        .aimem-form-group textarea { min-height: 120px; resize: vertical; }
        .aimem-form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }

        .aimem-empty { text-align: center; padding: 40px; color: #94a3b8; }
        .aimem-content-preview { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
<div class="admin-container">
    <?php adminSidebarHTML($currentPage); ?>
    <main class="main-content">
        <div class="aimem-page-header">
            <h2>AI記憶管理</h2>
            <p>専門AIが自動収集した記憶の確認・修正・追記・検索</p>
        </div>

        <div class="aimem-filters">
            <div class="aimem-filter-group">
                <label>組織</label>
                <select id="aimemOrgSelect">
                    <option value="">組織を選択</option>
                    <?php foreach ($orgs as $org): ?>
                    <option value="<?= (int)$org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="aimem-filter-group">
                <label>専門AI</label>
                <select id="aimemTypeSelect">
                    <option value="">すべて</option>
                    <?php foreach ($specialistTypes as $type => $label): ?>
                    <option value="<?= $type ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="aimem-filter-group">
                <label>キーワード</label>
                <input type="text" id="aimemKeyword" placeholder="検索...">
            </div>
            <div class="aimem-filter-group">
                <label>開始日</label>
                <input type="date" id="aimemDateFrom">
            </div>
            <div class="aimem-filter-group">
                <label>終了日</label>
                <input type="date" id="aimemDateTo">
            </div>
            <div class="aimem-filter-group">
                <label>&nbsp;</label>
                <button class="aimem-btn aimem-btn-primary" onclick="aimemSearch()">検索</button>
            </div>
            <div class="aimem-filter-group">
                <label>&nbsp;</label>
                <button class="aimem-btn aimem-btn-success" onclick="aimemOpenCreate()">+ 追記</button>
            </div>
        </div>

        <div class="aimem-stats" id="aimemStats"></div>

        <div class="aimem-table-wrap">
            <table class="aimem-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>専門AI</th>
                        <th>タイトル</th>
                        <th>内容（プレビュー）</th>
                        <th>種別</th>
                        <th>更新日</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="aimemTableBody">
                    <tr><td colspan="7" class="aimem-empty">組織を選択して検索してください</td></tr>
                </tbody>
            </table>
            <div class="aimem-pagination" id="aimemPagination"></div>
        </div>
    </main>
</div>

<div class="aimem-modal-overlay" id="aimemModal">
    <div class="aimem-modal">
        <h3 id="aimemModalTitle">記憶の編集</h3>
        <input type="hidden" id="aimemEditId">
        <div class="aimem-form-group">
            <label>専門AI</label>
            <select id="aimemEditType">
                <?php foreach ($specialistTypes as $type => $label): ?>
                <option value="<?= $type ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="aimem-form-group">
            <label>タイトル</label>
            <input type="text" id="aimemEditTitle" placeholder="簡潔なタイトル">
        </div>
        <div class="aimem-form-group">
            <label>内容</label>
            <textarea id="aimemEditContent" placeholder="記憶の内容"></textarea>
        </div>
        <div class="aimem-form-group">
            <label>タグ（カンマ区切り）</label>
            <input type="text" id="aimemEditTags" placeholder="タグ1, タグ2">
        </div>
        <div class="aimem-form-actions">
            <button class="aimem-btn" onclick="aimemCloseModal()">キャンセル</button>
            <button class="aimem-btn aimem-btn-primary" onclick="aimemSave()">保存</button>
        </div>
    </div>
</div>

<script>
const AIMEM_API = '../api/ai-memories.php';
let aimemCurrentPage = 1;

function aimemSearch(page) {
    aimemCurrentPage = page || 1;
    const orgId = document.getElementById('aimemOrgSelect').value;
    if (!orgId) { alert('組織を選択してください'); return; }

    const params = new URLSearchParams({
        action: 'search',
        organization_id: orgId,
        specialist_type: document.getElementById('aimemTypeSelect').value,
        keyword: document.getElementById('aimemKeyword').value,
        date_from: document.getElementById('aimemDateFrom').value,
        date_to: document.getElementById('aimemDateTo').value,
        page: aimemCurrentPage,
        per_page: 20,
    });

    fetch(AIMEM_API + '?' + params)
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert(data.error); return; }
            aimemRenderTable(data);
            aimemRenderPagination(data);
        })
        .catch(e => console.error(e));
}

const BADGE_CLASSES = {
    work: 'aimem-badge-work', people: 'aimem-badge-people', finance: 'aimem-badge-finance',
    compliance: 'aimem-badge-compliance', mentalcare: 'aimem-badge-mentalcare',
    education: 'aimem-badge-education', customer: 'aimem-badge-customer'
};
const TYPE_LABELS = <?= json_encode($specialistTypes, JSON_UNESCAPED_UNICODE) ?>;

function aimemRenderTable(data) {
    const tbody = document.getElementById('aimemTableBody');
    if (!data.items || data.items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="aimem-empty">記憶がありません</td></tr>';
        return;
    }
    tbody.innerHTML = data.items.map(item => `
        <tr>
            <td>${item.id}</td>
            <td><span class="aimem-badge ${BADGE_CLASSES[item.specialist_type] || ''}">${TYPE_LABELS[item.specialist_type] || item.specialist_type}</span></td>
            <td>${esc(item.title)}</td>
            <td class="aimem-content-preview">${esc(item.content_preview)}</td>
            <td>${item.source_type}</td>
            <td>${item.updated_at ? item.updated_at.substring(0,10) : ''}</td>
            <td>
                <button class="aimem-btn aimem-btn-sm aimem-btn-primary" onclick="aimemEdit(${item.id})">編集</button>
                <button class="aimem-btn aimem-btn-sm aimem-btn-danger" onclick="aimemDelete(${item.id})">削除</button>
            </td>
        </tr>
    `).join('');
}

function aimemRenderPagination(data) {
    const el = document.getElementById('aimemPagination');
    if (data.pages <= 1) { el.innerHTML = `<span style="color:#94a3b8">${data.total}件</span>`; return; }
    let html = `<span style="color:#94a3b8">${data.total}件</span>`;
    html += `<button ${data.page <= 1 ? 'disabled' : ''} onclick="aimemSearch(${data.page - 1})">前</button>`;
    for (let i = 1; i <= data.pages && i <= 10; i++) {
        html += `<button class="${i === data.page ? 'active' : ''}" onclick="aimemSearch(${i})">${i}</button>`;
    }
    html += `<button ${data.page >= data.pages ? 'disabled' : ''} onclick="aimemSearch(${data.page + 1})">次</button>`;
    el.innerHTML = html;
}

function aimemOpenCreate() {
    if (!document.getElementById('aimemOrgSelect').value) { alert('組織を選択してください'); return; }
    document.getElementById('aimemModalTitle').textContent = '記憶の追記';
    document.getElementById('aimemEditId').value = '';
    document.getElementById('aimemEditTitle').value = '';
    document.getElementById('aimemEditContent').value = '';
    document.getElementById('aimemEditTags').value = '';
    document.getElementById('aimemModal').classList.add('active');
}

function aimemEdit(id) {
    const orgId = document.getElementById('aimemOrgSelect').value;
    fetch(AIMEM_API + '?action=get&organization_id=' + orgId + '&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert(data.error); return; }
            document.getElementById('aimemModalTitle').textContent = '記憶の編集';
            document.getElementById('aimemEditId').value = data.id;
            document.getElementById('aimemEditType').value = data.specialist_type;
            document.getElementById('aimemEditTitle').value = data.title;
            document.getElementById('aimemEditContent').value = data.content;
            document.getElementById('aimemEditTags').value = (data.tags || []).join(', ');
            document.getElementById('aimemModal').classList.add('active');
        });
}

function aimemCloseModal() {
    document.getElementById('aimemModal').classList.remove('active');
}

function aimemSave() {
    const orgId = document.getElementById('aimemOrgSelect').value;
    const id = document.getElementById('aimemEditId').value;
    const tags = document.getElementById('aimemEditTags').value.split(',').map(t => t.trim()).filter(Boolean);
    const body = {
        organization_id: orgId,
        specialist_type: document.getElementById('aimemEditType').value,
        title: document.getElementById('aimemEditTitle').value,
        content: document.getElementById('aimemEditContent').value,
        tags: tags,
    };
    if (id) {
        body.id = parseInt(id);
        body.action = 'update';
    } else {
        body.action = 'create';
    }

    fetch(AIMEM_API + '?action=' + body.action + '&organization_id=' + orgId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert(data.error); return; }
        aimemCloseModal();
        aimemSearch(aimemCurrentPage);
    });
}

function aimemDelete(id) {
    if (!confirm('この記憶を削除しますか？（復元可能）')) return;
    const orgId = document.getElementById('aimemOrgSelect').value;
    fetch(AIMEM_API + '?action=delete&organization_id=' + orgId + '&id=' + id, { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert(data.error); return; }
            aimemSearch(aimemCurrentPage);
        });
}

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>
</body>
</html>
