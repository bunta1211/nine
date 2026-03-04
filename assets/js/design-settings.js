/**
 * デザイン設定ページ用JavaScript
 * 
 * 依存関係:
 * - グローバル変数: settings, themesData, stylesData, fontsData, transparentStyles
 *   (PHPからインライン生成)
 */

/** APIのベースURL（サブディレクトリ対応） */
function getApiUrl(path) {
    const base = (typeof API_BASE !== 'undefined' && API_BASE) ? API_BASE : '';
    const urlPath = base ? (base + path) : path.replace(/^\//, '');
    // 絶対パス（/で始まる）の場合は origin を付与して fetch の挙動を確実に
    if (urlPath.startsWith('/') && typeof window !== 'undefined') {
        return window.location.origin + urlPath;
    }
    return urlPath;
}

/**
 * 透明テーマのプレビュースタイルを適用/解除
 * インラインスタイルではなく、CSSカスタムプロパティを動的に設定
 * @param {boolean} enable - trueで適用、falseで解除
 * @param {Object} customStyles - カスタムスタイル（オプション）
 */
function applyTransparentPreviewStyles(enable, customStyles = null) {
    if (typeof transparentStyles === 'undefined') {
        console.warn('transparentStyles is not defined');
        return;
    }
    
    const root = document.documentElement;
    
    if (enable) {
        // カスタムスタイルがある場合はそれを使用、なければデフォルト
        const msgBg = customStyles?.messageBg || transparentStyles.preview_message_other?.background || 'rgba(40,40,60,0.9)';
        const msgText = customStyles?.messageText || transparentStyles.preview_message_other?.color || '#ffffff';
        const msgTime = customStyles?.messageTime || msgText;
        const inputBg = customStyles?.inputBg || transparentStyles.preview_input?.background || 'rgba(255,255,255,0.95)';
        const inputText = customStyles?.inputText || transparentStyles.preview_input?.color || '#1a1a1a';
        const inputBorder = customStyles?.inputBorder || transparentStyles.preview_input?.borderColor || 'rgba(100,100,100,0.3)';
        const leftPanelBg = customStyles?.leftPanelBg || customStyles?.panelBg || 'rgba(255,255,255,0.85)';
        const leftPanelText = customStyles?.leftPanelText || customStyles?.panelText || '#1a1a1a';
        
        // デザイントークンをCSSカスタムプロパティとして設定（左パネル・中央・入力欄をデザインページで反映）
        root.style.setProperty('--dt-msg-self-bg', msgBg);
        root.style.setProperty('--dt-msg-self-text', msgText);
        root.style.setProperty('--dt-msg-self-time', msgTime);
        root.style.setProperty('--dt-msg-other-bg', msgBg);
        root.style.setProperty('--dt-msg-other-text', msgText);
        root.style.setProperty('--dt-msg-other-time', msgTime);
        root.style.setProperty('--dt-input-bg', inputBg);
        root.style.setProperty('--dt-input-text', inputText);
        root.style.setProperty('--dt-input-border', inputBorder);
        root.style.setProperty('--dt-left-bg', leftPanelBg);
        root.style.setProperty('--dt-left-text', leftPanelText);
        root.style.setProperty('--dt-center-input-bg', inputBg);
        root.style.setProperty('--dt-center-bg', 'transparent');
        root.style.setProperty('--dt-right-bg', leftPanelBg);
        root.style.setProperty('--dt-right-text', leftPanelText);
        
        // パネルのぼかしなし
        document.querySelectorAll('.left-panel, .center-panel, .right-panel').forEach(panel => {
            panel.style.setProperty('backdrop-filter', 'none', 'important');
            panel.style.setProperty('-webkit-backdrop-filter', 'none', 'important');
        });
        document.body.classList.add('theme-transparent');
    } else {
        // CSSカスタムプロパティを削除（透明用に設定した分）
        root.style.removeProperty('--dt-msg-self-bg');
        root.style.removeProperty('--dt-msg-self-text');
        root.style.removeProperty('--dt-msg-self-time');
        root.style.removeProperty('--dt-msg-other-bg');
        root.style.removeProperty('--dt-msg-other-text');
        root.style.removeProperty('--dt-msg-other-time');
        root.style.removeProperty('--dt-input-bg');
        root.style.removeProperty('--dt-input-text');
        root.style.removeProperty('--dt-input-border');
        root.style.removeProperty('--dt-left-bg');
        root.style.removeProperty('--dt-left-text');
        root.style.removeProperty('--dt-center-input-bg');
        root.style.removeProperty('--dt-center-bg');
        root.style.removeProperty('--dt-right-bg');
        root.style.removeProperty('--dt-right-text');
        
        document.querySelectorAll('.left-panel, .center-panel, .right-panel').forEach(panel => {
            panel.style.removeProperty('backdrop-filter');
            panel.style.removeProperty('-webkit-backdrop-filter');
        });
        document.body.classList.remove('theme-transparent');
    }
}

/**
 * テーマのデザイントークン（--dt-*）を :root に適用（左パネル・中央・入力欄の即時反映用）
 * @param {string} themeId - テーマID
 */
function applyDesignTokensToRoot(themeId) {
    if (typeof themesData === 'undefined' || !Array.isArray(themesData)) return;
    const theme = themesData.find(t => t.id === themeId);
    if (!theme) return;
    const root = document.documentElement;
    if (theme.dtLeftBg) root.style.setProperty('--dt-left-bg', theme.dtLeftBg);
    if (theme.dtLeftText) root.style.setProperty('--dt-left-text', theme.dtLeftText);
    if (theme.dtRightBg) root.style.setProperty('--dt-right-bg', theme.dtRightBg);
    if (theme.dtRightText) root.style.setProperty('--dt-right-text', theme.dtRightText);
    if (theme.dtCenterBg) root.style.setProperty('--dt-center-bg', theme.dtCenterBg);
    if (theme.dtCenterHeaderBg) root.style.setProperty('--dt-center-header-bg', theme.dtCenterHeaderBg);
    if (theme.dtCenterHeaderText) root.style.setProperty('--dt-center-header-text', theme.dtCenterHeaderText);
    if (theme.dtCenterInputBg) root.style.setProperty('--dt-center-input-bg', theme.dtCenterInputBg);
    if (theme.dtInputBg) root.style.setProperty('--dt-input-bg', theme.dtInputBg);
    if (theme.dtInputText) root.style.setProperty('--dt-input-text', theme.dtInputText);
    if (theme.dtInputPlaceholder) root.style.setProperty('--dt-input-placeholder', theme.dtInputPlaceholder);
    if (theme.dtInputBorder) root.style.setProperty('--dt-input-border', theme.dtInputBorder);
    if (theme.dtDivider) root.style.setProperty('--dt-divider', theme.dtDivider);
    if (theme.otherMsgBg) root.style.setProperty('--dt-msg-other-bg', theme.otherMsgBg);
    if (theme.otherMsgText !== undefined) root.style.setProperty('--dt-msg-other-text', theme.otherMsgText);
    if (theme.selfMsgBg) root.style.setProperty('--dt-msg-self-bg', theme.selfMsgBg);
    if (theme.selfMsgText !== undefined) root.style.setProperty('--dt-msg-self-text', theme.selfMsgText || '#ffffff');
}

/**
 * テーマ選択
 * @param {HTMLElement} element - クリックされたテーマ要素
 */
function selectTheme(element) {
    const themeId = element.dataset.theme;
    const headerGradient = element.dataset.header;
    const bgColor = element.dataset.bg;
    const accent = element.dataset.accent;
    const panelBg = element.dataset.panelBg;
    const textColor = element.dataset.textColor;
    const textMuted = element.dataset.textMuted;
    const cardBg = element.dataset.cardBg;
    const otherMsgBg = element.dataset.otherMsgBg;
    const selfMsgBg = element.dataset.selfMsgBg;
    const isTransparent = element.dataset.isTransparent === '1';
    
    settings.theme = themeId;
    
    // data属性を更新（CSS詳細度活用）
    document.body.dataset.theme = themeId;
    
    // 左パネル・中央・入力欄をデザイントークンで即時反映（design.php の var(--dt-*) に効かせる）
    applyDesignTokensToRoot(themeId);
    
    // UIを更新
    document.querySelectorAll('.theme-item').forEach(item => item.classList.remove('active'));
    document.querySelectorAll('.recommended-item').forEach(item => item.classList.remove('active'));
    element.classList.add('active');
    
    // 透明テーマ（transparent）の場合はカスタム背景セクションを表示
    const customBgSection = document.getElementById('customBgSection');
    if (customBgSection) {
        const isTransparentTheme = (themeId === 'transparent');
        customBgSection.style.display = isTransparentTheme ? 'block' : 'none';
    }
    
    // 画面に反映（!important付きで上書き）
    document.getElementById('topPanel').style.setProperty('background', headerGradient, 'important');
    document.body.style.setProperty('background', bgColor, 'important');
    document.documentElement.style.setProperty('background', bgColor, 'important');
    
    // パネル・中央は --dt-* で効くが、フォールバックとして直接も設定
    document.querySelectorAll('.left-panel, .right-panel').forEach(panel => {
        panel.style.setProperty('background', panelBg, 'important');
        panel.style.setProperty('color', textColor, 'important');
    });
    document.querySelectorAll('.center-panel').forEach(panel => {
        panel.style.setProperty('background', panelBg, 'important');
        panel.style.setProperty('color', textColor, 'important');
    });
    
    // カード類の色を更新
    document.querySelectorAll('.theme-item, .style-item, .font-item, .conv-item').forEach(card => {
        if (!card.classList.contains('active')) {
            card.style.setProperty('background', cardBg, 'important');
        }
        card.style.setProperty('color', textColor, 'important');
    });
    
    // テキストカラーを更新
    document.querySelectorAll('.setting-section-title, .preview-header').forEach(el => {
        el.style.setProperty('color', textMuted, 'important');
    });
    document.querySelectorAll('.style-name, .font-name, .theme-item-name').forEach(el => {
        el.style.setProperty('color', textColor, 'important');
    });
    document.querySelectorAll('.style-desc, .font-desc').forEach(el => {
        el.style.setProperty('color', textMuted, 'important');
    });
    
    // 相手のメッセージ背景を更新
    let actualOtherMsgBg = otherMsgBg;
    let actualOtherMsgColor = isTransparent ? 'rgba(255,255,255,0.95)' : textColor;
    let actualSelfMsgBg = selfMsgBg || accent;
    let actualSelfMsgColor = 'rgba(255,255,255,1)';
    
    document.querySelectorAll('.preview-message.other').forEach(msg => {
        msg.style.setProperty('background', actualOtherMsgBg, 'important');
        msg.style.setProperty('color', actualOtherMsgColor, 'important');
    });
    
    // 自分のメッセージ背景を更新
    document.querySelectorAll('.preview-message.self').forEach(msg => {
        msg.style.setProperty('background', actualSelfMsgBg, 'important');
        msg.style.setProperty('color', actualSelfMsgColor, 'important');
    });
    
    // 入力欄のテキスト色を更新（--dt-input-* も applyDesignTokensToRoot で設定済み）
    document.querySelectorAll('.preview-input, input, textarea').forEach(input => {
        input.style.setProperty('color', isTransparent ? 'rgba(30,30,50,0.9)' : textColor, 'important');
    });
    
    // CSS変数も更新
    document.documentElement.style.setProperty('--theme-header', headerGradient);
    document.documentElement.style.setProperty('--theme-bg', bgColor);
    document.documentElement.style.setProperty('--theme-accent', accent);
    document.documentElement.style.setProperty('--theme-text', textColor);
    document.documentElement.style.setProperty('--theme-text-muted', textMuted);
    
    // アクセントカラーも更新
    setAccentColor(accent, true);
    
    // 透明テーマの場合は背景画像セクションを表示
    const bgSection = document.getElementById('backgroundSection');
    if (bgSection) {
        bgSection.style.display = (themeId === 'transparent') ? 'block' : 'none';
    }
    
    // 透明テーマのプレビュースタイルを適用/解除
    applyTransparentPreviewStyles(themeId === 'transparent');
    
    // 設定を保存してページをリロード
    saveSettingsAndReload();
}

/**
 * 設定を保存してページをリロード
 */
async function saveSettingsAndReload() {
    const apiUrl = getApiUrl('/api/settings.php');
    const payload = { action: 'update_design', ...settings };
    try {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        if (!response.ok) {
            alert('保存に失敗しました: HTTP ' + response.status);
            return;
        }
        const data = await response.json().catch(() => ({ success: false, error: 'レスポンスの解析に失敗しました' }));
        if (typeof console !== 'undefined' && console.log) {
            console.log('Design save response:', data);
        }
        localStorage.setItem('social9_design', JSON.stringify(settings));
        if (!data.success) {
            alert('保存に失敗しました: ' + (data.error || '不明なエラー'));
            return;
        }
    } catch (e) {
        if (typeof console !== 'undefined' && console.error) {
            console.error('Design save error:', e);
        }
        alert('保存に失敗しました: ' + (e.message || 'ネットワークエラー'));
        return;
    }
    location.reload();
}

/**
 * カスタム背景画像選択を開く（ファイルピッカーを直接開く）
 * @param {HTMLElement} element - クリックされた要素
 */
function openCustomBackgroundPicker(element) {
    // 透明テーマとして選択
    const themeId = element.dataset.theme;
    if (settings.theme === 'transparent') {
        settings.theme = settings.theme; // 維持
    } else {
        settings.theme = themeId;
    }
    
    // data属性を更新
    document.body.dataset.theme = settings.theme;
    
    // UIを更新
    document.querySelectorAll('.theme-item').forEach(item => item.classList.remove('active'));
    document.querySelectorAll('.recommended-item').forEach(item => item.classList.remove('active'));
    element.classList.add('active');
    
    // カスタム背景セクションを表示
    const customBgSection = document.getElementById('customBgSection');
    if (customBgSection) {
        customBgSection.style.display = 'block';
    }
    
    // 透明テーマのプレビュースタイルを適用
    applyTransparentPreviewStyles(true);
    
    // ファイル選択ダイアログを開く
    const backgroundInput = document.getElementById('backgroundInput');
    if (backgroundInput) backgroundInput.click();
}

/**
 * 背景画像アップロード
 * @param {HTMLInputElement} input - ファイル入力要素
 */
async function uploadBackground(input) {
    if (!input.files || !input.files[0]) return;
    
    const file = input.files[0];
    
    // ファイルサイズチェック（maxSizeMBはPHPから注入）
    const maxSize = (typeof maxSizeMB !== 'undefined' ? maxSizeMB : 10);
    if (file.size > maxSize * 1024 * 1024) {
        alert(`ファイルサイズは${maxSize}MB以下にしてください`);
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'upload_background');
    formData.append('background_image', file);
    
    try {
        const response = await fetch(getApiUrl('/api/settings.php') + '?action=upload_background', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            // プレビューを更新
            const preview = document.getElementById('backgroundPreview');
            if (preview) {
                preview.innerHTML = `
                    <img src="${data.url}?t=${Date.now()}" alt="背景">
                    <button class="remove-bg-btn" onclick="removeBackground()" title="削除">×</button>
                `;
            }
            
            // カスタム背景セクションを表示
            const customBgSection = document.getElementById('customBgSection');
            if (customBgSection) {
                customBgSection.style.display = 'block';
            }
            
            // サンプル選択を解除
            document.querySelectorAll('.sample-bg-item').forEach(item => item.classList.remove('active'));
            document.querySelectorAll('.recommended-item').forEach(item => item.classList.remove('active'));
            
            // 背景画像を適用
            applyBackgroundImage(data.url);
            
            // 設定を更新（透明系テーマの場合は維持）
            if (settings.theme !== 'transparent') {
                settings.theme = 'transparent';
            }
            settings.background_image = data.filename;
            
            // パネルを透明に
            document.querySelectorAll('.left-panel, .right-panel, .center-panel').forEach(panel => {
                panel.style.setProperty('background', 'rgba(255,255,255,0.85)', 'important');
            });
            
            // サーバーに設定を保存してリロード
            await fetch(getApiUrl('/api/settings.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_design',
                    theme: settings.theme,
                    background_image: data.filename
                })
            });
            
            localStorage.setItem('social9_design', JSON.stringify(settings));
            
            // ページをリロードして反映
            location.reload();
        } else {
            alert(data.error || 'アップロードに失敗しました');
        }
    } catch (error) {
        console.error('Upload error:', error);
        alert('アップロードに失敗しました');
    }
    
    // inputをリセット
    input.value = '';
}

