/**
 * パネルリサイズ機能
 * 左・右パネルの幅をドラッグで変更可能にする
 */
(function() {
    'use strict';

    const STORAGE_KEY_LEFT = 'chat_left_panel_width';
    const STORAGE_KEY_RIGHT = 'chat_right_panel_width';
    const MIN_LEFT = 180;
    const MAX_LEFT = 420;
    const MIN_RIGHT = 200;
    const MAX_RIGHT = 480;
    const DEFAULT_LEFT = 260;
    const DEFAULT_RIGHT = 280;

    function getContainer() {
        return document.querySelector('.main-container');
    }

    function px(val) {
        return typeof val === 'number' ? val + 'px' : val;
    }

    function setPanelWidth(side, value) {
        const container = getContainer();
        const varName = side === 'left' ? '--left-panel-width' : '--right-panel-width';
        const val = typeof value === 'number' ? value + 'px' : value;
        if (container) container.style.setProperty(varName, val);
        document.documentElement.style.setProperty(varName, val);
    }

    function initResize() {
        const container = getContainer();
        if (!container) return;

        const leftHandle = document.getElementById('resizeLeftHandle');
        const rightHandle = document.getElementById('resizeRightHandle');
        const leftPanel = document.getElementById('leftPanel');
        const rightPanel = document.getElementById('rightPanel');

        if (!leftHandle || !rightHandle || !leftPanel || !rightPanel) return;

        // モバイルでは何もしない（768px以下）
        if (window.innerWidth <= 768) return;

        // 保存された幅を復元
        const savedLeft = parseInt(localStorage.getItem(STORAGE_KEY_LEFT), 10);
        const savedRight = parseInt(localStorage.getItem(STORAGE_KEY_RIGHT), 10);
        if (savedLeft && savedLeft >= MIN_LEFT && savedLeft <= MAX_LEFT && !leftPanel.classList.contains('collapsed')) {
            setPanelWidth('left', savedLeft);
        }
        if (savedRight && savedRight >= MIN_RIGHT && savedRight <= MAX_RIGHT && !rightPanel.classList.contains('collapsed')) {
            setPanelWidth('right', savedRight);
        }

        // 左パネルリサイズ
        let startX = 0, startLeftWidth = 0;
        leftHandle.addEventListener('mousedown', function(e) {
            if (e.button !== 0) return;
            e.preventDefault();
            if (leftPanel.classList.contains('collapsed')) return;
            startX = e.clientX;
            let w = container.style.getPropertyValue('--left-panel-width');
            if (!w) {
                const cs = getComputedStyle(leftPanel);
                w = cs.width; /* 実際の幅を取得 */
            }
            startLeftWidth = w ? parseInt(w, 10) : DEFAULT_LEFT;
            document.body.classList.add('resizing-panel');
            leftHandle.classList.add('resizing');

            function onMove(e) {
                const dx = e.clientX - startX;
                let newW = startLeftWidth + dx;
                newW = Math.max(MIN_LEFT, Math.min(MAX_LEFT, newW));
                setPanelWidth('left', newW);
                localStorage.setItem(STORAGE_KEY_LEFT, String(newW));
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.body.classList.remove('resizing-panel');
                leftHandle.classList.remove('resizing');
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // 右パネルリサイズ
        let startRightWidth = 0;
        rightHandle.addEventListener('mousedown', function(e) {
            if (e.button !== 0) return;
            e.preventDefault();
            if (rightPanel.classList.contains('collapsed')) return;
            startX = e.clientX;
            let w = container.style.getPropertyValue('--right-panel-width');
            if (!w) {
                const cs = getComputedStyle(rightPanel);
                w = cs.width; /* 実際の幅を取得 */
            }
            startRightWidth = w ? parseInt(w, 10) : DEFAULT_RIGHT;
            document.body.classList.add('resizing-panel');
            rightHandle.classList.add('resizing');

            function onMove(e) {
                const dx = startX - e.clientX; // 右方向ドラッグで右パネル拡大
                let newW = startRightWidth + dx;
                newW = Math.max(MIN_RIGHT, Math.min(MAX_RIGHT, newW));
                setPanelWidth('right', newW);
                localStorage.setItem(STORAGE_KEY_RIGHT, String(newW));
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.body.classList.remove('resizing-panel');
                rightHandle.classList.remove('resizing');
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initResize);
    } else {
        initResize();
    }
})();
