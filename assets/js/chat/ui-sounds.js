/**
 * UI効果音（パネル収納ボタン等）
 * 左右パネル収納時に book1 を再生
 */
(function() {
    'use strict';

    var PANEL_COLLAPSE_SOUND = 'assets/sounds/book1.mp3';
    var _panelSound = null;

    /**
     * パネル収納ボタン押下時に再生する音（book1）
     */
    window.playPanelCollapseSound = function() {
        try {
            if (!_panelSound) {
                _panelSound = new Audio(PANEL_COLLAPSE_SOUND);
            }
            _panelSound.currentTime = 0;
            _panelSound.volume = 0.5;
            _panelSound.play().catch(function() {});
        } catch (e) {}
    };
})();