/**
 * 背景画像を削除
 */
async function removeBackground() {
    if (!confirm('背景画像を削除しますか？')) return;
    
    try {
        const response = await fetch(getApiUrl('/api/settings.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_background' })
        });
        const data = await response.json();
        
        if (data.success) {
            // プレビューを更新（標準デザイン固定で要素が存在しない場合あり）
            const preview = document.getElementById('backgroundPreview');
            if (preview) {
                preview.innerHTML = `
                    <div class="no-background">
                        <span>📷</span>
                        <span>画像を選択</span>
                    </div>
                `;
            }
            // 背景画像を削除
            document.body.style.removeProperty('background-image');
            settings.background_image = 'none';
        }
    } catch (error) {
        console.error('Delete error:', error);
    }
}

/**
 * 背景画像を適用
 * @param {string} url - 画像URL
 * @param {boolean} isPattern - パターン背景かどうか
 * @param {string} patternSize - パターンサイズ（例: '200px'）
 */
function applyBackgroundImage(url, isPattern = false, patternSize = '300px') {
    // まずショートハンドプロパティをクリア（個別プロパティを上書きしないように）
    document.body.style.removeProperty('background');
    document.documentElement.style.removeProperty('background');
    
    if (url && url !== 'none') {
        document.body.style.setProperty('background-image', `url('${url}')`, 'important');
        document.body.style.setProperty('background-color', 'transparent', 'important');
        if (isPattern) {
            // パターン背景：繰り返し表示
            document.body.style.setProperty('background-size', patternSize, 'important');
            document.body.style.setProperty('background-position', 'top left', 'important');
            document.body.style.setProperty('background-attachment', 'fixed', 'important');
            document.body.style.setProperty('background-repeat', 'repeat', 'important');
        } else {
            // 通常背景：カバー表示
            document.body.style.setProperty('background-size', 'cover', 'important');
            document.body.style.setProperty('background-position', 'center center', 'important');
            document.body.style.setProperty('background-attachment', 'fixed', 'important');
            document.body.style.setProperty('background-repeat', 'no-repeat', 'important');
        }
    } else {
        document.body.style.removeProperty('background-image');
        document.body.style.removeProperty('background-size');
        document.body.style.removeProperty('background-position');
        document.body.style.removeProperty('background-attachment');
        document.body.style.removeProperty('background-repeat');
    }
}

