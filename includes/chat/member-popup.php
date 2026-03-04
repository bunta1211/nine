<?php
/**
 * チャット画面 - メンバーポップアップ
 * 
 * 必要な変数:
 * - $selected_conversation: 選択中の会話
 * - $members: メンバーリスト
 * - $user_id: 現在のユーザーID
 * - $currentLang: 現在の言語
 * 
 * 注意: TO選択ポップアップはchat.phpのbody終了タグ直前に直接配置
 */

// 変数の安全なアクセス
$allowMemberDm = 1;
if (isset($selected_conversation) && is_array($selected_conversation)) {
    $allowMemberDm = (int)($selected_conversation['allow_member_dm'] ?? 1);
}
?>

<?php if (isset($selected_conversation) && $selected_conversation && isset($members) && !empty($members)): ?>
<div class="member-popup-overlay" id="memberPopupOverlay" onclick="closeMemberPopup()"></div>
<div class="member-popup" id="memberPopup" data-allow-dm="<?= $allowMemberDm ?>">
    <div class="member-popup-header">
        <span>👥 <?= $currentLang === 'en' ? 'Members' : ($currentLang === 'zh' ? '成员' : 'メンバー') ?> (<?= count($members) ?>)</span>
        <button class="member-popup-close" onclick="closeMemberPopup()" aria-label="閉じる">×</button>
    </div>
    <div class="member-popup-list">
        <?php foreach ($members as $m): ?>
        <div class="member-popup-item <?= $m['id'] == $user_id ? 'is-me' : '' ?> <?= !$allowMemberDm && $m['id'] != $user_id ? 'dm-disabled' : '' ?>" 
             data-user-id="<?= $m['id'] ?>"
             onclick="<?= ($m['id'] != $user_id && $allowMemberDm) ? 'startDmWithUser(' . $m['id'] . ', \'' . htmlspecialchars($m['display_name'], ENT_QUOTES) . '\')' : '' ?>">
            <div class="member-popup-avatar"><?= mb_substr($m['display_name'], 0, 1) ?></div>
            <div class="member-popup-info">
                <span class="member-popup-name">
                    <?= htmlspecialchars($m['display_name']) ?>
                    <?php if ($m['id'] == $user_id): ?>
                    <span class="you-label">(<?= $currentLang === 'en' ? 'You' : ($currentLang === 'zh' ? '你' : 'あなた') ?>)</span>
                    <?php endif; ?>
                </span>
                <?php if ($m['role'] === 'admin'): ?>
                <span class="member-popup-role"><?= $currentLang === 'en' ? 'Admin' : ($currentLang === 'zh' ? '管理员' : '管理者') ?></span>
                <?php endif; ?>
            </div>
            <?php if ($m['id'] != $user_id && $allowMemberDm): ?>
            <div class="member-popup-action">
                <span class="dm-icon" title="<?= $currentLang === 'en' ? 'Send Message' : ($currentLang === 'zh' ? '发送消息' : 'メッセージを送る') ?>">💬</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$allowMemberDm): ?>
    <div class="member-popup-notice">
        🔒 <?= $currentLang === 'en' ? 'DM is disabled for this group' : ($currentLang === 'zh' ? '该群组已禁用私信' : 'このグループではDMが無効です') ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>