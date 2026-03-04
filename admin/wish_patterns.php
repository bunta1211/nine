<?php
/**
 * Wish抽出パターン管理画面
 * 管理者用：AIがチャットからWishを抽出するパターンを設定
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

requireLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];

// 管理者権限チェック
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !in_array($user['role'], ['admin', 'support'])) {
    header('Location: ../chat.php');
    exit;
}

// パターン一覧取得
$patterns = [];
$stmt = $pdo->query("SHOW TABLES LIKE 'wish_patterns'");
if ($stmt->fetch()) {
    $stmt = $pdo->query("SELECT * FROM wish_patterns ORDER BY priority DESC, id ASC");
    $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ユーザー提案一覧取得（グループ化）
$suggestions = [];
$popularSuggestions = [];
$stmt = $pdo->query("SHOW TABLES LIKE 'wish_pattern_suggestions'");
if ($stmt->fetch()) {
    $stmt = $pdo->query("
        SELECT 
            extracted_wish,
            suggested_category,
            COUNT(*) as suggestion_count,
            COUNT(DISTINCT user_id) as unique_users,
            MAX(original_text) as sample_text,
            MAX(id) as latest_id,
            MAX(created_at) as last_suggested
        FROM wish_pattern_suggestions
        WHERE status = 'pending'
        GROUP BY extracted_wish, suggested_category
        ORDER BY suggestion_count DESC, last_suggested DESC
        LIMIT 50
    ");
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 人気の提案（3回以上、2人以上）
    $stmt = $pdo->query("
        SELECT 
            extracted_wish,
            suggested_category,
            COUNT(*) as suggestion_count,
            COUNT(DISTINCT user_id) as unique_users
        FROM wish_pattern_suggestions
        WHERE status = 'pending'
        GROUP BY extracted_wish, suggested_category
        HAVING suggestion_count >= 3 AND unique_users >= 2
        ORDER BY suggestion_count DESC
        LIMIT 10
    ");
    $popularSuggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$categories = [
    'request' => '依頼',
    'desire' => '願望',
    'want' => '欲しい',
    'travel' => '旅行',
    'purchase' => '購入',
    'work' => 'やること',
    'other' => 'その他'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wish抽出パターン管理 | Social9</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Hiragino Sans', 'Meiryo', sans-serif; background: var(--bg-secondary); min-height: 100vh; }
        
        .header {
            background: linear-gradient(135deg, #059669, #10b981);
            padding: 16px 24px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-left .back-btn {
            width: 40px; height: 40px;
            background: rgba(255,255,255,0.2);
            border: none; border-radius: 10px;
            color: white; font-size: 18px;
            cursor: pointer;
        }
        .header-left h1 { font-size: 20px; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        
        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 20px;
        }
        .section-header h2 { font-size: 18px; }
        
        .btn {
            padding: 10px 20px;
            border: none; border-radius: 8px;
            cursor: pointer; font-size: 14px;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: white; border: 1px solid var(--border-light); }
        .btn-danger { background: #dc2626; color: white; }
        .btn:hover { opacity: 0.9; }
        
        /* テスト入力エリア */
        .test-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }
        .test-section h3 { font-size: 16px; margin-bottom: 12px; }
        .test-input {
            display: flex; gap: 12px;
        }
        .test-input textarea {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
        }
        .test-result {
            margin-top: 16px;
            padding: 16px;
            background: #f0fdf4;
            border-radius: 8px;
            display: none;
        }
        .test-result.active { display: block; }
        .test-result.error { background: #fef2f2; }
        .test-result-item {
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .test-result-item:last-child { margin-bottom: 0; }
        
        /* パターンテーブル */
        .pattern-table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .pattern-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .pattern-table th, .pattern-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-light);
        }
        .pattern-table th {
            background: var(--bg-secondary);
            font-size: 13px;
            font-weight: 600;
        }
        .pattern-table tr:hover { background: #f9fafb; }
        
        .pattern-code {
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 12px;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .category-badge {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            background: #e0f2fe;
            color: #0369a1;
        }
        
        .status-active { color: #16a34a; }
        .status-inactive { color: #9ca3af; }
        
        .priority-num {
            font-weight: 600;
            color: var(--primary);
        }
        
        .action-btns { display: flex; gap: 8px; }
        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
        }
        .action-btn.edit { background: #dbeafe; color: #1d4ed8; }
        .action-btn.delete { background: #fee2e2; color: #dc2626; }
        
        /* モーダル */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center; justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 16px;
            width: 90%; max-width: 600px;
            max-height: 90vh;
            overflow: hidden;
        }
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 { font-size: 18px; }
        .modal-close {
            background: none; border: none;
            font-size: 24px; cursor: pointer;
            color: var(--text-muted);
        }
        .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-light);
            display: flex; gap: 12px;
            justify-content: flex-end;
        }
        
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 6px;
        }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        
        /* タブ */
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            background: #f3f4f6;
            padding: 4px;
            border-radius: 10px;
            width: fit-content;
        }
        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .tab-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        /* 提案カード */
        .suggestion-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .suggestion-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .suggestion-count {
            background: linear-gradient(135deg, #059669, #10b981);
            color: white;
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        .suggestion-count .num { font-size: 18px; }
        .suggestion-count .label { font-size: 9px; opacity: 0.8; }
        .suggestion-info { flex: 1; }
        .suggestion-wish {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .suggestion-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .suggestion-sample {
            font-size: 13px;
            background: #f9fafb;
            padding: 8px 12px;
            border-radius: 6px;
            color: #666;
        }
        .suggestion-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .suggestion-actions .btn { white-space: nowrap; }
        
        /* 人気提案バナー */
        .popular-banner {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .popular-banner .icon { font-size: 32px; }
        .popular-banner .text h3 { font-size: 16px; margin-bottom: 4px; }
        .popular-banner .text p { font-size: 13px; color: #92400e; }
        .popular-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .popular-tag {
            background: white;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: 1px solid #fcd34d;
        }
        .popular-tag:hover { background: #fffbeb; }
        .popular-tag .count {
            background: #f59e0b;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <button class="back-btn" onclick="location.href='../settings.php'">←</button>
            <h1>⭐ Wish抽出パターン管理</h1>
        </div>
    </header>
    
    <div class="container">
        <!-- 人気提案バナー -->
        <?php if (!empty($popularSuggestions)): ?>
        <div class="popular-banner">
            <div class="icon">🔥</div>
            <div class="text">
                <h3>人気のユーザー提案</h3>
                <p>複数のユーザーから同じ提案がありました。パターン化を検討してください。</p>
                <div class="popular-list">
                    <?php foreach ($popularSuggestions as $pop): ?>
                    <span class="popular-tag" onclick="showSuggestionDetail('<?= htmlspecialchars($pop['extracted_wish'], ENT_QUOTES) ?>')">
                        <?= htmlspecialchars($pop['extracted_wish']) ?>
                        <span class="count"><?= (int)$pop['suggestion_count'] ?>回</span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- タブ -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('patterns')">📋 登録パターン</button>
            <button class="tab-btn" onclick="switchTab('suggestions')">
                💡 ユーザー提案
                <?php if (count($suggestions) > 0): ?>
                <span style="background:#ef4444;color:white;padding:2px 8px;border-radius:10px;font-size:11px;margin-left:4px;"><?= count($suggestions) ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" onclick="switchTab('test')">🧪 テスト</button>
        </div>
        
        <!-- パターン一覧タブ -->
        <div class="tab-content active" id="tab-patterns">
            <div class="section-header">
                <h2>登録パターン一覧</h2>
                <button class="btn btn-primary" onclick="openPatternModal()">➕ パターン追加</button>
            </div>
        
        <?php if (empty($patterns)): ?>
        <div class="empty-state">
            <p>パターンが登録されていません。<br>データベースにwish_patternsテーブルを作成し、初期データを投入してください。</p>
        </div>
        <?php else: ?>
        <div class="pattern-table">
            <table>
                <thead>
                    <tr>
                        <th>優先度</th>
                        <th>カテゴリ</th>
                        <th>パターン（正規表現）</th>
                        <th>例文</th>
                        <th>状態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patterns as $p): ?>
                    <tr>
                        <td><span class="priority-num"><?= (int)$p['priority'] ?></span></td>
                        <td><span class="category-badge"><?= htmlspecialchars($categories[$p['category']] ?? $p['category']) ?></span></td>
                        <td><code class="pattern-code" title="<?= htmlspecialchars($p['pattern']) ?>"><?= htmlspecialchars($p['pattern']) ?></code></td>
                        <td>
                            <?php if ($p['example_input']): ?>
                            <small><?= htmlspecialchars(mb_substr($p['example_input'], 0, 20)) ?>... → <?= htmlspecialchars($p['example_output']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['is_active']): ?>
                            <span class="status-active">✓ 有効</span>
                            <?php else: ?>
                            <span class="status-inactive">— 無効</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="action-btn edit" onclick="editPattern(<?= $p['id'] ?>)">編集</button>
                                <button class="action-btn delete" onclick="deletePattern(<?= $p['id'] ?>)">削除</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        </div>
        
        <!-- ユーザー提案タブ -->
        <div class="tab-content" id="tab-suggestions">
            <div class="section-header">
                <h2>ユーザー提案一覧</h2>
                <p style="color: var(--text-muted); font-size: 13px;">ユーザーが手動で登録したWishがここに表示されます。よく使われるものはパターン化を検討してください。</p>
            </div>
            
            <?php if (empty($suggestions)): ?>
            <div class="empty-state">
                <p>ユーザー提案はまだありません。<br>ユーザーがチャットからWishを手動登録すると、ここに表示されます。</p>
            </div>
            <?php else: ?>
            <div class="suggestion-list">
                <?php foreach ($suggestions as $s): ?>
                <div class="suggestion-card">
                    <div class="suggestion-count">
                        <span class="num"><?= (int)$s['suggestion_count'] ?></span>
                        <span class="label">件</span>
                    </div>
                    <div class="suggestion-info">
                        <div class="suggestion-wish"><?= htmlspecialchars($s['extracted_wish']) ?></div>
                        <div class="suggestion-meta">
                            <span class="category-badge"><?= htmlspecialchars($categories[$s['suggested_category']] ?? $s['suggested_category']) ?></span>
                            ・ユーザー <?= (int)$s['unique_users'] ?>人から提案
                            ・最終提案: <?= date('Y/m/d H:i', strtotime($s['last_suggested'])) ?>
                        </div>
                        <div class="suggestion-sample">💬 「<?= htmlspecialchars(mb_substr($s['sample_text'], 0, 80)) ?>」</div>
                    </div>
                    <div class="suggestion-actions">
                        <button class="btn btn-primary" onclick="approveAndPatternize('<?= htmlspecialchars($s['extracted_wish'], ENT_QUOTES) ?>', '<?= $s['suggested_category'] ?>')">
                            ✓ パターン化
                        </button>
                        <button class="btn btn-secondary" onclick="rejectSuggestion('<?= htmlspecialchars($s['extracted_wish'], ENT_QUOTES) ?>')">
                            ✕ 却下
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- テストタブ -->
        <div class="tab-content" id="tab-test">
            <div class="test-section" style="margin-bottom: 0;">
                <h3>🧪 パターンテスト</h3>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 12px;">メッセージを入力して、どのWishが抽出されるかテストできます。</p>
                <div class="test-input">
                    <textarea id="testMessage" placeholder="テストメッセージを入力してください。例：「明日の資料準備しておいてね」「沖縄行きたいなー」"></textarea>
                    <button class="btn btn-primary" onclick="testPatterns()">テスト実行</button>
                </div>
                <div class="test-result" id="testResult">
                    <div id="testResultContent"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- パターン編集モーダル -->
    <div class="modal-overlay" id="patternModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="patternModalTitle">パターン追加</h3>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="patternId">
                
                <div class="form-group">
                    <label>正規表現パターン *</label>
                    <input type="text" id="patternRegex" placeholder="(.+?)(?:して|やって)(?:おいて|ください)">
                    <div class="form-hint">※ スラッシュ不要。キャプチャグループ()で抽出したい部分を囲む</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>カテゴリ</label>
                        <select id="patternCategory">
                            <?php foreach ($categories as $key => $label): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>優先度</label>
                        <input type="number" id="patternPriority" value="50" min="0" max="100">
                        <div class="form-hint">高い順に適用（0-100）</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>説明</label>
                    <input type="text" id="patternDescription" placeholder="このパターンの説明">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>例文（入力）</label>
                        <input type="text" id="patternExampleInput" placeholder="明日の資料準備しておいて">
                    </div>
                    <div class="form-group">
                        <label>例文（抽出結果）</label>
                        <input type="text" id="patternExampleOutput" placeholder="明日の資料準備">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="patternActive" checked> 有効にする
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">キャンセル</button>
                <button class="btn btn-primary" onclick="savePattern()">保存</button>
            </div>
        </div>
    </div>
    
    <script>
        // パターンデータ（PHP→JS）
        const patternsData = <?= json_encode($patterns, JSON_UNESCAPED_UNICODE) ?>;
        const categoriesData = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
        
        // タブ切り替え
        function switchTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            document.querySelector(`.tab-btn[onclick="switchTab('${tabId}')"]`).classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('patternModal').classList.remove('active');
        }
        
        // ユーザー提案を承認してパターン化
        async function approveAndPatternize(extractedWish, category) {
            const pattern = prompt(
                'このWishをパターン化します。\n正規表現パターンを確認/編集してください：\n\n（そのままでOKの場合は空欄のまま「OK」）',
                ''
            );
            
            if (pattern === null) return; // キャンセル
            
            try {
                const response = await fetch('../api/wish_extractor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'approve_suggestion',
                        extracted_wish: extractedWish,
                        pattern: pattern || '', // 空の場合は自動生成
                        category: category,
                        category_label: categoriesData[category] || ''
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    alert(`パターンを追加しました！\n\n生成されたパターン: ${data.data.pattern}`);
                    location.reload();
                } else {
                    alert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                alert('パターン化に失敗しました');
            }
        }
        
        // ユーザー提案を却下
        async function rejectSuggestion(extractedWish) {
            if (!confirm('この提案を却下しますか？\n却下された提案は一覧から消えます。')) return;
            
            try {
                const response = await fetch('../api/wish_extractor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'reject_suggestion',
                        extracted_wish: extractedWish
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                alert('却下に失敗しました');
            }
        }
        
        // 人気提案の詳細表示
        function showSuggestionDetail(wish) {
            switchTab('suggestions');
            // スクロールして該当提案をハイライト
            setTimeout(() => {
                const cards = document.querySelectorAll('.suggestion-card');
                cards.forEach(card => {
                    if (card.querySelector('.suggestion-wish').textContent === wish) {
                        card.style.background = '#fef3c7';
                        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        setTimeout(() => card.style.background = '', 2000);
                    }
                });
            }, 100);
        }
        
        function openPatternModal(patternId = null) {
            const modal = document.getElementById('patternModal');
            const title = document.getElementById('patternModalTitle');
            
            if (patternId) {
                const pattern = patternsData.find(p => p.id == patternId);
                if (pattern) {
                    document.getElementById('patternId').value = pattern.id;
                    document.getElementById('patternRegex').value = pattern.pattern;
                    document.getElementById('patternCategory').value = pattern.category;
                    document.getElementById('patternPriority').value = pattern.priority;
                    document.getElementById('patternDescription').value = pattern.description || '';
                    document.getElementById('patternExampleInput').value = pattern.example_input || '';
                    document.getElementById('patternExampleOutput').value = pattern.example_output || '';
                    document.getElementById('patternActive').checked = pattern.is_active == 1;
                    title.textContent = 'パターン編集';
                }
            } else {
                document.getElementById('patternId').value = '';
                document.getElementById('patternRegex').value = '';
                document.getElementById('patternCategory').value = 'other';
                document.getElementById('patternPriority').value = '50';
                document.getElementById('patternDescription').value = '';
                document.getElementById('patternExampleInput').value = '';
                document.getElementById('patternExampleOutput').value = '';
                document.getElementById('patternActive').checked = true;
                title.textContent = 'パターン追加';
            }
            
            modal.classList.add('active');
        }
        
        function editPattern(id) {
            openPatternModal(id);
        }
        
        async function savePattern() {
            const patternId = document.getElementById('patternId').value;
            const pattern = document.getElementById('patternRegex').value.trim();
            
            if (!pattern) {
                alert('正規表現パターンを入力してください');
                return;
            }
            
            const payload = {
                action: patternId ? 'update_pattern' : 'add_pattern',
                pattern: pattern,
                category: document.getElementById('patternCategory').value,
                priority: parseInt(document.getElementById('patternPriority').value),
                description: document.getElementById('patternDescription').value.trim(),
                example_input: document.getElementById('patternExampleInput').value.trim(),
                example_output: document.getElementById('patternExampleOutput').value.trim(),
                is_active: document.getElementById('patternActive').checked ? 1 : 0
            };
            
            if (patternId) {
                payload.pattern_id = parseInt(patternId);
            }
            
            try {
                const response = await fetch('../api/wish_extractor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                alert('保存に失敗しました');
            }
        }
        
        async function deletePattern(id) {
            if (!confirm('このパターンを削除しますか？')) return;
            
            try {
                const response = await fetch('../api/wish_extractor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_pattern', pattern_id: id })
                });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'エラーが発生しました');
                }
            } catch (e) {
                alert('削除に失敗しました');
            }
        }
        
        async function testPatterns() {
            const message = document.getElementById('testMessage').value.trim();
            const resultDiv = document.getElementById('testResult');
            const resultContent = document.getElementById('testResultContent');
            
            if (!message) {
                alert('テストメッセージを入力してください');
                return;
            }
            
            try {
                const response = await fetch('../api/wish_extractor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'test', message: message })
                });
                const data = await response.json();
                
                resultDiv.classList.add('active');
                resultDiv.classList.remove('error');
                
                if (data.success && data.data.extracted.length > 0) {
                    resultContent.innerHTML = data.data.extracted.map(e => `
                        <div class="test-result-item">
                            <span class="category-badge">${e.category_label || e.category}</span>
                            <strong>${e.wish}</strong>
                            <small style="color: var(--text-muted);">← 「${e.original_text}」</small>
                        </div>
                    `).join('');
                } else {
                    resultContent.innerHTML = '<p style="color: var(--text-muted);">抽出されたWishはありません</p>';
                }
            } catch (e) {
                resultDiv.classList.add('active', 'error');
                resultContent.innerHTML = '<p style="color: #dc2626;">テストに失敗しました</p>';
            }
        }
    </script>
</body>
</html>