/**
 * おすすめデザインを選択（透明テーマ＋指定背景）
 * @param {string} designId - デザインID（fuji, snow）
 */
async function selectRecommendedDesign(designId) {
    const sampleImages = {
        'fuji': 'assets/samples/fuji.jpg',
        'snow': 'assets/samples/snow.jpg',
        'suika': 'assets/samples/suika01.jpg',
        'yukidaruma': 'assets/samples/yukidaruma01.jpg',
        'tokei_clover': 'assets/samples/tokei_clover01.jpg',
        'city': 'assets/samples/city01.jpg'
    };
    const sampleFileNames = {
        'fuji': 'sample_fuji.jpg',
        'snow': 'sample_snow.jpg',
        'suika': 'sample_suika01.jpg',
        'yukidaruma': 'sample_yukidaruma01.jpg',
        'tokei_clover': 'sample_tokei_clover01.jpg',
        'city': 'sample_city01.jpg'
    };
    
    // 各デザインに合わせた配色・スタイル設定
    // font_familyは getFontConfigs() の有効なID: default, zen-maru, yomogi, kosugi-maru, noto-sans
    // isPattern: パターン背景として繰り返し表示するかどうか
    // patternSize: パターンのサイズ
    // messageBg/messageText: メッセージの背景色と文字色（統一ルール）
    const designStyles = {
        'fuji': {
            ui_style: 'natural',
            font_family: 'noto-sans',
            isPattern: false,
            messageBg: 'rgba(80,90,110,0.7)',
            messageText: '#ffffff'
        },
        'snow': {
            ui_style: 'natural',
            font_family: 'noto-sans',
            isPattern: false,
            messageBg: 'linear-gradient(135deg, rgba(245,248,255,0.92) 0%, rgba(238,242,255,0.88) 100%)',
            messageText: '#1e1b4b'
        },
        'suika': {
            ui_style: 'cute',
            font_family: 'kosugi-maru',
            isPattern: false,
            messageBg: 'linear-gradient(135deg, rgba(220,252,231,0.9) 0%, rgba(187,247,208,0.85) 100%)',
            messageText: '#1a1a1a'
        },
        'yukidaruma': {
            ui_style: 'natural',
            font_family: 'zen-maru',
            isPattern: false,
            messageBg: 'linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(248,254,255,0.92) 100%)',
            messageText: '#0c4a6e'
        },
        'city': {
            ui_style: 'classic',
            font_family: 'noto-sans',
            isPattern: false,
            messageBg: 'linear-gradient(135deg, #ffffff 0%, #f8fafc 100%)',
            messageText: '#1e293b'
        },
        'tokei_clover': {
            ui_style: 'classic',
            font_family: 'noto-sans',
            isPattern: false,
            messageBg: 'rgba(255,255,255,0.85)',
            messageText: '#1a3d1a'
        }
    };
    
    const bgUrl = sampleImages[designId];
    const bgImage = sampleFileNames[designId];
    const styleConfig = designStyles[designId] || {};
    
    if (!bgUrl || !bgImage) {
        console.error('Invalid designId:', designId);
        return;
    }
    
    console.log('Selecting recommended design:', designId, bgUrl, bgImage);
    
    // 設定を更新（透明系テーマの場合は現在のテーマを維持）
    const currentTheme = settings.theme;
    if (currentTheme === 'transparent') {
        settings.theme = currentTheme;
    } else {
        settings.theme = 'transparent';
    }
    settings.background_image = bgImage;
    if (styleConfig.ui_style) settings.ui_style = styleConfig.ui_style;
    if (styleConfig.font_family) settings.font_family = styleConfig.font_family;
    
    // UIを即座に更新
    document.querySelectorAll('.recommended-item').forEach(item => item.classList.remove('active'));
    document.querySelectorAll('.theme-item').forEach(item => item.classList.remove('active'));
    
    // クリックされた推奨アイテムをアクティブに
    const clickedItem = document.querySelector(`[onclick*="selectRecommendedDesign('${designId}')"]`);
    if (clickedItem) clickedItem.classList.add('active');
    
    // テーマが維持された場合、対応するテーマアイテムもアクティブに
    const themeItem = document.querySelector(`.theme-item[data-theme="${settings.theme}"]`);
    if (themeItem && !themeItem.classList.contains('transparent-theme-btn')) {
        themeItem.classList.add('active');
    }
    
    // 背景画像を即座に適用（パターン対応）
    const isPattern = styleConfig.isPattern || false;
    const patternSize = styleConfig.patternSize || '300px';
    
    if (isPattern) {
        // パターン背景
        document.body.style.setProperty('background-image', `url('${bgUrl}')`, 'important');
        document.body.style.setProperty('background-size', patternSize, 'important');
        document.body.style.setProperty('background-position', 'top left', 'important');
        document.body.style.setProperty('background-attachment', 'fixed', 'important');
        document.body.style.setProperty('background-repeat', 'repeat', 'important');
        document.documentElement.style.setProperty('background-image', `url('${bgUrl}')`, 'important');
        document.documentElement.style.setProperty('background-size', patternSize, 'important');
        document.documentElement.style.setProperty('background-repeat', 'repeat', 'important');
    } else {
        // 通常背景（カバー）
        document.body.style.setProperty('background', `url('${bgUrl}') center/cover fixed`, 'important');
        document.documentElement.style.setProperty('background', `url('${bgUrl}') center/cover fixed`, 'important');
    }
    
    // 透明テーマのスタイルを適用（デザイン固有の色を渡す）
    applyTransparentPreviewStyles(true, styleConfig);
    
    // パネルを透明に
    document.querySelectorAll('.left-panel, .right-panel, .center-panel').forEach(panel => {
        panel.style.setProperty('background', 'rgba(255,255,255,0.85)', 'important');
    });
    
    // サーバーに保存してリロード
    try {
        const saveData = { 
            action: 'update_design',
            theme: settings.theme,
            background_image: bgImage
        };
        if (styleConfig.ui_style) saveData.ui_style = styleConfig.ui_style;
        if (styleConfig.font_family) saveData.font_family = styleConfig.font_family;
        
        const response = await fetch(getApiUrl('/api/settings.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(saveData)
        });
        const data = await response.json();
        console.log('Save response:', data);
        localStorage.setItem('social9_design', JSON.stringify(settings));
    } catch (error) {
        console.error('Save error:', error);
        localStorage.setItem('social9_design', JSON.stringify(settings));
    }
    // 常にリロード
    location.reload();
}

