<?php
/**
 * 日本語ファイル名 → 英語ファイル名にリネーム
 * 実行: php rename_to_english.php
 */
$dir = __DIR__;
$renames = [
    '8bitかわす2.mp3'  => '8bit_dodge_2.mp3',
    '8bit詠唱1.mp3'    => '8bit_chant_1.mp3',
    '8bit詠唱2.mp3'    => '8bit_chant_2.mp3',
    '8bit獲得2.mp3'    => '8bit_acquire_2.mp3',
    '8bit獲得8.mp3'    => '8bit_acquire_8.mp3',
    '8bit選択2.mp3'    => '8bit_select_2.mp3',
    '8bit選択9.mp3'    => '8bit_select_9.mp3',
    'ジャジャーン1.mp3' => 'fanfare_1.mp3',
    'パフッ1.mp3'      => 'puff_1.mp3',
    'ページめくり2.mp3' => 'page_turn_2.mp3',
    '完了2.mp3'        => 'complete_2.mp3',
    '正解1.mp3'        => 'correct_1.mp3',
    '正解7.mp3'        => 'correct_7.mp3',
    '選択2.mp3'        => 'select_2.mp3',
    '選択7.mp3'        => 'select_7.mp3',
    '電源オン.mp3'     => 'power_on.mp3',
];

foreach ($renames as $old => $new) {
    $path = $dir . DIRECTORY_SEPARATOR . $old;
    if (file_exists($path)) {
        $to = $dir . DIRECTORY_SEPARATOR . $new;
        if (rename($path, $to)) {
            echo "OK: {$old} -> {$new}\n";
        } else {
            echo "FAIL: {$old}\n";
        }
    } else {
        echo "SKIP (not found): {$old}\n";
    }
}
echo "Done.\n";
