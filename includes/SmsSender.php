<?php
/**
 * SMS送信クラス
 * config/sms.php で SMS_DRIVER に応じて Twilio / AWS SNS / ログのみ を切り替え
 */
class SmsSender {

    public function __construct() {
        $configPath = __DIR__ . '/../config/sms.php';
        if (is_file($configPath)) {
            require_once $configPath;
        }
    }

    /**
     * 認証コードをSMSで送信
     * @param string $phone 正規化済み電話番号（数字のみ。国際形式なら先頭に国番号）
     * @param string $code 認証コード（4桁または6桁）
     * @return bool 送信成功時 true（driver=log のときは常に true）
     */
    public function sendVerificationCode($phone, $code) {
        $driver = defined('SMS_DRIVER') ? SMS_DRIVER : 'log';

        if ($driver === 'log') {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('SmsSender (log): phone=' . $phone . ', code=' . $code);
            } else {
                error_log('SmsSender (log): verification code requested for ' . substr($phone, 0, 4) . '****');
            }
            return true;
        }

        if ($driver === 'twilio') {
            return $this->sendViaTwilio($phone, $code);
        }

        if ($driver === 'sns') {
            return $this->sendViaSns($phone, $code);
        }

        error_log('SmsSender: unknown SMS_DRIVER=' . $driver);
        return false;
    }

    /**
     * Twilio REST API でSMS送信
     */
    private function sendViaTwilio($phone, $code) {
        $sid = defined('SMS_TWILIO_SID') ? SMS_TWILIO_SID : '';
        $token = defined('SMS_TWILIO_TOKEN') ? SMS_TWILIO_TOKEN : '';
        $from = defined('SMS_FROM_NUMBER') ? SMS_FROM_NUMBER : '';

        if ($sid === '' || $token === '' || $from === '') {
            error_log('SmsSender Twilio: SMS_TWILIO_SID, SMS_TWILIO_TOKEN, SMS_FROM_NUMBER を設定してください');
            return false;
        }

        $body = 'Social9 認証コード: ' . $code . ' （10分間有効）';
        $to = $this->ensureE164($phone);

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json';
        $params = http_build_query([
            'To' => $to,
            'From' => $from,
            'Body' => $body
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' =>
                    "Content-Type: application/x-www-form-urlencoded\r\n" .
                    'Authorization: Basic ' . base64_encode($sid . ':' . $token) . "\r\n",
                'content' => $params
            ]
        ]);

        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            error_log('SmsSender Twilio: request failed');
            return false;
        }

        $data = json_decode($res, true);
        if (isset($data['sid'])) {
            return true;
        }
        error_log('SmsSender Twilio: ' . ($data['message'] ?? $res));
        return false;
    }

    /**
     * AWS SNS でSMS送信
     * AWS SDK がなければ HTTP で Publish を呼ぶ簡易実装
     */
    private function sendViaSns($phone, $code) {
        $region = defined('SMS_AWS_REGION') ? SMS_AWS_REGION : 'ap-northeast-1';
        $key = defined('SMS_AWS_KEY') ? SMS_AWS_KEY : '';
        $secret = defined('SMS_AWS_SECRET') ? SMS_AWS_SECRET : '';

        if ($key === '' || $secret === '') {
            error_log('SmsSender SNS: SMS_AWS_KEY, SMS_AWS_SECRET を設定してください');
            return false;
        }

        $body = 'Social9 認証コード: ' . $code . ' （10分間有効）';
        $to = $this->ensureE164($phone);

        if (class_exists('Aws\Sns\SnsClient')) {
            try {
                $client = new \Aws\Sns\SnsClient([
                    'version' => 'latest',
                    'region' => $region,
                    'credentials' => [
                        'key' => $key,
                        'secret' => $secret
                    ]
                ]);
                $client->publish([
                    'PhoneNumber' => $to,
                    'Message' => $body
                ]);
                return true;
            } catch (Exception $e) {
                error_log('SmsSender SNS: ' . $e->getMessage());
                return false;
            }
        }

        // SDK なし: SigV4 で Publish を呼ぶ（簡易）
        $result = $this->snsPublishHttp($region, $key, $secret, $to, $body);
        return $result;
    }

    /**
     * AWS SigV4 で SNS Publish（SDK なし用の簡易実装）
     */
    private function snsPublishHttp($region, $key, $secret, $phoneNumber, $message) {
        $service = 'sns';
        $host = $service . '.' . $region . '.amazonaws.com';
        $endpoint = 'https://' . $host . '/';
        $payload = json_encode([
            'PhoneNumber' => $phoneNumber,
            'Message' => $message
        ]);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);

        $canonicalUri = '/';
        $canonicalQueryString = '';
        $signedHeaders = 'host;x-amz-date;x-amz-target';
        $canonicalHeaders = "host:{$host}\nx-amz-date:{$amzDate}\nx-amz-target:SNS.Publish\n";
        $canonicalRequest = "POST\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $payload);

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "{$algorithm} Credential={$key}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' =>
                    "Content-Type: application/x-amz-json-1.0\r\n" .
                    "Host: {$host}\r\n" .
                    "X-Amz-Date: {$amzDate}\r\n" .
                    "X-Amz-Target: SNS.Publish\r\n" .
                    "Authorization: {$authHeader}\r\n",
                'content' => $payload
            ]
        ]);

        $res = @file_get_contents($endpoint, false, $ctx);
        if ($res === false) {
            error_log('SmsSender SNS HTTP: request failed');
            return false;
        }
        $status = $http_response_header[0] ?? '';
        if (strpos($status, '200') !== false) {
            return true;
        }
        error_log('SmsSender SNS HTTP: ' . $status . ' ' . $res);
        return false;
    }

    /**
     * 国内番号を E.164 に（先頭が 0 なら 81 に置換）
     */
    private function ensureE164($phone) {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) >= 10 && $phone[0] === '0') {
            return '+' . '81' . substr($phone, 1);
        }
        if (strpos($phone, '81') === 0) {
            return '+' . $phone;
        }
        return '+' . $phone;
    }
}
