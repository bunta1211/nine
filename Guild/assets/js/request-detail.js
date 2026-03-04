/**
 * Guild 依頼詳細JS
 */

document.addEventListener('DOMContentLoaded', () => {
    initApplyModal();
    initApplicationActions();
    initWorkActions();
});

/**
 * 立候補モーダル初期化
 */
function initApplyModal() {
    const applyBtn = document.getElementById('apply-btn');
    const modal = document.getElementById('apply-modal');
    const backdrop = document.getElementById('apply-modal-backdrop');
    const form = document.getElementById('apply-form');
    
    if (!applyBtn || !modal) return;
    
    // モーダルを開く
    applyBtn.addEventListener('click', () => {
        modal.classList.add('active');
        backdrop.classList.add('active');
    });
    
    // モーダルを閉じる
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            modal.classList.remove('active');
            backdrop.classList.remove('active');
        });
    });
    
    backdrop.addEventListener('click', () => {
        modal.classList.remove('active');
        backdrop.classList.remove('active');
    });
    
    // 立候補送信
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const formData = new FormData(form);
        
        try {
            const response = await fetch(Guild.baseUrl + '/api/requests.php?action=apply', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': Guild.csrfToken,
                },
                body: JSON.stringify({
                    request_id: REQUEST_ID,
                    comment: formData.get('comment'),
                }),
            });
            
            const data = await response.json();
            
            if (data.success) {
                Guild.toast('立候補しました', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                Guild.toast(data.message || 'エラーが発生しました', 'error');
            }
        } catch (error) {
            console.error('Apply error:', error);
            Guild.toast('エラーが発生しました', 'error');
        }
    });
}

/**
 * 立候補者アクション初期化
 */
function initApplicationActions() {
    // 選定ボタン
    document.querySelectorAll('.accept-applicant-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const applicationId = btn.dataset.id;
            
            if (!await Guild.confirm('この立候補者を選定しますか？')) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/api/requests.php?action=accept_application', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ application_id: applicationId }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('選定しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Accept error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
    
    // 立候補取り消しボタン
    document.querySelectorAll('.withdraw-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!await Guild.confirm('立候補を取り消しますか？')) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/api/requests.php?action=withdraw', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ request_id: REQUEST_ID }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('立候補を取り消しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Withdraw error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
}

/**
 * 作業アクション初期化
 */
function initWorkActions() {
    // 作業開始
    document.querySelectorAll('.start-work-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                const response = await fetch(Guild.baseUrl + '/api/requests.php?action=start_work', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ request_id: REQUEST_ID }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('作業を開始しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Start work error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
    
    // 完了報告
    document.querySelectorAll('.complete-work-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const report = prompt('完了報告を入力してください（任意）');
            
            try {
                const response = await fetch(Guild.baseUrl + '/api/requests.php?action=complete_work', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ 
                        request_id: REQUEST_ID,
                        report: report || '',
                    }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('完了報告を送信しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Complete work error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
    
    // 完了承認
    document.querySelectorAll('.approve-complete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!await Guild.confirm('完了を承認しますか？')) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/api/requests.php?action=approve_complete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ request_id: REQUEST_ID }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('完了を承認しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Approve complete error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
    
    // 依頼キャンセル
    document.querySelectorAll('.cancel-request-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!await Guild.confirm('この依頼をキャンセルしますか？')) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/api/requests.php?action=cancel', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ request_id: REQUEST_ID }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('依頼をキャンセルしました', 'success');
                    setTimeout(() => location.href = 'requests.php', 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Cancel request error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
}
