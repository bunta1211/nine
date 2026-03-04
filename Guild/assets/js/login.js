/**
 * Guild ログインページJS
 */

document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    const loginBtn = document.getElementById('login-btn');
    
    if (loginForm) {
        loginForm.addEventListener('submit', handleLogin);
    }
});

/**
 * ログイン処理
 */
async function handleLogin(e) {
    e.preventDefault();
    
    const form = e.target;
    const btn = form.querySelector('#login-btn');
    const email = form.querySelector('#email').value.trim();
    const password = form.querySelector('#password').value;
    
    if (!email || !password) {
        Guild.toast('メールアドレスとパスワードを入力してください', 'error');
        return;
    }
    
    // ローディング状態
    form.classList.add('loading');
    btn.disabled = true;
    
    // デバッグ用：APIのURL確認
    const apiUrl = Guild.baseUrl + '/api/auth.php?action=login';
    console.log('API URL:', apiUrl);
    
    try {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ email, password }),
        });
        
        // レスポンスのテキストを取得（デバッグ用）
        const responseText = await response.text();
        console.log('Response text:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            Guild.toast('サーバーからの応答が不正です', 'error');
            return;
        }
        
        if (data.success) {
            // CSRFトークンを保存
            if (data.csrf_token) {
                Guild.csrfToken = data.csrf_token;
            }
            
            // ホームページへリダイレクト
            window.location.href = Guild.baseUrl + '/home.php';
        } else {
            Guild.toast(data.message || 'ログインに失敗しました', 'error');
        }
    } catch (error) {
        console.error('Login error:', error);
        Guild.toast('ログイン処理中にエラーが発生しました: ' + error.message, 'error');
    } finally {
        form.classList.remove('loading');
        btn.disabled = false;
    }
}
