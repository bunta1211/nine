/**
 * ページテストランナー
 * 
 * ブラウザのコンソールで TestRunner.run() を実行すると、
 * 現在のページのボタンやリンクを自動テストします。
 * 
 * 使い方:
 * 1. ブラウザでページを開く
 * 2. F12でコンソールを開く
 * 3. TestRunner.run() を実行
 */

window.TestRunner = {
    errors: [],
    warnings: [],
    tested: [],
    
    // テスト開始
    run: function() {
        this.errors = [];
        this.warnings = [];
        this.tested = [];
        
        console.log('%c🔍 ページテスト開始...', 'color: blue; font-weight: bold; font-size: 14px');
        console.log('URL:', location.href);
        console.log('ページタイトル:', document.title);
        console.log('');
        
        // 1. JavaScript エラーをキャッチ
        this.setupErrorHandler();
        
        // 2. ボタンをテスト
        this.testButtons();
        
        // 3. リンクをテスト
        this.testLinks();
        
        // 4. フォームをテスト
        this.testForms();
        
        // 5. モーダルをテスト
        this.testModals();
        
        // 結果表示
        this.showResults();
    },
    
    setupErrorHandler: function() {
        const self = this;
        window.addEventListener('error', function(e) {
            self.errors.push({
                type: 'runtime',
                message: e.message,
                file: e.filename,
                line: e.lineno,
                col: e.colno
            });
        });
    },
    
    testButtons: function() {
        console.log('%c📌 ボタンテスト', 'color: purple; font-weight: bold');
        
        const buttons = document.querySelectorAll('button:not([disabled])');
        console.log(`  発見: ${buttons.length} 個のボタン`);
        
        buttons.forEach((btn, i) => {
            const text = btn.innerText.trim() || btn.getAttribute('title') || btn.className || `Button ${i}`;
            const hasClick = btn.onclick || btn.getAttribute('onclick');
            const hasListener = this.hasEventListener(btn);
            
            if (!hasClick && !hasListener && !btn.type) {
                this.warnings.push({
                    element: 'button',
                    text: text,
                    issue: 'クリックハンドラなし'
                });
            }
            
            this.tested.push({
                type: 'button',
                text: text.substring(0, 30),
                status: 'checked'
            });
        });
    },
    
    testLinks: function() {
        console.log('%c🔗 リンクテスト', 'color: purple; font-weight: bold');
        
        const links = document.querySelectorAll('a[href]');
        console.log(`  発見: ${links.length} 個のリンク`);
        
        links.forEach((link, i) => {
            const href = link.getAttribute('href');
            const text = link.innerText.trim() || link.getAttribute('title') || `Link ${i}`;
            
            // 空のhrefをチェック
            if (!href || href === '#') {
                const hasClick = link.onclick || link.getAttribute('onclick');
                if (!hasClick) {
                    this.warnings.push({
                        element: 'link',
                        text: text.substring(0, 30),
                        issue: '無効なhrefでクリックハンドラもなし'
                    });
                }
            }
            
            // javascript: リンク
            if (href && href.startsWith('javascript:')) {
                // OKだが記録
                this.tested.push({
                    type: 'link-js',
                    text: text.substring(0, 30),
                    status: 'javascript'
                });
            }
        });
    },
    
    testForms: function() {
        console.log('%c📝 フォームテスト', 'color: purple; font-weight: bold');
        
        const forms = document.querySelectorAll('form');
        console.log(`  発見: ${forms.length} 個のフォーム`);
        
        forms.forEach((form, i) => {
            const action = form.getAttribute('action') || '(none)';
            const method = form.getAttribute('method') || 'GET';
            const hasSubmit = form.onsubmit || form.getAttribute('onsubmit');
            
            // 入力フィールドチェック
            const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
            
            this.tested.push({
                type: 'form',
                text: `Form ${i+1} (${method} ${action})`,
                status: inputs.length > 0 ? `必須項目${inputs.length}個` : 'OK'
            });
        });
    },
    
    testModals: function() {
        console.log('%c🪟 モーダルテスト', 'color: purple; font-weight: bold');
        
        const modals = document.querySelectorAll('.modal, [role="dialog"], .popup, .overlay');
        console.log(`  発見: ${modals.length} 個のモーダル/ポップアップ`);
        
        modals.forEach((modal, i) => {
            const id = modal.id || `Modal ${i+1}`;
            const isHidden = modal.style.display === 'none' || 
                            modal.classList.contains('hidden') ||
                            getComputedStyle(modal).display === 'none';
            
            this.tested.push({
                type: 'modal',
                text: id,
                status: isHidden ? '非表示' : '表示中'
            });
        });
    },
    
    hasEventListener: function(element) {
        // jQueryイベントをチェック
        if (window.jQuery) {
            const events = jQuery._data(element, 'events');
            if (events && events.click) return true;
        }
        return false;
    },
    
    showResults: function() {
        console.log('');
        console.log('%c📊 テスト結果', 'color: green; font-weight: bold; font-size: 14px');
        console.log('─'.repeat(40));
        
        // テストした項目
        console.log(`✅ テスト項目: ${this.tested.length}`);
        
        // 警告
        if (this.warnings.length > 0) {
            console.log(`⚠️ 警告: ${this.warnings.length}`);
            this.warnings.forEach(w => {
                console.warn(`  - [${w.element}] ${w.text}: ${w.issue}`);
            });
        } else {
            console.log('⚠️ 警告: 0');
        }
        
        // エラー
        if (this.errors.length > 0) {
            console.log(`❌ エラー: ${this.errors.length}`);
            this.errors.forEach(e => {
                console.error(`  - ${e.message} (${e.file}:${e.line})`);
            });
        } else {
            console.log('❌ エラー: 0');
        }
        
        console.log('─'.repeat(40));
        
        // サマリー
        if (this.errors.length === 0 && this.warnings.length === 0) {
            console.log('%c✨ すべてのテストに合格！', 'color: green; font-weight: bold');
        } else {
            console.log('%c⚠️ 問題が見つかりました', 'color: orange; font-weight: bold');
        }
        
        return {
            tested: this.tested.length,
            warnings: this.warnings.length,
            errors: this.errors.length
        };
    },
    
    // 全ページリスト
    getPageList: function() {
        return [
            { url: '/', name: 'ログイン' },
            { url: '/chat.php', name: 'チャット（メイン）' },
            { url: '/settings.php', name: '設定' },
            { url: '/tasks.php', name: 'タスク' },
            { url: '/memos.php', name: 'メモ' },
            { url: '/notifications.php', name: '通知' },
            { url: '/design.php', name: 'デザイン' },
            { url: '/ai_chat.php', name: 'AIチャット' },
            { url: '/admin/index.php', name: '管理ダッシュボード' },
            { url: '/admin/users.php', name: 'ユーザー管理' },
            { url: '/admin/members.php', name: '組織メンバー管理' },
            { url: '/admin/groups.php', name: 'グループ管理' },
            { url: '/admin/reports.php', name: '通報管理' },
            { url: '/admin/security.php', name: 'セキュリティ' },
            { url: '/admin/monitor.php', name: '監視ダッシュボード' },
            { url: '/admin/attackers.php', name: '攻撃者情報' },
            { url: '/admin/settings.php', name: 'システム設定' },
            { url: '/admin/logs.php', name: 'システムログ' },
            { url: '/admin/backup.php', name: 'バックアップ' }
        ];
    },
    
    // ページリスト表示
    showPageList: function() {
        console.log('%c📋 テスト対象ページ一覧', 'color: blue; font-weight: bold; font-size: 14px');
        this.getPageList().forEach((page, i) => {
            console.log(`  ${i+1}. ${page.name} - ${page.url}`);
        });
    },
    
    // 使い方表示
    help: function() {
        console.log('%c🔧 TestRunner 使い方', 'color: blue; font-weight: bold; font-size: 14px');
        console.log('');
        console.log('TestRunner.run()      - 現在のページをテスト');
        console.log('TestRunner.showPageList() - テスト対象ページ一覧');
        console.log('TestRunner.help()     - このヘルプを表示');
    }
};

// 自動的にヘルプを表示
console.log('%c🧪 TestRunner が読み込まれました', 'color: green; font-weight: bold');
console.log('TestRunner.help() でヘルプを表示');
