/**
 * Guild 設定ページJS
 */

document.addEventListener('DOMContentLoaded', () => {
    initRangeInputs();
    initProfileForm();
    initAvailabilityForm();
    initNotificationForm();
    initDisplayForm();
    initDarkModeToggle();
});

/**
 * レンジ入力の値表示
 */
function initRangeInputs() {
    document.querySelectorAll('.range-input').forEach(input => {
        const displayId = input.dataset.display;
        const display = document.getElementById(displayId);
        
        input.addEventListener('input', () => {
            if (display) {
                display.textContent = input.value + '%';
            }
        });
    });
}

/**
 * プロフィールフォーム
 */
function initProfileForm() {
    const form = document.getElementById('profile-form');
    if (!form) return;
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveSettings('profile', new FormData(form));
    });
}

/**
 * 余力フォーム
 */
function initAvailabilityForm() {
    const form = document.getElementById('availability-form');
    if (!form) return;
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveSettings('availability', new FormData(form));
    });
}

/**
 * 通知フォーム
 */
function initNotificationForm() {
    const form = document.getElementById('notification-form');
    if (!form) return;
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveSettings('notifications', new FormData(form));
    });
}

/**
 * 表示フォーム
 */
function initDisplayForm() {
    const form = document.getElementById('display-form');
    if (!form) return;
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveSettings('display', new FormData(form));
    });
}

/**
 * ダークモードトグル
 */
function initDarkModeToggle() {
    const toggle = document.getElementById('dark-mode-toggle');
    if (!toggle) return;
    
    toggle.addEventListener('change', () => {
        if (toggle.checked) {
            document.documentElement.classList.add('dark');
            localStorage.setItem('guild_theme', 'dark');
        } else {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('guild_theme', 'light');
        }
    });
}

/**
 * 設定を保存
 */
async function saveSettings(section, formData) {
    const data = Object.fromEntries(formData.entries());
    
    // チェックボックスの処理（未チェックは0）
    const checkboxes = ['notify_new_request', 'notify_assigned', 'notify_approved', 
                        'notify_earth_received', 'notify_thanks', 'notify_advance_payment',
                        'email_notifications', 'dark_mode'];
    checkboxes.forEach(name => {
        if (!data[name]) {
            data[name] = '0';
        }
    });
    
    try {
        const response = await fetch(Guild.baseUrl + '/api/settings.php?action=update&section=' + section, {
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
            
            // 言語変更の場合はリロード
            if (section === 'display' && data.language) {
                setTimeout(() => location.reload(), 1000);
            }
        } else {
            Guild.toast(result.message || 'エラーが発生しました', 'error');
        }
    } catch (error) {
        console.error('Save settings error:', error);
        Guild.toast('エラーが発生しました', 'error');
    }
}
