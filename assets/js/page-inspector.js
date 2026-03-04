/**
 * ページインスペクター（拡張版 v2）
 * 
 * 包括的なUI検査を実行
 * - 視覚的問題検出（コントラスト、可視性）
 * - レイアウト問題
 * - 機能性チェック
 * - アクセシビリティ
 * - パフォーマンス
 * - リソース読み込みエラー検出
 * - 動的コンテンツ監視
 */

window.PageInspector = {
    results: [],
    errors: [],
    warnings: [],
    info: [],
    details: {},
    resourceErrors: [],
    networkErrors: [],
    observer: null,
    
    // WCAG 2.1 コントラスト比基準
    CONTRAST_RATIO_AA: 4.5,
    CONTRAST_RATIO_AA_LARGE: 3,
    MIN_FONT_SIZE: 10,
    MIN_TOUCH_TARGET: 44,
    
    /**
     * 現在のページを完全検査
     */
    inspect: function(options = {}) {
        const startTime = performance.now();
        
        this.results = [];
        this.errors = [];
        this.warnings = [];
        this.info = [];
        this.resourceErrors = [];
        this.networkErrors = [];
        this.details = {
            visibility: [],
            layout: [],
            functionality: [],
            accessibility: [],
            performance: [],
            resources: [],
            data: [],
            mobile: [],
            transparent: [],
            pwa: []
        };
        
        console.log('%c🔍 ページ検査開始...', 'color: #3b82f6; font-weight: bold; font-size: 16px');
        console.log('URL:', location.href);
        console.log('ビューポート:', window.innerWidth + 'x' + window.innerHeight);
        console.log('');
        
        // 1. 視覚的問題検出
        this.checkVisibility();
        
        // 2. レイアウト問題
        this.checkLayout();
        
        // 3. 機能性チェック
        this.checkFunctionality();
        
        // 4. アクセシビリティ
        this.checkAccessibility();
        
        // 5. パフォーマンス
        this.checkPerformance();
        
        // 6. コンソールエラー
        this.checkConsoleErrors();
        
        // 7. リソース読み込みエラー
        this.checkResourceErrors();
        
        // 8. データ整合性（ファイルパスの表示など）
        this.checkDataIntegrity();
        
        // 9. 透明デザイン問題
        this.checkTransparentDesignIssues();
        
        // 10. PWA/アプリ問題
        this.checkPWAIssues();
        
        // 11. モバイルUI問題（詳細版）
        this.checkMobileUIIssues();
        
        const duration = Math.round(performance.now() - startTime);
        this.info.push(`検査時間: ${duration}ms`);
        
        // 結果を表示
        this.showResults();
        
        return this.getFullReport();
    },
    
    // ========================================
    // 1. 視覚的問題検出
    // ========================================
    
    checkVisibility: function() {
        console.log('%c👁️ 視覚的問題検出', 'color: #8b5cf6; font-weight: bold');
        
        const textElements = document.querySelectorAll('p, span, h1, h2, h3, h4, h5, h6, label, a, button, td, th, li, div');
        let lowContrastCount = 0;
        let invisibleCount = 0;
        let tinyTextCount = 0;
        let overlappingCount = 0;
        
        textElements.forEach(el => {
            const text = el.innerText?.trim();
            if (!text || text.length < 2) return;
            if (el.children.length > 0 && el.children[0].innerText === text) return; // 子要素と重複
            
            const style = getComputedStyle(el);
            const rect = el.getBoundingClientRect();
            
            // 非表示要素はスキップ
            if (style.display === 'none' || style.visibility === 'hidden') return;
            if (rect.width === 0 || rect.height === 0) return;
            
            // 透明度チェック
            const opacity = parseFloat(style.opacity);
            if (opacity < 0.5) {
                invisibleCount++;
                if (invisibleCount <= 5) {
                    this.addDetail('visibility', 'warning', '低透明度テキスト', {
                        element: el.tagName,
                        text: text.substring(0, 30),
                        opacity: opacity
                    });
                }
            }
            
            // フォントサイズチェック
            const fontSize = parseFloat(style.fontSize);
            if (fontSize < this.MIN_FONT_SIZE) {
                tinyTextCount++;
                if (tinyTextCount <= 5) {
                    this.addDetail('visibility', 'warning', '小さすぎるテキスト', {
                        element: el.tagName,
                        text: text.substring(0, 30),
                        fontSize: fontSize + 'px'
                    });
                }
            }
            
            // コントラスト比チェック
            const color = style.color;
            const bgColor = this.getEffectiveBackgroundColor(el);
            
            if (color && bgColor) {
                const contrast = this.calculateContrast(color, bgColor);
                const isLargeText = fontSize >= 18 || (fontSize >= 14 && style.fontWeight >= 700);
                const minRatio = isLargeText ? this.CONTRAST_RATIO_AA_LARGE : this.CONTRAST_RATIO_AA;
                
                if (contrast < minRatio) {
                    lowContrastCount++;
                    if (lowContrastCount <= 10) {
                        const severity = contrast < 2 ? 'error' : 'warning';
                        this.addDetail('visibility', severity, 'コントラスト不足', {
                            element: el.tagName,
                            text: text.substring(0, 30),
                            contrast: contrast.toFixed(2) + ':1',
                            required: minRatio + ':1',
                            color: color,
                            background: bgColor
                        });
                        
                        if (contrast < 2) {
                            this.errors.push({
                                type: 'low-contrast',
                                message: `読めないテキスト: "${text.substring(0, 20)}..." (コントラスト ${contrast.toFixed(1)}:1)`
                            });
                        }
                    }
                }
            }
            
            // 要素の重なりチェック（サンプリング）
            if (Math.random() < 0.1) { // 10%の要素をチェック
                const centerX = rect.left + rect.width / 2;
                const centerY = rect.top + rect.height / 2;
                const elementAtPoint = document.elementFromPoint(centerX, centerY);
                
                if (elementAtPoint && elementAtPoint !== el && !el.contains(elementAtPoint) && !elementAtPoint.contains(el)) {
                    const overlapperZ = parseInt(getComputedStyle(elementAtPoint).zIndex) || 0;
                    const currentZ = parseInt(style.zIndex) || 0;
                    
                    if (overlapperZ > currentZ) {
                        overlappingCount++;
                    }
                }
            }
        });
        
        this.info.push(`テキスト要素: ${textElements.length}個`);
        if (lowContrastCount > 0) this.warnings.push({ type: 'contrast', message: `低コントラスト: ${lowContrastCount}箇所` });
        if (invisibleCount > 0) this.info.push(`低透明度: ${invisibleCount}箇所`);
        if (tinyTextCount > 0) this.warnings.push({ type: 'tiny-text', message: `小さいテキスト: ${tinyTextCount}箇所` });
    },
    
    // ========================================
    // 2. レイアウト問題
    // ========================================
    
    checkLayout: function() {
        console.log('%c📐 レイアウト問題検出', 'color: #8b5cf6; font-weight: bold');
        
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const docWidth = document.documentElement.scrollWidth;
        const docHeight = document.documentElement.scrollHeight;
        const isMobile = viewportWidth < 768;
        
        // 水平スクロールチェック
        if (docWidth > viewportWidth + 20) {
            this.errors.push({
                type: 'horizontal-scroll',
                message: `水平スクロール発生 (${docWidth}px > ${viewportWidth}px)`
            });
            this.addDetail('layout', 'error', '水平スクロール', {
                documentWidth: docWidth,
                viewportWidth: viewportWidth,
                overflow: docWidth - viewportWidth + 'px'
            });
        }
        
        // 画面外要素チェック
        let offscreenCount = 0;
        const importantElements = document.querySelectorAll('button, a, input, select, textarea, [role="button"]');
        
        importantElements.forEach(el => {
            const rect = el.getBoundingClientRect();
            const style = getComputedStyle(el);
            
            if (style.display === 'none' || style.visibility === 'hidden') return;
            if (style.position === 'fixed' || style.position === 'absolute') return;
            
            // 完全に画面外
            if (rect.right < 0 || rect.left > viewportWidth || rect.bottom < 0 || rect.top > viewportHeight + 1000) {
                offscreenCount++;
                if (offscreenCount <= 5) {
                    this.addDetail('layout', 'warning', '画面外の重要要素', {
                        element: el.tagName,
                        text: (el.innerText || el.value || '').substring(0, 30),
                        position: `left:${Math.round(rect.left)}, top:${Math.round(rect.top)}`
                    });
                }
            }
        });
        
        // オーバーフロー要素チェック
        let overflowCount = 0;
        const containers = document.querySelectorAll('div, section, article, main, aside');
        
        containers.forEach(container => {
            const style = getComputedStyle(container);
            if (style.overflow === 'visible' || style.overflow === '') {
                const rect = container.getBoundingClientRect();
                
                Array.from(container.children).forEach(child => {
                    const childRect = child.getBoundingClientRect();
                    const childStyle = getComputedStyle(child);
                    
                    if (childStyle.position === 'absolute' || childStyle.position === 'fixed') return;
                    
                    if (childRect.right > rect.right + 50 || childRect.left < rect.left - 50) {
                        overflowCount++;
                    }
                });
            }
        });
        
        // z-index競合チェック
        const modals = document.querySelectorAll('.modal, [role="dialog"], .popup, .dropdown, .overlay');
        const zIndexes = [];
        
        modals.forEach(m => {
            const style = getComputedStyle(m);
            const z = parseInt(style.zIndex) || 0;
            if (z > 0 && style.display !== 'none') {
                zIndexes.push({ element: m, zIndex: z });
            }
        });
        
        // 同じz-indexを持つ要素
        const zIndexCounts = {};
        zIndexes.forEach(z => {
            zIndexCounts[z.zIndex] = (zIndexCounts[z.zIndex] || 0) + 1;
        });
        
        Object.entries(zIndexCounts).forEach(([z, count]) => {
            if (count > 1) {
                this.addDetail('layout', 'warning', 'z-index競合の可能性', {
                    zIndex: z,
                    count: count
                });
            }
        });
        
        // ========================================
        // 要素の重なり検出（モバイル対応強化）
        // ========================================
        this.checkElementOverlaps(isMobile, viewportWidth, viewportHeight);
        
        this.info.push(`ドキュメントサイズ: ${docWidth}x${docHeight}`);
        this.info.push(`デバイス: ${isMobile ? 'モバイル' : 'デスクトップ'} (${viewportWidth}px)`);
        if (offscreenCount > 0) this.info.push(`画面外要素: ${offscreenCount}個`);
        if (overflowCount > 0) this.info.push(`オーバーフロー: ${overflowCount}個`);
    },
    
    // ========================================
    // 要素の重なり検出
    // ========================================
    
    checkElementOverlaps: function(isMobile, viewportWidth, viewportHeight) {
        console.log('%c🔲 要素重なり検出', 'color: #8b5cf6; font-weight: bold');
        
        let overlapCount = 0;
        let fixedOverlapCount = 0;
        let buttonOverlapCount = 0;
        
        // クリッカブル要素を取得
        const clickables = document.querySelectorAll('button, a[href], input, select, textarea, [role="button"], [onclick], .btn');
        const clickableRects = [];
        
        clickables.forEach(el => {
            const style = getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') return;
            if (parseFloat(style.opacity) < 0.1) return;
            
            const rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return;
            
            // ビューポート内の要素のみ
            if (rect.bottom < 0 || rect.top > viewportHeight) return;
            if (rect.right < 0 || rect.left > viewportWidth) return;
            
            clickableRects.push({
                element: el,
                rect: rect,
                zIndex: parseInt(style.zIndex) || 0,
                position: style.position
            });
        });
        
        // 重なりチェック
        for (let i = 0; i < clickableRects.length; i++) {
            for (let j = i + 1; j < clickableRects.length; j++) {
                const a = clickableRects[i];
                const b = clickableRects[j];
                
                // 親子関係をチェック（親子は除外）
                if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
                
                // 重なり判定
                const overlap = this.getOverlapArea(a.rect, b.rect);
                
                if (overlap > 0) {
                    const overlapPercent = Math.max(
                        overlap / (a.rect.width * a.rect.height) * 100,
                        overlap / (b.rect.width * b.rect.height) * 100
                    );
                    
                    // 20%以上の重なりは問題
                    if (overlapPercent > 20) {
                        overlapCount++;
                        buttonOverlapCount++;
                        
                        if (buttonOverlapCount <= 5) {
                            this.addDetail('layout', 'error', 'クリッカブル要素の重なり', {
                                element1: this.getElementDescription(a.element),
                                element2: this.getElementDescription(b.element),
                                overlapPercent: Math.round(overlapPercent) + '%',
                                position1: `${Math.round(a.rect.left)},${Math.round(a.rect.top)}`,
                                position2: `${Math.round(b.rect.left)},${Math.round(b.rect.top)}`,
                                // 詳細情報
                                selector1: this.getCssSelector(a.element),
                                selector2: this.getCssSelector(b.element),
                                size1: `${Math.round(a.rect.width)}x${Math.round(a.rect.height)}`,
                                size2: `${Math.round(b.rect.width)}x${Math.round(b.rect.height)}`,
                                suggestion: 'z-indexの調整、マージン/パディングの追加、または要素の配置変更を検討してください。'
                            });
                        }
                    }
                }
            }
        }
        
        // 固定要素（position: fixed）の重なりチェック
        const fixedElements = document.querySelectorAll('*');
        const fixedRects = [];
        
        fixedElements.forEach(el => {
            const style = getComputedStyle(el);
            if (style.position !== 'fixed') return;
            if (style.display === 'none' || style.visibility === 'hidden') return;
            
            const rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return;
            
            fixedRects.push({
                element: el,
                rect: rect,
                zIndex: parseInt(style.zIndex) || 0
            });
        });
        
        // 固定要素同士の重なり
        for (let i = 0; i < fixedRects.length; i++) {
            for (let j = i + 1; j < fixedRects.length; j++) {
                const a = fixedRects[i];
                const b = fixedRects[j];
                
                if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
                
                const overlap = this.getOverlapArea(a.rect, b.rect);
                
                if (overlap > 100) { // 100px²以上の重なり
                    fixedOverlapCount++;
                    
                    if (fixedOverlapCount <= 3) {
                        this.addDetail('layout', 'warning', '固定要素の重なり', {
                            element1: this.getElementDescription(a.element),
                            element2: this.getElementDescription(b.element),
                            zIndex1: a.zIndex,
                            zIndex2: b.zIndex,
                            overlapArea: Math.round(overlap) + 'px²'
                        });
                    }
                }
            }
        }
        
        // フレーム/パネルの重なりチェック
        this.checkFrameOverlaps(viewportWidth, viewportHeight);
        
        // モバイル特有の問題
        if (isMobile) {
            this.checkMobileSpecificIssues(viewportWidth, viewportHeight);
        }
        
        if (buttonOverlapCount > 0) {
            this.errors.push({
                type: 'button-overlap',
                message: `ボタン/リンクの重なり: ${buttonOverlapCount}箇所`
            });
        }
        
        if (fixedOverlapCount > 0) {
            this.warnings.push({
                type: 'fixed-overlap',
                message: `固定要素の重なり: ${fixedOverlapCount}箇所`
            });
        }
        
        this.info.push(`要素重なり検出: ${overlapCount}箇所`);
    },
    
    /**
     * フレーム/パネルの重なり検出
     */
    checkFrameOverlaps: function(viewportWidth, viewportHeight) {
        // 主要なフレーム/パネル要素を取得
        const frameSelectors = [
            '.sidebar', '.panel', '.frame', '.drawer', '.menu',
            '[class*="sidebar"]', '[class*="panel"]', '[class*="drawer"]',
            'nav', 'aside', 'header', 'footer',
            '.topbar', '.bottombar', '.toolbar',
            '.modal', '.popup', '.dialog'
        ];
        
        const frames = document.querySelectorAll(frameSelectors.join(','));
        const visibleFrames = [];
        
        frames.forEach(frame => {
            const style = getComputedStyle(frame);
            if (style.display === 'none' || style.visibility === 'hidden') return;
            if (parseFloat(style.opacity) < 0.1) return;
            
            const rect = frame.getBoundingClientRect();
            if (rect.width < 50 || rect.height < 50) return; // 小さすぎる要素は除外
            
            // ビューポート内
            if (rect.right < 0 || rect.left > viewportWidth) return;
            if (rect.bottom < 0 || rect.top > viewportHeight) return;
            
            visibleFrames.push({
                element: frame,
                rect: rect,
                zIndex: parseInt(style.zIndex) || 0,
                className: frame.className
            });
        });
        
        let frameOverlapCount = 0;
        
        for (let i = 0; i < visibleFrames.length; i++) {
            for (let j = i + 1; j < visibleFrames.length; j++) {
                const a = visibleFrames[i];
                const b = visibleFrames[j];
                
                if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
                
                const overlap = this.getOverlapArea(a.rect, b.rect);
                const minArea = Math.min(a.rect.width * a.rect.height, b.rect.width * b.rect.height);
                const overlapPercent = (overlap / minArea) * 100;
                
                // 30%以上の重なりは問題
                if (overlapPercent > 30) {
                    frameOverlapCount++;
                    
                    if (frameOverlapCount <= 5) {
                        this.addDetail('layout', 'error', 'フレーム/パネルの重なり', {
                            frame1: this.getElementDescription(a.element),
                            frame2: this.getElementDescription(b.element),
                            overlapPercent: Math.round(overlapPercent) + '%',
                            zIndex1: a.zIndex,
                            zIndex2: b.zIndex
                        });
                    }
                }
            }
        }
        
        if (frameOverlapCount > 0) {
            this.errors.push({
                type: 'frame-overlap',
                message: `フレーム重なり: ${frameOverlapCount}箇所`
            });
        }
    },
    
    /**
     * モバイル特有の問題検出
     */
    checkMobileSpecificIssues: function(viewportWidth, viewportHeight) {
        console.log('%c📱 モバイル問題検出', 'color: #8b5cf6; font-weight: bold');
        
        let issueCount = 0;
        
        // タッチターゲットが近すぎる
        const touchTargets = document.querySelectorAll('button, a[href], input, [role="button"], .btn');
        const targetRects = [];
        
        touchTargets.forEach(el => {
            const style = getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') return;
            
            const rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return;
            if (rect.bottom < 0 || rect.top > viewportHeight) return;
            
            targetRects.push({ element: el, rect: rect });
        });
        
        // 隣接するタッチターゲット間の距離チェック
        for (let i = 0; i < targetRects.length; i++) {
            for (let j = i + 1; j < targetRects.length; j++) {
                const a = targetRects[i];
                const b = targetRects[j];
                
                if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
                
                const distance = this.getMinDistance(a.rect, b.rect);
                
                // 8px未満は近すぎる（重なっていない場合）
                if (distance >= 0 && distance < 8) {
                    issueCount++;
                    
                    if (issueCount <= 3) {
                        this.addDetail('layout', 'warning', 'タッチターゲットが近すぎる', {
                            element1: this.getElementDescription(a.element),
                            element2: this.getElementDescription(b.element),
                            distance: Math.round(distance) + 'px',
                            recommended: '8px以上'
                        });
                    }
                }
            }
        }
        
        // 固定ヘッダー/フッターがコンテンツを隠していないか
        const fixedTop = document.querySelectorAll('header[style*="fixed"], .topbar, nav[style*="fixed"], [class*="header"]');
        const fixedBottom = document.querySelectorAll('footer[style*="fixed"], .bottombar, [class*="footer"]');
        
        let headerHeight = 0;
        let footerHeight = 0;
        
        fixedTop.forEach(el => {
            const style = getComputedStyle(el);
            if (style.position === 'fixed' || style.position === 'sticky') {
                const rect = el.getBoundingClientRect();
                if (rect.top < 10) {
                    headerHeight = Math.max(headerHeight, rect.height);
                }
            }
        });
        
        fixedBottom.forEach(el => {
            const style = getComputedStyle(el);
            if (style.position === 'fixed') {
                const rect = el.getBoundingClientRect();
                if (rect.bottom > viewportHeight - 10) {
                    footerHeight = Math.max(footerHeight, rect.height);
                }
            }
        });
        
        // 使用可能な領域が狭すぎる
        const usableHeight = viewportHeight - headerHeight - footerHeight;
        if (usableHeight < viewportHeight * 0.5) {
            this.addDetail('layout', 'warning', '表示領域が狭い', {
                viewportHeight: viewportHeight + 'px',
                headerHeight: headerHeight + 'px',
                footerHeight: footerHeight + 'px',
                usableHeight: usableHeight + 'px',
                usablePercent: Math.round(usableHeight / viewportHeight * 100) + '%'
            });
        }
        
        // 横スクロール可能な要素のチェック
        const scrollables = document.querySelectorAll('*');
        let horizontalScrollCount = 0;
        
        scrollables.forEach(el => {
            if (el.scrollWidth > el.clientWidth + 10) {
                const style = getComputedStyle(el);
                if (style.overflowX === 'auto' || style.overflowX === 'scroll') {
                    // 意図的なスクロール
                } else if (style.overflowX === 'visible' || style.overflow === 'visible') {
                    horizontalScrollCount++;
                }
            }
        });
        
        if (horizontalScrollCount > 0) {
            this.addDetail('layout', 'info', '横スクロール発生要素', {
                count: horizontalScrollCount
            });
        }
        
        if (issueCount > 0) {
            this.info.push(`モバイル問題: ${issueCount}件`);
        }
    },
    
    /**
     * 2つの矩形の重なり面積を計算
     */
    getOverlapArea: function(rect1, rect2) {
        const xOverlap = Math.max(0, Math.min(rect1.right, rect2.right) - Math.max(rect1.left, rect2.left));
        const yOverlap = Math.max(0, Math.min(rect1.bottom, rect2.bottom) - Math.max(rect1.top, rect2.top));
        return xOverlap * yOverlap;
    },
    
    /**
     * 2つの矩形間の最小距離を計算
     */
    getMinDistance: function(rect1, rect2) {
        const left = rect2.right < rect1.left;
        const right = rect1.right < rect2.left;
        const top = rect2.bottom < rect1.top;
        const bottom = rect1.bottom < rect2.top;
        
        if (left && top) {
            return Math.sqrt((rect1.left - rect2.right) ** 2 + (rect1.top - rect2.bottom) ** 2);
        } else if (left && bottom) {
            return Math.sqrt((rect1.left - rect2.right) ** 2 + (rect2.top - rect1.bottom) ** 2);
        } else if (right && top) {
            return Math.sqrt((rect2.left - rect1.right) ** 2 + (rect1.top - rect2.bottom) ** 2);
        } else if (right && bottom) {
            return Math.sqrt((rect2.left - rect1.right) ** 2 + (rect2.top - rect1.bottom) ** 2);
        } else if (left) {
            return rect1.left - rect2.right;
        } else if (right) {
            return rect2.left - rect1.right;
        } else if (top) {
            return rect1.top - rect2.bottom;
        } else if (bottom) {
            return rect2.top - rect1.bottom;
        } else {
            // 重なっている
            return -1;
        }
    },
    
    /**
     * 要素の説明を取得（詳細版）
     */
    getElementDescription: function(el) {
        if (!el) return '';
        
        let desc = el.tagName.toLowerCase();
        
        if (el.id) {
            desc += '#' + el.id;
        } else if (el.className && typeof el.className === 'string') {
            const cls = el.className.split(' ').filter(c => c && !c.startsWith('ng-'))[0];
            if (cls) desc += '.' + cls;
        }
        
        const text = (el.innerText || el.value || '').trim().substring(0, 15);
        if (text) {
            desc += ` "${text}${text.length >= 15 ? '...' : ''}"`;
        }
        
        return desc;
    },
    
    /**
     * 要素の詳細情報を取得（デバッグ用）
     */
    getElementDetails: function(el) {
        if (!el) return {};
        
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        
        return {
            // 基本情報
            tag: el.tagName.toLowerCase(),
            id: el.id || null,
            classes: el.className ? el.className.split(' ').filter(c => c).slice(0, 5).join(' ') : null,
            
            // CSSセレクタ（正確な特定用）
            selector: this.getCssSelector(el),
            
            // data属性
            dataAttrs: this.getDataAttributes(el),
            
            // 位置・サイズ
            position: {
                x: Math.round(rect.left),
                y: Math.round(rect.top),
                width: Math.round(rect.width),
                height: Math.round(rect.height)
            },
            
            // スタイル
            display: style.display,
            visibility: style.visibility,
            zIndex: style.zIndex,
            
            // テキスト（短縮）
            text: (el.innerText || '').trim().substring(0, 50) || null,
            
            // HTML抜粋
            htmlSnippet: el.outerHTML.substring(0, 200) + (el.outerHTML.length > 200 ? '...' : '')
        };
    },
    
    /**
     * CSSセレクタを生成
     */
    getCssSelector: function(el) {
        if (!el) return '';
        if (el.id) return '#' + el.id;
        
        const parts = [];
        let current = el;
        let depth = 0;
        
        while (current && current !== document.body && depth < 5) {
            let selector = current.tagName.toLowerCase();
            
            if (current.id) {
                selector = '#' + current.id;
                parts.unshift(selector);
                break; // IDがあればそこで終了
            }
            
            if (current.className && typeof current.className === 'string') {
                const classes = current.className.split(' ').filter(c => c && !c.startsWith('ng-')).slice(0, 2);
                if (classes.length > 0) {
                    selector += '.' + classes.join('.');
                }
            }
            
            // 同じタグの兄弟がいる場合はnth-childを追加
            const parent = current.parentElement;
            if (parent) {
                const siblings = Array.from(parent.children).filter(c => c.tagName === current.tagName);
                if (siblings.length > 1) {
                    const index = siblings.indexOf(current) + 1;
                    selector += `:nth-of-type(${index})`;
                }
            }
            
            parts.unshift(selector);
            current = parent;
            depth++;
        }
        
        return parts.join(' > ');
    },
    
    /**
     * data属性を取得
     */
    getDataAttributes: function(el) {
        if (!el || !el.dataset) return null;
        
        const attrs = {};
        const keys = ['messageId', 'id', 'conversationId', 'userId', 'type', 'action'];
        
        keys.forEach(key => {
            if (el.dataset[key]) {
                attrs[key] = el.dataset[key];
            }
        });
        
        // data-message-id などのハイフン形式もチェック
        if (el.getAttribute('data-message-id')) {
            attrs.messageId = el.getAttribute('data-message-id');
        }
        
        return Object.keys(attrs).length > 0 ? attrs : null;
    },
    
    /**
     * 親要素からメッセージIDを探す
     */
    findParentMessageId: function(el) {
        let current = el;
        let depth = 0;
        
        while (current && current !== document.body && depth < 10) {
            const msgId = current.getAttribute('data-message-id');
            if (msgId) return msgId;
            current = current.parentElement;
            depth++;
        }
        
        return null;
    },
    
    /**
     * ファイルパス問題の修正提案を生成
     */
    getSuggestionForFilePath: function(path) {
        if (path.match(/screenshot_|スクリーンショット_/i)) {
            return 'スクリーンショット画像がimgタグで表示されていません。正規表現パターンの確認が必要です。';
        }
        if (path.match(/アップロード|メッセージ/)) {
            return '日本語パスが英語パス(uploads/messages/)に変換されていません。normalizeFilePath関数を確認してください。';
        }
        if (path.match(/msg_[a-f0-9]+/i)) {
            return 'メッセージ添付画像がテキスト表示されています。imageMatchの正規表現を確認してください。';
        }
        if (path.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
            return 'この画像ファイルをimgタグで表示する必要があります。chat.phpまたはscripts.phpの画像処理ロジックを確認してください。';
        }
        return 'このファイルパスは適切なメディアタグで表示されるべきです。';
    },
    
    // ========================================
    // 3. 機能性チェック
    // ========================================
    
    checkFunctionality: function() {
        console.log('%c⚙️ 機能性チェック', 'color: #8b5cf6; font-weight: bold');
        
        // ボタンチェック
        const buttons = document.querySelectorAll('button, [role="button"], .btn');
        let functionalButtons = 0;
        let brokenButtons = 0;
        
        buttons.forEach(btn => {
            const style = getComputedStyle(btn);
            if (style.display === 'none') return;
            
            const isDisabled = btn.disabled || btn.getAttribute('disabled') !== null;
            
            // ハンドラの有無を総合的にチェック
            const hasHandler = btn.onclick || 
                              btn.getAttribute('onclick') || 
                              btn.type === 'submit' ||
                              btn.type === 'reset' ||
                              this.hasEventListener(btn);
            
            // UI要素として機能を持つ特殊なボタン
            const isUIControl = /\b(pos-btn|size-btn|nav-btn|cat-btn|tab|toggle|close|expand|collapse|nav|prev|next)\b/i.test(btn.className);
            
            if (!hasHandler && !isDisabled && !isUIControl) {
                brokenButtons++;
                if (brokenButtons <= 5) {
                    this.addDetail('functionality', 'warning', 'ハンドラなしボタン', {
                        element: btn.tagName,
                        text: (btn.innerText || btn.value || '').substring(0, 30),
                        class: btn.className.substring(0, 50)
                    });
                }
            } else {
                functionalButtons++;
            }
            
            // タッチターゲットサイズ
            const rect = btn.getBoundingClientRect();
            if (rect.width < this.MIN_TOUCH_TARGET || rect.height < this.MIN_TOUCH_TARGET) {
                if (!isDisabled && style.display !== 'none') {
                    this.addDetail('functionality', 'info', '小さいタッチターゲット', {
                        element: btn.tagName,
                        text: (btn.innerText || '').substring(0, 20),
                        size: `${Math.round(rect.width)}x${Math.round(rect.height)}px`,
                        recommended: `${this.MIN_TOUCH_TARGET}x${this.MIN_TOUCH_TARGET}px`
                    });
                }
            }
        });
        
        // リンクチェック
        const links = document.querySelectorAll('a');
        let validLinks = 0;
        let brokenLinks = 0;
        
        links.forEach(link => {
            const href = link.getAttribute('href');
            const style = getComputedStyle(link);
            if (style.display === 'none') return;
            
            if (!href || href === '#' || href === 'javascript:void(0)' || href === 'javascript:;') {
                const hasHandler = link.onclick || link.getAttribute('onclick');
                if (!hasHandler) {
                    brokenLinks++;
                    if (brokenLinks <= 5) {
                        this.addDetail('functionality', 'warning', '無効なリンク', {
                            text: (link.innerText || '').substring(0, 30),
                            href: href || '(empty)'
                        });
                    }
                }
            } else {
                validLinks++;
            }
        });
        
        // 画像チェック
        const images = document.querySelectorAll('img');
        let loadedImages = 0;
        let brokenImages = 0;
        
        images.forEach(img => {
            const style = getComputedStyle(img);
            // display:noneの画像やsrcがない画像はスキップ
            if (style.display === 'none' || !img.src || img.src === '' || img.src === 'about:blank') {
                return;
            }
            
            if (img.complete) {
                if (img.naturalWidth > 0) {
                    loadedImages++;
                } else {
                    brokenImages++;
                    this.addDetail('functionality', 'error', '壊れた画像', {
                        src: img.src?.substring(0, 50),
                        alt: img.alt || '(no alt)'
                    });
                }
            }
        });
        
        // ボタンクリックテスト（安全な範囲で）
        this.runButtonClickTests(buttons);
        
        // フォームチェック
        const forms = document.querySelectorAll('form');
        forms.forEach((form, i) => {
            const action = form.getAttribute('action');
            const method = form.getAttribute('method');
            const inputs = form.querySelectorAll('input:not([type="hidden"]), select, textarea');
            const submitBtn = form.querySelector('[type="submit"], button:not([type="button"])');
            
            if (!submitBtn && !form.onsubmit) {
                this.addDetail('functionality', 'info', '送信ボタンなしフォーム', {
                    formIndex: i + 1,
                    action: action || '(none)',
                    inputCount: inputs.length
                });
            }
        });
        
        this.info.push(`ボタン: ${buttons.length}個 (機能的: ${functionalButtons})`);
        this.info.push(`リンク: ${links.length}個 (有効: ${validLinks})`);
        this.info.push(`画像: ${images.length}個 (読み込み済み: ${loadedImages})`);
        
        if (brokenButtons > 0) this.warnings.push({ type: 'broken-buttons', message: `ハンドラなしボタン: ${brokenButtons}個` });
        if (brokenLinks > 0) this.warnings.push({ type: 'broken-links', message: `無効なリンク: ${brokenLinks}個` });
        if (brokenImages > 0) this.errors.push({ type: 'broken-images', message: `壊れた画像: ${brokenImages}個` });
    },
    
    /**
     * ボタンクリックテスト（安全な範囲で）
     * 削除・送信などの危険なボタンはスキップ
     */
    runButtonClickTests: function(buttons) {
        if (!buttons || buttons.length === 0) return;
        
        const safeButtons = [];
        const unsafeButtons = [];
        let errorCount = 0;
        
        // 危険なボタンのパターン
        const dangerPatterns = /削除|delete|remove|送信|submit|確定|確認|登録|購入|支払|決済|ログアウト|logout|退会/i;
        const safePatterns = /戻る|閉じる|close|cancel|キャンセル|開く|表示|詳細|展開|折りたたみ|toggle|tab|タブ|検索|search|リセット|reset|更新|refresh/i;
        
        buttons.forEach(btn => {
            const style = getComputedStyle(btn);
            if (style.display === 'none' || style.visibility === 'hidden') return;
            
            const text = (btn.innerText || btn.value || btn.title || '').trim();
            const className = btn.className || '';
            const rect = btn.getBoundingClientRect();
            
            // 画面外のボタンはスキップ
            if (rect.width === 0 || rect.height === 0) return;
            if (rect.top < -100 || rect.top > window.innerHeight + 100) return;
            
            // 危険なボタンを判定
            if (dangerPatterns.test(text) || dangerPatterns.test(className)) {
                unsafeButtons.push({ btn, text, reason: '危険な操作' });
                return;
            }
            
            // 安全なボタンを判定
            if (safePatterns.test(text) || safePatterns.test(className)) {
                safeButtons.push({ btn, text });
            }
        });
        
        // 安全なボタンのクリックテスト（最大10個）
        const testCount = Math.min(safeButtons.length, 10);
        let testedCount = 0;
        
        // エラーリスナーを設定
        const originalOnError = window.onerror;
        const clickErrors = [];
        
        window.onerror = (msg, url, line, col, error) => {
            clickErrors.push({ msg, url, line });
            return true; // エラーを握りつぶす
        };
        
        for (let i = 0; i < testCount; i++) {
            const { btn, text } = safeButtons[i];
            
            try {
                // クリックイベントをシミュレート（実際にはdispatchEventで安全に）
                const clickEvent = new MouseEvent('click', {
                    bubbles: true,
                    cancelable: true,
                    view: window
                });
                
                // イベント発火前のエラー数を記録
                const errorsBefore = clickErrors.length;
                
                // イベントをディスパッチ
                btn.dispatchEvent(clickEvent);
                
                testedCount++;
                
                // エラーが発生したか確認
                if (clickErrors.length > errorsBefore) {
                    errorCount++;
                    this.addDetail('functionality', 'error', 'ボタンクリックでエラー', {
                        button: text.substring(0, 30),
                        error: clickErrors[clickErrors.length - 1].msg?.substring(0, 100)
                    });
                }
            } catch (e) {
                errorCount++;
                this.addDetail('functionality', 'error', 'ボタンクリックで例外', {
                    button: text.substring(0, 30),
                    error: e.message?.substring(0, 100)
                });
            }
        }
        
        // エラーリスナーを復元
        window.onerror = originalOnError;
        
        // 結果を記録
        if (testedCount > 0) {
            this.info.push(`ボタンテスト: ${testedCount}個テスト, ${errorCount}エラー`);
        }
        
        if (unsafeButtons.length > 0) {
            this.addDetail('functionality', 'info', 'スキップしたボタン（安全のため）', {
                count: unsafeButtons.length,
                examples: unsafeButtons.slice(0, 3).map(b => b.text).join(', ')
            });
        }
        
        if (errorCount > 0) {
            this.warnings.push({
                type: 'button-errors',
                message: `ボタンエラー: ${errorCount}件`
            });
        }
    },
    
    // ========================================
    // 4. アクセシビリティ
    // ========================================
    
    checkAccessibility: function() {
        console.log('%c♿ アクセシビリティチェック', 'color: #8b5cf6; font-weight: bold');
        
        const issues = [];
        
        // lang属性
        if (!document.documentElement.lang) {
            issues.push('html要素にlang属性がない');
            this.addDetail('accessibility', 'warning', 'lang属性なし', {
                element: 'html',
                recommendation: '<html lang="ja">'
            });
        }
        
        // タイトル
        if (!document.title || document.title.trim() === '') {
            issues.push('ページタイトルがない');
            this.addDetail('accessibility', 'warning', 'タイトルなし', {});
        }
        
        // 見出し階層
        const headings = document.querySelectorAll('h1, h2, h3, h4, h5, h6');
        const h1Count = document.querySelectorAll('h1').length;
        
        if (h1Count === 0) {
            issues.push('h1がない');
            this.addDetail('accessibility', 'warning', 'h1なし', {});
        } else if (h1Count > 1) {
            this.addDetail('accessibility', 'info', '複数のh1', { count: h1Count });
        }
        
        // 見出しスキップ
        let lastLevel = 0;
        headings.forEach(h => {
            const level = parseInt(h.tagName[1]);
            if (lastLevel > 0 && level > lastLevel + 1) {
                this.addDetail('accessibility', 'warning', '見出しレベルスキップ', {
                    from: 'h' + lastLevel,
                    to: h.tagName.toLowerCase(),
                    text: h.innerText?.substring(0, 30)
                });
            }
            lastLevel = level;
        });
        
        // 画像のalt
        const images = document.querySelectorAll('img');
        let noAltCount = 0;
        
        images.forEach(img => {
            if (!img.alt && !img.getAttribute('aria-label') && !img.getAttribute('aria-hidden')) {
                noAltCount++;
                if (noAltCount <= 3) {
                    this.addDetail('accessibility', 'warning', 'alt属性なし画像', {
                        src: img.src?.substring(img.src.lastIndexOf('/') + 1, img.src.lastIndexOf('/') + 30)
                    });
                }
            }
        });
        
        // アイコンボタン
        const iconButtons = document.querySelectorAll('button, [role="button"]');
        let noLabelCount = 0;
        
        iconButtons.forEach(btn => {
            const text = btn.innerText?.trim();
            const ariaLabel = btn.getAttribute('aria-label');
            const title = btn.getAttribute('title');
            
            // テキストが絵文字やアイコンのみ
            if ((!text || text.length <= 2 || /^[\p{Emoji}\p{Symbol}]+$/u.test(text)) && !ariaLabel && !title) {
                noLabelCount++;
                if (noLabelCount <= 3) {
                    this.addDetail('accessibility', 'warning', 'ラベルなしアイコンボタン', {
                        text: text || '(empty)',
                        class: btn.className?.substring(0, 30)
                    });
                }
            }
        });
        
        // フォームラベル
        const inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea');
        let noFormLabelCount = 0;
        
        inputs.forEach(input => {
            const id = input.id;
            const hasLabel = id && document.querySelector(`label[for="${id}"]`);
            const hasAriaLabel = input.getAttribute('aria-label');
            const hasPlaceholder = input.placeholder;
            const hasTitle = input.title;
            const isWrappedInLabel = input.closest('label');
            
            if (!hasLabel && !hasAriaLabel && !hasPlaceholder && !hasTitle && !isWrappedInLabel) {
                noFormLabelCount++;
                if (noFormLabelCount <= 3) {
                    this.addDetail('accessibility', 'warning', 'ラベルなし入力フィールド', {
                        type: input.type || input.tagName.toLowerCase(),
                        name: input.name || '(no name)'
                    });
                }
            }
        });
        
        // tabindex
        const focusableElements = document.querySelectorAll('a[href], button, input, select, textarea, [tabindex]');
        const positiveTabindex = Array.from(focusableElements).filter(el => {
            const tabindex = parseInt(el.getAttribute('tabindex'));
            return tabindex > 0;
        });
        
        if (positiveTabindex.length > 0) {
            this.addDetail('accessibility', 'info', '正のtabindex使用', {
                count: positiveTabindex.length,
                recommendation: 'tabindex="0"またはDOM順序の使用を推奨'
            });
        }
        
        this.info.push(`見出し: ${headings.length}個`);
        if (noAltCount > 0) this.warnings.push({ type: 'no-alt', message: `alt属性なし画像: ${noAltCount}個` });
        if (noLabelCount > 0) this.info.push(`ラベルなしボタン: ${noLabelCount}個`);
        if (noFormLabelCount > 0) this.info.push(`ラベルなし入力: ${noFormLabelCount}個`);
    },
    
    // ========================================
    // 5. パフォーマンス
    // ========================================
    
    checkPerformance: function() {
        console.log('%c⚡ パフォーマンスチェック', 'color: #8b5cf6; font-weight: bold');
        
        // DOM要素数
        const allElements = document.querySelectorAll('*');
        const elementCount = allElements.length;
        
        if (elementCount > 3000) {
            this.addDetail('performance', 'warning', 'DOM要素が多い', {
                count: elementCount,
                recommendation: '1500以下を推奨'
            });
        }
        
        // HTMLサイズ
        const htmlSize = document.documentElement.outerHTML.length;
        const htmlSizeKB = Math.round(htmlSize / 1024);
        
        if (htmlSizeKB > 500) {
            this.addDetail('performance', 'warning', 'HTMLが大きい', {
                size: htmlSizeKB + 'KB',
                recommendation: '200KB以下を推奨'
            });
        }
        
        // 画像サイズ
        const images = document.querySelectorAll('img');
        let largeImageCount = 0;
        
        images.forEach(img => {
            if (img.complete && img.naturalWidth > 0) {
                const displayWidth = img.clientWidth;
                const displayHeight = img.clientHeight;
                const naturalWidth = img.naturalWidth;
                const naturalHeight = img.naturalHeight;
                
                // 表示サイズの2倍以上の解像度
                if (naturalWidth > displayWidth * 2 && naturalHeight > displayHeight * 2 && displayWidth > 0) {
                    largeImageCount++;
                    if (largeImageCount <= 3) {
                        this.addDetail('performance', 'info', '大きすぎる画像', {
                            src: img.src?.substring(img.src.lastIndexOf('/') + 1).substring(0, 30),
                            display: `${displayWidth}x${displayHeight}`,
                            actual: `${naturalWidth}x${naturalHeight}`
                        });
                    }
                }
            }
        });
        
        // 外部リソース
        const scripts = document.querySelectorAll('script[src]');
        const stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
        
        if (scripts.length > 20) {
            this.addDetail('performance', 'warning', '多すぎるスクリプト', {
                count: scripts.length,
                recommendation: 'バンドル化を検討'
            });
        }
        
        if (stylesheets.length > 10) {
            this.addDetail('performance', 'info', '多すぎるスタイルシート', {
                count: stylesheets.length,
                recommendation: '統合を検討'
            });
        }
        
        // インラインスタイル
        const inlineStyles = document.querySelectorAll('[style]');
        if (inlineStyles.length > 50) {
            this.addDetail('performance', 'info', '多すぎるインラインスタイル', {
                count: inlineStyles.length,
                recommendation: 'CSSクラスの使用を推奨'
            });
        }
        
        this.info.push(`DOM要素: ${elementCount}個`);
        this.info.push(`HTMLサイズ: ${htmlSizeKB}KB`);
        this.info.push(`スクリプト: ${scripts.length}個, CSS: ${stylesheets.length}個`);
        if (largeImageCount > 0) this.info.push(`最適化可能画像: ${largeImageCount}個`);
    },
    
    // ========================================
    // 6. コンソールエラー
    // ========================================
    
    checkConsoleErrors: function() {
        if (window.ErrorCollector && typeof window.ErrorCollector.getErrors === 'function') {
            const errors = window.ErrorCollector.getErrors();
            if (errors && errors.length > 0) {
                errors.forEach(e => {
                    this.errors.push({
                        type: 'js-error',
                        message: e.message?.substring(0, 100) || 'Unknown error'
                    });
                });
            }
        }
    },
    
    // ========================================
    // 7. リソース読み込みエラー
    // ========================================
    
    checkResourceErrors: function() {
        console.log('%c📦 リソースエラー検出', 'color: #8b5cf6; font-weight: bold');
        
        let brokenResourceCount = 0;
        
        // 画像チェック（詳細版）
        const images = document.querySelectorAll('img');
        images.forEach(img => {
            const src = img.src || img.dataset.src;
            const style = getComputedStyle(img);
            
            // srcがない、空、またはdisplay:noneの画像はスキップ
            if (!src || src === '' || src === 'about:blank' || style.display === 'none') return;
            
            // 読み込み完了したが画像が壊れている
            if (img.complete && img.naturalWidth === 0 && img.naturalHeight === 0) {
                brokenResourceCount++;
                this.addDetail('resources', 'error', '画像読み込み失敗', {
                    src: this.truncateUrl(src),
                    element: this.getElementPath(img)
                });
                this.resourceErrors.push({
                    type: 'image',
                    src: src,
                    element: this.getElementPath(img)
                });
            }
            
            // まだ読み込み中の画像
            if (!img.complete) {
                // まだ読み込み中
                this.addDetail('resources', 'info', '読み込み中の画像', {
                    src: this.truncateUrl(src)
                });
            }
        });
        
        // 動画チェック
        const videos = document.querySelectorAll('video');
        videos.forEach(video => {
            const src = video.src || video.querySelector('source')?.src;
            if (!src) return;
            
            // エラー状態をチェック
            if (video.error) {
                brokenResourceCount++;
                this.addDetail('resources', 'error', '動画読み込み失敗', {
                    src: this.truncateUrl(src),
                    error: video.error.message || 'Unknown error'
                });
                this.resourceErrors.push({
                    type: 'video',
                    src: src,
                    error: video.error.message
                });
            }
            
            // readyState=0は読み込み開始していない
            if (video.readyState === 0 && video.networkState === 3) {
                brokenResourceCount++;
                this.addDetail('resources', 'error', '動画読み込み失敗', {
                    src: this.truncateUrl(src),
                    networkState: 'NETWORK_NO_SOURCE'
                });
            }
        });
        
        // 音声チェック
        const audios = document.querySelectorAll('audio');
        audios.forEach(audio => {
            const src = audio.src || audio.querySelector('source')?.src;
            if (!src) return;
            
            if (audio.error) {
                brokenResourceCount++;
                this.addDetail('resources', 'error', '音声読み込み失敗', {
                    src: this.truncateUrl(src),
                    error: audio.error.message || 'Unknown error'
                });
            }
        });
        
        // iframeチェック（同一オリジンのみ）
        const iframes = document.querySelectorAll('iframe');
        iframes.forEach(iframe => {
            const src = iframe.src;
            if (!src || src === 'about:blank') return;
            
            try {
                // 同一オリジンかチェック
                const iframeUrl = new URL(src, location.href);
                if (iframeUrl.origin === location.origin) {
                    // iframeの読み込み状態は直接確認できないので、サイズのみチェック
                    const rect = iframe.getBoundingClientRect();
                    if (rect.width > 0 && rect.height > 0) {
                        // 表示されているが内容がないかも
                    }
                }
            } catch (e) {
                // URLパースエラー
            }
        });
        
        // 背景画像チェック
        const elementsWithBg = document.querySelectorAll('[style*="background"]');
        let bgImageErrors = 0;
        
        elementsWithBg.forEach(el => {
            const style = getComputedStyle(el);
            const bgImage = style.backgroundImage;
            
            if (bgImage && bgImage !== 'none') {
                // url()を抽出
                const urlMatch = bgImage.match(/url\(['"]?([^'"]+)['"]?\)/);
                if (urlMatch) {
                    const imageUrl = urlMatch[1];
                    // テスト用のImageを作成して読み込みをチェック
                    // （非同期なので、ここでは記録のみ）
                }
            }
        });
        
        // Performance APIからリソースエラーを取得
        if (window.performance && window.performance.getEntriesByType) {
            const resources = performance.getEntriesByType('resource');
            resources.forEach(resource => {
                // transferSizeが0でdurationが0より大きい場合はキャッシュから
                // responseEndが0の場合は失敗の可能性
                if (resource.responseEnd === 0 && resource.duration > 0) {
                    // 読み込み失敗の可能性
                    this.addDetail('resources', 'warning', 'リソース応答なし', {
                        name: this.truncateUrl(resource.name),
                        type: resource.initiatorType
                    });
                }
            });
        }
        
        this.info.push(`リソースエラー: ${brokenResourceCount}個`);
        if (brokenResourceCount > 0) {
            this.errors.push({
                type: 'resource-error',
                message: `読み込み失敗リソース: ${brokenResourceCount}個`
            });
        }
    },
    
    // ========================================
    // 8. データ整合性チェック
    // ========================================
    
    checkDataIntegrity: function() {
        console.log('%c🔗 データ整合性チェック', 'color: #8b5cf6; font-weight: bold');
        
        let issueCount = 0;
        
        // ファイルパスがテキストとして表示されていないかチェック
        const filePathPatterns = [
            // 日本語のファイルパス
            /アップロード[\\/\/]メッセージ[\\/\/][^\s<]+\.(jpg|jpeg|png|gif|webp|mp4|webm|pdf)/gi,
            // 英語のファイルパス（imgタグ外）
            /(?<!src=["'])uploads[\\/\/]messages[\\/\/][^\s<]+\.(jpg|jpeg|png|gif|webp|mp4|webm|pdf)/gi,
            // Windows形式のパス
            /[A-Z]:[\\/][^\s<]+\.(jpg|jpeg|png|gif|webp|mp4|webm|pdf)/gi,
            // 一般的なファイル名パターン（拡張子付き）
            /(?<![\/\w])[\w-]+_\d{10,}\.(jpg|jpeg|png|gif|webp|mp4|webm|pdf)/gi,
            // msg_で始まるファイル名（画像としてレンダリングされるべき）
            /msg_[a-f0-9]+_\d+\.(jpg|jpeg|png|gif|webp|mp4|webm)/gi,
            // スクリーンショット等のファイル名
            /スクリーンショット[_\s\d]+\.(jpg|jpeg|png|gif|webp)/gi
        ];
        
        // テキストノードをチェック
        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );
        
        const foundPaths = [];
        let node;
        while (node = walker.nextNode()) {
            const text = node.textContent.trim();
            if (!text || text.length < 5) continue;
            
            // 親がscript/styleなら無視
            const parent = node.parentElement;
            if (!parent || parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE') continue;
            
            // パターンをチェック
            for (const pattern of filePathPatterns) {
                pattern.lastIndex = 0; // reset regex
                const matches = text.match(pattern);
                if (matches) {
                    matches.forEach(match => {
                        if (!foundPaths.includes(match)) {
                            foundPaths.push(match);
                            issueCount++;
                            
                            // 詳細情報を収集
                            const messageId = this.findParentMessageId(parent);
                            const selector = this.getCssSelector(parent);
                            
                            this.addDetail('data', 'error', 'ファイルパスがテキスト表示', {
                                path: match,
                                context: text.substring(0, 100),
                                element: parent.tagName + (parent.className ? '.' + parent.className.split(' ')[0] : ''),
                                // 追加の詳細情報
                                cssSelector: selector,
                                messageId: messageId,
                                fullPath: this.getElementPath(parent),
                                suggestion: this.getSuggestionForFilePath(match),
                                htmlSnippet: parent.outerHTML.substring(0, 150)
                            });
                        }
                    });
                }
            }
        }
        
        // data-*属性に画像パスがあるがimgタグがない要素
        const elementsWithData = document.querySelectorAll('[data-image], [data-src], [data-file]');
        elementsWithData.forEach(el => {
            const dataImage = el.dataset.image || el.dataset.src || el.dataset.file;
            if (!dataImage) return;
            
            // 対応するimg要素があるか
            const hasImg = el.querySelector('img') || el.tagName === 'IMG';
            if (!hasImg && dataImage.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
                issueCount++;
                this.addDetail('data', 'warning', '未表示の画像データ', {
                    data: this.truncateUrl(dataImage),
                    element: this.getElementPath(el)
                });
            }
        });
        
        // プレースホルダーテキストのチェック
        const placeholderPatterns = [
            /\{\{[^}]+\}\}/g,  // {{variable}}
            /\$\{[^}]+\}/g,    // ${variable}
            /%[a-zA-Z_]+%/g,   // %PLACEHOLDER%
            /\[object Object\]/gi
        ];
        
        const allText = document.body.innerText;
        placeholderPatterns.forEach(pattern => {
            const matches = allText.match(pattern);
            if (matches && matches.length > 0) {
                issueCount++;
                this.addDetail('data', 'warning', '未置換のプレースホルダー', {
                    patterns: matches.slice(0, 5).join(', '),
                    count: matches.length
                });
            }
        });
        
        // エラーメッセージがそのまま表示されていないか
        const errorPatterns = [
            /Fatal error:/i,
            /Parse error:/i,
            /Warning:/i,
            /Notice:/i,
            /Undefined variable/i,
            /Undefined index/i,
            /Cannot read property/i,
            /is not defined/i,
            /SQLSTATE\[/i,
            /MySQL error/i
        ];
        
        errorPatterns.forEach(pattern => {
            if (pattern.test(allText)) {
                issueCount++;
                const match = allText.match(pattern);
                this.addDetail('data', 'error', 'エラーメッセージ表示', {
                    pattern: match ? match[0] : pattern.toString(),
                    type: 'PHP/JS Error exposed'
                });
                this.errors.push({
                    type: 'exposed-error',
                    message: `エラーメッセージが画面に表示されています`
                });
            }
        });
        
        if (issueCount > 0) {
            this.warnings.push({
                type: 'data-integrity',
                message: `データ整合性問題: ${issueCount}件`
            });
        }
        
        this.info.push(`データ問題: ${issueCount}件`);
    },
    
    // ========================================
    // 9. 透明デザイン問題検出
    // ========================================
    
    checkTransparentDesignIssues: function() {
        console.log('%c✨ 透明デザイン問題検出', 'color: #8b5cf6; font-weight: bold');
        
        const body = document.body;
        const bodyStyle = getComputedStyle(body);
        
        // 透明デザインかどうかを判定
        const isTransparentDesign = this.detectTransparentDesign();
        
        if (!isTransparentDesign) {
            this.info.push('透明デザイン: 未使用');
            return;
        }
        
        this.info.push('透明デザイン: 検出');
        let issueCount = 0;
        
        // 半透明要素の重なりチェック
        const semiTransparentElements = [];
        const allElements = document.querySelectorAll('div, section, aside, nav, header, footer, .panel, .sidebar, .card, .modal');
        
        allElements.forEach(el => {
            const style = getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') return;
            
            const rect = el.getBoundingClientRect();
            if (rect.width < 50 || rect.height < 50) return;
            
            // 透明度をチェック
            const bgColor = style.backgroundColor;
            const opacity = parseFloat(style.opacity);
            
            let bgAlpha = 1;
            if (bgColor.startsWith('rgba')) {
                const match = bgColor.match(/rgba?\([\d\s,]+,?\s*([\d.]+)\)/);
                if (match) bgAlpha = parseFloat(match[1]);
            } else if (bgColor === 'transparent') {
                bgAlpha = 0;
            }
            
            // 半透明の要素（0.1 < alpha < 0.9）
            if ((bgAlpha > 0.1 && bgAlpha < 0.9) || (opacity > 0.1 && opacity < 0.9)) {
                semiTransparentElements.push({
                    element: el,
                    rect: rect,
                    bgAlpha: bgAlpha,
                    opacity: opacity,
                    zIndex: parseInt(style.zIndex) || 0
                });
            }
        });
        
        // 半透明要素同士の重なりで可読性が低下していないか
        for (let i = 0; i < semiTransparentElements.length; i++) {
            for (let j = i + 1; j < semiTransparentElements.length; j++) {
                const a = semiTransparentElements[i];
                const b = semiTransparentElements[j];
                
                if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
                
                const overlap = this.getOverlapArea(a.rect, b.rect);
                const minArea = Math.min(a.rect.width * a.rect.height, b.rect.width * b.rect.height);
                const overlapPercent = (overlap / minArea) * 100;
                
                if (overlapPercent > 50) {
                    // 両方が半透明で大きく重なっている
                    const combinedAlpha = 1 - (1 - a.bgAlpha * a.opacity) * (1 - b.bgAlpha * b.opacity);
                    
                    if (combinedAlpha < 0.7) {
                        issueCount++;
                        
                        if (issueCount <= 3) {
                            this.addDetail('layout', 'warning', '半透明要素の重なり（可読性低下）', {
                                element1: this.getElementDescription(a.element),
                                element2: this.getElementDescription(b.element),
                                alpha1: a.bgAlpha.toFixed(2),
                                alpha2: b.bgAlpha.toFixed(2),
                                combinedAlpha: combinedAlpha.toFixed(2)
                            });
                        }
                    }
                }
            }
        }
        
        // 透明背景上のテキスト可読性
        const textElements = document.querySelectorAll('p, span, h1, h2, h3, h4, h5, h6, label, a, button, li');
        let lowReadabilityCount = 0;
        
        textElements.forEach(el => {
            const text = el.innerText?.trim();
            if (!text || text.length < 2) return;
            
            const style = getComputedStyle(el);
            if (style.display === 'none') return;
            
            // 親要素の背景をチェック
            let parent = el.parentElement;
            let hasStableBg = false;
            
            while (parent && parent !== document.body) {
                const parentStyle = getComputedStyle(parent);
                const bgColor = parentStyle.backgroundColor;
                
                if (bgColor && bgColor !== 'transparent' && !bgColor.startsWith('rgba(0, 0, 0, 0)')) {
                    const alphaMatch = bgColor.match(/rgba?\([\d\s,]+,?\s*([\d.]+)\)/);
                    const alpha = alphaMatch ? parseFloat(alphaMatch[1]) : 1;
                    
                    if (alpha > 0.8) {
                        hasStableBg = true;
                        break;
                    }
                }
                parent = parent.parentElement;
            }
            
            // 安定した背景がない場合、テキストの読みやすさを警告
            if (!hasStableBg) {
                const color = style.color;
                // 白っぽいテキストは背景によっては見えにくい
                const rgb = color.match(/\d+/g);
                if (rgb) {
                    const brightness = (parseInt(rgb[0]) * 299 + parseInt(rgb[1]) * 587 + parseInt(rgb[2]) * 114) / 1000;
                    
                    // 明るい色（白っぽい）のテキストで背景が透明
                    if (brightness > 200) {
                        lowReadabilityCount++;
                        
                        if (lowReadabilityCount <= 3) {
                            this.addDetail('visibility', 'warning', '透明背景上の明るいテキスト', {
                                text: text.substring(0, 30),
                                color: color,
                                brightness: Math.round(brightness),
                                recommendation: '背景にコントラストを追加'
                            });
                        }
                    }
                }
            }
        });
        
        // ガラス効果（backdrop-filter）の確認
        const glassElements = document.querySelectorAll('[style*="backdrop-filter"], [style*="-webkit-backdrop-filter"]');
        let glassCount = 0;
        
        allElements.forEach(el => {
            const style = getComputedStyle(el);
            if (style.backdropFilter && style.backdropFilter !== 'none') {
                glassCount++;
            }
        });
        
        if (glassCount > 0) {
            this.info.push(`ガラス効果要素: ${glassCount}個`);
        }
        
        if (issueCount > 0 || lowReadabilityCount > 0) {
            this.warnings.push({
                type: 'transparent-design',
                message: `透明デザイン問題: 重なり${issueCount}件, 可読性${lowReadabilityCount}件`
            });
        }
    },
    
    // ========================================
    // 10. PWA/アプリ問題検出
    // ========================================
    
    checkPWAIssues: function() {
        console.log('%c📱 PWA/アプリ問題検出', 'color: #8b5cf6; font-weight: bold');
        
        let issueCount = 0;
        
        // manifest.jsonリンクをチェック
        const manifestLink = document.querySelector('link[rel="manifest"]');
        if (!manifestLink) {
            issueCount++;
            this.addDetail('mobile', 'warning', 'manifest.jsonがない', {
                recommendation: '<link rel="manifest" href="manifest.json">を追加'
            });
        } else {
            // manifestの内容を検証（非同期なのでここでは記録のみ）
            this.info.push(`manifest: ${manifestLink.href}`);
        }
        
        // Service Workerの登録状態
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistration().then(reg => {
                if (!reg) {
                    this.addDetail('mobile', 'info', 'Service Worker未登録', {});
                }
            }).catch(() => {});
        }
        
        // viewport meta
        const viewportMeta = document.querySelector('meta[name="viewport"]');
        if (!viewportMeta) {
            issueCount++;
            this.addDetail('mobile', 'error', 'viewport metaがない', {
                recommendation: '<meta name="viewport" content="width=device-width, initial-scale=1">'
            });
            this.errors.push({
                type: 'no-viewport',
                message: 'viewport metaタグがありません'
            });
        } else {
            const content = viewportMeta.getAttribute('content') || '';
            if (!content.includes('width=device-width')) {
                this.addDetail('mobile', 'warning', 'viewport設定が不適切', {
                    current: content,
                    recommended: 'width=device-width, initial-scale=1'
                });
            }
        }
        
        // apple-touch-icon
        const appleTouchIcon = document.querySelector('link[rel="apple-touch-icon"]');
        if (!appleTouchIcon) {
            this.addDetail('mobile', 'info', 'apple-touch-iconがない', {
                recommendation: 'iOSホーム画面アイコン用'
            });
        }
        
        // theme-color
        const themeColor = document.querySelector('meta[name="theme-color"]');
        if (!themeColor) {
            this.addDetail('mobile', 'info', 'theme-colorがない', {
                recommendation: 'ブラウザUIの色を設定'
            });
        }
        
        // standalone表示の確認
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                            window.navigator.standalone === true;
        if (isStandalone) {
            this.info.push('表示モード: スタンドアロン（PWA）');
            
            // PWAモードでの追加チェック
            this.checkPWAModeIssues();
        }
        
        if (issueCount > 0) {
            this.warnings.push({
                type: 'pwa',
                message: `PWA問題: ${issueCount}件`
            });
        }
    },
    
    /**
     * PWAモード特有の問題をチェック
     */
    checkPWAModeIssues: function() {
        // 戻るボタンがあるか
        const backButtons = document.querySelectorAll('[onclick*="history.back"], [onclick*="go(-1)"], .back-button, [class*="back"]');
        if (backButtons.length === 0) {
            this.addDetail('mobile', 'warning', 'PWAモードで戻るボタンがない', {
                recommendation: 'ナビゲーション用の戻るボタンを追加'
            });
        }
        
        // オフラインページの確認（間接的）
        if (!navigator.onLine) {
            this.addDetail('mobile', 'info', 'オフライン状態', {});
        }
    },
    
    // ========================================
    // 11. モバイルUI問題（詳細版）
    // ========================================
    
    checkMobileUIIssues: function() {
        console.log('%c📲 モバイルUI詳細検査', 'color: #8b5cf6; font-weight: bold');
        
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const isMobile = viewportWidth < 768;
        
        if (!isMobile) {
            this.info.push('モバイルUI検査: スキップ（デスクトップ）');
            return;
        }
        
        let issueCount = 0;
        
        // パネル/サイドバー/モーダルの重なり検出
        const panels = document.querySelectorAll(
            '.sidebar, .panel, .drawer, .modal, .popup, .dropdown, .menu, ' +
            '[class*="sidebar"], [class*="panel"], [class*="drawer"], [class*="modal"], ' +
            '[class*="popup"], [class*="menu"], [class*="overlay"], ' +
            '.right-panel, .left-panel, .detail-panel, .rightpanel'
        );
        
        const visiblePanels = [];
        
        panels.forEach(panel => {
            const style = getComputedStyle(panel);
            const rect = panel.getBoundingClientRect();
            
            // 表示されているパネルを収集
            if (style.display !== 'none' && 
                style.visibility !== 'hidden' && 
                parseFloat(style.opacity) > 0.1 &&
                rect.width > 50 && rect.height > 50) {
                
                // ビューポート内にあるか
                if (rect.right > 0 && rect.left < viewportWidth &&
                    rect.bottom > 0 && rect.top < viewportHeight) {
                    
                    visiblePanels.push({
                        element: panel,
                        rect: rect,
                        zIndex: parseInt(style.zIndex) || 0,
                        className: panel.className,
                        position: style.position
                    });
                }
            }
        });
        
        // 複数のパネルが同時に表示されている
        if (visiblePanels.length > 2) {
            issueCount++;
            this.addDetail('mobile', 'error', '複数パネルが同時表示', {
                count: visiblePanels.length,
                panels: visiblePanels.slice(0, 5).map(p => this.getElementDescription(p.element)).join(', '),
                recommendation: 'モバイルでは1つのパネルのみ表示'
            });
            this.errors.push({
                type: 'multiple-panels',
                message: `モバイルで${visiblePanels.length}個のパネルが同時表示`
            });
        }
        
        // パネル同士の重なりを詳細チェック
        for (let i = 0; i < visiblePanels.length; i++) {
            for (let j = i + 1; j < visiblePanels.length; j++) {
                const a = visiblePanels[i];
                const b = visiblePanels[j];
                
                if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
                
                const overlap = this.getOverlapArea(a.rect, b.rect);
                const minArea = Math.min(a.rect.width * a.rect.height, b.rect.width * b.rect.height);
                const overlapPercent = (overlap / minArea) * 100;
                
                if (overlapPercent > 30) {
                    issueCount++;
                    this.addDetail('mobile', 'error', 'パネル重なり', {
                        panel1: this.getElementDescription(a.element),
                        panel2: this.getElementDescription(b.element),
                        overlap: Math.round(overlapPercent) + '%',
                        zIndex1: a.zIndex,
                        zIndex2: b.zIndex
                    });
                }
            }
        }
        
        // 入力フォームの重なり
        const forms = document.querySelectorAll('form, .form, [class*="input-area"], .message-input, .input-container');
        const visibleForms = [];
        
        forms.forEach(form => {
            const style = getComputedStyle(form);
            const rect = form.getBoundingClientRect();
            
            if (style.display !== 'none' && rect.width > 50 && rect.height > 20) {
                if (rect.bottom > 0 && rect.top < viewportHeight) {
                    visibleForms.push({ element: form, rect: rect });
                }
            }
        });
        
        // フォーム同士の重なり
        for (let i = 0; i < visibleForms.length; i++) {
            for (let j = i + 1; j < visibleForms.length; j++) {
                const a = visibleForms[i];
                const b = visibleForms[j];
                
                if (a.element.contains(b.element) || b.element.contains(a.element)) continue;
                
                const overlap = this.getOverlapArea(a.rect, b.rect);
                if (overlap > 500) { // 500px²以上
                    issueCount++;
                    this.addDetail('mobile', 'error', '入力フォーム重なり', {
                        form1: this.getElementDescription(a.element),
                        form2: this.getElementDescription(b.element),
                        overlapArea: Math.round(overlap) + 'px²'
                    });
                }
            }
        }
        
        // モーダル/ポップアップの問題
        const modals = document.querySelectorAll('.modal, .popup, [role="dialog"], .overlay');
        let visibleModalCount = 0;
        
        modals.forEach(modal => {
            const style = getComputedStyle(modal);
            if (style.display !== 'none' && style.visibility !== 'hidden') {
                visibleModalCount++;
            }
        });
        
        if (visibleModalCount > 1) {
            issueCount++;
            this.addDetail('mobile', 'warning', '複数モーダル同時表示', {
                count: visibleModalCount
            });
        }
        
        // 固定要素が画面を覆いすぎていないか
        const fixedElements = [];
        document.querySelectorAll('*').forEach(el => {
            const style = getComputedStyle(el);
            if (style.position === 'fixed' && style.display !== 'none') {
                const rect = el.getBoundingClientRect();
                if (rect.width > 0 && rect.height > 0) {
                    fixedElements.push({ element: el, rect: rect });
                }
            }
        });
        
        let fixedCoverage = 0;
        fixedElements.forEach(f => {
            fixedCoverage += f.rect.width * f.rect.height;
        });
        
        const coveragePercent = (fixedCoverage / (viewportWidth * viewportHeight)) * 100;
        if (coveragePercent > 40) {
            issueCount++;
            this.addDetail('mobile', 'warning', '固定要素が画面を覆いすぎ', {
                coverage: Math.round(coveragePercent) + '%',
                fixedElements: fixedElements.length,
                recommendation: '40%以下を推奨'
            });
        }
        
        // テキストが読めるか（背景との対比）
        this.checkMobileTextReadability();
        
        // スクロール可能エリアの問題
        this.checkScrollableAreas();
        
        // 不要なテキスト折り返し検出
        const wrapIssues = this.checkTextWrapping();
        issueCount += wrapIssues;
        
        // モバイルボタン動作チェック
        const buttonIssues = this.checkMobileButtonFunctionality();
        issueCount += buttonIssues;
        
        if (issueCount > 0) {
            this.errors.push({
                type: 'mobile-ui',
                message: `モバイルUI問題: ${issueCount}件`
            });
        }
        
        this.info.push(`モバイルUI問題: ${issueCount}件`);
    },
    
    /**
     * モバイルでのテキスト可読性チェック
     */
    checkMobileTextReadability: function() {
        const messages = document.querySelectorAll('.message-card, .message, [class*="message"], .chat-bubble');
        let unreadableCount = 0;
        
        messages.forEach(msg => {
            const style = getComputedStyle(msg);
            if (style.display === 'none') return;
            
            const rect = msg.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return;
            
            // 背景の透明度をチェック
            const bgColor = style.backgroundColor;
            let bgAlpha = 1;
            
            if (bgColor.startsWith('rgba')) {
                const match = bgColor.match(/[\d.]+(?=\))/);
                if (match) bgAlpha = parseFloat(match[0]);
            } else if (bgColor === 'transparent') {
                bgAlpha = 0;
            }
            
            // 背景が透明すぎる
            if (bgAlpha < 0.5) {
                // テキストの色をチェック
                const textColor = style.color;
                const textContent = msg.innerText?.trim();
                
                if (textContent && textContent.length > 10) {
                    unreadableCount++;
                    
                    if (unreadableCount <= 3) {
                        this.addDetail('visibility', 'warning', 'メッセージの背景が透明すぎる', {
                            text: textContent.substring(0, 30) + '...',
                            bgAlpha: bgAlpha.toFixed(2),
                            recommendation: '背景のalpha値を0.7以上に'
                        });
                    }
                }
            }
        });
        
        if (unreadableCount > 0) {
            this.warnings.push({
                type: 'unreadable-messages',
                message: `読みにくいメッセージ: ${unreadableCount}件`
            });
        }
    },
    
    /**
     * スクロール可能エリアの問題チェック
     */
    checkScrollableAreas: function() {
        const scrollables = document.querySelectorAll('[style*="overflow"], .scroll, .scrollable');
        let nestedScrollCount = 0;
        
        scrollables.forEach(el => {
            const style = getComputedStyle(el);
            const isScrollable = style.overflow === 'auto' || style.overflow === 'scroll' ||
                                style.overflowY === 'auto' || style.overflowY === 'scroll';
            
            if (!isScrollable) return;
            
            // 親にもスクロール可能な要素があるか
            let parent = el.parentElement;
            while (parent && parent !== document.body) {
                const parentStyle = getComputedStyle(parent);
                const parentScrollable = parentStyle.overflow === 'auto' || parentStyle.overflow === 'scroll' ||
                                        parentStyle.overflowY === 'auto' || parentStyle.overflowY === 'scroll';
                
                if (parentScrollable) {
                    nestedScrollCount++;
                    this.addDetail('mobile', 'info', 'ネストされたスクロール', {
                        child: this.getElementDescription(el),
                        parent: this.getElementDescription(parent)
                    });
                    break;
                }
                parent = parent.parentElement;
            }
        });
        
        if (nestedScrollCount > 2) {
            this.addDetail('mobile', 'warning', 'ネストスクロールが多い', {
                count: nestedScrollCount,
                recommendation: 'スクロール領域の整理を推奨'
            });
        }
    },
    
    /**
     * モバイルボタン動作チェック
     * クリック不能・反応しないボタンを検出
     */
    checkMobileButtonFunctionality: function() {
        console.log('%c🔘 モバイルボタン動作検査', 'color: #f59e0b; font-weight: bold');
        
        let issueCount = 0;
        const buttons = document.querySelectorAll(
            'button, [role="button"], .btn, .group-setting-item, ' +
            '[onclick], [class*="btn"], [class*="button"], .action-item, .menu-item'
        );
        
        buttons.forEach(btn => {
            const style = getComputedStyle(btn);
            const rect = btn.getBoundingClientRect();
            const text = (btn.innerText || btn.value || btn.title || '').trim().substring(0, 30);
            
            // 非表示要素はスキップ
            if (style.display === 'none' || style.visibility === 'hidden') return;
            if (rect.width === 0 || rect.height === 0) return;
            
            // ビューポート外はスキップ
            if (rect.right < 0 || rect.left > window.innerWidth) return;
            if (rect.bottom < 0 || rect.top > window.innerHeight) return;
            
            // 問題1: pointer-eventsが無効
            if (style.pointerEvents === 'none') {
                issueCount++;
                this.addDetail('mobile', 'error', 'クリック不能ボタン（pointer-events: none）', {
                    element: btn.tagName,
                    text: text,
                    cssSelector: this.getCssSelector(btn),
                    suggestion: 'pointer-events: auto を設定'
                });
            }
            
            // 問題2: 透明度が低すぎて見えない
            if (parseFloat(style.opacity) < 0.3) {
                issueCount++;
                this.addDetail('mobile', 'warning', 'ボタンが透明すぎる', {
                    element: btn.tagName,
                    text: text,
                    opacity: style.opacity,
                    suggestion: 'opacity を上げる'
                });
            }
            
            // 問題3: 他の要素に覆われている
            const centerX = rect.left + rect.width / 2;
            const centerY = rect.top + rect.height / 2;
            const topElement = document.elementFromPoint(centerX, centerY);
            
            if (topElement && topElement !== btn && !btn.contains(topElement) && !topElement.contains(btn)) {
                // 覆っている要素がクリックイベントを持っていない場合は問題
                const topStyle = getComputedStyle(topElement);
                if (topStyle.pointerEvents !== 'none') {
                    issueCount++;
                    this.addDetail('mobile', 'error', 'ボタンが他要素に覆われている', {
                        button: text || this.getCssSelector(btn),
                        coveredBy: this.getElementDescription(topElement),
                        buttonZIndex: style.zIndex || 'auto',
                        coveringZIndex: topStyle.zIndex || 'auto',
                        suggestion: 'z-indexを調整するか、覆っている要素を移動'
                    });
                }
            }
            
            // 問題4: イベントハンドラがない（グループ設定項目など重要なボタン）
            const isImportantButton = /\b(setting|change|edit|delete|create|save|submit|upload|download|open|close)\b/i.test(btn.className) ||
                                      /\b(変更|削除|作成|保存|送信|アップロード|ダウンロード|開く|閉じる|編集)\b/.test(text) ||
                                      btn.classList.contains('group-setting-item');
            
            if (isImportantButton) {
                const hasHandler = btn.onclick || 
                                  btn.getAttribute('onclick') || 
                                  this.hasEventListener(btn);
                
                if (!hasHandler && !btn.disabled) {
                    issueCount++;
                    this.addDetail('mobile', 'error', '重要ボタンにハンドラなし', {
                        element: btn.tagName,
                        text: text,
                        class: btn.className.substring(0, 50),
                        cssSelector: this.getCssSelector(btn),
                        suggestion: 'onclick属性またはaddEventListenerでイベントを設定'
                    });
                }
            }
            
            // 問題5: タッチターゲットが小さすぎる
            if (rect.width < 44 || rect.height < 44) {
                // 重要なボタンのみ警告
                if (isImportantButton || rect.width < 30 || rect.height < 30) {
                    this.addDetail('mobile', 'info', 'タッチターゲットが小さい', {
                        element: btn.tagName,
                        text: text,
                        size: `${Math.round(rect.width)}x${Math.round(rect.height)}px`,
                        recommended: '44x44px以上'
                    });
                }
            }
        });
        
        if (issueCount > 0) {
            this.warnings.push({
                type: 'mobile-button-issues',
                message: `モバイルボタン問題: ${issueCount}件`
            });
        }
        
        return issueCount;
    },
    
    /**
     * 不要なテキスト折り返し検出（モバイル）
     * ボタン、タブ、ナビ等で1行に収まるべきテキストが2行になっている問題
     */
    checkTextWrapping: function() {
        const viewportWidth = window.innerWidth;
        if (viewportWidth >= 768) return 0; // モバイルのみ
        
        let issueCount = 0;
        const reportedElements = new Set();
        
        // 1行であるべき要素のセレクタ
        const singleLineSelectors = [
            'button', '.btn', '[class*="btn"]', '[role="button"]',
            '.tab', '.nav-item', '.menu-item', '[class*="tab"]',
            '.header', '.toolbar', '[class*="header"]', '[class*="toolbar"]',
            '.title', '.label', '[class*="title"]', '[class*="label"]',
            '.badge', '.tag', '[class*="badge"]', '[class*="tag"]',
            '.chip', '[class*="chip"]',
            'th', '.table-header',
            '.breadcrumb', '.breadcrumb-item'
        ];
        
        const elements = document.querySelectorAll(singleLineSelectors.join(', '));
        
        elements.forEach(el => {
            const style = getComputedStyle(el);
            
            // 非表示要素はスキップ
            if (style.display === 'none' || style.visibility === 'hidden') return;
            
            const rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return;
            
            // 画面外はスキップ
            if (rect.right < 0 || rect.left > viewportWidth) return;
            
            const text = el.innerText?.trim();
            if (!text || text.length < 2 || text.length > 30) return; // 短いテキストのみ対象
            
            // 改行が含まれる場合はスキップ（意図的な改行）
            if (text.includes('\n')) return;
            
            // 絵文字のみのテキストはスキップ（絵文字アイコンボタンなど）
            const emojiOnlyPattern = /^[\p{Emoji}\s]+$/u;
            if (emojiOnlyPattern.test(text)) return;
            
            // line-heightを取得
            const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.2;
            const elementHeight = rect.height;
            const paddingTop = parseFloat(style.paddingTop) || 0;
            const paddingBottom = parseFloat(style.paddingBottom) || 0;
            const contentHeight = elementHeight - paddingTop - paddingBottom;
            
            // 内容が2行以上になっているか判定
            const estimatedLines = contentHeight / lineHeight;
            
            if (estimatedLines >= 1.8 && lineHeight > 0) { // 1.8行以上で2行と判定
                // 同じ親要素の問題を1回だけ報告
                const parentKey = el.parentElement?.className || el.parentElement?.id || 'root';
                if (reportedElements.has(parentKey + text)) return;
                reportedElements.add(parentKey + text);
                
                issueCount++;
                
                if (issueCount <= 10) {
                    this.addDetail('mobile', 'warning', '不要な行折り返し', {
                        element: el.tagName,
                        class: el.className?.split(' ')[0] || '',
                        text: text.substring(0, 25) + (text.length > 25 ? '...' : ''),
                        width: Math.round(rect.width) + 'px',
                        height: Math.round(rect.height) + 'px',
                        estimatedLines: estimatedLines.toFixed(1),
                        cssSelector: this.getCssSelector ? this.getCssSelector(el) : '',
                        suggestion: 'font-size縮小、white-space: nowrap、または幅の拡大を検討'
                    });
                }
            }
        });
        
        // 追加: テーブルセル内の折り返し検出
        const tableCells = document.querySelectorAll('th, td');
        tableCells.forEach(cell => {
            const style = getComputedStyle(cell);
            if (style.display === 'none') return;
            
            const rect = cell.getBoundingClientRect();
            if (rect.width === 0) return;
            
            const text = cell.innerText?.trim();
            if (!text || text.length > 15) return; // 短いテキストのみ
            
            const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.2;
            const paddingTop = parseFloat(style.paddingTop) || 0;
            const paddingBottom = parseFloat(style.paddingBottom) || 0;
            const contentHeight = rect.height - paddingTop - paddingBottom;
            const estimatedLines = contentHeight / lineHeight;
            
            if (estimatedLines >= 1.8 && lineHeight > 0) {
                const cellKey = 'cell-' + text;
                if (reportedElements.has(cellKey)) return;
                reportedElements.add(cellKey);
                
                issueCount++;
                
                if (issueCount <= 15) {
                    this.addDetail('mobile', 'warning', 'テーブルセル内の折り返し', {
                        element: cell.tagName,
                        text: text,
                        width: Math.round(rect.width) + 'px',
                        suggestion: 'セル幅の調整またはフォントサイズの縮小を検討'
                    });
                }
            }
        });
        
        if (issueCount > 0) {
            this.warnings.push({
                type: 'text-wrapping',
                message: `不要な行折り返し: ${issueCount}箇所`
            });
            this.info.push(`テキスト折り返し問題: ${issueCount}箇所`);
        }
        
        return issueCount;
    },
    
    /**
     * 透明デザインを使用しているか検出
     * 注意: 背景画像があるだけでは透明デザインとは判定しない
     */
    detectTransparentDesign: function() {
        const body = document.body;
        
        // data-theme属性をチェック（最優先）
        const theme = body.dataset.theme || body.getAttribute('data-theme') || '';
        if (theme.includes('transparent') || theme.includes('glass')) {
            return true;
        }
        
        // data-bg-style属性をチェック
        const bgStyle = body.dataset.bgStyle || body.getAttribute('data-bg-style') || '';
        if (bgStyle === 'transparent' || bgStyle.includes('glass')) {
            return true;
        }
        
        // クラス名をチェック
        if (body.className.includes('theme-transparent') || body.className.includes('glass-theme')) {
            return true;
        }
        
        // 上記に該当しない場合は透明デザインではない
        // （背景画像があるだけでは透明デザインとは言えない）
        return false;
    },
    
    // ========================================
    // ヘルパー関数
    // ========================================
    
    addDetail: function(category, severity, title, data) {
        this.details[category].push({
            severity: severity,
            title: title,
            data: data,
            timestamp: Date.now()
        });
    },
    
    getEffectiveBackgroundColor: function(element) {
        let el = element;
        let bgColor = null;
        
        while (el && el !== document.body) {
            const style = getComputedStyle(el);
            const bg = style.backgroundColor;
            
            if (bg && bg !== 'transparent' && bg !== 'rgba(0, 0, 0, 0)') {
                bgColor = bg;
                break;
            }
            
            // 背景画像がある場合
            if (style.backgroundImage && style.backgroundImage !== 'none') {
                return null; // 計算できない
            }
            
            el = el.parentElement;
        }
        
        return bgColor || 'rgb(255, 255, 255)';
    },
    
    calculateContrast: function(color1, color2) {
        const lum1 = this.getLuminance(color1);
        const lum2 = this.getLuminance(color2);
        
        if (lum1 === null || lum2 === null) return 21; // 計算できない場合は最大値
        
        const lighter = Math.max(lum1, lum2);
        const darker = Math.min(lum1, lum2);
        
        return (lighter + 0.05) / (darker + 0.05);
    },
    
    getLuminance: function(color) {
        const rgb = color.match(/\d+/g);
        if (!rgb || rgb.length < 3) return null;
        
        const [r, g, b] = rgb.slice(0, 3).map(c => {
            const val = parseInt(c) / 255;
            return val <= 0.03928 ? val / 12.92 : Math.pow((val + 0.055) / 1.055, 2.4);
        });
        
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    },
    
    hasEventListener: function(element) {
        // jQueryイベントをチェック
        if (window.jQuery) {
            try {
                const events = jQuery._data(element, 'events');
                if (events && (events.click || events.submit || events.touchend)) return true;
            } catch (e) {}
        }
        
        // form内のボタンはsubmit機能を持つ可能性がある
        if (element.closest('form')) return true;
        
        // カスタムデータ属性でハンドラを持つ場合
        if (element.dataset && Object.keys(element.dataset).some(key => 
            key.toLowerCase().includes('action') || 
            key.toLowerCase().includes('target') ||
            key.toLowerCase().includes('toggle') ||
            key.toLowerCase().includes('click')
        )) return true;
        
        // 特定のクラス名パターン（UIフレームワークのボタン）
        const className = element.className || '';
        if (/\b(toggle|dropdown|collapse|modal|tab|accordion|dismiss|close)\b/i.test(className)) return true;
        
        // aria属性でインタラクティブなボタン
        if (element.getAttribute('aria-controls') || 
            element.getAttribute('aria-expanded') ||
            element.getAttribute('aria-pressed') ||
            element.getAttribute('data-toggle') ||
            element.getAttribute('data-bs-toggle')) return true;
        
        // Vue/React/Angularのイベントバインディング
        for (const attr of element.attributes) {
            if (attr.name.startsWith('@click') || 
                attr.name.startsWith('v-on:') ||
                attr.name.startsWith('ng-click') ||
                attr.name.startsWith('(click)')) return true;
        }
        
        return false;
    },
    
    /**
     * URLを短縮
     */
    truncateUrl: function(url) {
        if (!url) return '';
        if (url.length <= 60) return url;
        
        try {
            const urlObj = new URL(url, location.href);
            const path = urlObj.pathname;
            const filename = path.split('/').pop();
            return '...' + path.substring(Math.max(0, path.length - 50));
        } catch (e) {
            return url.substring(0, 30) + '...' + url.substring(url.length - 20);
        }
    },
    
    /**
     * 要素のパスを取得（詳細版）
     */
    getElementPath: function(element) {
        if (!element) return '';
        
        const parts = [];
        let el = element;
        let depth = 0;
        
        while (el && el !== document.body && depth < 5) {
            let part = el.tagName.toLowerCase();
            
            if (el.id) {
                part = '#' + el.id;
                parts.unshift(part);
                break; // IDがあればそこで終了
            }
            
            if (el.className && typeof el.className === 'string') {
                const classes = el.className.split(' ').filter(c => c && !c.startsWith('ng-')).slice(0, 2);
                if (classes.length > 0) {
                    part += '.' + classes.join('.');
                }
            }
            
            // data-message-idがあれば追加
            const msgId = el.getAttribute('data-message-id');
            if (msgId) {
                part += `[data-message-id="${msgId}"]`;
            }
            
            parts.unshift(part);
            el = el.parentElement;
            depth++;
        }
        
        return parts.join(' > ');
    },
    
    // ========================================
    // 結果表示
    // ========================================
    
    showResults: function() {
        console.log('');
        console.log('%c📊 検査結果サマリー', 'color: #22c55e; font-weight: bold; font-size: 16px');
        console.log('═'.repeat(60));
        
        // 概要
        console.log('%c📋 概要', 'font-weight: bold');
        this.info.forEach(i => console.log('  ' + i));
        
        // エラー
        if (this.errors.length > 0) {
            console.log('');
            console.log('%c❌ エラー (' + this.errors.length + '件)', 'color: #ef4444; font-weight: bold');
            this.errors.forEach(e => console.error('  ' + e.message));
        }
        
        // 警告
        if (this.warnings.length > 0) {
            console.log('');
            console.log('%c⚠️ 警告 (' + this.warnings.length + '件)', 'color: #f59e0b; font-weight: bold');
            this.warnings.forEach(w => console.warn('  ' + w.message));
        }
        
        // カテゴリ別詳細
        console.log('');
        console.log('%c📝 カテゴリ別詳細', 'font-weight: bold');
        
        const categories = [
            { key: 'visibility', name: '視覚' },
            { key: 'layout', name: 'レイアウト（重なり含む）' },
            { key: 'functionality', name: '機能' },
            { key: 'accessibility', name: 'アクセシビリティ' },
            { key: 'performance', name: 'パフォーマンス' },
            { key: 'resources', name: 'リソース' },
            { key: 'data', name: 'データ整合性' },
            { key: 'mobile', name: 'モバイルUI' },
            { key: 'transparent', name: '透明デザイン' },
            { key: 'pwa', name: 'PWA/アプリ' }
        ];
        
        categories.forEach(cat => {
            const items = this.details[cat.key];
            const errorCount = items.filter(i => i.severity === 'error').length;
            const warningCount = items.filter(i => i.severity === 'warning').length;
            
            let status = '✅';
            if (errorCount > 0) status = '❌';
            else if (warningCount > 0) status = '⚠️';
            
            console.log(`  ${status} ${cat.name}: エラー ${errorCount}, 警告 ${warningCount}`);
        });
        
        console.log('');
        console.log('═'.repeat(60));
        
        const totalErrors = this.errors.length;
        const totalWarnings = this.warnings.length;
        
        if (totalErrors === 0 && totalWarnings === 0) {
            console.log('%c✨ 問題は検出されませんでした！', 'color: #22c55e; font-weight: bold; font-size: 14px');
        } else {
            console.log(`%c検出: エラー ${totalErrors}件, 警告 ${totalWarnings}件`, 
                        totalErrors > 0 ? 'color: #ef4444; font-weight: bold' : 'color: #f59e0b; font-weight: bold');
        }
        
        console.log('');
        console.log('詳細レポート: PageInspector.getFullReport()');
    },
    
    getFullReport: function() {
        return {
            url: location.href,
            title: document.title,
            viewport: { width: window.innerWidth, height: window.innerHeight },
            timestamp: new Date().toISOString(),
            summary: {
                errors: this.errors.length,
                warnings: this.warnings.length,
                info: this.info.length
            },
            errors: this.errors,
            warnings: this.warnings,
            info: this.info,
            details: this.details
        };
    },
    
    getSummary: function() {
        return {
            url: location.href,
            errors: this.errors.length,
            warnings: this.warnings.length,
            status: this.errors.length > 0 ? 'error' : (this.warnings.length > 0 ? 'warning' : 'ok')
        };
    },
    
    help: function() {
        console.log('%c🔍 PageInspector ヘルプ', 'color: #3b82f6; font-weight: bold; font-size: 14px');
        console.log('');
        console.log('PageInspector.inspect()      - 完全検査を実行');
        console.log('PageInspector.getFullReport() - 詳細レポートを取得');
        console.log('PageInspector.getSummary()   - サマリーを取得');
        console.log('PageInspector.help()         - このヘルプを表示');
    }
};

