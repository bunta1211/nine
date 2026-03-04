/**
 * AI秘書 熟慮モード フロントエンド
 * 進行状況をポーリングし、Cursor風の枠内に逐次表示する
 */
(function() {
    'use strict';

    var POLL_INTERVAL = 1500;
    var PHASE_ICONS = {
        search:  '🔍',
        think:   '🧠',
        execute: '⚡',
        done:    '✅',
        error:   '❌'
    };

    function createDeliberationBox(container) {
        var box = document.createElement('div');
        box.className = 'ai-deliberation-box';
        box.id = 'aiDeliberationBox';
        box.innerHTML =
            '<div class="deliberation-title">' +
                '<span class="deliberation-spinner"></span> 熟慮モード — 調査・推論中...' +
            '</div>' +
            '<div class="deliberation-lines" id="aiDelibLines"></div>';
        container.appendChild(box);
        box.scrollIntoView({ behavior: 'smooth', block: 'end' });
        return box;
    }

    function addLine(phase, message) {
        var linesEl = document.getElementById('aiDelibLines');
        if (!linesEl) return;
        var icon = PHASE_ICONS[phase] || '📌';
        var line = document.createElement('div');
        line.className = 'deliberation-line';
        line.innerHTML =
            '<span class="phase-icon">' + icon + '</span>' +
            '<span>' + escapeHtml(message) + '</span>';
        linesEl.appendChild(line);
        var box = document.getElementById('aiDeliberationBox');
        if (box) box.scrollTop = box.scrollHeight;
    }

    function markComplete() {
        var box = document.getElementById('aiDeliberationBox');
        if (!box) return;
        box.classList.add('completed');
        var title = box.querySelector('.deliberation-title');
        if (title) {
            title.innerHTML = '✅ 熟慮完了';
        }
    }

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function pollStatus(sessionId, afterLine, onComplete) {
        var url = 'api/ai-deliberation-status.php?session_id=' +
                  encodeURIComponent(sessionId) +
                  '&after_line=' + afterLine;
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    onComplete(null);
                    return;
                }
                if (data.lines && data.lines.length > 0) {
                    for (var i = 0; i < data.lines.length; i++) {
                        addLine(data.lines[i].phase, data.lines[i].message);
                    }
                }
                if (data.finished) {
                    markComplete();
                    onComplete(data.result || null);
                } else {
                    setTimeout(function() {
                        pollStatus(sessionId, data.total, onComplete);
                    }, POLL_INTERVAL);
                }
            })
            .catch(function(err) {
                console.error('[Deliberation] poll error:', err);
                setTimeout(function() {
                    pollStatus(sessionId, afterLine, onComplete);
                }, POLL_INTERVAL * 2);
            });
    }

    /**
     * 熟慮モードでメッセージを送信
     * @param {string} question
     * @param {HTMLElement} messagesContainer
     * @param {Function} onDone callback(answer)
     */
    window.sendDeliberationMessage = function(question, messagesContainer, onDone) {
        var box = createDeliberationBox(messagesContainer);
        addLine('search', '熟慮モードで調査を開始しています...');

        fetch('api/ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'ask',
                question: question,
                deliberation_mode: true
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data && data.data.answer) {
                var sid = data.data.deliberation_session_id;
                if (sid) {
                    pollStatus(sid, 0, function() {
                        markComplete();
                        onDone(data.data.answer, data.data);
                    });
                } else {
                    addLine('think', '情報を分析しました');
                    addLine('execute', '回答を作成しました');
                    markComplete();
                    onDone(data.data.answer, data.data);
                }
            } else {
                addLine('error', data.message || '処理に失敗しました');
                markComplete();
                onDone(data.message || '熟慮モードの処理に失敗しました。', null);
            }
        })
        .catch(function(err) {
            console.error('[Deliberation] send error:', err);
            addLine('error', '通信エラーが発生しました');
            markComplete();
            onDone('通信エラーが発生しました。', null);
        });
    };
})();
