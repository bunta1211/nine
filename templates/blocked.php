<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アクセス拒否</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .container {
            text-align: center;
            padding: 40px;
        }
        .shield {
            font-size: 80px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 48px;
            margin-bottom: 10px;
            color: #ff6b6b;
        }
        .code {
            font-size: 24px;
            color: #feca57;
            margin-bottom: 30px;
        }
        .message {
            font-size: 18px;
            color: #a0a0a0;
            max-width: 500px;
            line-height: 1.6;
            margin: 0 auto 30px;
        }
        .info {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
            font-size: 14px;
            color: #666;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #888;
        }
        .info-value {
            color: #ccc;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="shield">🛡️</div>
        <h1>403</h1>
        <div class="code">ACCESS DENIED</div>
        <p class="message">
            あなたのアクセスはセキュリティシステムによってブロックされました。
            この判定が誤りであると思われる場合は、管理者にお問い合わせください。
        </p>
        <div class="info">
            <div class="info-row">
                <span class="info-label">あなたのIP:</span>
                <span class="info-value"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">日時:</span>
                <span class="info-value"><?= date('Y-m-d H:i:s') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">リクエストID:</span>
                <span class="info-value"><?= substr(md5(uniqid()), 0, 12) ?></span>
            </div>
        </div>
    </div>
    <!-- 追跡用の隠しスクリプト（攻撃者の追加情報を収集） -->
    <script>
        (function() {
            try {
                var data = {
                    screen: screen.width + 'x' + screen.height,
                    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                    language: navigator.language,
                    platform: navigator.platform,
                    cookieEnabled: navigator.cookieEnabled,
                    doNotTrack: navigator.doNotTrack,
                    plugins: Array.from(navigator.plugins || []).map(function(p) { return p.name; }).slice(0, 5)
                };
                
                // Canvas fingerprint
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillText('fingerprint', 2, 2);
                data.canvasHash = canvas.toDataURL().slice(-50);
                
                // WebGL情報
                var gl = canvas.getContext('webgl');
                if (gl) {
                    var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                    if (debugInfo) {
                        data.webglVendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL);
                        data.webglRenderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
                    }
                }
                
                // 送信
                navigator.sendBeacon('/api/security.php?action=collect_blocked', JSON.stringify(data));
            } catch(e) {}
        })();
    </script>
</body>
</html>
