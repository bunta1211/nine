/**
 * Guild レイアウトJS
 */

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initUserMenu();
    initLanguageSelector();
    initLogout();
});

/**
 * サイドバー初期化
 */
function initSidebar() {
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
        
        // サイドバー外クリックで閉じる
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }
}

/**
 * ユーザーメニュー初期化
 */
function initUserMenu() {
    const userMenu = document.getElementById('user-menu');
    const toggle = document.getElementById('user-menu-toggle');
    
    if (userMenu && toggle) {
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenu.classList.toggle('open');
            // 言語メニューを閉じる
            document.getElementById('language-selector')?.classList.remove('open');
        });
        
        document.addEventListener('click', (e) => {
            if (!userMenu.contains(e.target)) {
                userMenu.classList.remove('open');
            }
        });
    }
}

/**
 * 言語セレクター初期化
 */
function initLanguageSelector() {
    const selector = document.getElementById('language-selector');
    const toggle = document.getElementById('language-toggle');
    
    if (selector && toggle) {
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            selector.classList.toggle('open');
            // ユーザーメニューを閉じる
            document.getElementById('user-menu')?.classList.remove('open');
        });
        
        document.addEventListener('click', (e) => {
            if (!selector.contains(e.target)) {
                selector.classList.remove('open');
            }
        });
    }
}

/**
 * ログアウト処理
 */
function initLogout() {
    const logoutBtn = document.getElementById('logout-btn');
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            
            const confirmed = await Guild.confirm('ログアウトしますか？');
            if (!confirmed) return;
            
            try {
                const response = await fetch(Guild.baseUrl + '/api/auth.php?action=logout', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': Guild.csrfToken || '',
                    },
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = Guild.baseUrl + '/index.php';
                } else {
                    Guild.toast('ログアウトに失敗しました', 'error');
                }
            } catch (error) {
                console.error('Logout error:', error);
                Guild.toast('ログアウト処理中にエラーが発生しました', 'error');
            }
        });
    }
}

/**
 * Earth受け取りアニメーション
 */
function showEarthAnimation(amount, newBalance) {
    const overlay = document.getElementById('earth-animation');
    const amountEl = document.getElementById('earth-received-amount');
    const balanceEl = document.getElementById('earth-new-balance');
    const coinRain = overlay.querySelector('.coin-rain');
    
    if (!overlay) return;
    
    // コインを降らせる
    coinRain.innerHTML = '';
    for (let i = 0; i < 30; i++) {
        const coin = document.createElement('span');
        coin.className = 'coin';
        coin.textContent = '🌍';
        coin.style.left = Math.random() * 100 + '%';
        coin.style.animationDelay = Math.random() * 1 + 's';
        coinRain.appendChild(coin);
    }
    
    // 金額をアニメーション
    overlay.style.display = 'flex';
    
    let currentAmount = 0;
    const step = Math.ceil(amount / 30);
    const interval = setInterval(() => {
        currentAmount += step;
        if (currentAmount >= amount) {
            currentAmount = amount;
            clearInterval(interval);
        }
        amountEl.textContent = currentAmount.toLocaleString();
    }, 50);
    
    balanceEl.textContent = newBalance.toLocaleString();
    
    // 3秒後に閉じる
    setTimeout(() => {
        overlay.style.display = 'none';
        
        // サイドバーのEarth残高を更新
        const sidebarBalance = document.getElementById('earth-balance');
        if (sidebarBalance) {
            sidebarBalance.textContent = newBalance.toLocaleString();
        }
    }, 3000);
}

// グローバルに公開
window.showEarthAnimation = showEarthAnimation;