/**
 * サンプル背景を選択
 * @param {string} sampleId - サンプルID
 */
async function selectSampleBackground(sampleId) {
    const sampleImages = {
        'fuji': 'assets/samples/fuji.jpg',
        'snow': 'assets/samples/snow.jpg'
    };
    
    const url = sampleImages[sampleId];
    if (!url) return;
    
    // UIを更新
    document.querySelectorAll('.sample-bg-item').forEach(item => item.classList.remove('active'));
    event.currentTarget.classList.add('active');
    
    // カスタム背景セクションを非表示（標準デザイン固定で要素が存在しない場合あり）
    const customBgSection = document.getElementById('customBgSection');
    if (customBgSection) customBgSection.style.display = 'none';
    
    // 背景を適用
    applyBackgroundImage(url);
    
    // サーバーに保存してリロード
    try {
        const response = await fetch(getApiUrl('/api/settings.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'update_design',
                theme: settings.theme,
                background_image: 'sample_' + sampleId + '.jpg'
            })
        });
        const data = await response.json();
        if (data.success) {
            settings.background_image = 'sample_' + sampleId + '.jpg';
            location.reload();
        }
    } catch (error) {
        console.error('Save error:', error);
        location.reload();
    }
}

/**
 * スタイル選択
 * @param {HTMLElement} element - クリックされたスタイル要素
 */
