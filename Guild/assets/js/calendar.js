/**
 * Guild カレンダーJS
 */

document.addEventListener('DOMContentLoaded', () => {
    initCalendarDayClick();
    initEntryForm();
    initEntryTypeChange();
});

/**
 * 日付クリック
 */
function initCalendarDayClick() {
    document.querySelectorAll('.calendar-day:not(.empty)').forEach(day => {
        day.addEventListener('click', () => {
            const date = day.dataset.date;
            openEntryModal(date);
        });
    });
}

/**
 * エントリーモーダルを開く
 */
function openEntryModal(date) {
    const modal = document.getElementById('entry-modal');
    const backdrop = document.getElementById('entry-modal-backdrop');
    const title = document.getElementById('entry-modal-title');
    const dateInput = document.getElementById('entry-date');
    
    // 日付を設定
    const dateObj = new Date(date);
    const formattedDate = dateObj.toLocaleDateString('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short'
    });
    
    title.textContent = formattedDate;
    dateInput.value = date;
    
    // 既存のエントリーを読み込み
    loadEntry(date);
    
    // モーダルを表示
    modal.classList.add('active');
    backdrop.classList.add('active');
}

/**
 * 既存エントリーを読み込み
 */
async function loadEntry(date) {
    try {
        const response = await fetch(Guild.baseUrl + '/api/calendar.php?action=get&date=' + date, {
            headers: {
                'X-CSRF-Token': Guild.csrfToken,
            },
        });
        
        const data = await response.json();
        
        if (data.success && data.entry) {
            document.getElementById('entry-type').value = data.entry.entry_type || '';
            document.getElementById('work-location').value = data.entry.work_location || '';
            document.getElementById('start-time').value = data.entry.start_time || '';
            document.getElementById('end-time').value = data.entry.end_time || '';
            document.getElementById('entry-note').value = data.entry.note || '';
            
            // 表示切り替え
            toggleEntryDetails(data.entry.entry_type);
        } else {
            // フォームをリセット
            document.getElementById('entry-type').value = '';
            document.getElementById('work-location').value = '';
            document.getElementById('start-time').value = '';
            document.getElementById('end-time').value = '';
            document.getElementById('entry-note').value = '';
            toggleEntryDetails('');
        }
    } catch (error) {
        console.error('Load entry error:', error);
    }
}

/**
 * エントリータイプ変更
 */
function initEntryTypeChange() {
    const typeSelect = document.getElementById('entry-type');
    typeSelect.addEventListener('change', () => {
        toggleEntryDetails(typeSelect.value);
    });
}

/**
 * 詳細フィールドの表示切り替え
 */
function toggleEntryDetails(type) {
    const workDetails = document.getElementById('work-details');
    const timeDetails = document.getElementById('time-details');
    
    if (type === 'work') {
        workDetails.style.display = 'block';
        timeDetails.style.display = 'block';
    } else if (type && type !== '') {
        workDetails.style.display = 'none';
        timeDetails.style.display = 'block';
    } else {
        workDetails.style.display = 'none';
        timeDetails.style.display = 'none';
    }
}

/**
 * エントリーフォーム
 */
function initEntryForm() {
    const form = document.getElementById('entry-form');
    const modal = document.getElementById('entry-modal');
    const backdrop = document.getElementById('entry-modal-backdrop');
    
    // 閉じるボタン
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
    
    // 送信
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        
        try {
            const response = await fetch(Guild.baseUrl + '/api/calendar.php?action=save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': Guild.csrfToken,
                },
                body: JSON.stringify(data),
            });
            
            const result = await response.json();
            
            if (result.success) {
                Guild.toast('保存しました', 'success');
                setTimeout(() => location.reload(), 500);
            } else {
                Guild.toast(result.message || 'エラーが発生しました', 'error');
            }
        } catch (error) {
            console.error('Save entry error:', error);
            Guild.toast('エラーが発生しました', 'error');
        }
    });
}