// ========================================
// リソース監視（自動開始）
// ========================================

(function() {
    // グローバルエラーハンドラでリソースエラーをキャッチ
    window.addEventListener('error', function(e) {
        if (e.target && (e.target.tagName === 'IMG' || e.target.tagName === 'VIDEO' || e.target.tagName === 'AUDIO')) {
            PageInspector.resourceErrors.push({
                type: e.target.tagName.toLowerCase(),
                src: e.target.src || e.target.currentSrc,
                timestamp: Date.now()
            });
        }
    }, true); // captureフェーズで取得
    
    // fetch/XHRエラーを監視
    const originalFetch = window.fetch;
    window.fetch = async function(...args) {
        try {
            const response = await originalFetch.apply(this, args);
            if (!response.ok && response.status >= 400) {
                PageInspector.networkErrors.push({
                    type: 'fetch',
                    url: args[0]?.url || args[0],
                    status: response.status,
                    timestamp: Date.now()
                });
            }
            return response;
        } catch (error) {
            PageInspector.networkErrors.push({
                type: 'fetch',
                url: args[0]?.url || args[0],
                error: error.message,
                timestamp: Date.now()
            });
            throw error;
        }
    };
})();

console.log('%c🔍 PageInspector (拡張版 v2) 読み込み完了', 'color: #22c55e; font-weight: bold');
console.log('PageInspector.inspect() で完全検査を開始');
console.log('リソースエラー監視: 有効');
