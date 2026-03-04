<?php
/**
 * ハニーポット - 偽の管理画面ログイン
 * 
 * 攻撃者を誘い込んで情報を収集
 * 正規の管理画面は /admin/ ディレクトリ
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ipinfo.php';

$pdo = getDB();
$security = getSecurity();

// アクセスを記録（ハニーポットへのアクセス）
$security->logEvent('suspicious_activity', 'high', [
    'description' => 'ハニーポット（偽管理画面）へのアクセス',
    'resource' => '/admin.php'
]);

// IP情報を取得
$ipInfo = getIPInfo();

// フォーム送信があった場合
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // 攻撃者の入力を全て記録
    $security->logEvent('brute_force', 'critical', [
        'username' => $username,
        'description' => "ハニーポットでログイン試行: {$username}",
        'raw_data' => [
            'username' => $username,
            'password_length' => strlen($password),
            'password_first_chars' => substr($password, 0, 3) . '***'
        ]
    ]);
    
    // IPを即座にブロック（24時間）
    $security->blockIP(
        $security->getClientIP(),
        'ハニーポットでログイン試行',
        1440
    );
    
    // 遅延を入れて正規の動作に見せかける
    sleep(2);
    
    $error = 'ログインに失敗しました。';
}

// 追加情報収集用のJavaScript
$collectScript = <<<'JS'
<script>
(function() {
    var data = {
        screen: {width: screen.width, height: screen.height, colorDepth: screen.colorDepth},
        window: {width: window.innerWidth, height: window.innerHeight},
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        language: navigator.language,
        languages: navigator.languages,
        platform: navigator.platform,
        vendor: navigator.vendor,
        cookieEnabled: navigator.cookieEnabled,
        doNotTrack: navigator.doNotTrack,
        hardwareConcurrency: navigator.hardwareConcurrency,
        deviceMemory: navigator.deviceMemory,
        connection: navigator.connection ? {
            type: navigator.connection.type,
            effectiveType: navigator.connection.effectiveType,
            downlink: navigator.connection.downlink
        } : null,
        plugins: Array.from(navigator.plugins || []).map(function(p) { return p.name; }),
        mimeTypes: Array.from(navigator.mimeTypes || []).map(function(m) { return m.type; }).slice(0, 10),
        webdriver: navigator.webdriver,
        battery: null,
        touchPoints: navigator.maxTouchPoints
    };
    
    // Canvas fingerprint
    try {
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillStyle = '#f60';
        ctx.fillRect(125, 1, 62, 20);
        ctx.fillStyle = '#069';
        ctx.fillText('Cwm fjord', 2, 15);
        ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
        ctx.fillText('Cwm fjord', 4, 17);
        data.canvasFingerprint = canvas.toDataURL().slice(-100);
    } catch(e) {}
    
    // WebGL
    try {
        var canvas = document.createElement('canvas');
        var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (gl) {
            var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                data.webgl = {
                    vendor: gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL),
                    renderer: gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
                };
            }
        }
    } catch(e) {}
    
    // Audio fingerprint
    try {
        var AudioContext = window.AudioContext || window.webkitAudioContext;
        if (AudioContext) {
            var context = new AudioContext();
            data.audioContext = {
                sampleRate: context.sampleRate,
                state: context.state
            };
        }
    } catch(e) {}
    
    // fonts検出
    try {
        var baseFonts = ['monospace', 'sans-serif', 'serif'];
        var testFonts = ['Arial', 'Verdana', 'Times New Roman', 'Courier New', 'Georgia', 'Comic Sans MS', 'Impact', 'Lucida Console'];
        var testString = 'mmmmmmmmmmlli';
        var testSize = '72px';
        var d = document.createElement('div');
        d.style.cssText = 'position:absolute;left:-9999px';
        document.body.appendChild(d);
        
        data.fonts = [];
        testFonts.forEach(function(font) {
            var detected = false;
            baseFonts.forEach(function(baseFont) {
                var s = document.createElement('span');
                s.style.fontSize = testSize;
                s.style.fontFamily = font + ',' + baseFont;
                s.textContent = testString;
                d.appendChild(s);
                var w1 = s.offsetWidth;
                s.style.fontFamily = baseFont;
                if (s.offsetWidth !== w1) {
                    detected = true;
                }
            });
            if (detected) data.fonts.push(font);
        });
        document.body.removeChild(d);
    } catch(e) {}
    
    // バッテリー情報
    if (navigator.getBattery) {
        navigator.getBattery().then(function(battery) {
            data.battery = {
                charging: battery.charging,
                level: battery.level,
                chargingTime: battery.chargingTime,
                dischargingTime: battery.dischargingTime
            };
            sendData();
        });
    } else {
        sendData();
    }
    
    function sendData() {
        navigator.sendBeacon('/api/security.php?action=honeypot_collect', JSON.stringify(data));
    }
})();
</script>
JS;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ログイン - System Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            color: #999;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🔒 管理者ログイン</h1>
        
        <?php if (isset($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">ユーザー名</label>
                <input type="text" id="username" name="username" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="password">パスワード</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">ログイン</button>
        </form>
        
        <div class="footer">
            System Administration Panel v2.1
        </div>
    </div>
    
    <?= $collectScript ?>
</body>
</html>
