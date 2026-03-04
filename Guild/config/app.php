<?php
/**
 * Guild アプリケーション設定
 */

// アプリケーション基本設定
define('GUILD_APP_NAME', 'Guild');
define('GUILD_VERSION', '1.0.0');

// Earth設定
define('EARTH_TO_YEN', 10); // 1 Earth = 10円
define('LARGE_REQUEST_THRESHOLD', 10000); // 承認が必要な金額
define('ADVANCE_PAYMENT_PERCENT', 80); // 前借り可能割合（%）
define('TENURE_EARTH_PER_YEAR', 500); // 在籍1年あたりのEarth

// 支払いスケジュール
define('PAYMENT_SCHEDULE', [
    ['period' => '04-06', 'date' => '08-20', 'type' => 'regular'],
    ['period' => '07-09', 'date' => '09-30', 'type' => 'regular'],
    ['period' => '10-12', 'date' => '03-31', 'type' => 'regular'],
    ['period' => '01-03', 'date' => '03-31', 'type' => 'regular'],
]);

// 年度設定
define('FISCAL_YEAR_START_MONTH', 4); // 4月開始
define('SETTLEMENT_DAY', 10); // 3月10日が最終決済日
define('FREEZE_START_DAY', 11); // 3月11日から依頼停止

// メール送信時刻
define('EMAIL_SEND_HOUR', 18); // 18時に送信

// セッションタイムアウト（Social9と同じ設定を使用）
define('SESSION_TIMEOUT_MINUTES', 60);

// ファイルアップロード設定
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ページネーション
define('ITEMS_PER_PAGE', 20);

// 依頼タイプ
define('REQUEST_TYPES', [
    'public' => [
        'name_ja' => '公開依頼',
        'name_en' => 'Public Request',
        'name_zh' => '公开请求',
        'earth_source' => 'guild',
        'can_apply' => true,
    ],
    'designated' => [
        'name_ja' => '指名依頼',
        'name_en' => 'Designated Request',
        'name_zh' => '指名请求',
        'earth_source' => 'guild',
        'can_decline' => true,
    ],
    'order' => [
        'name_ja' => '業務指令',
        'name_en' => 'Work Order',
        'name_zh' => '工作命令',
        'earth_source' => 'guild',
        'can_decline' => false,
    ],
    'shift_swap' => [
        'name_ja' => '勤務交代依頼',
        'name_en' => 'Shift Swap Request',
        'name_zh' => '换班请求',
        'earth_source' => 'personal',
        'requires_approval' => true,
    ],
    'personal' => [
        'name_ja' => '個人依頼',
        'name_en' => 'Personal Request',
        'name_zh' => '个人请求',
        'earth_source' => 'personal',
    ],
    'thanks' => [
        'name_ja' => '感謝の気持ち',
        'name_en' => 'Thanks',
        'name_zh' => '感谢',
        'earth_source' => 'personal',
        'can_anonymous' => true,
        'min_earth' => 1,
    ],
    'special_reward' => [
        'name_ja' => '特別報酬',
        'name_en' => 'Special Reward',
        'name_zh' => '特别奖励',
        'earth_source' => 'guild',
        'can_link_request' => true,
    ],
]);

// 余力ステータス
define('AVAILABILITY_STATUS', [
    'available' => [
        'name_ja' => '新規依頼受付中',
        'name_en' => 'Available',
        'name_zh' => '可接受新请求',
        'color' => '#22c55e', // green
        'icon' => '🟢',
    ],
    'limited' => [
        'name_ja' => '余裕あり',
        'name_en' => 'Limited',
        'name_zh' => '有限',
        'color' => '#eab308', // yellow
        'icon' => '🟡',
    ],
    'unavailable' => [
        'name_ja' => '新規依頼不可',
        'name_en' => 'Unavailable',
        'name_zh' => '无法接受',
        'color' => '#ef4444', // red
        'icon' => '🔴',
    ],
]);

// ギルドロール
define('GUILD_ROLES', [
    'leader' => [
        'name_ja' => 'ギルドリーダー',
        'name_en' => 'Guild Leader',
        'name_zh' => '公会领袖',
        'level' => 3,
    ],
    'sub_leader' => [
        'name_ja' => 'サブリーダー',
        'name_en' => 'Sub Leader',
        'name_zh' => '副领袖',
        'level' => 2,
    ],
    'coordinator' => [
        'name_ja' => 'コーディネーター',
        'name_en' => 'Coordinator',
        'name_zh' => '协调员',
        'level' => 1,
    ],
    'member' => [
        'name_ja' => 'メンバー',
        'name_en' => 'Member',
        'name_zh' => '成员',
        'level' => 0,
    ],
]);

// 対応言語
define('SUPPORTED_LANGUAGES', [
    'ja' => '日本語',
    'en' => 'English',
    'zh' => '中文',
]);

// デフォルト言語
define('DEFAULT_LANGUAGE', 'ja');

/**
 * 現在の年度を取得
 */
function getCurrentFiscalYear() {
    $now = new DateTime();
    $year = (int)$now->format('Y');
    $month = (int)$now->format('n');
    
    // 4月より前は前年度
    if ($month < FISCAL_YEAR_START_MONTH) {
        $year--;
    }
    
    return $year;
}

/**
 * 在籍年数を計算
 * @param string $hireDate 入社日（Y-m-d形式）
 * @return int 勤続年数
 */
function calculateTenureYears($hireDate) {
    if (empty($hireDate)) {
        return 0;
    }
    
    $hire = new DateTime($hireDate);
    $hireYear = (int)$hire->format('Y');
    $hireMonth = (int)$hire->format('n');
    
    // 入社した次の4月1日を起算日とする
    if ($hireMonth >= FISCAL_YEAR_START_MONTH) {
        $startYear = $hireYear + 1;
    } else {
        $startYear = $hireYear;
    }
    
    $currentFiscalYear = getCurrentFiscalYear();
    $years = $currentFiscalYear - $startYear + 1;
    
    return max(0, $years);
}

/**
 * 在籍年数に応じたEarthを計算
 */
function calculateTenureEarth($hireDate) {
    $years = calculateTenureYears($hireDate);
    return $years * TENURE_EARTH_PER_YEAR;
}

/**
 * 前借り可能額を計算
 */
function calculateMaxAdvance($currentBalance) {
    return (int)floor($currentBalance * ADVANCE_PAYMENT_PERCENT / 100);
}

/**
 * EarthをÆ'円に変換
 */
function earthToYen($earth) {
    return $earth * EARTH_TO_YEN;
}

/**
 * 依頼停止期間かどうかを判定
 */
function isFreezeZPeriod() {
    $now = new DateTime();
    $month = (int)$now->format('n');
    $day = (int)$now->format('j');
    
    // 3月11日〜3月31日は依頼停止
    if ($month === 3 && $day >= FREEZE_START_DAY) {
        return true;
    }
    
    return false;
}

/**
 * 最終決済日の警告を表示すべきかを判定
 */
function shouldShowSettlementWarning() {
    $now = new DateTime();
    $month = (int)$now->format('n');
    
    // 3月に入ったら警告を表示
    return $month === 3;
}
