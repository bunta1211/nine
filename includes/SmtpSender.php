<?php
/**
 * 軽量 SMTP 送信（PHPMailer 未導入時のフォールバック）
 * AWS SES の SMTP インターフェース（port 587, STARTTLS）に対応。
 */
class SmtpSender {
    private $host;
    private $port;
    private $user;
    private $pass;
    private $encryption;
    private $fromEmail;
    private $fromName;

    public function __construct() {
        $this->host       = defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : '';
        $this->port       = (int)(defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : 587);
        $this->user       = defined('MAIL_SMTP_USER') ? MAIL_SMTP_USER : '';
        $this->pass       = defined('MAIL_SMTP_PASS') ? MAIL_SMTP_PASS : '';
        $this->encryption = defined('MAIL_SMTP_ENCRYPTION') ? MAIL_SMTP_ENCRYPTION : 'tls';
        $this->fromEmail  = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@social9.jp';
        $this->fromName   = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Social9';
    }

    /**
     * @param string $to 送信先
     * @param string $subject 件名
     * @param string $body 本文
     * @param bool $isHtml
     * @return bool
     */
    public function send($to, $subject, $body, $isHtml = true) {
        $sock = @stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $errno, $errstr, 15,
            STREAM_CLIENT_CONNECT
        );
        if (!$sock) {
            error_log('SmtpSender: connect failed ' . $errno . ' ' . $errstr);
            return false;
        }
        stream_set_timeout($sock, 30);
        $reply = $this->readLine($sock);
        if (substr($reply, 0, 3) !== '220') {
            $this->close($sock);
            return false;
        }

        $ehlo1 = $this->command($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if ($this->encryption === 'tls') {
            $starttls = $this->command($sock, 'STARTTLS');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('SmtpSender: STARTTLS crypto failed');
                $this->close($sock);
                return false;
            }
            $this->command($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        $this->command($sock, 'AUTH LOGIN');
        $this->command($sock, base64_encode($this->user));
        $reply = $this->command($sock, base64_encode($this->pass));
        if (substr($reply, 0, 3) !== '235') {
            error_log('SmtpSender: AUTH failed: ' . $reply);
            $this->close($sock);
            return false;
        }
        $mailFrom = $this->command($sock, 'MAIL FROM:<' . $this->fromEmail . '>');
        if (substr($mailFrom, 0, 3) !== '250') {
            error_log('SmtpSender: MAIL FROM rejected: ' . $mailFrom);
            $this->command($sock, 'QUIT');
            $this->close($sock);
            return false;
        }
        $rcptTo = $this->command($sock, 'RCPT TO:<' . $to . '>');
        if (substr($rcptTo, 0, 3) !== '250') {
            error_log('SmtpSender: RCPT TO rejected: ' . $rcptTo);
            $this->command($sock, 'QUIT');
            $this->close($sock);
            return false;
        }
        $dataReply = $this->command($sock, 'DATA');
        if (substr($dataReply, 0, 3) !== '354') {
            error_log('SmtpSender: DATA not accepted: ' . $dataReply);
            $this->command($sock, 'QUIT');
            $this->close($sock);
            return false;
        }
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $msg  = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $msg .= "To: {$to}\r\n";
        $msg .= "Subject: {$subjectEnc}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= $isHtml ? "Content-Type: text/html; charset=UTF-8\r\n" : "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body), 76, "\r\n");
        $msg .= "\r\n.\r\n";
        $this->write($sock, $msg);
        $reply = $this->readLine($sock);
        $this->command($sock, 'QUIT');
        $this->close($sock);
        $ok = substr($reply, 0, 3) === '250';
        if (!$ok) {
            error_log('SmtpSender: DATA reply not 250: ' . $reply);
        } else {
            error_log('SmtpSender: sent OK to ' . $to);
        }
        return $ok;
    }

    private function command($sock, $line) {
        $this->write($sock, $line . "\r\n");
        return $this->readLine($sock);
    }

    private function readLine($sock) {
        $s = '';
        while ($line = fgets($sock, 512)) {
            $s .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return trim($s);
    }

    private function write($sock, $data) {
        fwrite($sock, $data);
    }

    private function close($sock) {
        if (is_resource($sock)) fclose($sock);
    }
}
