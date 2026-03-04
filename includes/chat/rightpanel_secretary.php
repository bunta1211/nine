<?php
/**
 * AI秘書（AIクローン）専用 右パネル
 *
 * - 訓練言語の選択
 * - 判断材料フォルダ（CRUD）
 * - 会話記憶（自動分析結果の表示）
 * - 自動返信トグル＋修正率
 */
?>
<aside class="right-panel sec-right-panel" id="secretaryRightPanel" style="display:none">
    <button class="mobile-close-panel" onclick="closeMobileRightPanel()" aria-label="パネルを閉じる">×</button>
    <div class="right-header">
        <h3>AIクローン育成</h3>
    </div>

    <div class="right-panel-scroll">
        <!-- 訓練言語 -->
        <div class="right-section sec-rp-section">
            <div class="section-header" onclick="toggleSection(this)">
                <h3><img src="assets/icons/line/globe.svg" alt="" class="icon-line icon-line--sm" width="16" height="16" onerror="this.style.display='none'"> 訓練言語</h3>
                <span class="toggle-icon">▽</span>
            </div>
            <div class="section-content">
                <select id="secCloneLang" class="sec-rp-select" onchange="SecRP.saveLang(this.value)">
                    <option value="ja">日本語</option>
                    <option value="en">English</option>
                    <option value="zh">中文</option>
                </select>
            </div>
        </div>

        <!-- 判断材料フォルダ -->
        <div class="right-section sec-rp-section">
            <div class="section-header" onclick="toggleSection(this)">
                <h3><img src="assets/icons/line/folder.svg" alt="" class="icon-line icon-line--sm" width="16" height="16" onerror="this.style.display='none'"> 判断材料</h3>
                <span class="toggle-icon">▽</span>
            </div>
            <div class="section-content">
                <div id="secJudgmentTree" class="sec-rp-tree">
                    <div class="sec-rp-loading">読み込み中...</div>
                </div>
                <button class="sec-rp-add-btn" onclick="SecRP.addFolder()">
                    <span class="plus-icon">＋</span> フォルダを追加
                </button>
            </div>
        </div>

        <!-- 会話記憶 -->
        <div class="right-section sec-rp-section">
            <div class="section-header" onclick="toggleSection(this)">
                <h3><img src="assets/icons/line/brain.svg" alt="" class="icon-line icon-line--sm" width="16" height="16" onerror="this.style.display='none'"> 会話記憶</h3>
                <span class="toggle-icon">▽</span>
            </div>
            <div class="section-content">
                <div id="secConvMemory" class="sec-rp-memory">
                    <p class="sec-rp-muted">自動分析がまだ実行されていません。</p>
                </div>
                <button class="sec-rp-action-btn" onclick="SecRP.analyzeMemory()">
                    <span>🔄</span> 今すぐ再分析
                </button>
            </div>
        </div>

        <!-- 自動返信 -->
        <div class="right-section sec-rp-section">
            <div class="section-header" onclick="toggleSection(this)">
                <h3><img src="assets/icons/line/zap.svg" alt="" class="icon-line icon-line--sm" width="16" height="16" onerror="this.style.display='none'"> 自動返信</h3>
                <span class="toggle-icon">▽</span>
            </div>
            <div class="section-content">
                <div class="sec-rp-autoreply-stats" id="secAutoReplyStats">
                    <div class="sec-rp-stat-row">
                        <span>送信済み提案</span>
                        <span id="secStatTotal">-</span>
                    </div>
                    <div class="sec-rp-stat-row">
                        <span>修正率</span>
                        <span id="secStatRate">-</span>
                    </div>
                    <div class="sec-rp-stat-row">
                        <span>自動返信資格</span>
                        <span id="secStatEligible">-</span>
                    </div>
                </div>
                <label class="sec-rp-toggle-label" id="secAutoReplyLabel" style="display:none">
                    <input type="checkbox" id="secAutoReplyToggle" onchange="SecRP.saveAutoReply(this.checked)">
                    <span>AI自動返信を有効にする</span>
                </label>
                <p class="sec-rp-muted sec-rp-note">修正率が20%以下・20件以上の送信実績で自動返信が有効になります。</p>
            </div>
        </div>
    </div>
</aside>
