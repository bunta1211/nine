/**
 * AI秘書 性格設定パネル
 * インポート/エクスポート、熟慮時間、自動話しかけ設定を含む
 */
(function() {
    'use strict';

    var PERSONALITY_FIELDS = [
        { key: 'pronoun',    label: '一人称・呼び方',    placeholder: '例: 私は「私」、ユーザーは名前で呼ぶ。愛称は「〇〇さん」', hint: 'AIの一人称とユーザーへの呼び方' },
        { key: 'tone',       label: '話し方・口調',      placeholder: '例: 丁寧語ベースだけどフレンドリー。絵文字は控えめに', hint: '敬語/タメ口/丁寧さの度合い' },
        { key: 'character',  label: '性格・態度',        placeholder: '例: 明るく前向き。困っている時は優しく寄り添う', hint: '陽気/冷静/厳格/優しいなど' },
        { key: 'expertise',  label: '得意分野・知識',    placeholder: '例: プログラミング、スケジュール管理、ビジネス文書', hint: '特に詳しい分野や役割' },
        { key: 'behavior',   label: '行動スタイル',      placeholder: '例: 結論を先に言う。長文は避ける。箇条書きを好む', hint: '回答の長さ・スタイル・積極性' },
        { key: 'avoid',      label: '禁止事項・注意点',  placeholder: '例: 専門用語を使わない。政治的な話題は避ける', hint: 'してほしくないこと' },
        { key: 'other',      label: 'その他の指示',      placeholder: '例: 毎回挨拶をする。冗談を交える', hint: '上以外の自由記述' }
    ];

    var MAX_CHAR = 500;

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function buildModal(data) {
        var personality = data.personality || {};
        var deliberationMin = Math.round((data.deliberation_max_seconds || 180) / 60);
        var proactiveEnabled = (data.proactive_message_enabled == 1 || data.proactive_message_enabled === '1');
        var proactiveHour = data.proactive_message_hour != null ? parseInt(data.proactive_message_hour, 10) : 18;
        var todayTopicsOshi = (data.today_topics_oshi != null && data.today_topics_oshi !== '') ? String(data.today_topics_oshi) : '';

        var fieldsHtml = '';
        for (var i = 0; i < PERSONALITY_FIELDS.length; i++) {
            var f = PERSONALITY_FIELDS[i];
            var val = personality[f.key] || '';
            fieldsHtml +=
                '<div class="ai-personality-field">' +
                    '<label class="ai-personality-label">' + escapeHtml(f.label) + '</label>' +
                    '<p class="ai-personality-hint">' + escapeHtml(f.hint) + '</p>' +
                    '<textarea class="ai-personality-input" data-pkey="' + f.key + '" ' +
                        'placeholder="' + escapeHtml(f.placeholder) + '" maxlength="' + MAX_CHAR + '">' + escapeHtml(val) + '</textarea>' +
                    '<div class="ai-personality-char-count"><span class="pchar-count">' + val.length + '</span>/' + MAX_CHAR + '</div>' +
                '</div>';
        }

        var hourOptions = '';
        for (var h = 0; h <= 23; h++) {
            var sel = (h === proactiveHour) ? ' selected' : '';
            hourOptions += '<option value="' + h + '"' + sel + '>' + h + ':00</option>';
        }

        var delibOptions = '';
        var delibChoices = [1,2,3,5,10,15,20,30];
        for (var di = 0; di < delibChoices.length; di++) {
            var ds = (delibChoices[di] === deliberationMin) ? ' selected' : '';
            delibOptions += '<option value="' + delibChoices[di] + '"' + ds + '>' + delibChoices[di] + '分</option>';
        }

        var html =
            '<div class="ai-personality-overlay" id="aiPersonalityOverlay">' +
                '<div class="ai-personality-modal">' +
                    '<div class="ai-personality-header">' +
                        '<h3>🧠 性格設定</h3>' +
                        '<button class="ai-personality-close-btn" id="aiPersonalityCloseBtn">&times;</button>' +
                    '</div>' +
                    '<div class="ai-personality-body">' +
                        fieldsHtml +

                        '<hr class="ai-personality-section-divider">' +

                        '<div class="ai-personality-setting-row">' +
                            '<div class="ai-personality-setting-label">熟慮モード最大時間<small>調査・推論にかける最大時間</small></div>' +
                            '<select class="ai-personality-select" id="aiDelibMaxMin">' + delibOptions + '</select>' +
                        '</div>' +

                        '<div class="ai-personality-setting-row">' +
                            '<div class="ai-personality-setting-label">毎日の自動話しかけ<small>AI秘書から1日1回話題を提供</small></div>' +
                            '<label class="ai-personality-toggle">' +
                                '<input type="checkbox" id="aiProactiveEnabled"' + (proactiveEnabled ? ' checked' : '') + '>' +
                                '<span class="ai-personality-toggle-slider"></span>' +
                            '</label>' +
                        '</div>' +

                        '<div class="ai-personality-setting-row">' +
                            '<div class="ai-personality-setting-label">話しかけ時刻<small>毎日この時刻頃に話しかけます</small></div>' +
                            '<select class="ai-personality-select" id="aiProactiveHour">' + hourOptions + '</select>' +
                        '</div>' +

                        '<hr class="ai-personality-section-divider">' +

                        '<div class="ai-personality-field ai-personality-oshi-row">' +
                            '<label class="ai-personality-label" for="aiTodayTopicsOshi">推し（今日の話題）</label>' +
                            '<p class="ai-personality-hint">応援している人物・芸能人・アーティスト名など。夕方の興味レポートでその方の話題をお届けします（有料プランでご利用いただけます）</p>' +
                            '<input type="text" class="ai-personality-input ai-personality-oshi-input" id="aiTodayTopicsOshi" placeholder="例: 好きな芸能人・VTuber・アーティスト名" value="' + escapeHtml(todayTopicsOshi) + '" maxlength="100">' +
                        '</div>' +
                        '<div class="ai-personality-paid-notice">' +
                            '<strong>📋 夕方の興味レポートについて</strong><br>' +
                            'お試し期間は1週間です。2週間以降も夕方の興味レポートをご希望の場合は、月額300〜500円（予定）のニュース配信プランへのご加入が必要です。利用者200名を超えた時点で有料に切り替わります。' +
                        '</div>' +

                        '<hr class="ai-personality-section-divider">' +

                        '<div class="ai-personality-ie-row">' +
                            '<button class="ai-personality-ie-btn" id="aiPersonalityImportBtn">📥 インポート</button>' +
                            '<button class="ai-personality-ie-btn" id="aiPersonalityExportBtn">📤 エクスポート</button>' +
                            '<button class="ai-personality-ie-btn" id="aiPersonalityCopyBtn">📋 コピー</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="ai-personality-footer">' +
                        '<button class="ai-personality-cancel-btn" id="aiPersonalityCancelBtn">キャンセル</button>' +
                        '<button class="ai-personality-save-btn" id="aiPersonalitySaveBtn">保存</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        return html;
    }

    function collectPersonality() {
        var result = {};
        var areas = document.querySelectorAll('#aiPersonalityOverlay .ai-personality-input');
        for (var i = 0; i < areas.length; i++) {
            var key = areas[i].getAttribute('data-pkey');
            if (key) result[key] = areas[i].value.trim();
        }
        return result;
    }

    function fillPersonality(personality) {
        if (!personality || typeof personality !== 'object') return;
        var areas = document.querySelectorAll('#aiPersonalityOverlay .ai-personality-input');
        for (var i = 0; i < areas.length; i++) {
            var key = areas[i].getAttribute('data-pkey');
            if (key && personality[key] != null) {
                areas[i].value = personality[key];
                var cnt = areas[i].parentNode.querySelector('.pchar-count');
                if (cnt) cnt.textContent = areas[i].value.length;
            }
        }
    }

    function closeModal() {
        var overlay = document.getElementById('aiPersonalityOverlay');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(function() { overlay.remove(); }, 300);
        }
    }

    function bindEvents() {
        document.getElementById('aiPersonalityCloseBtn').addEventListener('click', closeModal);
        document.getElementById('aiPersonalityCancelBtn').addEventListener('click', closeModal);

        var areas = document.querySelectorAll('#aiPersonalityOverlay .ai-personality-input');
        for (var i = 0; i < areas.length; i++) {
            areas[i].addEventListener('input', function() {
                var cnt = this.parentNode.querySelector('.pchar-count');
                if (cnt) cnt.textContent = this.value.length;
            });
        }

        document.getElementById('aiPersonalitySaveBtn').addEventListener('click', savePersonality);
        document.getElementById('aiPersonalityExportBtn').addEventListener('click', exportPersonality);
        document.getElementById('aiPersonalityImportBtn').addEventListener('click', importPersonality);
        document.getElementById('aiPersonalityCopyBtn').addEventListener('click', copyPersonality);
    }

    async function savePersonality() {
        var personality = collectPersonality();
        var delibMin = parseInt(document.getElementById('aiDelibMaxMin').value, 10) || 3;
        var proactiveEnabled = document.getElementById('aiProactiveEnabled').checked ? 1 : 0;
        var proactiveHour = parseInt(document.getElementById('aiProactiveHour').value, 10);

        var btn = document.getElementById('aiPersonalitySaveBtn');
        btn.disabled = true;
        btn.textContent = '保存中...';

        try {
            var resp = await fetch('api/ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_personality',
                    personality: personality,
                    deliberation_max_seconds: delibMin * 60,
                    proactive_message_enabled: proactiveEnabled,
                    proactive_message_hour: proactiveHour,
                    today_topics_oshi: (document.getElementById('aiTodayTopicsOshi') && document.getElementById('aiTodayTopicsOshi').value) ? document.getElementById('aiTodayTopicsOshi').value.trim() : ''
                })
            });
            var data = await resp.json();
            if (data.success) {
                closeModal();
                if (typeof window.addAIChatMessage === 'function') {
                    window.addAIChatMessage('性格設定を保存しました！これからの会話に反映されます。', 'ai');
                }
            } else {
                alert(data.message || '保存に失敗しました');
            }
        } catch (e) {
            console.error('[AI Personality] save error:', e);
            alert('保存に失敗しました');
        } finally {
            btn.disabled = false;
            btn.textContent = '保存';
        }
    }

    function exportPersonality() {
        var personality = collectPersonality();
        var exportData = {
            version: 1,
            app: 'social9',
            personality: personality,
            exported_at: new Date().toISOString()
        };
        var json = JSON.stringify(exportData, null, 2);
        var blob = new Blob([json], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        var dateStr = new Date().toISOString().slice(0, 10).replace(/-/g, '');
        a.href = url;
        a.download = 'personality_' + dateStr + '.json';
        a.click();
        URL.revokeObjectURL(url);
    }

    function importPersonality() {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = '.json';
        input.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(ev) {
                try {
                    var parsed = JSON.parse(ev.target.result);
                    var p = parsed.personality || parsed;
                    if (typeof p !== 'object') throw new Error('invalid');
                    fillPersonality(p);
                    if (typeof Chat !== 'undefined' && Chat.ui && Chat.ui.toast) {
                        Chat.ui.toast('インポートしました。内容を確認して保存してください。', 'info');
                    }
                } catch (err) {
                    alert('JSONの読み込みに失敗しました。正しい形式か確認してください。');
                }
            };
            reader.readAsText(file);
        });
        input.click();
    }

    function copyPersonality() {
        var personality = collectPersonality();
        var exportData = {
            version: 1,
            app: 'social9',
            personality: personality,
            exported_at: new Date().toISOString()
        };
        var json = JSON.stringify(exportData, null, 2);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(json).then(function() {
                if (typeof Chat !== 'undefined' && Chat.ui && Chat.ui.toast) {
                    Chat.ui.toast('性格設定をクリップボードにコピーしました', 'success');
                } else {
                    alert('コピーしました');
                }
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = json;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            alert('コピーしました');
        }
    }

    window.showAIPersonalitySettings = async function() {
        if (document.getElementById('aiPersonalityOverlay')) {
            closeModal();
            return;
        }

        var settingsData = {
            personality: null,
            deliberation_max_seconds: 180,
            proactive_message_enabled: 1,
            proactive_message_hour: 18
        };

        try {
            var resp = await fetch('api/ai.php?action=get_settings');
            var data = await resp.json();
            if (data.success && data.data) {
                if (data.data.personality) settingsData.personality = data.data.personality;
                if (data.data.deliberation_max_seconds != null) settingsData.deliberation_max_seconds = parseInt(data.data.deliberation_max_seconds, 10);
                if (data.data.proactive_message_enabled != null) settingsData.proactive_message_enabled = data.data.proactive_message_enabled;
                if (data.data.proactive_message_hour != null) settingsData.proactive_message_hour = parseInt(data.data.proactive_message_hour, 10);
                if (data.data.today_topics_oshi != null) settingsData.today_topics_oshi = String(data.data.today_topics_oshi).trim();
            }
        } catch (e) {
            console.error('[AI Personality] get_settings error:', e);
        }

        var html = buildModal(settingsData);
        document.body.insertAdjacentHTML('beforeend', html);

        bindEvents();

        requestAnimationFrame(function() {
            document.getElementById('aiPersonalityOverlay').classList.add('active');
        });
    };

    window.showAISettings = window.showAIPersonalitySettings;
})();
