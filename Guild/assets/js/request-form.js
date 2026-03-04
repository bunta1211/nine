/**
 * Guild 依頼フォームJS
 */

document.addEventListener('DOMContentLoaded', () => {
    initRequestTypeSwitch();
    initEarthCalculation();
    initDistributionTiming();
    initTemplateSelect();
    initGuildSelect();
    initFormSubmit();
});

/**
 * 依頼種類切り替え
 */
function initRequestTypeSwitch() {
    const typeInputs = document.querySelectorAll('input[name="request_type"]');
    const guildSection = document.getElementById('guild-section');
    const personalSection = document.getElementById('personal-section');
    const targetSection = document.getElementById('target-section');
    const applicantsSection = document.getElementById('applicants-section');
    const shiftSection = document.getElementById('shift-section');
    const guildSelect = document.getElementById('guild-select');
    
    typeInputs.forEach(input => {
        input.addEventListener('change', () => {
            const type = input.value;
            const earthSource = input.dataset.earthSource;
            
            // ギルド予算 or 個人Earth表示切り替え
            if (earthSource === 'guild') {
                guildSection.style.display = 'block';
                personalSection.style.display = 'none';
                guildSelect.required = true;
            } else {
                guildSection.style.display = 'none';
                personalSection.style.display = 'block';
                guildSelect.required = false;
            }
            
            // 対象者セクション（指名依頼・業務指令）
            if (['designated', 'order'].includes(type)) {
                targetSection.style.display = 'block';
                applicantsSection.style.display = 'none';
            } else {
                targetSection.style.display = 'none';
                applicantsSection.style.display = 'block';
            }
            
            // 勤務交代セクション
            shiftSection.style.display = type === 'shift_swap' ? 'block' : 'none';
        });
    });
}

/**
 * Earth計算
 */
function initEarthCalculation() {
    const earthInput = document.getElementById('earth_amount');
    const earthYen = document.getElementById('earth-yen');
    const warningEl = document.getElementById('large-request-warning');
    
    earthInput.addEventListener('input', () => {
        const amount = parseInt(earthInput.value) || 0;
        const yen = amount * EARTH_TO_YEN;
        
        earthYen.textContent = '= ¥' + yen.toLocaleString();
        
        // 1万Earth以上の警告
        warningEl.style.display = amount >= LARGE_REQUEST_THRESHOLD ? 'inline' : 'none';
    });
}

/**
 * 分配タイミング
 */
function initDistributionTiming() {
    const timingSelect = document.getElementById('distribution_timing');
    const dateGroup = document.getElementById('distribution-date-group');
    
    timingSelect.addEventListener('change', () => {
        dateGroup.style.display = timingSelect.value === 'on_date' ? 'block' : 'none';
    });
}

/**
 * テンプレート選択
 */
function initTemplateSelect() {
    const templateSelect = document.getElementById('template-select');
    if (!templateSelect) return;
    
    templateSelect.addEventListener('change', () => {
        const option = templateSelect.options[templateSelect.selectedIndex];
        if (!option.value) return;
        
        // フォームに値を設定
        document.getElementById('title').value = option.dataset.title || '';
        document.getElementById('description').value = option.dataset.description || '';
        document.getElementById('earth_amount').value = option.dataset.earth || '';
        document.getElementById('required_qualifications').value = option.dataset.qualifications || '';
        
        // 依頼種類を選択
        const typeInput = document.querySelector(`input[name="request_type"][value="${option.dataset.type}"]`);
        if (typeInput) {
            typeInput.checked = true;
            typeInput.dispatchEvent(new Event('change'));
        }
        
        // Earth計算をトリガー
        document.getElementById('earth_amount').dispatchEvent(new Event('input'));
    });
}

/**
 * ギルド選択
 */
function initGuildSelect() {
    const guildSelect = document.getElementById('guild-select');
    const budgetInfo = document.getElementById('budget-info');
    const remainingBudget = document.getElementById('remaining-budget');
    
    guildSelect.addEventListener('change', () => {
        const option = guildSelect.options[guildSelect.selectedIndex];
        if (option.value) {
            budgetInfo.style.display = 'block';
            remainingBudget.textContent = parseInt(option.dataset.budget || 0).toLocaleString();
        } else {
            budgetInfo.style.display = 'none';
        }
    });
}

/**
 * フォーム送信
 */
function initFormSubmit() {
    const form = document.getElementById('request-form');
    const submitBtn = document.getElementById('submit-btn');
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        
        // バリデーション
        const earthAmount = parseInt(data.earth_amount) || 0;
        const earthSource = document.querySelector('input[name="request_type"]:checked').dataset.earthSource;
        
        if (earthSource === 'personal' && earthAmount > USER_BALANCE) {
            Guild.toast('Earthが不足しています', 'error');
            return;
        }
        
        submitBtn.disabled = true;
        submitBtn.textContent = '送信中...';
        
        try {
            const response = await fetch(Guild.baseUrl + '/api/requests.php?action=create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': Guild.csrfToken,
                },
                body: JSON.stringify(data),
            });
            
            const result = await response.json();
            
            if (result.success) {
                Guild.toast('依頼を作成しました', 'success');
                setTimeout(() => {
                    window.location.href = 'request.php?id=' + result.request_id;
                }, 1000);
            } else {
                Guild.toast(result.message || 'エラーが発生しました', 'error');
            }
        } catch (error) {
            console.error('Create request error:', error);
            Guild.toast('エラーが発生しました', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = '依頼を作成';
        }
    });
}
