<?php
/**
 * 管理パネル - 仕様書ビューア
 * 仕様書: 27_運営管理仕様書ビューア.md
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

$currentPage = basename(__FILE__, '.php');
require_once __DIR__ . '/_sidebar.php';

requireLogin();
requireSystemAdmin();

$docs_base = __DIR__ . '/../DOCS/';
$search = $_GET['search'] ?? '';
$current_file = $_GET['file'] ?? ''; // 例: "spec/00_全体概要.md" または "new_spec/00_目次.md"

// 仕様書ファイル一覧を取得（DOCS/spec/ を優先、DOCS/new_spec/ も含める）
$files = [];
$file_to_path = [];
foreach (['spec', 'new_spec'] as $subdir) {
    $dir = $docs_base . $subdir . '/';
    if (!is_dir($dir)) continue;
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || pathinfo($entry, PATHINFO_EXTENSION) !== 'md') continue;
        $rel = $subdir . '/' . $entry;
        $files[] = $rel;
        $file_to_path[$rel] = $dir . $entry;
    }
}
sort($files);

// 検索
$search_results = [];
if ($search && !empty($file_to_path)) {
    foreach ($files as $file) {
        $path = $file_to_path[$file] ?? $docs_base . $file;
        if (!file_exists($path)) continue;
        $content = @file_get_contents($path);
        if ($content !== false && stripos($content, $search) !== false) {
            $lines = explode("\n", $content);
            foreach ($lines as $line_num => $line) {
                if (stripos($line, $search) !== false) {
                    $search_results[] = [
                        'file' => $file,
                        'line' => $line_num + 1,
                        'content' => trim($line)
                    ];
                }
            }
        }
    }
}

// ファイル内容を取得
$file_content = '';
if ($current_file && in_array($current_file, $files)) {
    $path = $file_to_path[$current_file] ?? $docs_base . $current_file;
    if (file_exists($path)) {
        $file_content = file_get_contents($path);
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仕様書ビューア - 管理パネル</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <style>
        body { background: var(--bg-secondary); }
        <?php adminSidebarCSS(); ?>
        .main-content { display: flex; gap: 20px; }
        
        .file-list {
            width: 280px;
            background: white;
            border-radius: 12px;
            padding: 16px;
            height: fit-content;
            max-height: calc(100vh - 60px);
            overflow-y: auto;
            position: sticky;
            top: 30px;
        }
        
        .file-list h3 { font-size: 14px; color: var(--text-muted); margin-bottom: 12px; }
        
        .search-box {
            margin-bottom: 16px;
        }
        
        .search-box input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
        }
        
        .file-item {
            display: block;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 13px;
            margin-bottom: 4px;
            transition: background 0.2s;
        }
        
        .file-item:hover { background: var(--bg-secondary); }
        .file-item.active { background: var(--primary-bg); color: var(--primary); font-weight: 500; }
        
        .content-area {
            flex: 1;
            background: white;
            border-radius: 12px;
            padding: 30px;
            min-height: 500px;
        }
        
        .content-area h1 { font-size: 24px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light); }
        .content-area h2 { font-size: 20px; margin-top: 30px; margin-bottom: 16px; color: var(--primary); }
        .content-area h3 { font-size: 16px; margin-top: 24px; margin-bottom: 12px; }
        .content-area p { margin-bottom: 12px; line-height: 1.8; }
        .content-area ul, .content-area ol { margin-bottom: 16px; padding-left: 24px; }
        .content-area li { margin-bottom: 6px; line-height: 1.6; }
        .content-area code { background: var(--bg-secondary); padding: 2px 6px; border-radius: 4px; font-size: 13px; }
        .content-area pre { background: var(--bg-dark); color: white; padding: 16px; border-radius: 8px; overflow-x: auto; margin: 16px 0; }
        .content-area pre code { background: none; padding: 0; }
        .content-area table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        .content-area th, .content-area td { padding: 10px 12px; border: 1px solid var(--border-light); text-align: left; }
        .content-area th { background: var(--bg-secondary); font-weight: 600; }
        
        .search-results {
            background: #fffbeb;
            border: 1px solid #fbbf24;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .search-results h4 { margin-bottom: 12px; color: #92400e; }
        
        .search-result-item {
            padding: 8px 0;
            border-bottom: 1px solid #fde68a;
        }
        
        .search-result-item:last-child { border-bottom: none; }
        
        .search-result-item a { color: var(--primary); font-weight: 500; }
        .search-result-item .preview { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        
        .empty-state .icon { font-size: 48px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php adminSidebarHTML($currentPage); ?>
        
        <main class="main-content">
            <div class="file-list">
                <form class="search-box" method="GET">
                    <input type="text" name="search" placeholder="仕様書を検索..." value="<?= htmlspecialchars($search) ?>">
                </form>
                
                <h3>📄 仕様書一覧（<?= count($files) ?>件）</h3>
                
                <?php foreach ($files as $file): ?>
                <a href="?file=<?= urlencode($file) ?>" class="file-item <?= $current_file === $file ? 'active' : '' ?>">
                    <?= htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)) ?>
                </a>
                <?php endforeach; ?>
                <?php if (empty($files)): ?>
                <p style="font-size: 13px; color: var(--text-muted);">DOCS/spec/ または DOCS/new_spec/ に .md ファイルがありません。</p>
                <?php endif; ?>
            </div>
            
            <div class="content-area">
                <?php if ($search && !empty($search_results)): ?>
                <div class="search-results">
                    <h4>🔍 「<?= htmlspecialchars($search) ?>」の検索結果（<?= count($search_results) ?>件）</h4>
                    <?php foreach (array_slice($search_results, 0, 10) as $result): ?>
                    <div class="search-result-item">
                        <a href="?file=<?= urlencode($result['file']) ?>&search=<?= urlencode($search) ?>">
                            <?= htmlspecialchars(pathinfo($result['file'], PATHINFO_FILENAME)) ?>
                        </a>
                        <span style="color: var(--text-muted); font-size: 12px;">（行 <?= $result['line'] ?>）</span>
                        <div class="preview"><?= htmlspecialchars(mb_substr($result['content'], 0, 100)) ?>...</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($search): ?>
                <div class="search-results">
                    <h4>🔍 「<?= htmlspecialchars($search) ?>」の検索結果</h4>
                    <p>見つかりませんでした。</p>
                </div>
                <?php endif; ?>
                
                <?php if ($file_content): ?>
                    <?= parseMarkdown($file_content) ?>
                <?php else: ?>
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <h3>仕様書を選択してください</h3>
                    <p>左のリストから閲覧したい仕様書をクリックしてください。</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
<script src="../assets/js/admin-sidebar-sort.js"></script>
</body>
</html>

<?php
/**
 * 簡易Markdownパーサー
 */
function parseMarkdown($text) {
    $text = htmlspecialchars($text);
    
    // ヘッダー
    $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
    
    // コードブロック
    $text = preg_replace('/```(\w+)?\n(.*?)\n```/s', '<pre><code>$2</code></pre>', $text);
    
    // インラインコード
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    
    // 太字
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    
    // リスト
    $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $text);
    
    // 番号付きリスト
    $text = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $text);
    
    // テーブル（簡易）
    $text = preg_replace('/^\|(.+)\|$/m', '<tr><td>$1</td></tr>', $text);
    $text = preg_replace('/<td>\s*:?-+:?\s*<\/td>/', '', $text);
    $text = str_replace('|', '</td><td>', $text);
    
    // 段落
    $text = preg_replace('/\n\n/', '</p><p>', $text);
    $text = '<p>' . $text . '</p>';
    
    // 空タグを削除
    $text = preg_replace('/<p>\s*<\/p>/', '', $text);
    $text = preg_replace('/<p>\s*<(h[1-6]|ul|ol|pre|table)/', '<$1', $text);
    $text = preg_replace('/<\/(h[1-6]|ul|ol|pre|table)>\s*<\/p>/', '</$1>', $text);
    
    return $text;
}
?>








