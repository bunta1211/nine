/**
 * 新規登録：携帯電話で登録フロー（SMS認証 → パスワード設定）
 */
(function () {
    var formEmail = document.getElementById('registerFormEmail');
    var registerByPhone = document.getElementById('registerByPhone');
    var tabs = document.querySelectorAll('.register-tab');
    var phoneStep1 = document.getElementById('phoneStep1');
    var phoneStep2 = document.getElementById('phoneStep2');
    var phoneStep3 = document.getElementById('phoneStep3');
    var phoneError = document.getElementById('phoneError');
    var regPhone = document.getElementById('regPhone');
    var regCode = document.getElementById('regCode');
    var regPassword = document.getElementById('regPassword');
    var regPasswordConfirm = document.getElementById('regPasswordConfirm');

    var phoneValue = '';
    var setPasswordToken = '';

    function showError(msg) {
        phoneError.textContent = msg || '';
        phoneError.style.display = msg ? 'block' : 'none';
    }

    function normalizePhone(val) {
        return (val || '').replace(/\D/g, '');
    }

    // タブ切替
    if (tabs.length) {
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var t = this.getAttribute('data-tab');
                tabs.forEach(function (x) {
                    x.classList.toggle('active', x.getAttribute('data-tab') === t);
                    x.setAttribute('aria-selected', x.getAttribute('data-tab') === t ? 'true' : 'false');
                });
                if (formEmail) formEmail.style.display = t === 'email' ? 'block' : 'none';
                if (registerByPhone) registerByPhone.style.display = t === 'phone' ? 'block' : 'none';
                if (t === 'email') showError('');
            });
        });
    }

    // 認証番号を送信
    var btnSend = document.getElementById('btnSendRegCode');
    if (btnSend) {
        btnSend.addEventListener('click', function () {
            showError('');
            var raw = (regPhone && regPhone.value) || '';
            phoneValue = normalizePhone(raw);
            if (phoneValue.length < 10 || phoneValue.length > 15) {
                showError('携帯電話番号は10〜15桁で入力してください。');
                return;
            }
            btnSend.disabled = true;
            fetch('api/auth_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'send_code', phone: phoneValue })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (phoneStep1) phoneStep1.style.display = 'none';
                        if (phoneStep2) {
                            var disp = document.getElementById('regPhoneDisplay');
                            if (disp) disp.textContent = phoneValue;
                            phoneStep2.style.display = 'block';
                        }
                    } else {
                        showError(data.error || '送信に失敗しました。');
                    }
                })
                .catch(function () { showError('通信エラーです。'); })
                .finally(function () { btnSend.disabled = false; });
        });
    }

    // 認証コード確認
    var btnVerify = document.getElementById('btnVerifyRegCode');
    if (btnVerify) {
        btnVerify.addEventListener('click', function () {
            showError('');
            var code = (regCode && regCode.value) || '';
            code = code.replace(/\D/g, '');
            if (code.length !== 4) {
                showError('4桁の認証コードを入力してください。');
                return;
            }
            btnVerify.disabled = true;
            fetch('api/auth_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'verify_code', phone: phoneValue, code: code })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (data.action === 'login' && data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        if (data.action === 'set_password') {
                            setPasswordToken = data.token || '';
                            if (phoneStep2) phoneStep2.style.display = 'none';
                            if (phoneStep3) phoneStep3.style.display = 'block';
                        }
                    } else {
                        showError(data.error || '認証に失敗しました。');
                    }
                })
                .catch(function () { showError('通信エラーです。'); })
                .finally(function () { btnVerify.disabled = false; });
        });
    }

    // パスワード設定して登録完了
    var btnSet = document.getElementById('btnSetPassword');
    if (btnSet) {
        btnSet.addEventListener('click', function () {
            showError('');
            var pwd = (regPassword && regPassword.value) || '';
            var pwd2 = (regPasswordConfirm && regPasswordConfirm.value) || '';
            if (pwd.length < 8) {
                showError('パスワードは8文字以上で入力してください。');
                return;
            }
            if (pwd !== pwd2) {
                showError('パスワードが一致しません。');
                return;
            }
            if (!setPasswordToken || !phoneValue) {
                showError('セッションが無効です。最初からやり直してください。');
                return;
            }
            btnSet.disabled = true;
            fetch('api/auth_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'set_password',
                    token: setPasswordToken,
                    phone: phoneValue,
                    password: pwd,
                    password_confirm: pwd2
                })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success && data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        showError(data.error || 'パスワード設定に失敗しました。');
                        btnSet.disabled = false;
                    }
                })
                .catch(function () {
                    showError('通信エラーです。');
                    btnSet.disabled = false;
                });
        });
    }
})();
