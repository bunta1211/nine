<?php
/**
 * ログイン画面・右パネル：用途・使い方（利用シーン）
 * 計画 3.3 に基づく。
 */
$landing_lang = isset($currentLang) ? $currentLang : 'ja';
?>
<div class="login-landing-right" id="loginLandingRight">
    <h3 class="landing-section-title"><?= $landing_lang === 'en' ? 'Use cases' : ($landing_lang === 'zh' ? '使用场景' : '用途・使い方') ?></h3>
    <ul class="landing-list landing-use-cases">
        <?php if ($landing_lang === 'en'): ?>
            <li>Team and department communication, reports, and meetings</li>
            <li>Task requests, progress updates, and reminders</li>
            <li>AI secretary: schedule suggestions, memo search, morning news</li>
            <li>Family, community, and group updates and event sharing</li>
            <li>File and link sharing, group shared folders</li>
            <li>Remote voice and video calls</li>
            <li>Organization admins: member and group management (including private groups)</li>
        <?php elseif ($landing_lang === 'zh'): ?>
            <li>团队、部门的联络、业务汇报与会议</li>
            <li>任务委托、进度共享与提醒</li>
            <li>AI秘书：日程建议、备忘录搜索、晨间新闻</li>
            <li>家庭、社区、团体的联络与活动共享</li>
            <li>文件与链接共享、群组共享文件夹</li>
            <li>远程语音与视频通话</li>
            <li>组织管理员：成员与群组管理（含私密群组）</li>
        <?php else: ?>
            <li>チーム・部署の連絡・業務報告・打ち合わせの場として</li>
            <li>タスクの依頼・進捗共有・リマインダー</li>
            <li>AI秘書によるスケジュール提案・メモ検索・朝のニュース配信</li>
            <li>家族・コミュニティ・団体の連絡・イベント共有</li>
            <li>ファイルやリンクの共有、グループごとの共有フォルダの活用</li>
            <li>リモートでの音声・ビデオ通話</li>
            <li>組織管理者によるメンバー管理・グループの整理（プライベートグループ含む）</li>
        <?php endif; ?>
    </ul>
</div>
