<?php
/**
 * 組織管理画面 - 専門AI管理
 * 
 * 組織向け: 専門AIの設定・プロンプト編集・利用ログ確認
 * システム管理者: デフォルトプロンプト・振り分けルール・機能フラグ管理（タブ表示）
 * 計画書 2.3 に基づく。
 */
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/ai_specialist_router.php';

requireOrgAdmin();

$pageTitle = '専門AI管理';
$currentUser = getCurrentUser();
$pdo = getDB();
$userId = $_SESSION['user_id'];

$currentOrgId = $_SESSION['current_org_id'] ?? null;
if (!$currentOrgId) {
    $stmt = $pdo->prepare("
        SELECT organization_id FROM organization_members
        WHERE user_id = ? AND left_at IS NULL AND role IN ('owner','admin')
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $currentOrgId = $stmt->fetchColumn();
    if ($currentOrgId) {
        $_SESSION['current_org_id'] = $currentOrgId;
    }
}

$isSystemAdmin = false;
try {
    $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $roleStmt->execute([$userId]);
    $userRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
    $isSystemAdmin = $userRow && in_array($userRow['role'] ?? '', ['developer', 'system_admin', 'admin']);
} catch (Throwable $e) {}

$specialistTypes = SpecialistType::LABELS_JA;
unset($specialistTypes['secretary']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';
    header('Content-Type: application/json; charset=utf-8');

    if ($postAction === 'save_org_specialist') {
        $orgId = (int)($_POST['org_id'] ?? 0);
        $type = $_POST['specialist_type'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');
        $prompt = trim($_POST['system_prompt'] ?? '');
        $rules = trim($_POST['custom_rules'] ?? '');
        $enabled = (int)($_POST['is_enabled'] ?? 1);

        $checkStmt = $pdo->prepare("
            SELECT 1 FROM organization_members
            WHERE organization_id = ? AND user_id = ? AND left_at IS NULL AND role IN ('owner','admin')
        ");
        $checkStmt->execute([$orgId, $userId]);
        if (!$checkStmt->fetchColumn() && !$isSystemAdmin) {
            echo json_encode(['error' => '権限がありません'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO org_ai_specialists (organization_id, specialist_type, display_name, system_prompt, custom_rules, is_enabled)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), system_prompt=VALUES(system_prompt),
                                    custom_rules=VALUES(custom_rules), is_enabled=VALUES(is_enabled)
        ");
        $stmt->execute([$orgId, $type, $displayName, $prompt ?: null, $rules ?: null, $enabled]);
        echo json_encode(['message' => '保存しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'save_default' && $isSystemAdmin) {
        $type = $_POST['specialist_type'] ?? '';
        $prompt = trim($_POST['default_prompt'] ?? '');
        $keywords = trim($_POST['intent_keywords'] ?? '');

        $kw = array_filter(array_map('trim', explode(',', $keywords)));
        $stmt = $pdo->prepare("
            INSERT INTO ai_specialist_defaults (specialist_type, default_prompt, intent_keywords, version)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE default_prompt=VALUES(default_prompt), intent_keywords=VALUES(intent_keywords), version=version+1
        ");
        $stmt->execute([$type, $prompt, json_encode($kw, JSON_UNESCAPED_UNICODE)]);
        echo json_encode(['message' => 'デフォルト設定を保存しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'save_feature_flag' && $isSystemAdmin) {
        $featureNum = (int)($_POST['feature_number'] ?? 0);
        $featureName = trim($_POST['feature_name'] ?? '');
        $status = $_POST['status'] ?? 'disabled';

        $stmt = $pdo->prepare("
            INSERT INTO ai_feature_flags (feature_number, feature_name, status, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE feature_name=VALUES(feature_name), status=VALUES(status), updated_by=VALUES(updated_by)
        ");
        $stmt->execute([$featureNum, $featureName, $status, $userId]);
        echo json_encode(['message' => '機能フラグを更新しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => '不明なアクション'], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - 組織管理</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/admin-ai-specialists.css">
    <style>
        .aisp-header { margin-bottom: 24px; }
        .aisp-header h2 { font-size: 24px; color: #1e293b; }
        .aisp-header p { color: #64748b; font-size: 14px; }

        .aisp-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; }
        .aisp-tab { padding: 10px 20px; cursor: pointer; font-size: 14px; font-weight: 600; color: #64748b; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; background: none; }
        .aisp-tab.active { color: #3b82f6; border-bottom-color: #3b82f6; }
        .aisp-tab:hover { color: #3b82f6; }
        .aisp-panel { display: none; }
        .aisp-panel.active { display: block; }

        .aisp-card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 16px; }
        .aisp-card h3 { font-size: 16px; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .aisp-form-group { margin-bottom: 14px; }
        .aisp-form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px; }
        .aisp-form-group input, .aisp-form-group select, .aisp-form-group textarea {
            width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit;
        }
        .aisp-form-group textarea { min-height: 100px; resize: vertical; }
        .aisp-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .aisp-btn-primary { background: #3b82f6; color: white; }
        .aisp-btn-primary:hover { background: #2563eb; }

        .aisp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }
        .aisp-toggle { display: flex; align-items: center; gap: 8px; }
        .aisp-toggle input[type="checkbox"] { width: 18px; height: 18px; }

        .aisp-log-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .aisp-log-table th, .aisp-log-table td { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; text-align: left; }
        .aisp-log-table th { background: #f8fafc; font-weight: 600; color: #475569; }

        .aisp-feature-row { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .aisp-feature-row:last-child { border-bottom: none; }
        .aisp-feature-num { font-weight: 700; color: #3b82f6; width: 30px; }
        .aisp-feature-name { flex: 1; font-size: 14px; }
        .aisp-feature-status select { padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="admin-container">
        <aside class="admin-sidebar">
            <div class="admin-logo">
                <h1>🏢 組織管理</h1>
                <select id="orgSwitcher" class="org-switcher" onchange="switchOrganization(this.value)">
                </select>
            </div>
            <nav class="admin-nav">
                <a href="members.php">👥 メンバー管理</a>
                <a href="groups.php">📁 グループ一覧</a>
                <a href="ai_specialist_admin.php" class="active">🎓 専門AI管理</a>
                <a href="/chat.php">💬 チャットへ戻る</a>
                <a href="/api/auth.php?action=logout">🚪 ログアウト</a>
            </nav>
            <div class="admin-user">
                <p>👤 <?= htmlspecialchars($currentUser['display_name'] ?? '') ?></p>
            </div>
        </aside>

        <main class="admin-main">
        <div class="aisp-header">
            <h2>🎓 専門AI管理</h2>
            <p>組織の専門AI設定・カスタムプロンプト・利用ログを管理</p>
        </div>

        <div class="aisp-tabs">
            <div class="aisp-tab active" data-tab="org">組織向け設定</div>
            <div class="aisp-tab" data-tab="logs">利用ログ</div>
            <?php if ($isSystemAdmin): ?>
            <div class="aisp-tab" data-tab="defaults">デフォルト設定</div>
            <div class="aisp-tab" data-tab="features">機能フラグ</div>
            <?php endif; ?>
        </div>

        <!-- 組織向け設定 -->
        <div class="aisp-panel active" id="panelOrg">
            <div class="aisp-grid" id="aispOrgCards"></div>
        </div>

        <!-- 利用ログ -->
        <div class="aisp-panel" id="panelLogs">
            <div class="aisp-card">
                <h3>専門AI利用ログ（直近100件）</h3>
                <table class="aisp-log-table">
                    <thead><tr><th>日時</th><th>ユーザー</th><th>専門AI</th><th>質問</th><th>応答</th></tr></thead>
                    <tbody id="aispLogBody"><tr><td colspan="5" style="text-align:center;color:#94a3b8">データを読み込み中...</td></tr></tbody>
                </table>
            </div>
        </div>

        <?php if ($isSystemAdmin): ?>
        <!-- デフォルト設定 -->
        <div class="aisp-panel" id="panelDefaults">
            <div class="aisp-grid" id="aispDefaultCards">
                <?php foreach ($specialistTypes as $type => $label): ?>
                <div class="aisp-card">
                    <h3><?= htmlspecialchars($label) ?></h3>
                    <form onsubmit="aispSaveDefault(event, '<?= $type ?>')">
                        <div class="aisp-form-group">
                            <label>デフォルトプロンプト</label>
                            <textarea id="defPrompt_<?= $type ?>" placeholder="この専門AIのデフォルトシステムプロンプト"></textarea>
                        </div>
                        <div class="aisp-form-group">
                            <label>振り分けキーワード（カンマ区切り）</label>
                            <input type="text" id="defKeywords_<?= $type ?>" placeholder="キーワード1, キーワード2">
                        </div>
                        <button type="submit" class="aisp-btn aisp-btn-primary">保存</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 機能フラグ -->
        <div class="aisp-panel" id="panelFeatures">
            <div class="aisp-card">
                <h3>機能フラグ管理（機能1〜33）</h3>
                <p style="color:#64748b;font-size:13px;margin-bottom:16px">各機能の有効/ベータ/無効を切り替え</p>
                <div id="aispFeatureList"></div>
            </div>
        </div>
        <?php endif; ?>
        </main>
    </div>

<script>
const SPEC_LABELS = <?= json_encode($specialistTypes, JSON_UNESCAPED_UNICODE) ?>;
const IS_SYSTEM_ADMIN = <?= $isSystemAdmin ? 'true' : 'false' ?>;
let currentOrgId = <?= (int)$currentOrgId ?>;

// 組織切り替え
async function loadMyOrganizations() {
    try {
        const response = await fetch('/admin/api/my-organizations.php');
        const data = await response.json();
        if (data.success && data.organizations.length > 0) {
            const select = document.getElementById('orgSwitcher');
            select.innerHTML = data.organizations.map(org => {
                const typeIcon = org.type === 'corporation' ? '🏢' : (org.type === 'family' ? '👨‍👩‍👧' : '👥');
                const ownerBadge = org.relationship === 'owner' ? ' ★' : '';
                const selected = org.id === data.current_org_id ? 'selected' : '';
                return `<option value="${org.id}" ${selected}>${typeIcon} ${org.name}${ownerBadge}</option>`;
            }).join('');
        }
    } catch (error) {
        console.error('組織一覧の取得に失敗:', error);
    }
}

async function switchOrganization(orgId) {
    try {
        const response = await fetch('/admin/api/switch-organization.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ organization_id: parseInt(orgId) })
        });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    } catch (error) {
        console.error('組織切り替えに失敗:', error);
    }
}

// タブ切り替え
document.querySelectorAll('.aisp-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.aisp-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.aisp-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel' + tab.dataset.tab.charAt(0).toUpperCase() + tab.dataset.tab.slice(1)).classList.add('active');
    });
});

// 組織向け設定を読み込み
function aispLoadOrgSettings() {
    if (!currentOrgId) {
        document.getElementById('aispOrgCards').innerHTML = '<p style="color:#94a3b8">組織を選択してください</p>';
        return;
    }

    let html = '';
    Object.entries(SPEC_LABELS).forEach(([type, label]) => {
        html += `
        <div class="aisp-card">
            <h3>${label}</h3>
            <form onsubmit="aispSaveOrg(event, '${type}')">
                <div class="aisp-form-group">
                    <label>表示名</label>
                    <input type="text" id="orgName_${type}" value="${label}" placeholder="組織での呼び名">
                </div>
                <div class="aisp-form-group">
                    <label>カスタムプロンプト（空欄はデフォルト使用）</label>
                    <textarea id="orgPrompt_${type}" placeholder="組織固有のシステムプロンプト"></textarea>
                </div>
                <div class="aisp-form-group">
                    <label>ルール・ポリシー</label>
                    <textarea id="orgRules_${type}" placeholder="この専門AI向けの社内ルール"></textarea>
                </div>
                <div class="aisp-toggle">
                    <input type="checkbox" id="orgEnabled_${type}" checked>
                    <label for="orgEnabled_${type}">有効</label>
                </div>
                <br>
                <button type="submit" class="aisp-btn aisp-btn-primary">保存</button>
            </form>
        </div>`;
    });
    document.getElementById('aispOrgCards').innerHTML = html;

    // 既存設定を読み込み
    fetch('/api/ai-specialists.php?action=list&organization_id=' + currentOrgId)
        .then(r => r.json())
        .then(data => {
            if (data.specialists) {
                data.specialists.forEach(s => {
                    const nameEl = document.getElementById('orgName_' + s.specialist_type);
                    const promptEl = document.getElementById('orgPrompt_' + s.specialist_type);
                    const rulesEl = document.getElementById('orgRules_' + s.specialist_type);
                    const enabledEl = document.getElementById('orgEnabled_' + s.specialist_type);
                    if (nameEl && s.display_name) nameEl.value = s.display_name;
                    if (promptEl && s.system_prompt) promptEl.value = s.system_prompt;
                    if (rulesEl && s.custom_rules) rulesEl.value = s.custom_rules;
                    if (enabledEl) enabledEl.checked = (s.is_enabled == 1);
                });
            }
        })
        .catch(() => {});
}

function aispSaveOrg(e, type) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('post_action', 'save_org_specialist');
    fd.append('org_id', currentOrgId);
    fd.append('specialist_type', type);
    fd.append('display_name', document.getElementById('orgName_' + type).value);
    fd.append('system_prompt', document.getElementById('orgPrompt_' + type).value);
    fd.append('custom_rules', document.getElementById('orgRules_' + type).value);
    fd.append('is_enabled', document.getElementById('orgEnabled_' + type).checked ? 1 : 0);

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => alert(d.message || d.error));
}

function aispSaveDefault(e, type) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('post_action', 'save_default');
    fd.append('specialist_type', type);
    fd.append('default_prompt', document.getElementById('defPrompt_' + type).value);
    fd.append('intent_keywords', document.getElementById('defKeywords_' + type).value);

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => alert(d.message || d.error));
}

function aispLoadLogs() {
    if (!currentOrgId) return;
    const tbody = document.getElementById('aispLogBody');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8">ログデータを読み込み中...</td></tr>';

    fetch('/api/ai-specialists.php?action=stats&organization_id=' + currentOrgId)
        .then(r => r.json())
        .then(data => {
            if (data.logs && data.logs.length > 0) {
                tbody.innerHTML = data.logs.map(l => `
                    <tr>
                        <td>${l.created_at || ''}</td>
                        <td>${l.user_name || ''}</td>
                        <td>${SPEC_LABELS[l.specialist_type] || l.specialist_type}</td>
                        <td>${(l.query_summary || '').substring(0, 60)}</td>
                        <td>${(l.response_summary || '').substring(0, 60)}</td>
                    </tr>
                `).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8">ログデータがありません</td></tr>';
            }
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8">ログの取得に失敗しました</td></tr>';
        });
}

if (IS_SYSTEM_ADMIN) {
    const FEATURES = [
        {n:1,name:'業務内容をマニュアル化'},{n:2,name:'やり方を教える＋注意事項'},{n:3,name:'マイク録音＋できる人の記録'},
        {n:4,name:'会議設定'},{n:5,name:'リマインダー・締切の伝達'},{n:6,name:'出張・外出の共有'},
        {n:7,name:'業務報告の集約'},{n:8,name:'プロジェクト進捗の一元化'},{n:9,name:'障害・クレームの初動共有'},
        {n:10,name:'他者へのタスク依頼'},{n:11,name:'負荷の平準化'},{n:12,name:'引き継ぎの半自動化'},
        {n:13,name:'Q&A集約＋顧客質問対応'},{n:14,name:'定例の欠席連絡'},{n:15,name:'過去のやり方の照会'},
        {n:16,name:'誰が詳しいかの特定'},{n:17,name:'イベント希望日集約'},{n:18,name:'誕生日・記念日'},
        {n:19,name:'会社全体の集合知AI'},{n:20,name:'顧客情報を積み上げるAI'},
        {n:21,name:'入社オンボーディング'},{n:22,name:'引き継ぎチェックリスト'},{n:23,name:'コンプライアンスリマインド'},
        {n:24,name:'監査用トレース'},{n:25,name:'負荷・ストレス可視化'},{n:26,name:'リモート・ハイブリッドすり合わせ'},
        {n:27,name:'スキル・経験可視化'},{n:28,name:'研修・資格リマインド'},{n:29,name:'稟議ルーティング提案'},
        {n:30,name:'繰り返し問い合わせ分析'},{n:31,name:'取引先窓口の知の継承'},{n:32,name:'働き方匿名集計'},
        {n:33,name:'運営への自動通報'},
    ];
    const listEl = document.getElementById('aispFeatureList');
    if (listEl) {
        listEl.innerHTML = FEATURES.map(f => `
            <div class="aisp-feature-row">
                <span class="aisp-feature-num">${f.n}</span>
                <span class="aisp-feature-name">${f.name}</span>
                <span class="aisp-feature-status">
                    <select id="feat_${f.n}" onchange="aispSaveFeature(${f.n}, '${f.name}')">
                        <option value="disabled">無効</option>
                        <option value="beta">ベータ</option>
                        <option value="enabled" selected>有効</option>
                    </select>
                </span>
            </div>
        `).join('');

        fetch('/api/ai-specialists.php?action=flags')
            .then(r => r.json())
            .then(data => {
                if (data.flags) {
                    data.flags.forEach(f => {
                        const sel = document.getElementById('feat_' + f.feature_number);
                        if (sel) sel.value = f.status || 'enabled';
                    });
                }
            })
            .catch(() => {});
    }
}

function aispSaveFeature(num, name) {
    const status = document.getElementById('feat_' + num).value;
    const fd = new FormData();
    fd.append('post_action', 'save_feature_flag');
    fd.append('feature_number', num);
    fd.append('feature_name', name);
    fd.append('status', status);
    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { /* silent save */ });
}

document.addEventListener('DOMContentLoaded', () => {
    loadMyOrganizations();
    aispLoadOrgSettings();
});
</script>
</body>
</html>
