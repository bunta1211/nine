/**
 * AIクローン 返信提案ロジック
 * メンション付きメッセージに対して、AIクローンが返信文を提案し、
 * ユーザーが編集→送信→修正内容を教材として記録する。
 */
(function () {
    'use strict';

    var API_AI = 'api/ai.php';
    var API_MSG = 'api/messages.php';

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function isMobile() {
        return window.innerWidth <= 768;
    }

    function getCardHtml(messageId, suggested, conversationId, suggestionId, members) {
        members = members || [];
        var toRow = '';
        if (members.length > 0) {
            toRow = '<div class="ai-reply-suggest-to-row">' +
                '<span class="ai-reply-suggest-to-label">To:</span> ' +
                '<button type="button" class="ai-reply-suggest-to-btn" data-ai-to-msg="' + messageId + '" data-ai-to-id="all" data-ai-to-label="全員">全員</button>';
            members.forEach(function (m) {
                var name = (m.display_name || '').trim() || ('ID' + m.id);
                var label = name + 'さん';
                toRow += ' <button type="button" class="ai-reply-suggest-to-btn" data-ai-to-msg="' + messageId + '" data-ai-to-id="' + m.id + '" data-ai-to-label="' + String(label).replace(/"/g, '&quot;') + '">' + escHtml(name) + '</button>';
            });
            toRow += '</div>';
        }
        return '<div class="ai-reply-suggest-card">' +
            '<div class="ai-reply-suggest-header">🤖 AIクローンの返信提案</div>' +
            toRow +
            '<textarea class="ai-reply-suggest-textarea" id="aiSuggestText_' + messageId + '">' + escHtml(suggested) + '</textarea>' +
            '<div class="ai-reply-suggest-actions">' +
            '<button class="ai-reply-suggest-cancel" onclick="AIReplySuggest.dismiss(' + messageId + ')">閉じる</button>' +
            '<button class="ai-reply-suggest-send" onclick="AIReplySuggest.send(' + messageId + ', ' + conversationId + ', ' + suggestionId + ')">この内容で送信</button>' +
            '</div>' +
            '</div>';
    }

    window.AIReplySuggest = {

        generate: function (messageId, conversationId, btnEl) {
            if (!conversationId) { alert('会話が選択されていません'); return; }

            var bar = btnEl.closest('.ai-reply-suggest-bar');
            if (!bar) return;

            bar.innerHTML = '<div class="ai-reply-suggest-loading">🤖 提案を生成中...</div>';

            fetch(API_AI, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'suggest_reply',
                    conversation_id: conversationId,
                    message_id: messageId
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success && (res.data || res.suggested_content !== undefined)) {
                    var payload = res.data || { suggested_content: res.suggested_content, suggestion_id: res.suggestion_id };
                    AIReplySuggest._showSuggestion(bar, payload, messageId, conversationId);
                } else {
                    var errText = res.message || '提案の生成に失敗しました';
                    if (res.hint) errText += '\n\n' + res.hint;
                    bar.innerHTML = '<div class="ai-reply-suggest-error">' + escHtml(errText) + ' <button class="ai-reply-suggest-btn" onclick="AIReplySuggest.generate(' + messageId + ', ' + conversationId + ', this)">再試行</button></div>';
                }
            })
            .catch(function () {
                bar.innerHTML = '<div class="ai-reply-suggest-error">通信エラー <button class="ai-reply-suggest-btn" onclick="AIReplySuggest.generate(' + messageId + ', ' + conversationId + ', this)">再試行</button></div>';
            });
        },

        _showSuggestion: function (bar, data, messageId, conversationId) {
            var suggestionId = data.suggestion_id || 0;
            var suggested = data.suggested_content || '';
            var members = data.members || [];
            var mobile = isMobile();
            var cardHtml = getCardHtml(messageId, suggested, conversationId, suggestionId, members);

            document.body.classList.add('ai-reply-suggest-open');

            if (mobile) {
                var existing = document.querySelector('.ai-reply-suggest-overlay');
                if (existing) existing.remove();
                var overlay = document.createElement('div');
                overlay.className = 'ai-reply-suggest-overlay';
                overlay.setAttribute('data-msg-id', String(messageId));
                overlay.innerHTML = cardHtml;
                document.body.appendChild(overlay);
                bar.innerHTML = '';
            } else {
                bar.innerHTML = cardHtml;
            }

            var container = mobile ? overlay : bar;
            container.querySelectorAll('.ai-reply-suggest-to-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var msgId = parseInt(btn.getAttribute('data-ai-to-msg'), 10);
                    var toId = btn.getAttribute('data-ai-to-id');
                    var toLabel = btn.getAttribute('data-ai-to-label') || '全員';
                    AIReplySuggest.insertTo(msgId, toId, toLabel);
                });
            });

            var ta = document.getElementById('aiSuggestText_' + messageId);
            if (ta && !mobile) {
                ta.style.height = 'auto';
                ta.style.height = ta.scrollHeight + 'px';
                ta.addEventListener('input', function () {
                    this.style.height = 'auto';
                    this.style.height = this.scrollHeight + 'px';
                });
            }
        },

        /**
         * 返信提案テキストに To 行を挿入（カーソル位置または先頭）
         */
        insertTo: function (messageId, toId, toLabel) {
            var ta = document.getElementById('aiSuggestText_' + messageId);
            if (!ta) return;
            var line = toId === 'all' ? '[To:all]全員' : '[To:' + toId + ']' + toLabel;
            var text = line + '\n';
            var start = ta.selectionStart;
            var end = ta.selectionEnd;
            var val = ta.value;
            ta.value = val.slice(0, start) + text + val.slice(end);
            ta.selectionStart = ta.selectionEnd = start + text.length;
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        },

        send: function (messageId, conversationId, suggestionId) {
            var ta = document.getElementById('aiSuggestText_' + messageId);
            if (!ta) return;
            var finalContent = ta.value.trim();
            if (!finalContent) { alert('返信内容を入力してください'); return; }

            var bar = ta.closest('.ai-reply-suggest-bar');
            if (!bar) bar = document.querySelector('.ai-reply-suggest-bar[data-msg-id="' + messageId + '"]');
            var overlay = document.querySelector('.ai-reply-suggest-overlay');
            var sendBtn = (overlay ? overlay.querySelector('.ai-reply-suggest-send') : null) || (bar ? bar.querySelector('.ai-reply-suggest-send') : null);
            if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = '送信中...'; }

            fetch(API_MSG, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'send',
                    conversation_id: conversationId,
                    content: finalContent,
                    reply_to_message_id: messageId
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success || res.message_id) {
                    if (suggestionId) {
                        AIReplySuggest._recordCorrection(suggestionId, finalContent);
                    }
                    if (overlay) overlay.remove();
                    document.body.classList.remove('ai-reply-suggest-open');
                    if (bar) {
                        bar.innerHTML = '<div class="ai-reply-suggest-sent">✅ 送信しました</div>';
                        setTimeout(function () { bar.remove(); }, 3000);
                    }
                } else {
                    alert(res.message || res.error || '送信に失敗しました');
                    if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'この内容で送信'; }
                }
            })
            .catch(function () {
                alert('通信エラー');
                if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'この内容で送信'; }
            });
        },

        _recordCorrection: function (suggestionId, finalContent) {
            fetch(API_AI, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'record_reply_correction',
                    suggestion_id: suggestionId,
                    final_content: finalContent
                })
            }).catch(function () {});
        },

        dismiss: function (messageId) {
            var overlay = document.querySelector('.ai-reply-suggest-overlay');
            if (overlay) overlay.remove();
            document.body.classList.remove('ai-reply-suggest-open');
            var bar = document.querySelector('.ai-reply-suggest-bar[data-msg-id="' + messageId + '"]');
            if (bar) bar.remove();
        },

        /**
         * 初期表示（PHP描画）メッセージのうち「自分宛て・3日以内」の直下に返信提案バーを挿入する。
         * PHP の $is_mentioned が本番で false になる場合のフォールバック。
         */
        injectBarsForInitialMessages: function () {
            var area = document.getElementById('messagesArea');
            if (!area) return;
            var conversationId = parseInt(area.getAttribute('data-conversation-id'), 10);
            if (!conversationId) return;
            var currentUserId = window._currentUserId;
            if (currentUserId == null || currentUserId === undefined) {
                var bodyId = document.body && document.body.getAttribute('data-user-id');
                if (bodyId !== null && bodyId !== '') currentUserId = parseInt(bodyId, 10);
            }
            if (currentUserId == null || currentUserId === undefined) return;
            currentUserId = parseInt(currentUserId, 10);
            var threeDaysAgo = Date.now() - (3 * 24 * 60 * 60 * 1000);
            var cards = area.querySelectorAll('.message-card:not(.own)');
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var msgId = card.getAttribute('data-message-id');
                if (!msgId) continue;
                var next = card.nextElementSibling;
                if (next && next.classList && next.classList.contains('ai-reply-suggest-bar')) continue;
                var createdAt = card.getAttribute('data-created-at');
                if (createdAt) {
                    var t = new Date(createdAt.replace(' ', 'T')).getTime();
                    if (isNaN(t) || t < threeDaysAgo) continue;
                }
                var isToMe = card.classList.contains('mentioned-me');
                if (!isToMe) {
                    var toUsersStr = card.getAttribute('data-to-users');
                    if (toUsersStr) {
                        try {
                            var toUsers = typeof toUsersStr === 'string' ? JSON.parse(toUsersStr) : toUsersStr;
                            if (Array.isArray(toUsers) && (toUsers.indexOf('all') !== -1 || toUsers.some(function (id) { return parseInt(id, 10) === currentUserId; }))) isToMe = true;
                        } catch (e) {}
                    }
                }
                if (!isToMe) {
                    var content = card.getAttribute('data-content');
                    if (content && typeof content === 'string') {
                        var toIdRe = /\[To:\s*(\d+)\]/g;
                        var match;
                        while ((match = toIdRe.exec(content)) !== null) {
                            if (parseInt(match[1], 10) === currentUserId) { isToMe = true; break; }
                        }
                    }
                }
                // 表示上のTOチップ（data-to）に自分が含まれる場合も自分宛てとする（事務局など全グループで表示するため）
                if (!isToMe && card.querySelector) {
                    var toChip = card.querySelector('[data-to="' + currentUserId + '"]') || card.querySelector('[data-to="all"]');
                    if (toChip) isToMe = true;
                }
                if (!isToMe) continue;
                var bar = document.createElement('div');
                bar.className = 'ai-reply-suggest-bar';
                bar.setAttribute('data-msg-id', msgId);
                bar.innerHTML = '<button type="button" class="ai-reply-suggest-btn" onclick="AIReplySuggest.generate(' + msgId + ', ' + conversationId + ', this)">🤖 AI返信提案を生成</button>';
                card.insertAdjacentElement('afterend', bar);
            }
        }
    };

    function runInjectBars() {
        if (window.AIReplySuggest && typeof window.AIReplySuggest.injectBarsForInitialMessages === 'function') {
            window.AIReplySuggest.injectBarsForInitialMessages();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runInjectBars);
    } else {
        runInjectBars();
    }
})();
