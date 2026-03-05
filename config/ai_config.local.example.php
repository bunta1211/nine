<?php
/**
 * AI API ローカル設定の例
 * 
 * 使い方:
 * 1. このファイルをコピーして ai_config.local.php を作成
 *    cp ai_config.local.example.php ai_config.local.php
 * 2. 下記の YOUR_GEMINI_API_KEY を、Google AI Studio で発行したAPIキーに置き換える
 *    取得: https://aistudio.google.com/app/apikey または https://makersuite.google.com/app/apikey
 * 3. 本番サーバー（EC2）では、/var/www/html/config/ai_config.local.php に同様に配置する
 *    （デプロイでは上書きされないため手動で作成・更新する）
 */

// Google Gemini API Key（自動返信提案・AI秘書で使用）
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');

// OpenAI API Key（翻訳などで使用する場合）
// define('OPENAI_API_KEY', '');