function selectStyle(element) {
    const styleId = element.dataset.style;
    settings.ui_style = styleId;
    
    // UIを更新
    document.querySelectorAll('.style-item').forEach(item => item.classList.remove('active'));
    element.classList.add('active');
    
    // スタイルを適用
    applyStyle(styleId);
    
    // 自動保存
    saveSettings();
}

/**
 * レガシー枠線IDを現行IDに解決（PHP resolveStyleId と同一）
 * @param {string} styleId - 保存値（natural / cute / classic / frame_square / frame_round1 / frame_round2）
 * @returns {string} 現行スタイルID
 */
function resolveStyleId(styleId) {
    const legacyMap = { natural: 'frame_round1', cute: 'frame_round2', classic: 'frame_square', modern: 'frame_round1' };
    return legacyMap[styleId] || styleId;
}

/**
 * スタイル適用（枠線：直角・丸み1・丸み2をプレビューと画面に即時反映）
 * @param {string} styleId - スタイルID（frame_square / frame_round1 / frame_round2 またはレガシー natural/cute/classic）
 */
function applyStyle(styleId) {
    const effectiveId = resolveStyleId(styleId);

    // 枠線スタイルのクラスを削除（旧ID natural/cute/classic と現行 frame_* の両対応）
    const styleClassPrefix = 'style-';
    [...document.body.classList].forEach(className => {
        if (className.startsWith(styleClassPrefix)) document.body.classList.remove(className);
    });
    document.body.classList.add(styleClassPrefix + effectiveId);
    document.body.dataset.style = effectiveId;

    // プレビューパネルにも同じクラスを適用
    document.querySelectorAll('.left-panel, .center-panel, .right-panel').forEach(panel => {
        [...panel.classList].forEach(className => {
            if (className.startsWith(styleClassPrefix)) panel.classList.remove(className);
        });
        panel.classList.add(styleClassPrefix + effectiveId);
    });

    // body の --ui-* を更新
    if (typeof stylesData !== 'undefined' && Array.isArray(stylesData)) {
        const styleConfig = stylesData.find(s => s.id === effectiveId);
        if (styleConfig) {
            const target = document.body;
            if (styleConfig.borderRadius !== undefined) target.style.setProperty('--ui-border-radius', styleConfig.borderRadius);
            if (styleConfig.buttonRadius !== undefined) target.style.setProperty('--ui-btn-radius', styleConfig.buttonRadius);
            if (styleConfig.cardRadius !== undefined) target.style.setProperty('--ui-card-radius', styleConfig.cardRadius);
            if (styleConfig.inputRadius !== undefined) target.style.setProperty('--ui-input-radius', styleConfig.inputRadius);
            if (styleConfig.shadow) target.style.setProperty('--ui-shadow', styleConfig.shadow);
            if (styleConfig.border) target.style.setProperty('--ui-border', styleConfig.border);

            // プレビュー要素に直接 border-radius を当てて確実に反映（CSS詳細度・読み込み順に依存しない）
            const cardR = styleConfig.cardRadius || '16px';
            const btnR = styleConfig.buttonRadius || '8px';
            const inputR = styleConfig.inputRadius || '20px';
            const center = document.querySelector('.center-panel');
            if (center) {
                center.querySelectorAll('.preview-message').forEach(el => {
                    el.style.setProperty('border-radius', cardR, 'important');
                });
                center.querySelectorAll('.preview-message.other').forEach(el => {
                    el.style.setProperty('border-bottom-left-radius', cardR === '0' ? '0' : '4px', 'important');
                });
                center.querySelectorAll('.preview-message.self').forEach(el => {
                    el.style.setProperty('border-bottom-right-radius', cardR === '0' ? '0' : '4px', 'important');
                });
                const inputArea = center.querySelector('.preview-input-area');
                if (inputArea) {
                    inputArea.style.setProperty('border-radius', cardR === '0' ? '0' : `0 0 ${cardR} ${cardR}`, 'important');
                    inputArea.querySelectorAll('.toolbar-btn, .toolbar-toggle-btn').forEach(el => {
                        el.style.setProperty('border-radius', btnR, 'important');
                    });
                    inputArea.querySelectorAll('.input-row textarea, .message-input').forEach(el => {
                        el.style.setProperty('border-radius', inputR, 'important');
                    });
                    inputArea.querySelectorAll('.input-toolbar-right .toolbar-toggle-btn, .input-toolbar-right .input-send-btn').forEach(el => {
                        el.style.setProperty('border-radius', btnR, 'important');
                    });
                }
            }
        }
    }
}

