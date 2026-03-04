<?php
/**
 * Guild ヘルプページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('help');

require_once __DIR__ . '/templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><?= __('help') ?></h1>
</div>

<div class="help-container">
    <div class="help-nav">
        <a href="#about" class="help-nav-item active">Guildとは</a>
        <a href="#requests" class="help-nav-item">依頼の種類</a>
        <a href="#earth" class="help-nav-item">Earthについて</a>
        <a href="#calendar" class="help-nav-item">カレンダー</a>
        <a href="#availability" class="help-nav-item">受付状況</a>
        <a href="#roles" class="help-nav-item">役職と権限</a>
        <a href="#advance" class="help-nav-item">前借り</a>
        <a href="#contact" class="help-nav-item">お問い合わせ</a>
    </div>
    
    <div class="help-content">
        <section id="about" class="help-section">
            <h2>Guildとは</h2>
            <p>Guildは社内の報酬分配システムです。業務依頼を出し合い、引き受けた仕事に対してEarthという社内通貨で報酬を受け取ることができます。</p>
            <ul>
                <li>人が受けたくない仕事、責任者がお願いしたい仕事を前向きに立候補で受けてもらえる仕組みです</li>
                <li>獲得したEarthは現金（1 Earth = <?= EARTH_TO_YEN ?>円）として支給されます</li>
                <li>自分のEarthを使って他の職員に個人的な依頼を出すこともできます</li>
            </ul>
        </section>
        
        <section id="requests" class="help-section">
            <h2>依頼の種類</h2>
            <div class="help-cards">
                <div class="help-card">
                    <h3>📋 一般依頼</h3>
                    <p>すべてのメンバーが応募可能な依頼です。ギルド予算から報酬が支払われます。</p>
                </div>
                <div class="help-card">
                    <h3>👤 指名依頼</h3>
                    <p>特定のメンバーを指名して依頼します。指名された人のみが受けられます。</p>
                </div>
                <div class="help-card">
                    <h3>⚡ 業務指令</h3>
                    <p>リーダー・サブリーダーからの業務命令です。原則として受ける必要があります。</p>
                </div>
                <div class="help-card">
                    <h3>🔄 勤務交代依頼</h3>
                    <p>シフトの交代をお願いする依頼です。リーダー・サブリーダーの承認が必要です。</p>
                </div>
                <div class="help-card">
                    <h3>💰 個人依頼</h3>
                    <p>自分のEarthを使って他のメンバーに依頼を出します。</p>
                </div>
                <div class="help-card">
                    <h3>💝 感謝の気持ち</h3>
                    <p>感謝の気持ちとしてEarthを送ります。匿名で送ることも可能です。</p>
                </div>
                <div class="help-card">
                    <h3>🏆 特別報酬</h3>
                    <p>期待以上の仕事に対する特別な報酬です。リーダーのみ発行可能です。</p>
                </div>
            </div>
        </section>
        
        <section id="earth" class="help-section">
            <h2>Earthについて</h2>
            <h3>獲得方法</h3>
            <ul>
                <li>依頼を引き受けて完了する</li>
                <li>感謝の気持ちを受け取る</li>
                <li>特別報酬を受け取る</li>
                <li>年度初めの在籍期間ボーナス（1年につき500 Earth）</li>
                <li>役職ボーナス</li>
            </ul>
            
            <h3>使用方法</h3>
            <ul>
                <li>他のメンバーへの個人依頼</li>
                <li>感謝の気持ちを送る</li>
            </ul>
            
            <h3>支払いスケジュール</h3>
            <ul>
                <li>4〜6月分 → 8月20日支給</li>
                <li>7〜9月分 → 9月に支給</li>
                <li>10〜12月分、1〜3月分 → 3月に支給（年度末精算）</li>
                <li>前借り申請可能（未支給の80%まで）</li>
            </ul>
        </section>
        
        <section id="calendar" class="help-section">
            <h2>カレンダー</h2>
            <p>カレンダーでは自分の勤務予定日や休日を入力できます。</p>
            <ul>
                <li><strong>勤務</strong>: 出勤予定日</li>
                <li><strong>休日</strong>: お休みの日</li>
                <li><strong>有休</strong>: 有給休暇</li>
            </ul>
            <p>休日に設定している日に依頼された場合は、ポップアップで警告が表示されます。</p>
        </section>
        
        <section id="availability" class="help-section">
            <h2>受付状況</h2>
            <p>依頼の受付状況を設定することで、他のメンバーに忙しさを伝えることができます。</p>
            <ul>
                <li><strong>受付中</strong>: 新規依頼を積極的に受け付けています</li>
                <li><strong>余裕あり</strong>: 少し忙しいですが、依頼を受けられます</li>
                <li><strong>不可</strong>: 現在、新規依頼を受け付けていません</li>
            </ul>
            <p>パーセンテージで細かく設定することもできます。</p>
        </section>
        
        <section id="roles" class="help-section">
            <h2>役職と権限</h2>
            <div class="help-cards">
                <div class="help-card">
                    <h3>👑 ギルドリーダー</h3>
                    <ul>
                        <li>ギルドの年間予算管理</li>
                        <li>すべての依頼タイプの発行</li>
                        <li>メンバーの追加・削除</li>
                        <li>依頼発行権限の付与</li>
                    </ul>
                </div>
                <div class="help-card">
                    <h3>⭐ サブリーダー</h3>
                    <ul>
                        <li>依頼の発行（業務指令含む）</li>
                        <li>報酬金額の設定</li>
                        <li>メンバーの追加・削除</li>
                        <li>勤務交代の承認</li>
                    </ul>
                </div>
                <div class="help-card">
                    <h3>🎯 コーディネーター</h3>
                    <ul>
                        <li>ギルド予算からの依頼発行</li>
                        <li>メンバーの追加・削除</li>
                    </ul>
                </div>
                <div class="help-card">
                    <h3>👤 メンバー</h3>
                    <ul>
                        <li>依頼への応募・受諾</li>
                        <li>個人Earthでの依頼発行</li>
                        <li>感謝の気持ちの送信</li>
                    </ul>
                </div>
            </div>
        </section>
        
        <section id="advance" class="help-section">
            <h2>前借り</h2>
            <p>通常の支給日より前にEarthを現金で受け取りたい場合、前借り申請ができます。</p>
            <ul>
                <li>未支給Earthの80%まで前借り可能</li>
                <li>給与日に支給されます</li>
                <li>システム管理者の承認が必要です</li>
            </ul>
        </section>
        
        <section id="contact" class="help-section">
            <h2>お問い合わせ</h2>
            <p>ご不明な点がございましたら、システム管理者までお問い合わせください。</p>
        </section>
    </div>
</div>

<style>
.help-container {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: var(--spacing-xl);
}

@media (max-width: 768px) {
    .help-container {
        grid-template-columns: 1fr;
    }
    
    .help-nav {
        display: flex;
        flex-wrap: wrap;
        gap: var(--spacing-sm);
    }
}

.help-nav {
    position: sticky;
    top: var(--spacing-lg);
    height: fit-content;
}

.help-nav-item {
    display: block;
    padding: var(--spacing-sm) var(--spacing-md);
    color: var(--color-text-secondary);
    text-decoration: none;
    border-radius: var(--radius-md);
    transition: all var(--transition-fast);
}

.help-nav-item:hover, .help-nav-item.active {
    background: var(--color-bg-hover);
    color: var(--color-primary);
}

.help-section {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-lg);
    scroll-margin-top: var(--spacing-lg);
}

.help-section h2 {
    font-size: var(--font-size-xl);
    margin-bottom: var(--spacing-md);
    color: var(--color-primary);
}

.help-section h3 {
    font-size: var(--font-size-lg);
    margin-top: var(--spacing-lg);
    margin-bottom: var(--spacing-sm);
}

.help-section ul {
    padding-left: var(--spacing-lg);
    margin-bottom: var(--spacing-md);
}

.help-section li {
    margin-bottom: var(--spacing-xs);
}

.help-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-md);
    margin-top: var(--spacing-md);
}

.help-card {
    background: var(--color-bg-hover);
    border-radius: var(--radius-md);
    padding: var(--spacing-md);
}

.help-card h3 {
    margin-top: 0;
    font-size: var(--font-size-base);
}

.help-card ul {
    font-size: var(--font-size-sm);
    margin-bottom: 0;
}
</style>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
