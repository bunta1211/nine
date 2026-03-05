/**
 * チャット入力欄の上下リサイズ
 * 右パネルの左右リサイズと同様に、ドラッグで入力欄の高さを変更可能にする
 */
(function() {
    'use strict';

    const STORAGE_KEY = 'chat_input_area_height';
    const MIN_HEIGHT = 90;   /* ツールバー＋約2行 */
    const MAX_HEIGHT = 320;  /* ツールバー＋約15行以上 */
    const DEFAULT_HEIGHT = 140;

    function getInputArea() {
        return document.getElementById('inputArea');
    }

    function getHandle() {
        return document.getElementById('inputAreaResizeHandle');
    }

    function getCurrentHeight(el) {
        const h = el.style.height;
        if (h) return parseInt(h, 10);
        return el.offsetHeight;
    }

    function applyHeight(height) {
        const area = getInputArea();
        if (!area) return;
        const val = Math.max(MIN_HEIGHT, Math.min(MAX_HEIGHT, height));
        area.style.height = val + 'px';
        area.classList.add('input-area-has-height');
        try { localStorage.setItem(STORAGE_KEY, String(val)); } catch (e) {}
        /* 携帯: メッセージ領域の余白をリサイズ高さに合わせる（PCと同じ挙動） */
        if (typeof window !== 'undefined' && window.innerWidth <= 768) {
            try { document.documentElement.style.setProperty('--mobile-input-height', val + 'px'); } catch (e) {}
        }
    }

    function initResize() {
        const area = getInputArea();
        const handle = getHandle();
        if (!area || !handle) return;

        /* 保存された高さを復元（7行未満の保存値は168にクランプ） */
        const savedRaw = parseInt(localStorage.getItem(STORAGE_KEY), 10);
        const saved = (savedRaw && savedRaw >= MIN_HEIGHT && savedRaw <= MAX_HEIGHT)
            ? Math.max(168, savedRaw)  /* 168未満は7行表示にクランプ */
            : 0;
        if (saved) {
            applyHeight(saved);
        }

        /* 携帯表示に切り替えたとき、リサイズ済みなら --mobile-input-height を同期 */
        function syncMobileHeightVar() {
            if (window.innerWidth > 768) return;
            var a = getInputArea();
            if (a && a.classList.contains('input-area-has-height') && a.style.height) {
                var px = parseInt(a.style.height, 10);
                if (!isNaN(px)) document.documentElement.style.setProperty('--mobile-input-height', px + 'px');
            }
        }
        window.addEventListener('resize', syncMobileHeightVar);

        function getY(e) {
            if (e.touches && e.touches.length) return e.touches[0].clientY;
            if (e.changedTouches && e.changedTouches.length) return e.changedTouches[0].clientY;
            return e.clientY;
        }

        function onStart(e) {
            if (e.type === 'mousedown' && e.button !== 0) return;
            e.preventDefault();
            const startY = getY(e);
            let startHeight = getCurrentHeight(area);
            if (!area.classList.contains('input-area-has-height') || !area.style.height) {
                startHeight = area.offsetHeight;
            }

            document.body.classList.add('resizing-input-area');
            handle.classList.add('resizing');

            function onMove(e) {
                const y = getY(e);
                const dy = startY - y; /* 上にドラッグ → y 減少 → dy 正 → 高さ増 */
                const newH = Math.max(MIN_HEIGHT, Math.min(MAX_HEIGHT, startHeight + dy));
                applyHeight(newH);
            }

            function onEnd() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onEnd);
                document.removeEventListener('touchmove', onMove, { passive: false });
                document.removeEventListener('touchend', onEnd);
                document.removeEventListener('touchcancel', onEnd);
                document.body.classList.remove('resizing-input-area');
                handle.classList.remove('resizing');
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onEnd);
            document.addEventListener('touchcancel', onEnd);
        }

        handle.addEventListener('mousedown', onStart);
        handle.addEventListener('touchstart', onStart, { passive: false });
    }

    if (typeof window !== 'undefined') {
        window.initInputAreaResize = initResize;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initResize);
    } else {
        initResize();
    }
})();