/**
 * フォント選択
 * @param {HTMLElement} element - クリックされたフォント要素
 */
function selectFont(element) {
    const fontId = element.dataset.font;
    const fontFamily = element.dataset.family;
    settings.font_family = fontId;
    
    // UIを更新
    document.querySelectorAll('.font-item').forEach(item => item.classList.remove('active'));
    element.classList.add('active');
    
    // フォントを適用
    applyFont(fontFamily);
    
    // 自動保存
    saveSettings();
}

/**
 * フォント適用
 * @param {string} fontFamily - フォントファミリー
 */
function applyFont(fontFamily) {
    document.body.style.setProperty('font-family', fontFamily, 'important');
    document.documentElement.style.setProperty('--user-font', fontFamily);
    
    // プレビューにも適用
    document.querySelectorAll('.preview-message, .preview-input').forEach(el => {
        el.style.fontFamily = fontFamily;
    });
}

/**
 * アクセントカラー設定
 * @param {string} color - カラーコード
 * @param {boolean} fromTheme - テーマからの呼び出しかどうか
 */
function setAccentColor(color, fromTheme = false) {
    settings.accent_color = color;
    document.documentElement.style.setProperty('--current-accent', color);
    document.documentElement.style.setProperty('--theme-accent', color);
    
    // プリセット選択状態を更新
    document.querySelectorAll('.color-preset').forEach(p => {
        p.classList.toggle('active', p.style.background === color || rgbToHex(p.style.background) === color);
    });
    
    // 送信ボタンなど更新
    const sendBtn = document.getElementById('previewSendBtn');
    if (sendBtn) {
        sendBtn.style.setProperty('background', color, 'important');
    }
    document.querySelectorAll('.preview-message.self').forEach(msg => {
        msg.style.setProperty('background', color, 'important');
    });
    
    // 自動保存（テーマ選択からの場合は1回だけ保存）
    if (fromTheme) {
        saveSettings();
    }
}

