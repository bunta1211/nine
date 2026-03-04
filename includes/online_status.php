<?php
/**
 * オンライン状態管理ヘルパー
 */

require_once __DIR__ . '/../config/database.php';

/**
 * ユーザーのアクティビティを更新
 */
function updateUserActivity(PDO $pdo, int $userId): void {
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET last_activity = NOW(), 
                online_status = 'online' 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        // エラーは無視（機能に影響しない）
    }
}

/**
 * ユーザーをオフラインに設定
 */
function setUserOffline(PDO $pdo, int $userId): void {
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET online_status = 'offline' 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        // エラーは無視
    }
}

/**
 * 非アクティブユーザーをオフラインに更新（5分以上アクティビティなし）
 */
function updateInactiveUsers(PDO $pdo): void {
    try {
        // 5分以上アクティビティがない場合はaway
        $pdo->exec("
            UPDATE users 
            SET online_status = 'away' 
            WHERE online_status = 'online' 
            AND last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        
        // 15分以上アクティビティがない場合はoffline
        $pdo->exec("
            UPDATE users 
            SET online_status = 'offline' 
            WHERE online_status IN ('online', 'away') 
            AND last_activity < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
    } catch (Exception $e) {
        // エラーは無視
    }
}

/**
 * ユーザーのオンライン状態を取得
 */
function getUserOnlineStatus(PDO $pdo, int $userId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT online_status, last_activity 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return [
                'status' => $result['online_status'] ?? 'offline',
                'last_activity' => $result['last_activity']
            ];
        }
    } catch (Exception $e) {
        // エラーは無視
    }
    
    return [
        'status' => 'offline',
        'last_activity' => null
    ];
}

/**
 * 最終アクティビティを人間が読める形式に変換
 */
function formatLastActivity(?string $lastActivity, string $lang = 'ja'): string {
    if (!$lastActivity) {
        return $lang === 'en' ? 'Unknown' : ($lang === 'zh' ? '未知' : '不明');
    }
    
    $timestamp = strtotime($lastActivity);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        return $lang === 'en' ? 'Just now' : ($lang === 'zh' ? '刚刚' : 'たった今');
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $lang === 'en' ? "{$minutes} min ago" : ($lang === 'zh' ? "{$minutes}分钟前" : "{$minutes}分前");
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $lang === 'en' ? "{$hours} hour ago" : ($lang === 'zh' ? "{$hours}小时前" : "{$hours}時間前");
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $lang === 'en' ? "{$days} day ago" : ($lang === 'zh' ? "{$days}天前" : "{$days}日前");
    } else {
        return date('Y/m/d', $timestamp);
    }
}

/**
 * オンライン状態のラベルを取得
 */
function getOnlineStatusLabel(string $status, string $lang = 'ja'): string {
    $labels = [
        'online' => ['ja' => 'オンライン', 'en' => 'Online', 'zh' => '在线'],
        'away' => ['ja' => '離席中', 'en' => 'Away', 'zh' => '离开'],
        'offline' => ['ja' => 'オフライン', 'en' => 'Offline', 'zh' => '离线']
    ];
    
    return $labels[$status][$lang] ?? $labels['offline'][$lang];
}

/**
 * オンライン状態の色を取得
 */
function getOnlineStatusColor(string $status): string {
    $colors = [
        'online' => '#22c55e',  // 緑
        'away' => '#f59e0b',    // オレンジ
        'offline' => '#6b7280'  // グレー
    ];
    
    return $colors[$status] ?? $colors['offline'];
}


