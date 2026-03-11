<?php
/**
 * ログイン画面・中央パネル：キャッチコピーと主な機能
 * 計画 3.1 / 3.2 に基づく。理念は掲載しない。
 */
$landing_lang = isset($currentLang) ? $currentLang : 'ja';
?>
<div class="login-landing-center" id="loginLandingCenter">
    <h1 class="landing-catch-title">
        <?php if ($landing_lang === 'en'): ?>
            Free social app for business, family, community, clubs, and hobbies.
        <?php elseif ($landing_lang === 'zh'): ?>
            从町内会、社团、兴趣小组到家庭联络、公司业务汇报，免费可用的社交应用。
        <?php else: ?>
            町内会・部活・サークル・趣味の会から、家族の連絡・会社の業務報告まで。さまざまなシーンで、無料で使えるソーシャルアプリが Social9 です。
        <?php endif; ?>
    </h1>

    <p class="landing-catch-april">
        <?php if ($landing_lang === 'en'): ?>
            As we welcome April and the new fiscal year, new organizations and groups are being formed. Social9 can help you set up contact networks and share information with new members.
        <?php elseif ($landing_lang === 'zh'): ?>
            迎接四月与新年度，新的组织与团体纷纷成立。欢迎使用 Social9 建立联络网、与新成员共享信息。
        <?php else: ?>
            4月を迎えるにあたり、新たな組織や団体が次々と生まれる季節です。新しい仲間との連絡網づくりや、新年度の情報共有に、Social9 をご活用ください。
        <?php endif; ?>
    </p>

    <div class="landing-notice-block">
        <p class="landing-notice-improve">
            <?php if ($landing_lang === 'en'): ?>
                This app is built with AI-assisted development and welcomes user feedback. Please share your improvement ideas with the AI secretary.
            <?php elseif ($landing_lang === 'zh'): ?>
                本应用由AI辅助开发，欢迎用户提出改进建议。请通过AI秘书告知您的意见。
            <?php else: ?>
                当アプリはAIコーディングにより作成されており、利用ユーザーの改善希望を受け付けております。より便利なアプリになるよう皆様のご意見をAI秘書にお知らせください。
            <?php endif; ?>
        </p>
        <p class="landing-notice-ai">
            <?php if ($landing_lang === 'en'): ?>
                * The AI secretary is still under development and may not always provide accurate information. Please verify important information independently.
            <?php elseif ($landing_lang === 'zh'): ?>
                ※AI秘书功能尚在完善中，请勿完全依赖其提供的信息，重要信息请另行确认。
            <?php else: ?>
                ※AI秘書は現状ではまだ満足のいく機能を備えてはいません。情報に関しては鵜吞みにせず個別に情報確認をお願い申し上げます。
            <?php endif; ?>
        </p>
        <p class="landing-notice-contact">
            <?php if ($landing_lang === 'en'): ?>
                To contact the operator, send "運営に連絡して" (contact operator) to the AI secretary. Your message will be forwarded to the operator's debug page for reference.
            <?php elseif ($landing_lang === 'zh'): ?>
                如需联系运营，请向AI秘书发送「运营に连络して」。内容将送达运营的调试页面以供参考。
            <?php else: ?>
                運営への連絡をご希望の方は、AI秘書に「運営に連絡して」と送信してください。内容は運営のデバッグページに届き、対応の参考にさせていただきます。
            <?php endif; ?>
        </p>
        <p class="landing-notice-pc">
            <?php if ($landing_lang === 'en'): ?>
                For organizations such as companies and neighborhood associations, we recommend using Social9 on a desktop or large screen rather than mobile.
            <?php elseif ($landing_lang === 'zh'): ?>
                法人、町内会等组织运营时，建议使用电脑大屏幕使用 Social9，而非仅用手机。
            <?php else: ?>
                法人や町内会などの組織運営においては、モバイル版よりもパソコンの大画面で Social9 をご利用いただくことを推奨します。
            <?php endif; ?>
        </p>
    </div>

    <h3 class="landing-section-title"><?= $landing_lang === 'en' ? 'Main features' : ($landing_lang === 'zh' ? '主要功能' : '主な機能') ?></h3>
    <ul class="landing-feature-list">
        <li><strong><?= $landing_lang === 'en' ? 'Group chat & DM' : ($landing_lang === 'zh' ? '群聊与私信' : 'グループチャット・DM') ?></strong> — <?= $landing_lang === 'en' ? 'Group and 1-on-1 conversations. Mentions, reply quotes, reactions.' : ($landing_lang === 'zh' ? '群组与一对一对话。@提及、引用回复、表情反应。' : '組織やチームで複数人との会話、1対1のDM。メンション・返信引用・リアクション。') ?></li>
        <li><strong><?= $landing_lang === 'en' ? 'AI secretary' : ($landing_lang === 'zh' ? 'AI秘书' : 'AI秘書') ?></strong> — <?= $landing_lang === 'en' ? 'Per-group AI assistant: morning news, task/memo search, calendar (Google), improvement feedback, conversation memory.' : ($landing_lang === 'zh' ? '每群AI助手：晨间新闻、任务与备忘录搜索、日历（Google）、改进建议、对话记忆。' : 'グループごとのAIアシスタント。朝のニュース動画、タスク・メモ検索、スケジュール（Googleカレンダー連携）、改善提案の聞き取り・記録、会話記憶・自動返信。') ?></li>
        <li><strong><?= $landing_lang === 'en' ? 'Organizations' : ($landing_lang === 'zh' ? '组织' : '組織') ?></strong> — <?= $landing_lang === 'en' ? 'Create organizations (company, group, family). Member invites, permissions, groups including private.' : ($landing_lang === 'zh' ? '创建企业、团体、家庭等组织。成员邀请、权限管理、含私密群组。' : '企業・団体・家族単位の組織を作成し、メンバー招待・権限管理。組織内でグループをまとめて管理。プライベートグループにも対応。') ?></li>
        <li><strong><?= $landing_lang === 'en' ? 'Address book' : ($landing_lang === 'zh' ? '通讯录' : '個人アドレス帳') ?></strong> — <?= $landing_lang === 'en' ? 'Add friends via QR, invite email, or contact picker.' : ($landing_lang === 'zh' ? '通过二维码、邀请邮件或通讯录添加好友。' : '友達追加、QRコード・招待メール・アドレス追加申請。端末連絡先との連携（Contact Picker）。') ?></li>
        <li><strong><?= $landing_lang === 'en' ? 'Tasks & memos' : ($landing_lang === 'zh' ? '任务与备忘录' : 'タスク・メモ') ?></strong> — <?= $landing_lang === 'en' ? 'Assign tasks from chat (multiple assignees), list and reminders; AI search.' : ($landing_lang === 'zh' ? '从聊天分配任务（可多选负责人）、列表与提醒；AI搜索。' : 'チャットからタスク依頼（担当者複数選択可）、タスク/メモ専用画面で一覧・リマインダー。AI秘書によるタスク・メモ検索。') ?></li>
        <li><strong><?= $landing_lang === 'en' ? 'Shared folder & vault' : ($landing_lang === 'zh' ? '共享文件夹与金库' : '共有フォルダ・金庫') ?></strong> — <?= $landing_lang === 'en' ? 'Group file sharing and Secure Vault for sensitive data.' : ($landing_lang === 'zh' ? '群组文件共享与安全金库保存敏感信息。' : 'グループ単位のファイル共有、Secure Vault（金庫）で重要情報を保持。') ?></li>
        <li><strong><?= $landing_lang === 'en' ? 'Voice & video calls' : ($landing_lang === 'zh' ? '语音与视频通话' : '音声・ビデオ通話') ?></strong> — <?= $landing_lang === 'en' ? 'Browser-based calls via Jitsi; incoming notifications and settings.' : ($landing_lang === 'zh' ? '通过 Jitsi 在浏览器内通话；来电通知与设置。' : 'Jitsi 連携でブラウザから通話。着信通知・通知設定あり。') ?></li>
        <li><strong><?= $landing_lang === 'en' ? 'More' : ($landing_lang === 'zh' ? '其他' : 'その他') ?></strong> — <?= $landing_lang === 'en' ? 'Design settings, Japanese / English / Chinese, push notifications, Guild rewards, search (in-org policy).' : ($landing_lang === 'zh' ? '设计设置、日英中多语言、推送通知、Guild 奖励、搜索（组织内策略）。' : 'デザイン設定、多言語（日本語・英語・中国語）、プッシュ通知、Guild（報酬分配）、検索（組織内表示名・グループ追加は組織内などポリシーあり）。') ?></li>
    </ul>

    <div class="landing-philosophy-block" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(0,0,0,0.08);">
        <p class="landing-philosophy-text" style="font-size: 14px; line-height: 1.7; color: var(--dt-text-muted, #555); margin-bottom: 8px;">
            <?php if ($landing_lang === 'en'): ?>
                Moving as humans, for humans.<br>Creating a bright and prosperous society.
            <?php elseif ($landing_lang === 'zh'): ?>
                作为人，为了人而行动。<br>创造光明富足的社会。
            <?php else: ?>
                人として 人のために動き<br>明るい豊かな社会を創造する
            <?php endif; ?>
        </p>
        <div class="landing-section-header" style="display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span style="font-size: 12px; color: #888;">Our Philosophy</span>
            <span class="brand" style="font-size: 15px; font-weight: 600; color: #6b8e23;"><?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Social9' ?></span>
        </div>
    </div>

    <h3 class="landing-section-title landing-section-title-use-cases"><?= $landing_lang === 'en' ? 'Use cases' : ($landing_lang === 'zh' ? '使用场景' : '用途・使い方') ?></h3>
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
