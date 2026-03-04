<?php
/**
 * アセット関連ヘルパー
 * 
 * 共通化のため includes に配置
 */

if (!function_exists('assetVersion')) {
    /**
     * アセットのバージョン取得（filemtimeの安全版）
     * ファイルが存在しない場合は time() を返す
     * 
     * @param string $path プロジェクトルートからの相対パス（例: assets/css/common.css）
     * @param string|null $baseDir ベースディレクトリ（省略時は呼び出し元の __DIR__ を想定）
     * @return int タイムスタンプ（キャッシュバスター用）
     */
    function assetVersion($path, $baseDir = null) {
        $base = $baseDir ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__));
        $fullPath = rtrim($base, '/\\') . '/' . ltrim($path, '/\\');
        return file_exists($fullPath) ? filemtime($fullPath) : time();
    }
}
