/**
 * Guild 管理者画面JS
 */

document.addEventListener('DOMContentLoaded', () => {
    initApprovalActions();
    initAdvanceActions();
});

/**
 * 依頼承認アクション
 */
function initApprovalActions() {
    // 承認
    document.querySelectorAll('.approve-request-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const requestId = btn.dataset.id;
            
            if (!await Guild.confirm('この依頼を承認しますか？')) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/admin/api/requests.php?action=approve', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ request_id: requestId }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('承認しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Approve error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
    
    // 却下
    document.querySelectorAll('.reject-request-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const requestId = btn.dataset.id;
            const reason = prompt('却下理由を入力してください');
            
            if (reason === null) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/admin/api/requests.php?action=reject', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ request_id: requestId, reason: reason }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('却下しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Reject error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
}

/**
 * 前借り申請アクション
 */
function initAdvanceActions() {
    // 承認
    document.querySelectorAll('.approve-advance-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const advanceId = btn.dataset.id;
            
            if (!await Guild.confirm('この前借り申請を承認しますか？')) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/admin/api/advances.php?action=approve', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ advance_id: advanceId }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('承認しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Approve advance error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
    
    // 却下
    document.querySelectorAll('.reject-advance-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const advanceId = btn.dataset.id;
            const reason = prompt('却下理由を入力してください');
            
            if (reason === null) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/admin/api/advances.php?action=reject', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Guild.csrfToken,
                    },
                    body: JSON.stringify({ advance_id: advanceId, reason: reason }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Guild.toast('却下しました', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Guild.toast(data.message || 'エラーが発生しました', 'error');
                }
            } catch (error) {
                console.error('Reject advance error:', error);
                Guild.toast('エラーが発生しました', 'error');
            }
        });
    });
}
