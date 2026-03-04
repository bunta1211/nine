<?php
/**
 * get_settings のみを処理する独立エンドポイント
 * ai.php が 500 になる原因切り分け用
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

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$settings = [
    'name' => 'あなたの秘書',
    'character_type' => null,
    'custom_instructions' => '',
    'character_selected' => 0,
    'user_profile' => '',
    'character_types' => []
];

try {
    $stmt = $pdo->prepare("SELECT secretary_name, character_type, custom_instructions,
        conversation_memory_summary, clone_training_language, clone_auto_reply_enabled
        FROM user_ai_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    if ($result) {
        if (!empty($result['secretary_name'])) {
            $settings['name'] = $result['secretary_name'];
        }
        if (!empty(trim((string)($result['character_type'] ?? '')))) {
            $settings['character_type'] = $result['character_type'];
            $settings['character_selected'] = 1;
        }
        if (!empty($result['custom_instructions'])) {
            $settings['custom_instructions'] = $result['custom_instructions'];
        }
        $settings['conversation_memory_summary'] = $result['conversation_memory_summary'] ?? '';
        $settings['clone_training_language'] = $result['clone_training_language'] ?? 'ja';
        $settings['clone_auto_reply_enabled'] = (int)($result['clone_auto_reply_enabled'] ?? 0);
    }
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'conversation_memory_summary') !== false
        || strpos($e->getMessage(), 'clone_training') !== false
        || strpos($e->getMessage(), 'clone_auto') !== false) {
        try {
            $stmt2 = $pdo->prepare("SELECT secretary_name, character_type, custom_instructions FROM user_ai_settings WHERE user_id = ?");
            $stmt2->execute([$user_id]);
            $result2 = $stmt2->fetch();
            if ($result2) {
                if (!empty($result2['secretary_name'])) $settings['name'] = $result2['secretary_name'];
                if (!empty(trim((string)($result2['character_type'] ?? '')))) {
                    $settings['character_type'] = $result2['character_type'];
                    $settings['character_selected'] = 1;
                }
                if (!empty($result2['custom_instructions'])) $settings['custom_instructions'] = $result2['custom_instructions'];
            }
        } catch (Throwable $e2) {}
    }
    error_log("get_settings error: " . $e->getMessage());
}

try {
    $statsStmt = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN final_content IS NOT NULL AND final_content != suggested_content THEN 1 ELSE 0 END) AS modified
        FROM user_ai_reply_suggestions WHERE user_id = ? AND sent_at IS NOT NULL");
    $statsStmt->execute([$user_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $total = (int)($stats['total'] ?? 0);
    $modified = (int)($stats['modified'] ?? 0);
    $rate = $total > 0 ? round($modified / $total * 100, 1) : 100;
    $settings['reply_stats'] = [
        'total_sent' => $total,
        'modified_count' => $modified,
        'modification_rate' => $rate,
        'auto_reply_eligible' => ($total >= 20 && $rate <= 20)
    ];
} catch (Throwable $e) {
    $settings['reply_stats'] = ['total_sent' => 0, 'modified_count' => 0, 'modification_rate' => 100, 'auto_reply_eligible' => false];
}

if (defined('AI_CHARACTER_TYPES')) {
    foreach (AI_CHARACTER_TYPES as $key => $type) {
        $settings['character_types'][$key] = [
            'name' => $type['name'],
            'emoji' => $type['emoji'],
            'image' => $type['image'] ?? '',
            'description' => $type['description']
        ];
    }
}

successResponse($settings);
