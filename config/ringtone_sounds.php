<?php
/**
 * 着信音・効果音の一覧と旧プリセット名のマッピング
 * assets/sounds/ フォルダ内のファイルを id で参照する。
 */

if (!defined('RINGTONE_SOUNDS_LOADED')) {
    define('RINGTONE_SOUNDS_LOADED', true);
}

// 利用可能な着信音: id => 表示名（ファイル名は id + .mp3）
$RINGTONE_SOUNDS_LIST = [
    'correct_1'       => '正解1',
    'correct_7'       => '正解7',
    '8bit_acquire_2'   => '8bit獲得2',
    '8bit_acquire_8'   => '8bit獲得8',
    '8bit_select_2'    => '8bit選択2',
    '8bit_select_9'    => '8bit選択9',
    '8bit_dodge_2'     => '8bitかわす2',
    '8bit_chant_1'     => '8bit詠唱1',
    '8bit_chant_2'     => '8bit詠唱2',
    'select_2'        => '選択2',
    'select_7'        => '選択7',
    'complete_2'       => '完了',
    'power_on'         => '電源オン',
];

// 旧プリセット名 → ファイル id（後方互換。DB に default/gentle 等が残っている場合に使用）
$RINGTONE_LEGACY_PRESET_TO_ID = [
    'default'  => 'correct_1',
    'gentle'   => 'correct_1',
    'bright'   => '8bit_acquire_2',
    'classic'  => 'select_2',
    'chime'    => '8bit_select_2',
];

/**
 * 着信音 id をファイル id に正規化する（旧プリセット名ならファイル id に変換）
 * @param string $id notification_sound または ringtone の値
 * @return string ファイル名（拡張子なし）
 */
function ringtone_resolve_sound_id($id) {
    global $RINGTONE_LEGACY_PRESET_TO_ID, $RINGTONE_SOUNDS_LIST;
    if ($id === 'silent' || $id === '') {
        return 'silent';
    }
    if (isset($RINGTONE_LEGACY_PRESET_TO_ID[$id])) {
        return $RINGTONE_LEGACY_PRESET_TO_ID[$id];
    }
    if (isset($RINGTONE_SOUNDS_LIST[$id])) {
        return $id;
    }
    return 'correct_1'; // 不明な場合はデフォルト
}

/**
 * 着信音として有効な id の一覧（API の valid_sounds 用）
 * @return string[] 'silent' + フォルダ由来の id 一覧
 */
function ringtone_valid_sound_ids() {
    global $RINGTONE_SOUNDS_LIST, $RINGTONE_LEGACY_PRESET_TO_ID;
    $ids = ['silent'];
    foreach (array_keys($RINGTONE_SOUNDS_LIST) as $id) {
        $ids[] = $id;
    }
    foreach (array_keys($RINGTONE_LEGACY_PRESET_TO_ID) as $legacy) {
        if (!in_array($legacy, $ids, true)) {
            $ids[] = $legacy;
        }
    }
    return $ids;
}

/**
 * 音声ファイルの相対パス（拡張子 .mp3）
 * @param string $id 拡張子なしの id（ringtone_resolve_sound_id 済みを想定）
 * @return string 例: assets/sounds/fanfare_1.mp3
 */
function ringtone_sound_path($id) {
    if ($id === 'silent') {
        return '';
    }
    return 'assets/sounds/' . $id . '.mp3';
}

/** 表示用ラベル（旧プリセット + フォルダ id）。設定画面の選択肢で使用。「デフォルト」「ジャジャーン」「パフッ」「ページめくり」は音が鳴らないため除外 */
$RINGTONE_DISPLAY_LABELS = [
    'silent'  => 'サイレント',
    'gentle'  => 'やさしい',
    'bright'  => '明るい',
    'classic' => 'クラシック',
    'chime'   => 'チャイム',
];
foreach ($RINGTONE_SOUNDS_LIST as $id => $label) {
    if (!isset($RINGTONE_DISPLAY_LABELS[$id])) {
        $RINGTONE_DISPLAY_LABELS[$id] = $label;
    }
}
