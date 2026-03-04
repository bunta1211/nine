/**
 * AI安全通報管理ページ JavaScript
 */
(function() {
    'use strict';

    const TYPE_LABELS = {
        social_norm: '社会通念違反', life_danger: '生命の危機',
        bullying: 'いじめ', other: 'その他'
    };
    const STATUS_LABELS = {
        'new': '未対応', reviewing: '確認中', resolved: '対応済み', dismissed: '却下'
    };
    const SEVERITY_LABELS = {
        critical: '緊急', high: '高', medium: '中', low: '低'
    };

    const $ = id => document.getElementById(id);
    let currentReportId = null;

    async function loadStats() {
        try {
            const res = await fetch('../api/ai-safety.php?action=stats');
            const s = await res.json();
            $('aisfStats').innerHTML = `
                <div class="aisf-stat-card ${s.critical_new > 0 ? 'aisf-stat-critical' : ''}">
                    <span class="aisf-stat-count">${s.critical_new}</span>
                    <span class="aisf-stat-label">緊急・未対応</span>
                </div>
                <div class="aisf-stat-card">
                    <span class="aisf-stat-count">${s.new_count}</span>
                    <span class="aisf-stat-label">未対応</span>
                </div>
                <div class="aisf-stat-card">
                    <span class="aisf-stat-count">${s.reviewing_count}</span>
                    <span class="aisf-stat-label">確認中</span>
                </div>
                <div class="aisf-stat-card">
                    <span class="aisf-stat-count">${s.resolved_count}</span>
                    <span class="aisf-stat-label">対応済み</span>
                </div>
                <div class="aisf-stat-card">
                    <span class="aisf-stat-count">${s.total}</span>
                    <span class="aisf-stat-label">合計</span>
                </div>
            `;
        } catch (e) {
            console.error(e);
        }
    }

    async function loadList() {
        const status = $('aisfStatusFilter').value;
        const params = new URLSearchParams({ action: 'list', limit: 50, offset: 0 });
        if (status) params.set('status', status);

        try {
            const res = await fetch('../api/ai-safety.php?' + params);
            const data = await res.json();
            if (!data.reports || !data.reports.length) {
                $('aisfList').innerHTML = '<p class="aisf-empty">通報はありません</p>';
                return;
            }
            $('aisfList').innerHTML = data.reports.map(r => `
                <div class="aisf-report-card aisf-severity-${r.severity}" onclick="aisfOpenDetail(${r.id})">
                    <div class="aisf-report-info">
                        <span class="aisf-report-type">${TYPE_LABELS[r.report_type] || r.report_type}</span>
                        <span class="aisf-severity-badge ${r.severity}">${SEVERITY_LABELS[r.severity] || r.severity}</span>
                        <span class="aisf-status-badge ${r.status}">${STATUS_LABELS[r.status] || r.status}</span>
                        <div class="aisf-report-summary">${escHtml(r.summary || '')}</div>
                        <div class="aisf-report-meta">
                            ${escHtml(r.user_display_name || r.username || '')} | ${formatDate(r.created_at)}
                        </div>
                    </div>
                </div>
            `).join('');
        } catch (e) {
            $('aisfList').innerHTML = '<p class="aisf-empty">読み込みに失敗しました</p>';
        }
    }

    async function openDetail(reportId) {
        currentReportId = reportId;
        try {
            const res = await fetch(`../api/ai-safety.php?action=detail&id=${reportId}`);
            const r = await res.json();
            if (r.error) { alert(r.error); return; }

            let socialCtx = '';
            if (r.user_social_context && typeof r.user_social_context === 'object') {
                const ctx = r.user_social_context;
                socialCtx = `名前: ${ctx.user_display_name || '不明'}\n`;
                if (ctx.organization_name) socialCtx += `組織: ${ctx.organization_name} (${ctx.organization_type || ''})\n`;
                if (ctx.role_in_org) socialCtx += `組織での役割: ${ctx.role_in_org}\n`;
                if (ctx.all_organizations) {
                    socialCtx += `所属組織一覧: ${ctx.all_organizations.map(o => o.name + '(' + o.role + ')').join(', ')}\n`;
                }
            }

            let personalityText = '';
            if (r.user_personality_snapshot && typeof r.user_personality_snapshot === 'object') {
                personalityText = JSON.stringify(r.user_personality_snapshot, null, 2);
            }

            $('aisfModalBody').innerHTML = `
                <div class="aisf-detail-section">
                    <h4>種別・重大度</h4>
                    <p>
                        <span class="aisf-report-type">${TYPE_LABELS[r.report_type] || r.report_type}</span>
                        <span class="aisf-severity-badge ${r.severity}">${SEVERITY_LABELS[r.severity] || r.severity}</span>
                    </p>
                </div>
                <div class="aisf-detail-section">
                    <h4>要約</h4>
                    <p>${escHtml(r.summary || '')}</p>
                </div>
                <div class="aisf-detail-section">
                    <h4>生コンテキスト（前後の文脈・判断した生文章）</h4>
                    <pre>${escHtml(r.raw_context || '')}</pre>
                </div>
                <div class="aisf-detail-section">
                    <h4>AIの判断理由</h4>
                    <pre>${escHtml(r.ai_reasoning || '')}</pre>
                </div>
                <div class="aisf-detail-section">
                    <h4>ユーザーの社会的立場・所属</h4>
                    <pre>${escHtml(socialCtx)}</pre>
                </div>
                <div class="aisf-detail-section">
                    <h4>ユーザーの性格分析スナップショット</h4>
                    <pre>${escHtml(personalityText)}</pre>
                </div>
                <div class="aisf-detail-section">
                    <h4>通報日時</h4>
                    <p>${formatDate(r.created_at)}</p>
                </div>
                ${r.review_notes ? `<div class="aisf-detail-section"><h4>メモ</h4><p>${escHtml(r.review_notes)}</p></div>` : ''}
            `;

            $('aisfStatusSelect').value = r.status;
            $('aisfNotes').value = r.review_notes || '';

            renderQuestions(r.questions || []);
            $('aisfModal').style.display = '';
        } catch (e) {
            alert('取得に失敗しました');
        }
    }

    function renderQuestions(questions) {
        if (!questions.length) {
            $('aisfQuestionsList').innerHTML = '<p style="color:#888;font-size:13px">まだ質問がありません</p>';
            return;
        }
        $('aisfQuestionsList').innerHTML = questions.map(q => `
            <div class="aisf-qa-item">
                <div class="aisf-qa-question">${escHtml(q.question)}</div>
                <div class="aisf-qa-answer">
                    ${q.answer ? escHtml(q.answer) : '<em>回答待ち...</em>'}
                </div>
                <div style="font-size:11px;color:#888;margin-top:4px">${formatDate(q.created_at)}</div>
            </div>
        `).join('');
    }

    async function updateStatus() {
        if (!currentReportId) return;
        const body = {
            id: currentReportId,
            status: $('aisfStatusSelect').value,
            notes: $('aisfNotes').value,
        };
        try {
            const res = await fetch('../api/ai-safety.php?action=update_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }
            loadList();
            loadStats();
        } catch (e) {
            alert('更新に失敗しました');
        }
    }

    async function askQuestion() {
        if (!currentReportId) return;
        const question = $('aisfNewQuestion').value.trim();
        if (!question) { alert('質問を入力してください'); return; }

        $('aisfAskBtn').disabled = true;
        $('aisfAskBtn').textContent = '回答を取得中...';

        try {
            const res = await fetch('../api/ai-safety.php?action=ask_question', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ report_id: currentReportId, question }),
            });
            const data = await res.json();
            if (data.error) { alert(data.error); return; }

            $('aisfNewQuestion').value = '';
            openDetail(currentReportId);
        } catch (e) {
            alert('質問の送信に失敗しました');
        } finally {
            $('aisfAskBtn').disabled = false;
            $('aisfAskBtn').textContent = '質問する';
        }
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function formatDate(s) {
        if (!s) return '';
        const d = new Date(s);
        return d.toLocaleDateString('ja-JP') + ' ' + d.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
    }

    $('aisfRefreshBtn').addEventListener('click', () => { loadList(); loadStats(); });
    $('aisfStatusFilter').addEventListener('change', loadList);
    $('aisfModalClose').addEventListener('click', () => $('aisfModal').style.display = 'none');
    $('aisfUpdateStatusBtn').addEventListener('click', updateStatus);
    $('aisfAskBtn').addEventListener('click', askQuestion);

    window.aisfOpenDetail = openDetail;

    loadStats();
    loadList();
})();
