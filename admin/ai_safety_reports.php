<?php
/**
 * AI安全通報管理ページ
 * 
 * 運営責任者（KEN）向け。
 * 社会通念違反・生命の危機・いじめ等の自動通報の確認・追加質問。
 * 計画書 6.1: 運営責任者のみアクセス可能。
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

if (!isLoggedIn() || !isOrgAdminUser()) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];

$isSystemAdmin = false;
try {
    $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $roleStmt->execute([$userId]);
    $userRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
    $isSystemAdmin = $userRow && $userRow['role'] === 'admin';
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI安全通報 - 管理パネル</title>
    <style>
        <?php adminSidebarCSS(); ?>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; }

        .asr-header { margin-bottom: 24px; }
        .asr-header h2 { font-size: 24px; color: #1e293b; }
        .asr-header p { color: #64748b; font-size: 14px; }

        .asr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .asr-stat { background: white; border-radius: 8px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .asr-stat .num { font-size: 28px; font-weight: 700; }
        .asr-stat .lbl { font-size: 11px; color: #64748b; margin-top: 4px; }
        .asr-stat-critical .num { color: #dc2626; }
        .asr-stat-new .num { color: #f59e0b; }
        .asr-stat-reviewing .num { color: #3b82f6; }
        .asr-stat-resolved .num { color: #10b981; }

        .asr-filters { display: flex; gap: 12px; margin-bottom: 16px; }
        .asr-filters select { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }

        .asr-table-wrap { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }
        .asr-table { width: 100%; border-collapse: collapse; }
        .asr-table th, .asr-table td { padding: 10px 14px; border-bottom: 1px solid #e5e7eb; font-size: 13px; text-align: left; }
        .asr-table th { background: #f8fafc; font-weight: 600; color: #475569; }
        .asr-table tr:hover { background: #f8fafc; cursor: pointer; }
        .asr-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .asr-badge-critical { background: #fef2f2; color: #dc2626; }
        .asr-badge-high { background: #fff7ed; color: #ea580c; }
        .asr-badge-medium { background: #fefce8; color: #ca8a04; }
        .asr-badge-low { background: #f0fdf4; color: #16a34a; }
        .asr-badge-new { background: #fef3c7; color: #92400e; }
        .asr-badge-reviewing { background: #dbeafe; color: #1e40af; }
        .asr-badge-resolved { background: #dcfce7; color: #166534; }
        .asr-badge-dismissed { background: #f3f4f6; color: #6b7280; }

        .asr-type-label { font-size: 12px; font-weight: 600; }
        .asr-type-social_norm { color: #dc2626; }
        .asr-type-life_danger { color: #b91c1c; }
        .asr-type-bullying { color: #9333ea; }

        .asr-detail-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; overflow-y: auto; }
        .asr-detail-overlay.active { display: flex; justify-content: center; padding: 40px 20px; }
        .asr-detail { background: white; border-radius: 12px; width: 100%; max-width: 800px; padding: 24px; height: fit-content; }
        .asr-detail h3 { font-size: 18px; margin-bottom: 16px; }
        .asr-detail-section { margin-bottom: 16px; }
        .asr-detail-section h4 { font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 6px; text-transform: uppercase; }
        .asr-detail-section pre { background: #f8fafc; padding: 12px; border-radius: 6px; font-size: 13px; white-space: pre-wrap; word-break: break-all; max-height: 300px; overflow-y: auto; font-family: inherit; }

        .asr-question-form { display: flex; gap: 8px; margin-top: 12px; }
        .asr-question-form input { flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .asr-question-item { background: #f8fafc; border-radius: 8px; padding: 12px; margin-top: 8px; }
        .asr-question-item .q { font-weight: 600; color: #1e293b; margin-bottom: 4px; }
        .asr-question-item .a { color: #374151; font-size: 13px; }
        .asr-question-item .meta { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .asr-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .asr-btn-primary { background: #3b82f6; color: white; }
        .asr-btn-primary:hover { background: #2563eb; }
        .asr-btn-sm { padding: 4px 10px; font-size: 12px; }
        .asr-actions { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
        .asr-actions select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; }

        .asr-empty { text-align: center; padding: 40px; color: #94a3b8; }
        <?php if (!$isSystemAdmin): ?>
        .asr-admin-only { display: none; }
        <?php endif; ?>
    </style>
</head>
<body>
<div class="admin-container">
    <?php adminSidebarHTML($currentPage); ?>
    <main class="main-content">
        <div class="asr-header">
            <h2>AI安全通報</h2>
            <p>社会通念違反・生命の危機・いじめ等の自動通報を確認<?= $isSystemAdmin ? '（運営責任者権限）' : '' ?></p>
        </div>

        <div class="asr-stats" id="asrStats"></div>

        <?php if ($isSystemAdmin): ?>
        <div class="asr-filters">
            <select id="asrStatusFilter" onchange="asrLoad()">
                <option value="">すべて</option>
                <option value="new" selected>未対応</option>
                <option value="reviewing">対応中</option>
                <option value="resolved">解決済み</option>
                <option value="dismissed">却下</option>
            </select>
        </div>

        <div class="asr-table-wrap">
            <table class="asr-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>日時</th>
                        <th>種別</th>
                        <th>重大度</th>
                        <th>ユーザー</th>
                        <th>要約</th>
                        <th>ステータス</th>
                    </tr>
                </thead>
                <tbody id="asrTableBody">
                    <tr><td colspan="7" class="asr-empty">読み込み中...</td></tr>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="asr-empty" style="background:white;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
            <p>この機能は運営責任者のみがアクセスできます。</p>
            <p style="font-size:13px;margin-top:8px">その他の運営人員には記憶・通報の参照権限は付与されていません。</p>
        </div>
        <?php endif; ?>
    </main>
</div>

<div class="asr-detail-overlay" id="asrDetailOverlay" onclick="if(event.target===this)asrCloseDetail()">
    <div class="asr-detail" id="asrDetailContent"></div>
</div>

<?php if ($isSystemAdmin): ?>
<script>
const API = '../api/ai-safety.php';
const TYPE_LABELS = { social_norm: '社会通念違反', life_danger: '生命の危機', bullying: 'いじめ', other: 'その他' };

function asrLoad() {
    fetch(API + '?action=stats')
        .then(r => r.json())
        .then(stats => {
            document.getElementById('asrStats').innerHTML = `
                <div class="asr-stat asr-stat-critical"><div class="num">${stats.critical_new}</div><div class="lbl">緊急（未対応）</div></div>
                <div class="asr-stat asr-stat-new"><div class="num">${stats.new_count}</div><div class="lbl">未対応</div></div>
                <div class="asr-stat asr-stat-reviewing"><div class="num">${stats.reviewing_count}</div><div class="lbl">対応中</div></div>
                <div class="asr-stat asr-stat-resolved"><div class="num">${stats.resolved_count}</div><div class="lbl">解決済み</div></div>
            `;
        });

    const status = document.getElementById('asrStatusFilter').value;
    const params = new URLSearchParams({ action: 'list', limit: 100 });
    if (status) params.set('status', status);

    fetch(API + '?' + params)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('asrTableBody');
            if (!data.reports || data.reports.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="asr-empty">通報はありません</td></tr>';
                return;
            }
            tbody.innerHTML = data.reports.map(r => `
                <tr onclick="asrShowDetail(${r.id})">
                    <td>${r.id}</td>
                    <td>${(r.created_at||'').substring(0,16)}</td>
                    <td><span class="asr-type-label asr-type-${r.report_type}">${TYPE_LABELS[r.report_type]||r.report_type}</span></td>
                    <td><span class="asr-badge asr-badge-${r.severity}">${r.severity}</span></td>
                    <td>${esc(r.user_display_name||r.username||'')}</td>
                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.summary)}</td>
                    <td><span class="asr-badge asr-badge-${r.status}">${r.status}</span></td>
                </tr>
            `).join('');
        });
}

function asrShowDetail(id) {
    fetch(API + '?action=detail&id=' + id)
        .then(r => r.json())
        .then(r => {
            if (r.error) { alert(r.error); return; }
            const socialCtx = typeof r.user_social_context === 'object' ? JSON.stringify(r.user_social_context, null, 2) : (r.user_social_context || '');
            const personality = typeof r.user_personality_snapshot === 'object' ? JSON.stringify(r.user_personality_snapshot, null, 2) : (r.user_personality_snapshot || '');

            let questionsHtml = '';
            if (r.questions && r.questions.length > 0) {
                questionsHtml = r.questions.map(q => `
                    <div class="asr-question-item">
                        <div class="q">Q: ${esc(q.question)}</div>
                        <div class="a">${q.answer ? 'A: ' + esc(q.answer) : '（回答待ち）'}</div>
                        <div class="meta">${q.asked_by_name || ''} - ${(q.created_at||'').substring(0,16)}</div>
                    </div>
                `).join('');
            }

            document.getElementById('asrDetailContent').innerHTML = `
                <h3>通報 #${r.id} - ${TYPE_LABELS[r.report_type]||r.report_type}
                    <span class="asr-badge asr-badge-${r.severity}" style="margin-left:8px">${r.severity}</span>
                    <span class="asr-badge asr-badge-${r.status}" style="margin-left:4px">${r.status}</span>
                </h3>

                <div class="asr-detail-section">
                    <h4>要約</h4>
                    <pre>${esc(r.summary)}</pre>
                </div>
                <div class="asr-detail-section">
                    <h4>AIの判断理由</h4>
                    <pre>${esc(r.ai_reasoning)}</pre>
                </div>
                <div class="asr-detail-section">
                    <h4>生コンテキスト（前後の文脈・判断した生文章）</h4>
                    <pre>${esc(r.raw_context)}</pre>
                </div>
                <div class="asr-detail-section">
                    <h4>ユーザーの社会的立場・所属</h4>
                    <pre>${esc(socialCtx)}</pre>
                </div>
                <div class="asr-detail-section">
                    <h4>性格分析スナップショット</h4>
                    <pre>${esc(personality)}</pre>
                </div>

                <div class="asr-actions">
                    <select id="asrNewStatus">
                        <option value="new"${r.status==='new'?' selected':''}>未対応</option>
                        <option value="reviewing"${r.status==='reviewing'?' selected':''}>対応中</option>
                        <option value="resolved"${r.status==='resolved'?' selected':''}>解決済み</option>
                        <option value="dismissed"${r.status==='dismissed'?' selected':''}>却下</option>
                    </select>
                    <input type="text" id="asrNotes" placeholder="メモ" style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px">
                    <button class="asr-btn asr-btn-primary asr-btn-sm" onclick="asrUpdateStatus(${r.id})">ステータス更新</button>
                </div>

                <div class="asr-detail-section" style="margin-top:20px">
                    <h4>秘書への追加質問</h4>
                    ${questionsHtml}
                    <div class="asr-question-form">
                        <input type="text" id="asrQuestion" placeholder="秘書に追加質問を送る...">
                        <button class="asr-btn asr-btn-primary asr-btn-sm" onclick="asrAskQuestion(${r.id})">質問</button>
                    </div>
                </div>

                <div style="margin-top:16px;text-align:right">
                    <button class="asr-btn" onclick="asrCloseDetail()">閉じる</button>
                </div>
            `;
            document.getElementById('asrDetailOverlay').classList.add('active');
        });
}

function asrCloseDetail() {
    document.getElementById('asrDetailOverlay').classList.remove('active');
}

function asrUpdateStatus(id) {
    const status = document.getElementById('asrNewStatus').value;
    const notes = document.getElementById('asrNotes').value;
    fetch(API + '?action=update_status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, status, notes }),
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) alert(d.error);
        else { asrCloseDetail(); asrLoad(); }
    });
}

function asrAskQuestion(reportId) {
    const question = document.getElementById('asrQuestion').value.trim();
    if (!question) return;
    document.getElementById('asrQuestion').value = '';

    fetch(API + '?action=ask_question', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ report_id: reportId, question }),
    })
    .then(r => r.json())
    .then(d => {
        if (d.error) { alert(d.error); return; }
        asrShowDetail(reportId);
    });
}

function esc(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

asrLoad();
</script>
<?php endif; ?>
</body>
</html>
