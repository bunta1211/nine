/**
 * Guild 通知ページJS
 */

document.addEventListener('DOMContentLoaded', () => {
    initMarkAllRead();
});

/**
 * すべて既読にする
 */
function initMarkAllRead() {
    const btn = document.getElementById('mark-all-read');
    if (!btn) return;
    
    btn.addEventListener('click', async () => {
        try {
            const response = await fetch(Guild.baseUrl + '/api/notifications.php?action=mark_all_read', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': Guild.csrfToken,
                },
            });
            
            const data = await response.json();
            
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                });
                Guild.toast('すべて既読にしました', 'success');
            }
        } catch (error) {
            console.error('Mark all read error:', error);
        }
    });
}