/**
 * アクセントカラー更新
 * @param {string} color - カラーコード
 */
function updateAccentColor(color) {
    setAccentColor(color);
    saveSettings();
}

/**
 * RGB to Hex変換
 * @param {string} rgb - RGB形式の色
 * @returns {string} Hex形式の色
 */
function rgbToHex(rgb) {
    if (rgb.startsWith('#')) return rgb;
    const match = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
    if (!match) return rgb;
    return '#' + [match[1], match[2], match[3]].map(x => {
        const hex = parseInt(x).toString(16);
        return hex.length === 1 ? '0' + hex : hex;
    }).join('');
}

/**
 * ダークモード切替
 */
function toggleDarkMode() {
    settings.dark_mode = document.getElementById('darkMode').checked;
    applyDarkMode(settings.dark_mode);
    saveSettings();
}

/**
 * ダークモード適用
 * @param {boolean} isDark - ダークモードかどうか
 */
function applyDarkMode(isDark) {
    document.body.classList.toggle('dark-mode', isDark);
    document.querySelectorAll('.left-panel, .center-panel, .right-panel').forEach(panel => {
        panel.classList.toggle('dark-mode', isDark);
    });
}

/**
 * 文字サイズ設定
 * @param {string} size - サイズ（small, medium, large）
 * @param {HTMLElement} btn - クリックされたボタン
 */
function setFontSize(size, btn) {
    settings.font_size = size;
    document.querySelectorAll('.font-size-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // プレビューに反映
    const chat = document.getElementById('previewChat');
    const sizes = { small: '13px', medium: '14px', large: '16px' };
    chat.querySelectorAll('.preview-message').forEach(msg => {
        msg.style.fontSize = sizes[size];
    });
    
    saveSettings();
}

/**
 * 初期設定に戻す
 */
function resetToDefault() {
    // デフォルトテーマを選択
    const defaultTheme = document.querySelector('.theme-item[data-theme="default"]');
    if (defaultTheme) {
        selectTheme(defaultTheme);
    }
    
    // スタイルをリセット（枠線デフォルト: frame_round1 / 旧 natural）
    const defaultStyleId = 'frame_round1';
    const defaultStyle = document.querySelector('.style-item[data-style="' + defaultStyleId + '"]') || document.querySelector('.style-item[data-style="natural"]');
    if (defaultStyle) {
        const styleId = defaultStyle.dataset.style;
        document.querySelectorAll('.style-item').forEach(item => item.classList.remove('active'));
        defaultStyle.classList.add('active');
        settings.ui_style = styleId;
        applyStyle(styleId);
    }
    
    // フォントをリセット
    const defaultFont = document.querySelector('.font-item[data-font="default"]');
    if (defaultFont) {
        document.querySelectorAll('.font-item').forEach(item => item.classList.remove('active'));
        defaultFont.classList.add('active');
        settings.font_family = 'default';
        applyFont("'Hiragino Sans', 'Meiryo', sans-serif");
    }
    
    // 文字サイズをリセット
    document.querySelectorAll('.font-size-btn').forEach((btn, i) => {
        btn.classList.toggle('active', i === 1); // 中
    });
    settings.font_size = 'medium';
    
    const chat = document.getElementById('previewChat');
    chat.querySelectorAll('.preview-message').forEach(msg => {
        msg.style.fontSize = '14px';
    });
    
    saveSettings();
}

/**
 * 自動保存
 */
async function saveSettings() {
    try {
        const response = await fetch(getApiUrl('/api/settings.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_design',
                ...settings
            })
        });
        const data = await response.json();
        
        if (data.success) {
            localStorage.setItem('social9_design', JSON.stringify(settings));
        }
    } catch (e) {
        // APIがない場合はLocalStorageのみ保存
        localStorage.setItem('social9_design', JSON.stringify(settings));
    }
}

/**
 * 初期化処理
 */
