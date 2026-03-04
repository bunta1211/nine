<?php
/**
 * AI ask フォールバック（api/ai.php が 500 のときの代替）
 * パターンマッチのみで応答、Gemini は使用しない
 */
header('Content-Type: application/json; charset=utf-8');
define('IS_API', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../includes/api-helpers.php';

if (!isLoggedIn()) {
    errorResponse('ログインが必要です', 401);
}

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
$input = is_array($input) ? $input : [];
$question = trim($input['question'] ?? '');
$language = $input['language'] ?? 'ja';

if (empty($question)) {
    errorResponse('質問を入力してください');
}

$pdo = getDB();
$user_id = (int)$_SESSION['user_id'];
$answer = getDefaultResponseFallback($language, $question);

// 会話を保存（ai_conversations があれば）
try {
    $stmt = $pdo->prepare("
        INSERT INTO ai_conversations (user_id, question, answer, answered_by, language)
        VALUES (?, ?, ?, 'ai', ?)
    ");
    $stmt->execute([$user_id, $question, $answer, $language]);
} catch (Throwable $e) {
    // 保存失敗は無視
}

// 使用量を記録（フォールバックでも「AI秘書チャット」としてカウント）
if (file_exists(__DIR__ . '/../includes/gemini_helper.php')) {
    require_once __DIR__ . '/../includes/gemini_helper.php';
}
if (function_exists('logGeminiUsage') && !empty($answer)) {
    logGeminiUsage($pdo, $user_id, 'chat', mb_strlen($question), mb_strlen($answer));
}

$response = [
    'success' => true,
    'conversation_id' => 0,
    'answer' => $answer,
    'answered_by' => 'fallback',
    'ai_enabled' => false
];
echo json_encode($response, JSON_UNESCAPED_UNICODE);

function getDefaultResponseFallback($language, $question) {
    $q = mb_strtolower($question);
    if (preg_match('/(こんにちは|こんばんは|おはよう|はじめまして|よろしく)/u', $q)) {
        return "こんにちは！何かお手伝いできることはありますか？😊";
    }
    if (preg_match('/(ありがとう|サンキュー|助かり)/u', $q)) {
        return "どういたしまして！また何かあればお気軽にどうぞ。";
    }
    if (preg_match('/(あなたは誰|自己紹介|名前は)/u', $q)) {
        return "私はあなたの秘書です！Social9での活動をサポートします。";
    }
    if (preg_match('/(使い方|やり方|方法|どうやって)/u', $q)) {
        return "具体的にどのような操作についてお知りになりたいですか？\n\n・メッセージの送り方\n・グループの作り方\n・通話の仕方\nなど、お気軽にお聞きください！";
    }
    $responses = [
        'ja' => "ご質問ありがとうございます！\n\n申し訳ありませんが、現在AIの応答に問題が発生しています。しばらくしてからもう一度お試しください。\n\n以下のことについてはお答えできます：\n・挨拶\n・感謝\n・Social9の使い方\n\n別の質問があればお気軽にどうぞ！",
        'en' => "Thank you for your question!\n\nThere seems to be an issue with the AI response. Please try again later.",
        'zh-CN' => "感谢您的提问！\n\n目前AI响应出现问题，请稍后再试。"
    ];
    return $responses[$language] ?? $responses['ja'];
}
