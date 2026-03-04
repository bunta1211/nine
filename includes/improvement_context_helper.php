<?php
/**
 * 改善提案記録用：Gemini に渡すプロジェクトコンテキスト（A+B+C）を組み立てる
 *
 * - 方法A: ARCHITECTURE.md および主要 DEPENDENCIES.md の内容を取得
 * - 方法B: DOCS/IMPROVEMENT_CONTEXT.md の内容を取得
 * - 方法C: DOCS/*.md および各所 DEPENDENCIES.md を走査して要約的に結合（サイズ上限あり）
 *
 * 秘密情報（.env, 認証情報, ユーザーデータ）は含めない。ファイル名・パス・役割の説明のみ。
 */

if (!function_exists('getImprovementContextForGemini')) {

    /**
     * 改善提案（extract_improvement_report）用に、プロジェクトの主要ファイル・役割をテキストで返す
     *
     * @param int $maxChars 返却テキストの最大文字数（デフォルト 35000）。Gemini の入力制限を考慮
     * @return string 【プロジェクト概要】としてプロンプトに付与するテキスト
     */
    function getImprovementContextForGemini($maxChars = 35000)
    {
        $base = dirname(__DIR__);
        $parts = [];

        // --- 方法A: 既存ドキュメント（ARCHITECTURE + 主要 DEPENDENCIES）
        $archPath = $base . '/ARCHITECTURE.md';
        if (is_readable($archPath)) {
            $content = @file_get_contents($archPath);
            if ($content !== false && $content !== '') {
                $parts[] = "## ARCHITECTURE.md\n" . trim($content);
            }
        }

        $depsPaths = [
            $base . '/includes/chat/DEPENDENCIES.md',
            $base . '/api/DEPENDENCIES.md',
            $base . '/includes/DEPENDENCIES.md',
        ];
        foreach ($depsPaths as $path) {
            if (is_readable($path)) {
                $content = @file_get_contents($path);
                if ($content !== false && $content !== '') {
                    $name = basename(dirname($path)) . '/' . basename($path);
                    $parts[] = "## {$name}\n" . trim($content);
                }
            }
        }

        // --- 方法B: 改善用コンテキスト 1 本
        $improvementPath = $base . '/DOCS/IMPROVEMENT_CONTEXT.md';
        if (is_readable($improvementPath)) {
            $content = @file_get_contents($improvementPath);
            if ($content !== false && $content !== '') {
                $parts[] = "## DOCS/IMPROVEMENT_CONTEXT.md（画面・機能別主要ファイル）\n" . trim($content);
            }
        }

        // --- 方法C: その他 DOCS/*.md を追加（要約用に先頭のみ。秘密情報は含めない）
        $docsDir = $base . '/DOCS';
        if (is_dir($docsDir)) {
            $exclude = ['IMPROVEMENT_CONTEXT.md', 'IMPROVEMENT_COST_ESTIMATE.md'];
            $list = @scandir($docsDir);
            if ($list !== false) {
                foreach ($list as $f) {
                    if ($f === '.' || $f === '..' || in_array($f, $exclude, true)) {
                        continue;
                    }
                    if (pathinfo($f, PATHINFO_EXTENSION) !== 'md') {
                        continue;
                    }
                    $path = $docsDir . '/' . $f;
                    if (!is_file($path) || !is_readable($path)) {
                        continue;
                    }
                    $content = @file_get_contents($path);
                    if ($content === false || $content === '') {
                        continue;
                    }
                    $content = trim($content);
                    if (mb_strlen($content) > 4000) {
                        $content = mb_substr($content, 0, 4000) . "\n...(省略)";
                    }
                    $parts[] = "## DOCS/{$f}\n" . $content;
                }
            }
        }

        $combined = implode("\n\n---\n\n", $parts);
        if (mb_strlen($combined) > $maxChars) {
            $combined = mb_substr($combined, 0, $maxChars) . "\n...(文字数制限で省略)";
        }

        return $combined === '' ? '' : "【プロジェクト概要】\n以下は Social9 の構成・主要ファイルの説明です。改善提案の related_files / suspected_location を書く際の参考にしてください。\n\n" . $combined;
    }
}
