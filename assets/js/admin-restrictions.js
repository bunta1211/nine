/**
 * メンバー利用制限設定 JavaScript
 * 組織管理者が子どもメンバーの利用時間などを設定できる
 */

// グローバル変数
let restrictionTargetId = null;

/**
 * 利用制限設定モーダルを開く
 * @param {number} memberId メンバーID
 * @param {string} memberName メンバー名
 */
async function openRestrictionsModal(memberId, memberName) {
    restrictionTargetId = memberId;
    
    const modal = document.getElementById('restrictionsModal');
    const title = document.getElementById('restrictionsModalTitle');
    
    title.textContent = `${memberName} の利用制限設定`;
    modal.classList.add('active');
    
    // 現在の設定を読み込み
    await loadRestrictions(memberId);
}

/**
 * 利用制限設定モーダルを閉じる
 */
function closeRestrictionsModal() {
    const modal = document.getElementById('restrictionsModal');
    modal.classList.remove('active');
    restrictionTargetId = null;
}

/**
 * 制限設定を読み込む
 */
async function loadRestrictions(memberId) {
    try {
        const response = await fetch(`/admin/api/member-restrictions.php?member_id=${memberId}`);
        const data = await response.json();
        
        if (data.success && data.member) {
            const m = data.member;
            
            // フォームに設定を反映
            document.getElementById('usageStartTime').value = formatTimeForInput(m.usage_start_time) || '07:00';
            document.getElementById('usageEndTime').value = formatTimeForInput(m.usage_end_time) || '21:00';
            document.getElementById('dailyLimitMinutes').value = m.daily_limit_minutes || 120;
            document.getElementById('externalContact').checked = m.external_contact == 1;
            document.getElementById('canCreateGroups').checked = m.can_create_groups == 1;
            document.getElementById('canLeaveOrg').checked = m.can_leave_org == 1;
            
            // 通話制限
            const callRestriction = m.call_restriction || 'none';
            document.getElementById('callRestriction').value = callRestriction;
        } else {
            alert(data.message || '設定の読み込みに失敗しました');
        }
    } catch (error) {
        console.error('制限設定の読み込みエラー:', error);
        alert('設定の読み込みに失敗しました');
    }
}

/**
 * 制限設定を保存する
 */
async function saveRestrictions() {
    if (!restrictionTargetId) return;
    
    const data = {
        member_id: restrictionTargetId,
        usage_start_time: document.getElementById('usageStartTime').value + ':00',
        usage_end_time: document.getElementById('usageEndTime').value + ':00',
        daily_limit_minutes: parseInt(document.getElementById('dailyLimitMinutes').value) || 120,
        external_contact: document.getElementById('externalContact').checked ? 1 : 0,
        call_restriction: document.getElementById('callRestriction').value,
        can_create_groups: document.getElementById('canCreateGroups').checked ? 1 : 0,
        can_leave_org: document.getElementById('canLeaveOrg').checked ? 1 : 0
    };
    
    try {
        const response = await fetch('/admin/api/member-restrictions.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('利用制限設定を保存しました');
            closeRestrictionsModal();
        } else {
            alert(result.message || '保存に失敗しました');
        }
    } catch (error) {
        console.error('制限設定の保存エラー:', error);
        alert('保存に失敗しました');
    }
}

/**
 * 時間を入力用フォーマットに変換
 */
function formatTimeForInput(time) {
    if (!time) return null;
    const parts = time.split(':');
    return `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}`;
}

/**
 * 日次利用時間をリセット
 */
async function resetDailyUsage() {
    if (!restrictionTargetId) return;
    
    if (!confirm('本日の利用時間をリセットしますか？')) return;
    
    try {
        const response = await fetch('/admin/api/member-restrictions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'reset_daily_usage',
                member_id: restrictionTargetId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('利用時間をリセットしました');
        } else {
            alert(result.message || 'リセットに失敗しました');
        }
    } catch (error) {
        console.error('利用時間のリセットエラー:', error);
        alert('リセットに失敗しました');
    }
}

// DOMロード時にイベント設定
document.addEventListener('DOMContentLoaded', () => {
    // モーダル閉じるボタン
    const closeBtn = document.getElementById('btnCloseRestrictionsModal');
    if (closeBtn) {
        closeBtn.addEventListener('click', closeRestrictionsModal);
    }
    
    const cancelBtn = document.getElementById('btnCancelRestrictions');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeRestrictionsModal);
    }
    
    // 保存ボタン
    const saveBtn = document.getElementById('btnSaveRestrictions');
    if (saveBtn) {
        saveBtn.addEventListener('click', saveRestrictions);
    }
    
    // モーダル外クリックで閉じる
    const modal = document.getElementById('restrictionsModal');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeRestrictionsModal();
        });
    }
});