function initDesignSettings() {
    // テーマを適用
    const themeElement = document.querySelector(`.theme-item[data-theme="${settings.theme}"]`);
    if (themeElement) {
        // activeクラスを設定
        document.querySelectorAll('.theme-item').forEach(item => item.classList.remove('active'));
        themeElement.classList.add('active');
        
        // テーマのスタイルを適用
        const headerGradient = themeElement.dataset.header;
        const bgColor = themeElement.dataset.bg;
        const accent = themeElement.dataset.accent;
        const panelBg = themeElement.dataset.panelBg;
        const textColor = themeElement.dataset.textColor;
        
        document.getElementById('topPanel').style.setProperty('background', headerGradient, 'important');
        document.body.style.setProperty('background', bgColor, 'important');
        document.documentElement.style.setProperty('background', bgColor, 'important');
        
        document.querySelectorAll('.left-panel, .right-panel, .center-panel').forEach(panel => {
            panel.style.setProperty('background', panelBg, 'important');
            panel.style.setProperty('color', textColor, 'important');
        });
        
        // CSS変数も更新
        document.documentElement.style.setProperty('--theme-header', headerGradient);
        document.documentElement.style.setProperty('--theme-bg', bgColor);
        document.documentElement.style.setProperty('--theme-accent', accent);
        document.documentElement.style.setProperty('--theme-text', textColor);
        
        // 透明テーマの場合
        // 注意: ページ読み込み時はPHPがデザイントークンを設定済みなので
        // ここではCSSカスタムプロパティを上書きしない（クラスのみ追加）
        if (settings.theme === 'transparent') {
            document.body.classList.add('theme-transparent');
            // パネルのぼかしを無効化
            document.querySelectorAll('.left-panel, .center-panel, .right-panel').forEach(panel => {
                panel.style.setProperty('backdrop-filter', 'none', 'important');
                panel.style.setProperty('-webkit-backdrop-filter', 'none', 'important');
            });
        }
    }
    
    // スタイルを適用
    applyStyle(settings.ui_style);
    document.querySelectorAll('.style-item').forEach(item => {
        item.classList.toggle('active', item.dataset.style === settings.ui_style);
    });
    
    // フォントを適用
    if (typeof fontsData !== 'undefined') {
        const fontData = fontsData.find(f => f.id === settings.font_family);
        if (fontData) {
            applyFont(fontData.family);
        }
    }
    document.querySelectorAll('.font-item').forEach(item => {
        item.classList.toggle('active', item.dataset.font === settings.font_family);
    });
    
    // 背景画像セクションの表示/非表示
    const bgSection = document.getElementById('backgroundSection');
    if (bgSection) {
        bgSection.style.display = (settings.theme === 'transparent') ? 'block' : 'none';
    }
    
    // 透明テーマの場合は背景画像も適用
    if (settings.theme === 'transparent' && settings.background_image && settings.background_image !== 'none') {
        let bgUrl;
        if (settings.background_image.startsWith('sample_')) {
            bgUrl = 'assets/samples/' + settings.background_image.replace('sample_', '');
        } else {
            bgUrl = 'uploads/backgrounds/' + settings.background_image;
        }
        
        // パターン背景の設定（sample画像のみ対応）
        const patternBackgrounds = {
        };
        const patternConfig = patternBackgrounds[settings.background_image] || { isPattern: false };
        
        // 背景ショートハンドをクリアしてから個別プロパティを設定
        document.body.style.removeProperty('background');
        document.documentElement.style.removeProperty('background');
        
        applyBackgroundImage(bgUrl, patternConfig.isPattern, patternConfig.patternSize);
        
        // サンプル選択状態を更新
        if (settings.background_image === 'sample_fuji.jpg') {
            document.querySelectorAll('.sample-bg-item').forEach(item => item.classList.remove('active'));
            document.querySelector('.sample-bg-item[onclick*="fuji"]')?.classList.add('active');
        } else if (settings.background_image === 'sample_snow.jpg') {
            document.querySelectorAll('.sample-bg-item').forEach(item => item.classList.remove('active'));
            document.querySelector('.sample-bg-item[onclick*="snow"]')?.classList.add('active');
        }
    }
}

// DOMContentLoadedで初期化
document.addEventListener('DOMContentLoaded', initDesignSettings);

/**
 * モバイル判定
 */
function isMobileDevice() {
    return window.innerWidth <= 768;
}

/**
 * モバイル用：設定を保存してチャットに戻る
 */
async function saveAndGoToChat() {
    try {
        const response = await fetch(getApiUrl('/api/settings.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_design',
                ...settings
            })
        });
        const data = await response.json();
        
        if (data.success) {
            localStorage.setItem('social9_design', JSON.stringify(settings));
        }
    } catch (e) {
        // APIがない場合はLocalStorageのみ保存
        localStorage.setItem('social9_design', JSON.stringify(settings));
    }
    
    // チャット画面に戻る
    window.location.href = 'chat.php';
}

// 各選択関数をラップしてモバイルでは自動的にチャットに戻る
const originalSelectTheme = selectTheme;
selectTheme = function(element) {
    originalSelectTheme(element);
    if (isMobileDevice()) {
        saveAndGoToChat();
    }
};

const originalSelectRecommendedDesign = selectRecommendedDesign;
selectRecommendedDesign = async function(designId) {
    await originalSelectRecommendedDesign(designId);
    if (isMobileDevice()) {
        saveAndGoToChat();
    }
};